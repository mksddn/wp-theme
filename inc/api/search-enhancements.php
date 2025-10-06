<?php
/**
 * Search endpoint enhancements for excerpt and featured_media support.
 *
 * @package wp-theme
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Override search endpoint to add excerpt and featured_media support.
 */
add_action('rest_api_init', function(): void {
    // Register enhanced search endpoint
    register_rest_route('wp/v2', '/search', [
        'methods' => 'GET',
        'callback' => 'wp_theme_enhanced_search_callback',
        'args' => [
            'search' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'default' => 10,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'type' => [
                'default' => 'post',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'subtype' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            '_fields' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
        'permission_callback' => '__return_true',
    ]);
});


/**
 * Enhanced search callback with excerpt and featured_media support.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function wp_theme_enhanced_search_callback($request) {
    $search_term = $request->get_param('search');
    $per_page = $request->get_param('per_page');
    $page = $request->get_param('page');
    $type = $request->get_param('type');
    $subtype = $request->get_param('subtype');
    $fields = $request->get_param('_fields');

    // Handle empty search
    if (empty($search_term) || trim((string) $search_term) === '') {
        return new WP_REST_Response([], 200);
    }

    // Build query arguments
    $args = [
        's' => $search_term,
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'suppress_filters' => false,
    ];

    // Set post type based on type and subtype
    if ($type === 'post' && $subtype) {
        $args['post_type'] = $subtype;
    } elseif ($type === 'post') {
        $args['post_type'] = 'post';
    } elseif ($type === 'page') {
        $args['post_type'] = 'page';
    } else {
        // Always search both posts and pages by default
        $args['post_type'] = ['post', 'page'];
    }

    // Apply theme search settings (commented out to allow all post types)
    // $args = wp_theme_apply_search_settings_to_rest_query($args, $request);

    // Force post_type to include both posts and pages
    $args['post_type'] = ['post', 'page'];

    // Execute search query
    $query = new WP_Query($args);

    $results = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $post_type = get_post_type($post_id);

            $result = [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'url' => get_permalink($post_id),
                'type' => $post_type === 'page' ? 'post' : 'post',
                'subtype' => $post_type,
            ];

            // Add excerpt if requested or if not specified
            if (!$fields || str_contains((string) $fields, 'excerpt')) {
                $excerpt = get_the_excerpt($post_id);
                if (empty($excerpt)) {
                    $excerpt = wp_trim_words(get_the_content($post_id), 20, '...');
                }

                $result['excerpt'] = $excerpt;
            }

            // Add featured_media if requested or if not specified
            if (!$fields || str_contains((string) $fields, 'featured_media')) {
                if (has_post_thumbnail($post_id)) {
                    $thumbnail_id = get_post_thumbnail_id($post_id);
                    $thumbnail = wp_get_attachment_image_src($thumbnail_id, 'medium');

                    if ($thumbnail) {
                        $result['featured_media'] = [
                            'id' => $thumbnail_id,
                            'url' => $thumbnail[0],
                            'width' => $thumbnail[1],
                            'height' => $thumbnail[2],
                            'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
                        ];
                    }
                } else {
                    $result['featured_media'] = null;
                }
            }

            // Add slug if requested
            if (!$fields || str_contains((string) $fields, 'slug')) {
                $result['slug'] = get_post_field('post_name', $post_id);
            }

            $results[] = $result;
        }
    }

    wp_reset_postdata();

    return new WP_REST_Response($results, 200);
}
