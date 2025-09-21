<?php
/**
 * Search settings.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Stop query in case of empty search (controlled via Theme Settings).
 */
if (wp_theme_settings()['search_empty_handling']) {
    add_filter(
        'posts_search',
        function ( $search, WP_Query $q ) {
            if (! is_admin() && empty( $search ) && $q->is_search() && $q->is_main_query()) {
                $search .= ' AND 0=1 ';
            }

            return $search;
        },
        10,
        2
    );
}

/**
 * Filter search results to show only specific post types (controlled via Theme Settings).
 */
if (wp_theme_settings()['search_post_types']) {
    add_filter(
        'pre_get_posts',
        function ( $query ) {
            if ($query->is_search() && !is_admin() && $query->is_main_query()) {
                $opts = wp_theme_get_settings();
                if (!empty($opts['search_post_types_list'])) {
                    $query->set('post_type', $opts['search_post_types_list']);
                }
            }

            return $query;
        }
    );
}


/**
 * Exclude specific posts/pages from search (controlled via Theme Settings).
 */
if (wp_theme_settings()['search_exclude_ids']) {
    add_filter(
        'pre_get_posts',
        function ( $query ) {
            if ($query->is_search() && !is_admin() && $query->is_main_query()) {
                $opts = wp_theme_get_settings();
                if (!empty($opts['search_exclude_ids_list'])) {
                    $query->set('post__not_in', $opts['search_exclude_ids_list']);
                }
            }

            return $query;
        }
    );
}

/**
 * Apply search settings to REST API queries.
 */
// Temporarily disabled to debug REST API issues
/*
if (wp_theme_settings()['search_empty_handling'] || wp_theme_settings()['search_post_types'] || wp_theme_settings()['search_exclude_ids']) {
    add_filter(
        'rest_post_query',
        fn($args, $request) => wp_theme_apply_search_settings_to_rest_query($args, $request),
        10,
        2
    );

    add_filter(
        'rest_page_query',
        fn($args, $request) => wp_theme_apply_search_settings_to_rest_query($args, $request),
        10,
        2
    );
}
*/

/**
 * Apply search settings to all custom post types REST API queries.
 */
// Temporarily disabled to debug REST API issues
/*
add_action('rest_api_init', function(): void {
    if (wp_theme_settings()['search_empty_handling'] || wp_theme_settings()['search_post_types'] || wp_theme_settings()['search_exclude_ids']) {
        $post_types = get_post_types(['public' => true, 'show_in_rest' => true], 'names');

        foreach ($post_types as $post_type) {
            add_filter("rest_{$post_type}_query", 'wp_theme_apply_search_settings_to_rest_query', 10, 2);
        }
    }
});
*/


/**
 * Apply theme search settings to REST API query arguments.
 *
 * @param array $args Query arguments.
 * @param WP_REST_Request $request REST request object.
 * @return array Modified query arguments.
 */
function wp_theme_apply_search_settings_to_rest_query($args, $request) {
    // Check if this is a search request
    $search = $request->get_param('search');

    // Only apply search settings if there's actually a search parameter
    if (!$request->has_param('search')) {
        return $args;
    }

    // Handle empty search - check for empty string or whitespace only
    if (wp_theme_settings()['search_empty_handling'] && (empty($search) || trim((string) $search) === '')) {
        $args['post__in'] = [0]; // Return no results for empty search
        return $args;
    }

    if (empty($search) || trim((string) $search) === '') {
        return $args;
    }

    $opts = wp_theme_get_settings();

    // Apply post type restrictions
    if (wp_theme_settings()['search_post_types'] && !empty($opts['search_post_types_list'])) {
        $args['post_type'] = $opts['search_post_types_list'];
    }

    // Apply post exclusion
    if (wp_theme_settings()['search_exclude_ids'] && !empty($opts['search_exclude_ids_list'])) {
        $exclude_ids = $opts['search_exclude_ids_list'];

        // Merge with existing exclusions if any
        if (isset($args['post__not_in']) && is_array($args['post__not_in'])) {
            $args['post__not_in'] = array_merge($args['post__not_in'], $exclude_ids);
        } else {
            $args['post__not_in'] = $exclude_ids;
        }
    }

    return $args;
}

