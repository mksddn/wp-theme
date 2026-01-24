<?php
/**
 * Hide Gutenberg editor when ACF field group has hide_content_editor enabled.
 * This ensures Gutenberg is hidden even when it's enabled globally.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Disable Gutenberg editor for posts where ACF field group hides content editor.
 *
 * @param bool    $use_block_editor Whether to use block editor.
 * @param WP_Post $post             Post object.
 * @return bool Modified value.
 */
function wp_theme_disable_gutenberg_for_acf_hidden_content(bool $use_block_editor, $post): bool {
    if (!$post || !function_exists('acf_get_field_groups')) {
        return $use_block_editor;
    }

    $post_id = $post->ID ?? 0;
    $post_type = $post->post_type ?? '';

    if (!$post_type && $post_id > 0) {
        $post_type = get_post_type($post_id);
    }

    if (!$post_type) {
        $post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : 'post';
    }

    $field_groups_args = [];
    if ($post_id > 0) {
        $field_groups_args['post_id'] = $post_id;
    } else {
        $field_groups_args['post_type'] = $post_type;
    }

    $field_groups = acf_get_field_groups($field_groups_args);

    foreach ($field_groups as $group) {
        $group_data = acf_get_field_group($group['key']);

        if (!$group_data || !isset($group_data['hide_on_screen'])) {
            continue;
        }

        $hide_on_screen = $group_data['hide_on_screen'];

        if (is_array($hide_on_screen) && in_array('the_content', $hide_on_screen, true)) {
            return false;
        }
    }

    return $use_block_editor;
}


/**
 * Register filter to disable Gutenberg when ACF hides content editor.
 */
if (class_exists('ACF')) {
    add_filter('use_block_editor_for_post', 'wp_theme_disable_gutenberg_for_acf_hidden_content', 10, 2);
}
