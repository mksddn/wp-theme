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
    private static $instance = null;

    /**
     * GitHub repository owner
     *
     * @var string
     */
    private $owner;

    /**
     * GitHub repository name
     *
     * @var string
     */
    private $repo;

    /**
     * Theme slug
     *
     * @var string
     */
    private $theme_slug;

    /**
     * Current theme version
     *
     * @var string
     */
    private $current_version;

    /**
     * GitHub API URL
     *
     * @var string
     */
    private $api_url;

    /**
     * Constructor
     */
    public function __construct() {
        // Prevent multiple instances
        if (self::$instance !== null) {
            return;
        }
        self::$instance = $this;
        
        $this->theme_slug = get_template();
        $this->current_version = $this->get_theme_version();
        
        // Extract GitHub info from style.css
        $github_uri = $this->get_github_theme_uri();
        if ($github_uri) {
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
        add_filter('pre_set_site_transient_update_themes', [$this, 'check_for_updates']);
        add_filter('themes_api', [$this, 'theme_api_call'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'after_theme_update'], 10, 2);
    }

    /**
     * Get theme version from style.css
     *
     * @return string
     */
    private function get_theme_version(): string {
        $theme_data = wp_get_theme();
        return $theme_data->get('Version') ?: '1.0.0';
    }

    /**
     * Get GitHub Theme URI from style.css
     *
     * @return string|null
     */
    private function get_github_theme_uri(): ?string {
        $theme_data = wp_get_theme();
        
        // Try different ways to get GitHub URI
        $github_uri = $theme_data->get('GitHub Theme URI');
        
        if (!$github_uri) {
            // Try Theme URI
            $theme_uri = $theme_data->get('Theme URI');
            if ($theme_uri && strpos($theme_uri, 'github.com') !== false) {
                $path = parse_url($theme_uri, PHP_URL_PATH);
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
     *
     * @return string|null
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
            return ltrim($data['tag_name'], 'v');
        }

        return null;
    }

    /**
     * Get download URL for specific version
     *
     * @param string $version
     * @return string
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
        if ($options['action'] === 'update' && $options['type'] === 'theme') {
            if (isset($options['themes']) && in_array($this->theme_slug, $options['themes'])) {
                // Clear any caches
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
                
                // Log the update
                error_log("Theme {$this->theme_slug} updated successfully via GitHub");
            }
        }
    }

}

// Initialize the updater only once
if (!class_exists('GitHub_Theme_Updater_Initialized')) {
    add_action('init', function() {
        if (is_admin() && get_template() === 'wp-theme') {
            new GitHub_Theme_Updater();
        }
    });
    
    // Mark as initialized to prevent multiple instances
    class GitHub_Theme_Updater_Initialized {}
}
