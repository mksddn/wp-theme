<?php
/**
 * Security features for WordPress.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Disable XML-RPC.
 */
function wp_theme_disable_xmlrpc(): void {
    add_filter('xmlrpc_enabled', '__return_false');
}


/**
 * Disable X-Pingback in header.
 */
function wp_theme_disable_x_pingback(): void {
    add_filter('wp_headers', 'wp_theme_remove_x_pingback');
}


/**
 * Removes X-Pingback from headers.
 *
 * @param array $headers Headers.
 * @return array Modified headers.
 */
function wp_theme_remove_x_pingback(array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
}


/**
 * Hide WordPress version from head and RSS feeds.
 */
function wp_theme_hide_wp_version(): void {
    // Remove version from head
    remove_action('wp_head', 'wp_generator');

    // Remove version from RSS feeds
    add_filter('the_generator', '__return_empty_string');
}


/**
 * Disable file editing in admin.
 */
function wp_theme_disable_file_editing(): void {
    if (! defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
}


/**
 * Remove unnecessary meta tags from head.
 *
 * @deprecated This functionality is now handled by Performance settings
 * to avoid duplication with performance_optimize_queries and performance_cleanup_head
 */
function wp_theme_remove_unnecessary_meta(): void {
    // Meta tags removal is now handled by Performance settings
    // to avoid duplication with performance_optimize_queries and performance_cleanup_head
}


/**
 * Disable user enumeration.
 */
function wp_theme_disable_user_enumeration(): void {
    // Block user enumeration via author archives
    if (! is_admin() && isset($_GET['author'])) {
        wp_redirect(home_url());
        exit;
    }

    // Block user enumeration via REST API
    add_filter('rest_endpoints', function (array $endpoints): array {
        if (isset($endpoints['/wp/v2/users'])) {
            unset($endpoints['/wp/v2/users']);
        }

        if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
            unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
        }

        return $endpoints;
    });
}


/**
 * Add security headers.
 */
function wp_theme_add_security_headers(): void {
    add_action('send_headers', function (): void {
        if (! is_admin()) {
            // Prevent clickjacking
            header('X-Frame-Options: SAMEORIGIN');

            // Prevent MIME type sniffing
            header('X-Content-Type-Options: nosniff');

            // Enable XSS protection (deprecated but still useful for older browsers)
            header('X-XSS-Protection: 1; mode=block');

            // Referrer Policy for privacy
            header('Referrer-Policy: strict-origin-when-cross-origin');

            // Content Security Policy header is controlled by Theme Settings
            $settings = function_exists('wp_theme_get_settings') ? wp_theme_get_settings() : [];
            if ($settings !== [] && ! empty($settings['security_csp_header'])) {
                $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self';";
                header("Content-Security-Policy: {$csp}");
            }

            // Strict Transport Security (HTTPS only)
            if (is_ssl()) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            }
        }
    });
}


/**
 * Disable directory browsing.
 */
function wp_theme_disable_directory_browsing(): void {
    // This is typically handled by .htaccess, but we can add extra protection
    add_action('init', function (): void {
        if (is_admin() || is_user_logged_in()) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';

        // Block common directory browsing attempts
        if (preg_match('/\/\.(git|svn|env|htaccess|htpasswd)/', $request_uri)) {
            wp_redirect(home_url());
            exit;
        }
    });
}


/**
 * Limit login attempts (basic implementation).
 */
function wp_theme_limit_login_attempts(): void {
    add_action('wp_login_failed', 'wp_theme_track_failed_login');
    add_filter('authenticate', 'wp_theme_check_login_attempts', 30, 3);
}


/**
 * Track failed login attempts.
 *
 * @param string $_username Username that failed to login.
 */
function wp_theme_track_failed_login(string $_username): void {
    $ip = wp_theme_get_client_ip();
    $attempts = get_transient("login_attempts_{$ip}") ?: 0;
    $attempts++;

    if ($attempts >= 5) {
        set_transient("login_blocked_{$ip}", true, 15 * MINUTE_IN_SECONDS);
    }

    set_transient("login_attempts_{$ip}", $attempts, 15 * MINUTE_IN_SECONDS);
}


/**
 * Check if IP is blocked from login attempts.
 *
 * @param WP_User|WP_Error|null $user User object or error.
 * @param string $_username Username.
 * @param string $_password Password.
 * @return WP_User|WP_Error|null User object, error, or null.
 */
function wp_theme_check_login_attempts($user, string $_username, string $_password) {
    $ip = wp_theme_get_client_ip();

    if (get_transient("login_blocked_{$ip}")) {
        return new WP_Error('login_blocked', 'Too many failed login attempts. Please try again later.');
    }

    return $user;
}


/**
 * Get client IP address with improved detection.
 *
 * @return string Client IP address.
 */
function wp_theme_get_client_ip(): string {
    // Priority order for IP detection (most trusted first)
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_TRUE_CLIENT_IP',       // Cloudflare Enterprise
        'HTTP_X_REAL_IP',            // Nginx proxy
        'HTTP_X_FORWARDED_FOR',      // Standard proxy header
        'HTTP_X_FORWARDED',          // Alternative proxy header
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster environments
        'HTTP_FORWARDED_FOR',        // RFC 7239
        'HTTP_FORWARDED',            // RFC 7239
        'HTTP_CLIENT_IP',            // Some proxies
        'REMOTE_ADDR'                // Direct connection
    ];

    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) && ! empty($_SERVER[$key])) {
            $ip = sanitize_text_field(wp_unslash((string) $_SERVER[$key]));

            // Handle comma-separated IPs (take the first one)
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }

            $ip = trim($ip);

            // Validate IP address (allow private ranges for internal networks)
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
}


/**
 * Initialize security features based on theme settings.
 */
function wp_theme_init_security_features(): void {
    $settings = wp_theme_get_settings();

    if ($settings['security_xmlrpc']) {
        wp_theme_disable_xmlrpc();
    }

    if ($settings['security_x_pingback']) {
        wp_theme_disable_x_pingback();
    }

    if ($settings['security_hide_version']) {
        wp_theme_hide_wp_version();
    }

    if ($settings['security_disable_editing']) {
        wp_theme_disable_file_editing();
    }

    if ($settings['security_disable_enumeration']) {
        wp_theme_disable_user_enumeration();
    }

    if ($settings['security_headers']) {
        wp_theme_add_security_headers();
    }

    if ($settings['security_directory_browsing']) {
        wp_theme_disable_directory_browsing();
    }

    if ($settings['security_login_limits']) {
        wp_theme_limit_login_attempts();
    }
}


// Initialize security features
add_action('init', 'wp_theme_init_security_features');
