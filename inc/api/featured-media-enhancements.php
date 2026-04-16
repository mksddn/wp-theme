<?php
/**
 * Featured media enhancements for REST API.
 *
 * @package wp-theme
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add enhanced featured media details to posts REST API.
 */
add_action('rest_api_init', 'wp_theme_register_enhanced_featured_media_rest_field');
add_filter('rest_prepare_post', 'wp_theme_prepare_legacy_featured_media_response', 20, 3);


/**
 * Register enhanced featured media details field for posts REST API.
 */
function wp_theme_register_enhanced_featured_media_rest_field(): void {
    register_rest_field('post', 'featured_media_data', [
        'get_callback' => 'wp_theme_get_enhanced_featured_media',
        'schema' => [
            'description' => 'Enhanced featured media object with full image data',
            'type' => 'object',
            'context' => ['view', 'edit'],
            'properties' => [
                'id' => [
                    'description' => 'Image ID',
                    'type' => 'integer',
                ],
                'url' => [
                    'description' => 'Image URL',
                    'type' => 'string',
                    'format' => 'uri',
                ],
                'width' => [
                    'description' => 'Image width',
                    'type' => 'integer',
                ],
                'height' => [
                    'description' => 'Image height',
                    'type' => 'integer',
                ],
                'alt' => [
                    'description' => 'Image alt text',
                    'type' => 'string',
                ],
                'sizes' => [
                    'description' => 'Available image sizes',
                    'type' => 'object',
                ],
            ],
        ],
    ]);
}


/**
 * Get enhanced featured media data for REST API.
 *
 * @param array $object Post object.
 * @return array|null Enhanced featured media data or null.
 */
function wp_theme_get_enhanced_featured_media(array $object): ?array {
    $post_id = $object['id'];

    if (!has_post_thumbnail($post_id)) {
        return null;
    }

    $thumbnail_id = get_post_thumbnail_id($post_id);
    $thumbnail = wp_get_attachment_image_src($thumbnail_id, 'medium');

    if (!$thumbnail) {
        return null;
    }

    return [
        'id' => (int) $thumbnail_id,
        'url' => $thumbnail[0],
        'width' => $thumbnail[1],
        'height' => $thumbnail[2],
        'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
        'sizes' => [
            'thumbnail' => wp_get_attachment_image_src($thumbnail_id, 'thumbnail'),
            'medium' => wp_get_attachment_image_src($thumbnail_id, 'medium'),
            'large' => wp_get_attachment_image_src($thumbnail_id, 'large'),
            'full' => wp_get_attachment_image_src($thumbnail_id, 'full'),
        ],
    ];
}


/**
 * Keep legacy featured_media object for public post responses.
 *
 * @param WP_REST_Response $response REST response.
 * @param WP_Post          $_post    Post object.
 * @param WP_REST_Request  $request  REST request.
 */
function wp_theme_prepare_legacy_featured_media_response(
    WP_REST_Response $response,
    WP_Post $_post,
    WP_REST_Request $request
): WP_REST_Response {
    if ($request->get_method() !== 'GET') {
        return $response;
    }

    if ($request->get_param('context') === 'edit') {
        return $response;
    }

    $data = $response->get_data();

    if (!is_array($data) || !array_key_exists('featured_media', $data)) {
        return $response;
    }

    $featured_media_id = (int) $data['featured_media'];

    if ($featured_media_id <= 0) {
        $data['featured_media_id'] = 0;
        $data['featured_media'] = null;
        $response->set_data($data);
        return $response;
    }

    $enhanced_featured_media = wp_theme_get_enhanced_featured_media([
        'id' => (int) $data['id'],
    ]);

    if ($enhanced_featured_media === null) {
        return $response;
    }

    $data['featured_media_id'] = $featured_media_id;
    $data['featured_media'] = $enhanced_featured_media;
    $response->set_data($data);

    return $response;
}
