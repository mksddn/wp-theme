<?php
/**
 * Keep ACF field group location page/post choices across Polylang languages.
 *
 * Polylang admin-bar language filter hides pages/posts from other languages in
 * ACF location dropdowns. Missing options look reset and can be overwritten on save.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Whether current request is ACF field group location UI.
 */
function wp_theme_is_acf_field_group_location_request(): bool {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $action = isset($_REQUEST['action']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_text_field(wp_unslash((string) $_REQUEST['action']))
            : '';

        return 'acf/field_group/render_location_rule' === $action;
    }

    if (! function_exists('get_current_screen')) {
        return false;
    }

    $screen = get_current_screen();

    return $screen && 'acf-field-group' === $screen->id;
}


/**
 * Disable Polylang language filter for location queries.
 *
 * @param WP_Query $query Query instance.
 */
function wp_theme_acf_location_force_all_langs(WP_Query $query): void {
    $query->set('lang', '');
}


/**
 * Enable all-languages queries on ACF field group screens.
 */
function wp_theme_acf_location_ignore_pll_lang(): void {
    static $enabled = false;

    if ($enabled || ! wp_theme_is_acf_field_group_location_request()) {
        return;
    }

    $enabled = true;
    add_action('pre_get_posts', 'wp_theme_acf_location_force_all_langs', 1);
}


/**
 * Ensure saved location page/post ID stays in choices if still missing.
 *
 * @param array $values Location rule choices.
 * @param array $rule   Location rule.
 * @return array
 */
function wp_theme_acf_location_preserve_selected_value(array $values, array $rule): array {
    $params = ['page', 'post', 'page_parent'];

    if (empty($rule['param']) || ! in_array($rule['param'], $params, true)) {
        return $values;
    }

    if (empty($rule['value'])) {
        return $values;
    }

    $post_id = (int) $rule['value'];

    if ($post_id <= 0) {
        return $values;
    }

    if (isset($values[$post_id]) || isset($values[(string) $post_id])) {
        return $values;
    }

    // Nested choices (Post location groups by post type label).
    foreach ($values as $group) {
        if (is_array($group) && (isset($group[$post_id]) || isset($group[(string) $post_id]))) {
            return $values;
        }
    }

    $post = get_post($post_id);

    if (! $post instanceof WP_Post) {
        return $values;
    }

    $label = function_exists('acf_get_post_title')
        ? acf_get_post_title($post)
        : $post->post_title;

    if ('post' === $rule['param']) {
        $post_type_obj = get_post_type_object($post->post_type);
        $group_label   = $post_type_obj ? $post_type_obj->labels->name : $post->post_type;

        if (! isset($values[$group_label]) || ! is_array($values[$group_label])) {
            $values[$group_label] = [];
        }

        $values[$group_label][$post_id] = $label;

        return $values;
    }

    $values[$post_id] = $label;

    return $values;
}


/**
 * Bootstrap Polylang + ACF location compatibility.
 */
function wp_theme_acf_polylang_location_fix_init(): void {
    if (! function_exists('pll_languages_list')) {
        return;
    }

    if (! class_exists('ACF') && ! function_exists('acf_get_field_groups')) {
        return;
    }

    add_action('current_screen', 'wp_theme_acf_location_ignore_pll_lang');
    add_action('admin_init', 'wp_theme_acf_location_ignore_pll_lang');
    add_filter('acf/location/rule_values', 'wp_theme_acf_location_preserve_selected_value', 20, 2);
}


add_action('init', 'wp_theme_acf_polylang_location_fix_init', 20);
