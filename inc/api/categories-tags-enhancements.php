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
 * Register enhanced categories and tags fields for posts REST API.
 */
function wp_theme_register_enhanced_categories_tags_rest_fields(): void {
    // Enhanced categories field
    register_rest_field('post', 'categories', [
        'get_callback' => 'wp_theme_get_enhanced_categories',
        'schema' => [
            'description' => 'Enhanced categories with full data',
            'type' => 'array',
            'context' => ['view', 'edit'],
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'description' => 'Category ID',
                        'type' => 'integer',
                    ],
                    'name' => [
                        'description' => 'Category name',
                        'type' => 'string',
                    ],
                    'slug' => [
                        'description' => 'Category slug',
                        'type' => 'string',
                    ],
                    'description' => [
                        'description' => 'Category description',
                        'type' => 'string',
                    ],
                    'count' => [
                        'description' => 'Number of posts in category',
                        'type' => 'integer',
                    ],
                ],
            ],
        ],
    ]);

    // Enhanced tags field
    register_rest_field('post', 'tags', [
        'get_callback' => 'wp_theme_get_enhanced_tags',
        'schema' => [
            'description' => 'Enhanced tags with full data',
            'type' => 'array',
            'context' => ['view', 'edit'],
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'description' => 'Tag ID',
                        'type' => 'integer',
                    ],
                    'name' => [
                        'description' => 'Tag name',
                        'type' => 'string',
                    ],
                    'slug' => [
                        'description' => 'Tag slug',
                        'type' => 'string',
                    ],
                    'description' => [
                        'description' => 'Tag description',
                        'type' => 'string',
                    ],
                    'count' => [
                        'description' => 'Number of posts with this tag',
                        'type' => 'integer',
                    ],
                ],
            ],
        ],
    ]);
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
        if ($category && !is_wp_error($category)) {
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
        if ($tag && !is_wp_error($tag)) {
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
