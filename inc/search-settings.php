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
add_filter(
    'pre_get_posts',
    function ( $query ) {
        if ($query->is_search() && !is_admin() && $query->is_main_query()) {
            $exclude_ids = wp_theme_get_excluded_post_ids_from_settings();
            if ($exclude_ids !== []) {
                $query->set('post__not_in', $exclude_ids);
            }
        }

        return $query;
    }
);

/**
 * Apply search settings to REST API queries.
 */
if (wp_theme_settings()['search_empty_handling'] || wp_theme_settings()['search_post_types'] || !empty(wp_theme_settings()['search_exclude_ids_list']) || !empty(wp_theme_settings()['search_exclude_slugs_list'])) {
    add_filter(
        'rest_post_query',
        fn($args, $request): array => wp_theme_apply_search_settings_to_rest_query($args, $request),
        10,
        2
    );

    add_filter(
        'rest_page_query',
        fn($args, $request): array => wp_theme_apply_search_settings_to_rest_query($args, $request),
        10,
        2
    );

    // Apply to the unified search endpoint /wp/v2/search
    add_filter(
        'rest_search_query',
        fn($args, $request): array => wp_theme_apply_search_settings_to_rest_query($args, $request),
        10,
        2
    );
}

/**
 * Apply search settings to all custom post types REST API queries.
 */
add_action('rest_api_init', function(): void {
    if (wp_theme_settings()['search_empty_handling'] || wp_theme_settings()['search_post_types'] || !empty(wp_theme_settings()['search_exclude_ids_list']) || !empty(wp_theme_settings()['search_exclude_slugs_list'])) {
        $post_types = get_post_types(['public' => true, 'show_in_rest' => true], 'names');

        foreach ($post_types as $post_type) {
            add_filter("rest_{$post_type}_query", 'wp_theme_apply_search_settings_to_rest_query', 10, 2);
        }
    }
});


/**
 * Apply theme search settings to REST API query arguments.
 *
 * @param array $args Query arguments.
 * @param WP_REST_Request $request REST request object.
 * @return array Modified query arguments.
 */
function wp_theme_apply_search_settings_to_rest_query(array $args, $request): array {
    // Only apply when explicit search param is present
    if (!$request->has_param('search')) {
        return $args;
    }

    $search = $request->get_param('search');
    $settings = wp_theme_get_settings();

    // Handle empty search by returning no results
    if (wp_theme_settings()['search_empty_handling'] && (empty($search) || trim((string) $search) === '')) {
        $args['post__in'] = [0];
        return $args;
    }

    if (empty($search) || trim((string) $search) === '') {
        return $args;
    }

    // Restrict post types if configured
    if (wp_theme_settings()['search_post_types'] && !empty($settings['search_post_types_list'])) {
        $args['post_type'] = $settings['search_post_types_list'];
    }

    // Exclude posts by IDs/slugs using a single helper
    $exclude_ids = wp_theme_get_excluded_post_ids_from_settings();
    if ($exclude_ids !== []) {
        if (isset($args['post__not_in']) && is_array($args['post__not_in'])) {
            $args['post__not_in'] = array_merge($args['post__not_in'], $exclude_ids);
        } else {
            $args['post__not_in'] = $exclude_ids;
        }
    }

    return $args;
}


/**
 * Helper: collect excluded post IDs from settings (IDs + slugs).
 */
function wp_theme_get_excluded_post_ids_from_settings(): array {
    $opts = wp_theme_get_settings();
    $exclude_ids = [];

    if (!empty($opts['search_exclude_ids_list'])) {
        $exclude_ids = array_merge($exclude_ids, (array) $opts['search_exclude_ids_list']);
    }

    if (!empty($opts['search_exclude_slugs_list'])) {
        $slugs = (array) $opts['search_exclude_slugs_list'];
        $slugs = array_values(array_filter(array_map('sanitize_title', $slugs)));
        if ($slugs !== []) {
            $posts = get_posts([
                'name' => '',
                'post_type' => 'any',
                'post_status' => 'any',
                'fields' => 'ids',
                'numberposts' => -1,
                'post_name__in' => $slugs,
                'suppress_filters' => true,
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);
            if (is_array($posts)) {
                $exclude_ids = array_merge($exclude_ids, $posts);
            }
        }
    }

    $exclude_ids = array_values(array_unique(array_map('intval', $exclude_ids)));
    return array_filter($exclude_ids, fn(int $id): bool => $id > 0);
}


/**
 * Fallback: filter unified search endpoint response to remove excluded IDs.
 */
add_filter( 'rest_post_dispatch', function( $result, $server, $request ) {
    // Apply only to WP_REST_Response and only for the unified search endpoint
    if (! ( $result instanceof WP_REST_Response )) {
        return $result;
    }

    $route = (string) $request->get_route();
    if (!str_starts_with($route, '/wp/v2/search')) {
        return $result;
    }

    $data = $result->get_data();
    if (! is_array( $data )) {
        return $result;
    }

    // Apply current exclusion logic
    $excluded = wp_theme_get_excluded_post_ids_from_settings();
    $filtered = [];

    foreach ($data as $item) {
        if (! is_array( $item ) || ! isset( $item['id'] )) {
            $filtered[] = $item;
            continue;
        }

        $id = (int) $item['id'];

        // Skip excluded IDs configured in settings
        if (in_array( $id, $excluded, true )) {
            continue;
        }

        // Enrich response fields with additional data
        $post = get_post( $id );
        if ($post) {
            $item['slug'] = $post->post_name;

            if ('post' === $post->post_type) {
                $terms = get_the_terms( $post->ID, 'category' );
                if ($terms && ! is_wp_error( $terms )) {
                    $item['categories'] = array_map( fn($t): array => [
                        'id'   => (int) $t->term_id,
                        'name' => $t->name,
                        'slug' => $t->slug,
                    ], $terms );
                } else {
                    $item['categories'] = [];
                }
            } else {
                $item['categories'] = [];
            }

            if (function_exists( 'pll_get_post_language' )) {
                $item['lang'] = pll_get_post_language( $post->ID ); // напр. "en"
            } else {
                $item['lang'] = null;
            }
        }

        // Decode HTML entities in search results
        if (isset($item['title'])) {
            if (is_string($item['title'])) {
                $item['title'] = html_entity_decode($item['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (is_array($item['title']) && isset($item['title']['rendered'])) {
                $item['title']['rendered'] = html_entity_decode((string) $item['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (isset($item['excerpt'])) {
            if (is_string($item['excerpt'])) {
                $item['excerpt'] = html_entity_decode($item['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (is_array($item['excerpt']) && isset($item['excerpt']['rendered'])) {
                $item['excerpt']['rendered'] = html_entity_decode((string) $item['excerpt']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (isset($item['content'])) {
            if (is_string($item['content'])) {
                $item['content'] = html_entity_decode($item['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (is_array($item['content']) && isset($item['content']['rendered'])) {
                $item['content']['rendered'] = html_entity_decode((string) $item['content']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $filtered[] = $item;
    }

    $result->set_data( array_values( $filtered ) );
    return $result;
}, 10, 3 );

