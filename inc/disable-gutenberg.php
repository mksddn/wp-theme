<?php
/**
 * Disable Gutenberg for selected post types.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Get selected post types for Gutenberg disabling.
 */
function wp_theme_get_gutenberg_disabled_post_types(): array {
    $raw_settings = get_option('wp_theme_settings', []);
    if (! is_array($raw_settings)) {
        return [];
    }

    $global_switch_enabled = ! empty($raw_settings['disable_gutenberg']);
    $has_post_types_setting = array_key_exists('disable_gutenberg_post_types', $raw_settings);

    if (! $has_post_types_setting) {
        return $global_switch_enabled ? ['__all__'] : [];
    }

    if (! is_array($raw_settings['disable_gutenberg_post_types'])) {
        return $global_switch_enabled ? ['__all__'] : [];
    }

    $selected_post_types = array_values(
        array_filter(
            array_map(sanitize_key(...), $raw_settings['disable_gutenberg_post_types'])
        )
    );

    if ($selected_post_types !== []) {
        return $selected_post_types;
    }

    return $global_switch_enabled ? ['__all__'] : [];
}


/**
 * Disable block editor for selected post types.
 *
 * @param bool   $use_block_editor Whether block editor is enabled.
 * @param string $post_type        Current post type.
 */
function wp_theme_disable_gutenberg_for_post_type($use_block_editor, $post_type): bool {
    $selected_post_types = wp_theme_get_gutenberg_disabled_post_types();

    if (in_array('__all__', $selected_post_types, true)) {
        return false;
    }

    if (is_string($post_type)
        && in_array(sanitize_key($post_type), $selected_post_types, true)
    ) {
        return false;
    }

    return (bool) $use_block_editor;
}


/**
 * Disable block editor for selected post objects.
 *
 * @param bool    $use_block_editor Whether block editor is enabled.
 * @param WP_Post $post             Current post object.
 */
function wp_theme_disable_gutenberg_for_post($use_block_editor, $post): bool {
    $post_type = '';

    if (is_object($post) && isset($post->post_type)) {
        $post_type = (string) $post->post_type;
    }

    return wp_theme_disable_gutenberg_for_post_type($use_block_editor, $post_type);
}


add_filter('use_block_editor_for_post_type', 'wp_theme_disable_gutenberg_for_post_type', 100, 2);
add_filter('use_block_editor_for_post', 'wp_theme_disable_gutenberg_for_post', 100, 2);
