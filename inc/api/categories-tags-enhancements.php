<?php
/**
 * Categories and tags enhancements for REST API.
 *
 * @package wp-theme
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add enhanced categories and tags support to posts REST API.
 */
add_action('rest_api_init', 'wp_theme_register_enhanced_categories_tags_rest_fields');


/**
 * Register enhanced categories and tags for posts REST API.
 *
 * Core `categories` / `tags` must stay as term ID arrays when context=edit
 * (block editor). For other contexts, replace response values with full objects
 * so existing API consumers keep the same shape without overriding REST schema.
 */
function wp_theme_register_enhanced_categories_tags_rest_fields(): void {
    add_filter('rest_prepare_post', 'wp_theme_rest_prepare_post_taxonomy_objects', 10, 3);
}


/**
 * Replace categories/tags with enhanced objects for non-edit REST contexts.
 *
 * @param WP_REST_Response $response Response object.
 * @param WP_Post          $post    Post object.
 * @param WP_REST_Request  $request Request object.
 */
function wp_theme_rest_prepare_post_taxonomy_objects(
    WP_REST_Response $response,
    WP_Post $post,
    WP_REST_Request $request
): WP_REST_Response {
    if ($request->get_param('context') === 'edit') {
        return $response;
    }

    $data = $response->get_data();
    if (!is_array($data)) {
        return $response;
    }

    $data['categories'] = wp_theme_get_enhanced_categories(['id' => (int) $post->ID]);
    $data['tags'] = wp_theme_get_enhanced_tags(['id' => (int) $post->ID]);

    $response->set_data($data);

    return $response;
}


/**
 * Get enhanced categories data for REST API.
 *
 * @param array $object Post object.
 * @return array Enhanced categories data.
 */
function wp_theme_get_enhanced_categories(array $object): array {
    $post_id = $object['id'];
    $category_ids = wp_get_post_categories($post_id);
    $categories = [];

    foreach ($category_ids as $category_id) {
        $category = get_category($category_id);
        if ($category instanceof WP_Term) {
            $categories[] = [
                'id' => (int) $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => (int) $category->count,
            ];
        }
    }

    return $categories;
}


/**
 * Get enhanced tags data for REST API.
 *
 * @param array $object Post object.
 * @return array Enhanced tags data.
 */
function wp_theme_get_enhanced_tags(array $object): array {
    $post_id = $object['id'];
    $tag_ids = wp_get_post_tags($post_id, ['fields' => 'ids']);
    $tags = [];

    foreach ($tag_ids as $tag_id) {
        $tag = get_tag($tag_id);
        if ($tag instanceof WP_Term) {
            $tags[] = [
                'id' => (int) $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'count' => (int) $tag->count,
            ];
        }
    }

    return $tags;
}
