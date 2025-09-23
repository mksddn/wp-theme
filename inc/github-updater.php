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
class GitHub_Theme_Updater {

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
     * Current theme version
     */
    private $current_version;

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

        $this->current_version = $this->get_theme_version();

        // Extract GitHub info from style.css
        $github_uri = $this->get_github_theme_uri();
        if ($github_uri) {
            $parts = explode('/', (string) $github_uri);
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
        add_filter('themes_api', $this->theme_api_call(...), 10, 3);
        add_action('upgrader_process_complete', $this->after_theme_update(...), 10, 2);
        add_action('upgrader_post_install', $this->after_theme_install(...), 10, 2);

        // Add admin notice for manual update check
        add_action('admin_notices', $this->admin_notice_for_updates(...));

        // Handle force check updates
        add_action('admin_init', $this->handle_force_check(...));
    }


    /**
     * Get theme version from style.css
     */
    private function get_theme_version() {
        $theme_data = wp_get_theme();
        return $theme_data->get('Version') ?: '1.0.0';
    }


    /**
     * Get GitHub Theme URI from style.css
     */
    private function get_github_theme_uri() {
        $theme_data = wp_get_theme();

        // Try different ways to get GitHub URI
        $github_uri = $theme_data->get('GitHub Theme URI');

        if (!$github_uri) {
            // Try Theme URI
            $theme_uri = $theme_data->get('Theme URI');
            if ($theme_uri && str_contains((string) $theme_uri, 'github.com')) {
                $path = parse_url((string) $theme_uri, PHP_URL_PATH);
                return ltrim($path, '/');
            }
        }

        // If still not found, try reading style.css directly
        if (!$github_uri) {
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
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_version = $this->get_remote_version();

        if ($remote_version && version_compare($this->current_version, $remote_version, '<')) {
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
     * Get remote version from GitHub API
     */
    private function get_remote_version(): ?string {
        $response = wp_remote_get($this->api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Theme-Updater',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['tag_name'])) {
            return ltrim((string) $data['tag_name'], 'v');
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

        $remote_version = $this->get_remote_version();
        if (!$remote_version) {
            return $result;
        }

        $response = wp_remote_get($this->api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Theme-Updater',
            ],
        ]);

        if (is_wp_error($response)) {
            return $result;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

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
     * @param object $upgrader_object
     * @param array $options
     */
    public function after_theme_update($upgrader_object, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'theme' && (isset($options['themes']) && in_array($this->theme_slug, $options['themes']))) {
            // Clear any caches
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            // Rename theme directory to remove version suffix
            $this->rename_theme_directory();
        }
    }


    /**
     * Actions after theme install
     *
     * @param object $upgrader_object
     * @param array $options
     */
    public function after_theme_install($upgrader_object, $options): void {
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
        $current_theme = get_template();

        // If theme directory has version suffix, rename it
        if (preg_match('/^(.+)-[\d\.]+$/', $current_theme, $matches)) {
            $base_name = $matches[1];
            $old_path = $themes_dir . $current_theme;
            $new_path = $themes_dir . $base_name;

            // Create backup of old directory if it exists
            if (is_dir($new_path)) {
                rename($new_path, $new_path . '-backup-' . time());
            }

            // Rename new directory to base name
            if (is_dir($old_path)) {
                rename($old_path, $new_path);
            }
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
            $remote_version = $this->get_remote_version();

            if ($remote_version && version_compare($this->current_version, $remote_version, '<')) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>Theme Update Available:</strong> ';
                echo "Version {$remote_version} is available. ";
                echo '<a href="' . admin_url('update-core.php') . '">Check for updates</a>';
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-info is-dismissible">';
                echo '<p><a href="' . admin_url('themes.php?force_check_updates=1') . '">Check for theme updates</a></p>';
                echo '</div>';
            }
        }
    }


    /**
     * Handle force check updates
     */
    public function handle_force_check(): void {
        if (isset($_GET['force_check_updates']) && $_GET['force_check_updates'] === '1') {
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
    class GitHub_Theme_Updater_Initialized {
    }
}
