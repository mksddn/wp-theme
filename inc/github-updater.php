<?php
/**
 * GitHub Theme Updater
 *
 * @package wp-theme
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Theme Updater Class
 */
final class GitHub_Theme_Updater {

    /**
     * Static flag to prevent multiple instances
     */
    private static ?self $instance = null;

    /**
     * GitHub repository owner
     */
    private ?string $owner = null;

    /**
     * GitHub repository name
     */
    private ?string $repo = null;

    /**
     * Theme slug
     *
     * @var string
     */
    private $theme_slug;

    /**
     * GitHub API URL
     */
    private readonly string $api_url;


    /**
     * Constructor
     */
    public function __construct() {
        // Prevent multiple instances
        if (self::$instance instanceof \GitHub_Theme_Updater) {
            return;
        }

        self::$instance = $this;

        $this->theme_slug = get_template();

        // If theme directory has version suffix, use the base name for GitHub updater
        if (preg_match('/^(.+)-[\d\.]+$/', $this->theme_slug, $matches)) {
            $this->theme_slug = $matches[1];
        }

        // Extract GitHub info from style.css
        $github_uri = $this->get_github_theme_uri();
        if (is_string($github_uri) && $github_uri) {
            $parts = explode('/', $github_uri);
            $this->owner = $parts[0] ?? '';
            $this->repo = $parts[1] ?? '';
        }

        $this->api_url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases/latest";

        if ($this->owner && $this->repo) {
            $this->init_hooks();
        }
    }


    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        add_filter('pre_set_site_transient_update_themes', $this->check_for_updates(...));
        add_filter('site_transient_update_themes', $this->discard_current_version_updates(...));
        add_filter('themes_api', $this->theme_api_call(...), 10, 3);
        add_action('upgrader_process_complete', $this->after_theme_update(...), 10, 2);
        add_action('upgrader_post_install', $this->after_theme_install(...), 10, 2);
        add_action('setup_theme', $this->ensure_consistent_theme_options(...), 1);

        // Add admin notice for manual update check
        add_action('admin_notices', $this->admin_notice_for_updates(...));

        // Handle force check updates
        add_action('admin_init', $this->handle_force_check(...));
    }


    /**
     * Stylesheet of the parent theme this updater tracks.
     *
     * wp_get_theme() without a slug is the active stylesheet. With a child
     * theme that is the child, whose version must not be compared to the parent release.
     */
    private function get_parent_stylesheet(): string {
        $template = get_template();
        if (is_string($template) && $template !== '') {
            return $template;
        }

        return $this->theme_slug;
    }


    /**
     * Theme directories this updater may store in the update transient.
     *
     * @return string[]
     */
    private function get_possible_slugs(): array {
        $slugs = [$this->theme_slug];
        $parent = $this->get_parent_stylesheet();
        if (!in_array($parent, $slugs, true)) {
            $slugs[] = $parent;
        }

        return $slugs;
    }


    /**
     * Strip a single leading "v" and surrounding whitespace.
     */
    private function normalize_version(string $version): string {
        $version = trim($version);
        $stripped = preg_replace('/^v/i', '', $version);

        return is_string($stripped) ? $stripped : '';
    }


    /**
     * Version header of a specific theme directory.
     */
    private function get_theme_version_for_slug(string $slug): string {
        if ($slug === '') {
            return '';
        }

        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return '';
        }

        $version = $theme->get('Version');
        if (!is_string($version) || $version === '') {
            return '';
        }

        return $this->normalize_version($version);
    }


    /**
     * Get the parent theme version from style.css.
     */
    private function get_theme_version(): string {
        $version = $this->get_theme_version_for_slug($this->get_parent_stylesheet());
        if ($version === '') {
            $version = $this->get_theme_version_for_slug($this->theme_slug);
        }

        return $version !== '' ? $version : '1.0.0';
    }


    /**
     * Get GitHub Theme URI from the parent theme style.css.
     */
    private function get_github_theme_uri() {
        $theme_data = wp_get_theme($this->get_parent_stylesheet());

        // Try different ways to get GitHub URI
        $github_uri = $theme_data->get('GitHub Theme URI');

        // If still not found, try reading style.css directly
        if (!$github_uri) {
            // Try Theme URI
            $theme_uri = $theme_data->get('Theme URI');
            if ($theme_uri && str_contains((string) $theme_uri, 'github.com')) {
                $path = parse_url((string) $theme_uri, PHP_URL_PATH);
                return ltrim($path, '/');
            }

            $style_css_path = get_template_directory() . '/style.css';
            if (file_exists($style_css_path)) {
                $style_content = file_get_contents($style_css_path);
                if (preg_match('/GitHub Theme URI:\s*(.+)/i', $style_content, $matches)) {
                    $github_uri = trim($matches[1]);
                }
            }
        }

        return $github_uri;
    }


    /**
     * Check for theme updates
     *
     * @param object $transient
     * @return object
     */
    public function check_for_updates($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        // Drop previous offers so an equal release cannot stay in response.
        foreach ($this->get_possible_slugs() as $slug) {
            if (isset($transient->response[$slug])) {
                unset($transient->response[$slug]);
            }
        }

        $current_version = $this->get_theme_version();
        $remote_version = $this->get_remote_version();

        // The themes screen lists every response entry, even when versions match.
        if ($remote_version && version_compare($current_version, $remote_version, '<')) {
            $transient->response[$this->theme_slug] = [
                'theme' => $this->theme_slug,
                'new_version' => $remote_version,
                'url' => "https://github.com/{$this->owner}/{$this->repo}",
                'package' => $this->get_download_url($remote_version),
            ];
        }

        return $transient;
    }


    /**
     * Hide an update offer that is not newer than the installed copy.
     *
     * A stale transient keeps showing in wp-admin until the next update check.
     * Compare the offered version with that theme's own style.css.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function discard_current_version_updates($transient) {
        if (!is_object($transient) || empty($transient->response) || !is_array($transient->response)) {
            return $transient;
        }

        foreach ($this->get_possible_slugs() as $slug) {
            if (!isset($transient->response[$slug])) {
                continue;
            }

            $new_version = $this->read_update_version($transient->response[$slug]);
            $installed = $this->get_theme_version_for_slug($slug);
            if ($installed === '' && isset($transient->checked[$slug])) {
                $installed = $this->normalize_version((string) $transient->checked[$slug]);
            }

            if ($installed === '') {
                $installed = $this->get_theme_version_for_slug($this->get_parent_stylesheet());
            }

            if ($new_version === '' || $installed === '') {
                continue;
            }

            if (version_compare($installed, $new_version, '>=')) {
                unset($transient->response[$slug]);
            }
        }

        return $transient;
    }


    /**
     * Read new_version from a theme update payload.
     *
     * @param mixed $update
     */
    private function read_update_version($update): string {
        $version = '';
        if (is_array($update) && isset($update['new_version'])) {
            $version = (string) $update['new_version'];
        } elseif (is_object($update) && isset($update->new_version)) {
            $version = (string) $update->new_version;
        }

        return $this->normalize_version($version);
    }


    /**
     * Get remote version from GitHub API
     */
    private function get_remote_version(): ?string {
        $data = $this->get_latest_release_data();
        if (!isset($data['tag_name'])) {
            return null;
        }

        $version = $this->normalize_version((string) $data['tag_name']);

        return $version !== '' ? $version : null;
    }


    /**
     * Fetch latest release data from GitHub with transient caching
     */
    private function get_latest_release_data(): ?array {
        if (!$this->owner || !$this->repo) {
            return null;
        }

        $cache_key = 'github_release_' . md5($this->owner . '/' . $this->repo);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_safe_remote_get($this->api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Theme-Updater',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 403) {
            $remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
            if ($remaining !== null && (int) $remaining === 0) {
                set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
                return null;
            }
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['tag_name'])) {
            set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
            return $data;
        }

        return null;
    }


    /**
     * Get download URL for specific version
     */
    private function get_download_url(string $version): string {
        return "https://github.com/{$this->owner}/{$this->repo}/archive/refs/tags/v{$version}.zip";
    }


    /**
     * Handle theme API calls
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object|array
     */
    public function theme_api_call($result, $action, $args) {
        if ($action !== 'theme_information' || $args->slug !== $this->theme_slug) {
            return $result;
        }

        $data = $this->get_latest_release_data();
        if (!$data || empty($data['tag_name'])) {
            return $result;
        }

        $remote_version = $this->normalize_version((string) $data['tag_name']);
        if ($remote_version === '') {
            return $result;
        }

        $result = [
            'name' => $this->repo,
            'slug' => $this->theme_slug,
            'version' => $remote_version,
            'author' => $this->owner,
            'homepage' => "https://github.com/{$this->owner}/{$this->repo}",
            'download_link' => $this->get_download_url($remote_version),
            'sections' => [
                'description' => $data['body'] ?? '',
                'changelog' => $data['body'] ?? '',
            ],
        ];

        return (object) $result;
    }


    /**
     * Actions after theme update
     *
     * @param object $_upgrader_object
     */
    public function after_theme_update($_upgrader_object, array $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'theme' && (isset($options['themes']) && in_array($this->theme_slug, $options['themes']))) {
            // Clear any caches
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            // Clear update transients to force recheck
            delete_site_transient('update_themes');
            delete_transient('update_themes');

            // Rename theme directory to remove version suffix
            $this->rename_theme_directory();
        }
    }


    /**
     * Actions after theme install
     *
     * @param object $_upgrader_object
     */
    public function after_theme_install($_upgrader_object, array $options): void {
        if ($options['type'] === 'theme' && isset($options['themes'])) {
            foreach ($options['themes'] as $theme) {
                if (str_starts_with((string) $theme, $this->theme_slug)) {
                    $this->rename_theme_directory();
                    break;
                }
            }
        }
    }


    /**
     * Rename theme directory to remove version suffix
     */
    private function rename_theme_directory(): void {
        $themes_dir = WP_CONTENT_DIR . '/themes/';
        $base_name = $this->theme_slug;

        // Detect any directory with version suffix for this theme
        $dir_handle = @opendir($themes_dir);
        $versioned_dir = null;
        if ($dir_handle) {
            while (($entry = readdir($dir_handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (is_dir($themes_dir . $entry) && preg_match('/^' . preg_quote($base_name, '/') . '-[\d\.]+$/', $entry)) {
                    $versioned_dir = $entry;
                    break;
                }
            }

            closedir($dir_handle);
        }

        // If versioned dir exists, rename to base name
        if ($versioned_dir) {
            $old_path = $themes_dir . $versioned_dir;
            $new_path = $themes_dir . $base_name;

            // Create backup of old directory if it exists
            if (is_dir($new_path)) {
                rename($new_path, $new_path . '-backup-' . time());
            }

            // Rename new directory to base name
            if (is_dir($old_path)) {
                $renamed = rename($old_path, $new_path);

                if ($renamed) {
                    // Ensure WP options point to the new base theme directory
                    $old_stylesheet = $versioned_dir;
                    $new_stylesheet = $base_name;

                    $stylesheet_option = get_option('stylesheet');
                    if ($stylesheet_option === $old_stylesheet) {
                        update_option('stylesheet', $new_stylesheet);
                    }

                    $template_option = get_option('template');
                    if ($template_option === $old_stylesheet) {
                        update_option('template', $new_stylesheet);
                    }

                    // Migrate theme mods to preserve Customizer settings
                    $old_mods_key = 'theme_mods_' . $old_stylesheet;
                    $new_mods_key = 'theme_mods_' . $new_stylesheet;
                    $old_mods = get_option($old_mods_key);
                    if ($old_mods !== false) {
                        $new_mods = get_option($new_mods_key);
                        if ($new_mods === false) {
                            update_option($new_mods_key, $old_mods);
                        }

                        delete_option($old_mods_key);
                    }

                    // Clean themes cache so WP immediately sees the new directory
                    if (function_exists('wp_clean_themes_cache')) {
                        wp_clean_themes_cache(true);
                    }
                }
            }
        }
    }


    /**
     * Ensure template/stylesheet options and theme_mods do not point to a versioned directory
     * Runs early on every request to self-heal after updates
     */
    public function ensure_consistent_theme_options(): void {
        $base_name = $this->theme_slug;
        $themes_dir = WP_CONTENT_DIR . '/themes/';
        $base_exists = is_dir($themes_dir . $base_name);
        if (!$base_exists) {
            return;
        }

        $stylesheet_option = get_option('stylesheet');
        $template_option = get_option('template');

        $updated = false;

        if (is_string($stylesheet_option) && preg_match('/^' . preg_quote($base_name, '/') . '-[\d\.]+$/', $stylesheet_option)) {
            $old = $stylesheet_option;
            update_option('stylesheet', $base_name);
            $this->migrate_theme_mods($old, $base_name);
            $updated = true;
        }

        if (is_string($template_option) && preg_match('/^' . preg_quote($base_name, '/') . '-[\d\.]+$/', $template_option)) {
            $old = $template_option;
            update_option('template', $base_name);
            $this->migrate_theme_mods($old, $base_name);
            $updated = true;
        }

        if ($updated && function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(true);
        }
    }


    /**
     * Migrate theme_mods from old key to new key
     */
    private function migrate_theme_mods(string $old_stylesheet, string $new_stylesheet): void {
        $old_mods_key = 'theme_mods_' . $old_stylesheet;
        $new_mods_key = 'theme_mods_' . $new_stylesheet;
        $old_mods = get_option($old_mods_key);
        if ($old_mods !== false) {
            $new_mods = get_option($new_mods_key);
            if ($new_mods === false) {
                update_option($new_mods_key, $old_mods);
            }

            delete_option($old_mods_key);
        }
    }


    /**
     * Show admin notice with update check button
     */
    public function admin_notice_for_updates(): void {
        if (!current_user_can('update_themes')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && $screen->id === 'themes') {
            // Get current version dynamically to ensure it's up to date
            $current_version = $this->get_theme_version();
            $remote_version = $this->get_remote_version();

            if ($remote_version && version_compare($current_version, $remote_version, '<')) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>' . esc_html__('Theme Update Available:', 'wp-theme') . '</strong> ';
                echo esc_html(
                    sprintf(
                        /* translators: %s: new theme version */
                        __('Version %s is available.', 'wp-theme'),
                        $remote_version
                    )
                ) . ' ';
                echo '<a href="' . esc_url(admin_url('update-core.php')) . '">' . esc_html__('Check for updates', 'wp-theme') . '</a>';
                echo '</p></div>';
            } else {
                $force_url = wp_nonce_url(admin_url('themes.php?force_check_updates=1'), 'force_check_updates');
                echo '<div class="notice notice-info is-dismissible">';
                echo '<p><a href="' . esc_url($force_url) . '">' . esc_html__('Check for theme updates', 'wp-theme') . '</a></p>';
                echo '</div>';
            }
        }
    }


    /**
     * Handle force check updates
     */
    public function handle_force_check(): void {
        if (isset($_GET['force_check_updates']) && sanitize_text_field(wp_unslash($_GET['force_check_updates'])) === '1') {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!$nonce || !wp_verify_nonce($nonce, 'force_check_updates')) {
                wp_die(esc_html__('Security check failed', 'wp-theme'));
            }

            delete_site_transient('update_themes');
            delete_transient('update_themes');
            wp_update_themes();
            wp_redirect(admin_url('themes.php?updated=1'));
            exit;
        }
    }


}

// Initialize the updater only once
if (!class_exists('GitHub_Theme_Updater_Initialized')) {
    add_action('init', function(): void {
        if (is_admin()) {
            $current_theme = get_template();
            // Check if current theme is wp-theme (with or without version suffix)
            if ($current_theme === 'wp-theme' || preg_match('/^wp-theme-[\d\.]+$/', $current_theme)) {
                new GitHub_Theme_Updater();
            }
        }
    });

    // Mark as initialized to prevent multiple instances
    /** @psalm-suppress UnusedClass */
    final class GitHub_Theme_Updater_Initialized {
    }
}
