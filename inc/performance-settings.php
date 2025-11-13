<?php
/**
 * Performance optimizations implementation.
 *
 * This file contains the actual performance optimizations that are applied
 * based on the settings configured in theme-features.php
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Apply performance optimizations based on settings.
 */
function wp_theme_apply_performance_optimizations(): void {
    $settings = wp_theme_get_settings();

    if (isset($settings['performance_remove_emojis']) && $settings['performance_remove_emojis']) {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }

    if (isset($settings['performance_disable_embeds']) && $settings['performance_disable_embeds']) {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        remove_action('rest_api_init', 'wp_oembed_register_route');
        remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
    }

    if (isset($settings['performance_optimize_queries']) && $settings['performance_optimize_queries']) {
        // Remove unnecessary queries - basic cleanup
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
    }

    if (isset($settings['performance_cleanup_head']) && $settings['performance_cleanup_head']) {
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
    }

    if (isset($settings['performance_disable_wp_cron']) && $settings['performance_disable_wp_cron'] && !defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }

    if (isset($settings['performance_limit_revisions']) && $settings['performance_limit_revisions']) {
        $limit = $settings['performance_revisions_limit'] ?? 3;

        // Set constant for backward compatibility
        if (!defined('WP_POST_REVISIONS')) {
            define('WP_POST_REVISIONS', $limit);
        }

        // Use filter for more reliable control over all post types
        add_filter('wp_revisions_to_keep', fn($_num, $_post) => $limit, 10, 2);
    }

}


// Hook into theme settings system
add_action('wp_theme_apply_settings', 'wp_theme_apply_performance_optimizations');

