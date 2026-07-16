<?php
/**
 * Hide content editor when an ACF field group has Hide on screen → Content Editor.
 *
 * ACF CSS only targets classic #postdivrich and only from the first field group.
 * Gutenberg ignores that setting, so we:
 * 1) Force classic editor off via use_block_editor_for_post.
 * 2) Remove editor support on the post edit screen so Classic is gone too.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Check whether matching ACF field groups hide the content editor.
 *
 * @param int    $post_id   Post ID (0 for new posts).
 * @param string $post_type Post type slug.
 */
function wp_theme_acf_hides_content_editor(int $post_id = 0, string $post_type = ''): bool {
    if (! function_exists('acf_get_field_groups')) {
        return false;
    }

    $post_type = sanitize_key($post_type);

    if ($post_type === '' && $post_id > 0) {
        $post_type = (string) get_post_type($post_id);
    }

    if ($post_type === '') {
        return false;
    }

    $field_groups_args = [];

    if ($post_id > 0) {
        $field_groups_args['post_id'] = $post_id;
    } else {
        $field_groups_args['post_type'] = $post_type;
    }

    $field_groups = acf_get_field_groups($field_groups_args);

    if (! is_array($field_groups) || $field_groups === []) {
        return false;
    }

    foreach ($field_groups as $group) {
        $hide_on_screen = $group['hide_on_screen'] ?? null;

        // Fallback if list payload omitted hide_on_screen.
        if ($hide_on_screen === null && ! empty($group['key']) && function_exists('acf_get_field_group')) {
            $group_data = acf_get_field_group($group['key']);
            $hide_on_screen = is_array($group_data) ? ($group_data['hide_on_screen'] ?? null) : null;
        }

        if (is_array($hide_on_screen) && in_array('the_content', $hide_on_screen, true)) {
            return true;
        }
    }

    return false;
}


/**
 * Resolve post ID / post type for the current admin edit request.
 *
 * @return array{post_id:int,post_type:string}|null
 */
function wp_theme_get_acf_editor_screen_context(): ?array {
    global $pagenow;

    if (! is_admin() || ! is_string($pagenow)) {
        return null;
    }

    if (! in_array($pagenow, ['post.php', 'post-new.php'], true)) {
        return null;
    }

    $post_id = 0;

    if (isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post_id = absint(wp_unslash($_GET['post'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    $post_type = '';

    if ($post_id > 0) {
        $post = get_post($post_id);

        if (! $post instanceof WP_Post) {
            return null;
        }

        $post_type = $post->post_type;
    } else {
        $post_type = isset($_GET['post_type']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash((string) $_GET['post_type'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 'post';
    }

    if ($post_type === '') {
        return null;
    }

    return [
        'post_id'   => $post_id,
        'post_type' => $post_type,
    ];
}


/**
 * Disable Gutenberg when ACF hides the content editor for this post.
 *
 * @param bool    $use_block_editor Whether to use block editor.
 * @param WP_Post $post             Post object.
 */
function wp_theme_disable_gutenberg_for_acf_hidden_content(bool $use_block_editor, $post): bool {
    if (! $post instanceof WP_Post) {
        return $use_block_editor;
    }

    $post_id   = (int) $post->ID;
    $post_type = (string) $post->post_type;

    if ($post_type === '' && $post_id > 0) {
        $post_type = (string) get_post_type($post_id);
    }

    if ($post_type === '') {
        $post_type = isset($_GET['post_type']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash((string) $_GET['post_type'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 'post';
    }

    if (wp_theme_acf_hides_content_editor($post_id, $post_type)) {
        return false;
    }

    return $use_block_editor;
}


/**
 * Remove classic editor support when ACF hides the content editor.
 *
 * Scoped to post.php / post-new.php only, so other screens and the frontend
 * keep editor support for the post type.
 */
function wp_theme_remove_classic_editor_for_acf_hidden_content(): void {
    $context = wp_theme_get_acf_editor_screen_context();

    if ($context === null) {
        return;
    }

    if (! wp_theme_acf_hides_content_editor($context['post_id'], $context['post_type'])) {
        return;
    }

    remove_post_type_support($context['post_type'], 'editor');
}


/**
 * Register hooks when ACF is available.
 */
if (class_exists('ACF')) {
    add_filter('use_block_editor_for_post', 'wp_theme_disable_gutenberg_for_acf_hidden_content', 10, 2);
    add_action('load-post.php', 'wp_theme_remove_classic_editor_for_acf_hidden_content');
    add_action('load-post-new.php', 'wp_theme_remove_classic_editor_for_acf_hidden_content');
}
