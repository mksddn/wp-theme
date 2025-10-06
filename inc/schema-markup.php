<?php
/**
 * Schema.org structured data markup functionality.
 *
 * Adds JSON-LD structured data to improve SEO and search engine understanding.
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Add schema markup to the head section.
 */
function wp_theme_add_schema_markup(): void {
    if (is_admin()) {
        return;
    }

    $schema_data = wp_theme_get_schema_data();

    if ($schema_data !== []) {
        echo '<script type="application/ld+json">' . wp_json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}


add_action('wp_head', 'wp_theme_add_schema_markup', 5);


/**
 * Get schema data based on current page type.
 */
function wp_theme_get_schema_data(): array {
    if (is_home() || is_front_page()) {
        return wp_theme_get_website_schema();
    }

    if (is_single()) {
        return wp_theme_get_article_schema();
    }

    if (is_page()) {
        return wp_theme_get_webpage_schema();
    }

    if (is_archive()) {
        return wp_theme_get_collection_page_schema();
    }

    return [];
}


/**
 * Get website schema for homepage.
 */
function wp_theme_get_website_schema(): array {
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
        'url' => home_url('/'),
    ];

    // Add potential action for search
    $schema['potentialAction'] = [
        '@type' => 'SearchAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => home_url('/?s={search_term_string}'),
        ],
        'query-input' => 'required name=search_term_string',
    ];

    return $schema;
}


/**
 * Get article schema for single posts.
 */
function wp_theme_get_article_schema(): array {
    global $post;

    if (! $post) {
        return [];
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => get_the_title($post),
        'description' => wp_theme_get_post_excerpt($post),
        'url' => get_permalink($post),
        'datePublished' => get_the_date('c', $post),
        'dateModified' => get_the_modified_date('c', $post),
        'author' => wp_theme_get_author_schema($post),
        'publisher' => wp_theme_get_publisher_schema(),
    ];

    // Add featured image if available
    $featured_image = get_the_post_thumbnail_url($post, 'full');
    if ($featured_image) {
        $schema['image'] = [
            '@type' => 'ImageObject',
            'url' => $featured_image,
            'width' => 1200,
            'height' => 630,
        ];
    }

    // Add categories as keywords
    $categories = get_the_category($post->ID);
    if (! empty($categories)) {
        $keywords = array_map(fn($cat) => $cat->name, $categories);
        $schema['keywords'] = implode(', ', $keywords);
    }

    return $schema;
}


/**
 * Get webpage schema for pages.
 */
function wp_theme_get_webpage_schema(): array {
    global $post;

    if (! $post) {
        return [];
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => get_the_title($post),
        'description' => wp_theme_get_post_excerpt($post),
        'url' => get_permalink($post),
        'datePublished' => get_the_date('c', $post),
        'dateModified' => get_the_modified_date('c', $post),
        'publisher' => wp_theme_get_publisher_schema(),
    ];

    // Add featured image if available
    $featured_image = get_the_post_thumbnail_url($post, 'full');
    if ($featured_image) {
        $schema['image'] = [
            '@type' => 'ImageObject',
            'url' => $featured_image,
        ];
    }

    return $schema;
}


/**
 * Get collection page schema for archive pages.
 */
function wp_theme_get_collection_page_schema(): array {
    return [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => wp_theme_get_archive_title(),
        'description' => wp_theme_get_archive_description(),
        'url' => wp_theme_get_archive_url(),
        'publisher' => wp_theme_get_publisher_schema(),
    ];
}


/**
 * Get author schema data.
 */
function wp_theme_get_author_schema($post): array {
    $author_id = $post->post_author;
    $author = get_userdata($author_id);

    if (! $author) {
        return [];
    }

    $schema = [
        '@type' => 'Person',
        'name' => $author->display_name,
        'url' => get_author_posts_url($author_id),
    ];

    // Add author description if available
    $description = get_the_author_meta('description', $author_id);
    if ($description) {
        $schema['description'] = $description;
    }

    return $schema;
}


/**
 * Get publisher schema data.
 */
function wp_theme_get_publisher_schema(): array {
    $schema = [
        '@type' => 'Organization',
        'name' => get_bloginfo('name'),
        'url' => home_url('/'),
    ];

    // Add logo if available
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
        if ($logo_url) {
            $schema['logo'] = [
                '@type' => 'ImageObject',
                'url' => $logo_url,
            ];
        }
    }

    return $schema;
}


/**
 * Get post excerpt for schema description.
 */
function wp_theme_get_post_excerpt($post): string {
    $excerpt = get_the_excerpt($post);

    if (empty($excerpt)) {
        $content = strip_tags((string) $post->post_content);
        $excerpt = wp_trim_words($content, 30, '...');
    }

    return $excerpt;
}


/**
 * Get archive title for schema.
 */
function wp_theme_get_archive_title(): string {
    if (is_category()) {
        return single_cat_title('', false);
    }

    if (is_tag()) {
        return single_tag_title('', false);
    }

    if (is_author()) {
        return get_the_author();
    }

    if (is_date()) {
        if (is_year()) {
            return get_the_date('Y');
        }

        if (is_month()) {
            return get_the_date('F Y');
        }

        if (is_day()) {
            return get_the_date();
        }
    }

    return get_the_archive_title();
}


/**
 * Get archive description for schema.
 */
function wp_theme_get_archive_description(): string {
    if (is_category()) {
        $description = category_description();
        if ($description) {
            return strip_tags((string) $description);
        }
    }

    if (is_tag()) {
        $description = tag_description();
        if ($description) {
            return strip_tags((string) $description);
        }
    }

    if (is_author()) {
        $description = get_the_author_meta('description');
        if ($description) {
            return $description;
        }
    }

    return get_bloginfo('description');
}


/**
 * Get archive URL for schema.
 */
function wp_theme_get_archive_url(): string {
    if (is_category()) {
        return get_category_link(get_queried_object_id());
    }

    if (is_tag()) {
        return get_tag_link(get_queried_object_id());
    }

    if (is_author()) {
        return get_author_posts_url(get_queried_object_id());
    }

    if (is_date()) {
        if (is_year()) {
            return get_year_link(get_query_var('year'));
        }

        if (is_month()) {
            return get_month_link(get_query_var('year'), get_query_var('monthnum'));
        }

        if (is_day()) {
            return get_day_link(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'));
        }
    }

    return home_url('/');
}
