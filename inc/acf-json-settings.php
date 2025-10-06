<?php
/**
 * ACF Local JSON settings for save/load paths.
 * Ensures directories exist and sets preferred locations.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Ensure a directory exists (create recursively if missing).
 * Returns the path if exists/created, otherwise returns null.
 */
function wp_theme_acf_ensure_directory($path) {
    if (!is_dir($path) && !wp_mkdir_p($path)) {
        return null;
    }

    return $path;
}


/**
 * Determine preferred save path for ACF Local JSON.
 * Priority: child theme → parent theme → wp-content/acf-json
 */
function wp_theme_acf_get_preferred_save_path(): string {
    $paths = [];

    $child_theme_path = trailingslashit(get_stylesheet_directory()) . 'acf-json';
    $parent_theme_path = trailingslashit(get_template_directory()) . 'acf-json';
    $content_fallback = trailingslashit(WP_CONTENT_DIR) . 'acf-json';

    $paths[] = $child_theme_path;
    if ($parent_theme_path !== $child_theme_path) {
        $paths[] = $parent_theme_path;
    }

    $paths[] = $content_fallback;

    foreach ($paths as $path) {
        $ensured = wp_theme_acf_ensure_directory($path);
        if ($ensured && is_writable($ensured)) {
            return $ensured;
        }
    }

    return $child_theme_path;
}


/**
 * Filter ACF save path for Local JSON and auto-create directories if needed.
 */
add_filter('acf/settings/save_json', function ($path) {
    $preferred = wp_theme_acf_get_preferred_save_path();
    return $preferred ?: $path;
});

/**
 * Filter ACF load paths for Local JSON: include child theme, parent theme and fallback.
 */
add_filter('acf/settings/load_json', function ($paths) {
    if (is_array($paths) && $paths !== []) {
        array_shift($paths);
    }

    $child_theme_path = trailingslashit(get_stylesheet_directory()) . 'acf-json';
    $parent_theme_path = trailingslashit(get_template_directory()) . 'acf-json';
    $content_fallback = trailingslashit(WP_CONTENT_DIR) . 'acf-json';

    foreach ([$child_theme_path, $parent_theme_path, $content_fallback] as $path) {
        if (is_dir($path)) {
            $paths[] = $path;
        }
    }

    return $paths;
});
