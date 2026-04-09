<?php
/**
 * Disable comments for theme.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


function wp_theme_disable_comments_admin_init(): void {
    global $pagenow;

    if ('edit-comments.php' === $pagenow || 'options-discussion.php' === $pagenow) {
        wp_safe_redirect(admin_url());
        exit;
    }

    remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

    foreach (get_post_types() as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }
}


add_action('admin_init', 'wp_theme_disable_comments_admin_init');


/**
 * Strip comment totals from the At a Glance widget (wp_dashboard_right_now uses wp_count_comments).
 */
function wp_theme_disable_comments_bind_dashboard_count_filter(): void {
    add_filter('wp_count_comments', 'wp_theme_disable_comments_zero_site_count', 10, 2);
}


add_action('load-index.php', 'wp_theme_disable_comments_bind_dashboard_count_filter');


/**
 * @param array|stdClass $count   Default empty array from filter; or prior value if chained.
 * @param int            $post_id 0 = whole site (dashboard glance).
 * @return array|stdClass
 */
function wp_theme_disable_comments_zero_site_count($count, $post_id) {
    if (0 !== (int) $post_id) {
        return $count;
    }

    return (object) [
        'approved'         => 0,
        'moderated'        => 0,
        'spam'             => 0,
        'trash'            => 0,
        'post-trashed'     => 0,
        'total_comments'   => 0,
        'all'              => 0,
    ];
}


add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);
add_filter('comments_array', '__return_empty_array', 10, 2);


function wp_theme_disable_comments_admin_menu(): void {
    remove_menu_page('edit-comments.php');
    remove_submenu_page('options-general.php', 'options-discussion.php');
}


add_action('admin_menu', 'wp_theme_disable_comments_admin_menu');


function wp_theme_disable_comments_hide_admin_bar_node(): void {
    if (is_admin_bar_showing()) {
        remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
    }
}


add_action('init', 'wp_theme_disable_comments_hide_admin_bar_node');
