<?php
/**
 * Security Hardening diagnostics bootstrap.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/admin.php';


/**
 * Admin page slug under Tools.
 */
function wp_theme_security_hardening_page_slug(): string {
    return 'wp-theme-security-hardening';
}


/**
 * Register Tools → Security Hardening.
 */
function wp_theme_security_hardening_register_admin_page(): void {
    add_management_page(
        __('Security Hardening', 'wp-theme'),
        __('Security Hardening', 'wp-theme'),
        'manage_options',
        wp_theme_security_hardening_page_slug(),
        'wp_theme_security_hardening_render_admin_page'
    );
}


add_action('admin_menu', 'wp_theme_security_hardening_register_admin_page');


/**
 * Enqueue admin assets only on the Security Hardening screen.
 *
 * Paths use the parent theme so a child theme cannot intercept them.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function wp_theme_security_hardening_enqueue_assets(string $hook_suffix): void {
    if ('tools_page_wp-theme-security-hardening' !== $hook_suffix) {
        return;
    }

    $css_rel  = '/inc/security-hardening/admin.css';
    $js_rel   = '/inc/security-hardening/admin.js';
    $css_path = get_template_directory() . $css_rel;
    $js_path  = get_template_directory() . $js_rel;

    if (is_readable($css_path)) {
        wp_enqueue_style(
            'wp-theme-security-hardening-admin',
            get_template_directory_uri() . $css_rel,
            array(),
            (string) filemtime($css_path)
        );
    }

    if (is_readable($js_path)) {
        wp_enqueue_script(
            'wp-theme-security-hardening-admin',
            get_template_directory_uri() . $js_rel,
            array(),
            (string) filemtime($js_path),
            true
        );

        wp_localize_script(
            'wp-theme-security-hardening-admin',
            'wpThemeSecurityHardening',
            array(
                'copy'    => __('Copy', 'wp-theme'),
                'copied'  => __('Copied', 'wp-theme'),
                'failed'  => __('Copy failed', 'wp-theme'),
            )
        );
    }
}


add_action('admin_enqueue_scripts', 'wp_theme_security_hardening_enqueue_assets');


/**
 * Register Site Health tests from the catalog.
 *
 * Tests are always registered. Environment softening is applied inside
 * each test so local/staging still lists recommended improvements.
 * Debug mode is omitted: WordPress already ships display/log tests.
 *
 * @param array<string, mixed> $tests Site Health tests.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_register_site_status_tests(array $tests): array {
    if (! isset($tests['direct']) || ! is_array($tests['direct'])) {
        $tests['direct'] = array();
    }

    foreach (wp_theme_security_hardening_catalog() as $id => $meta) {
        if (empty($meta['site_health'])) {
            continue;
        }

        $check_id = (string) $id;

        $tests['direct'][ 'wp_theme_security_hardening_' . $check_id ] = array(
            'label'     => wp_theme_security_hardening_check_label($check_id),
            'test'      => fn(): array => wp_theme_security_hardening_format_site_health_test($check_id),
            'skip_cron' => ! empty($meta['skip_cron']),
        );
    }

    return $tests;
}


add_filter('site_status_tests', 'wp_theme_security_hardening_register_site_status_tests');
add_filter('debug_information', 'wp_theme_security_hardening_debug_information');
