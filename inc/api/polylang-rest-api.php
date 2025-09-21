<?php
/**
 * Polylang REST API language detection and switching.
 *
 * @package wp-theme
 */

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Check if Polylang plugin is active.
 */
function wp_theme_is_polylang_active(): bool {
    return function_exists('pll_languages_list') && function_exists('pll_current_language');
}


/**
 * Get language from Accept-Language header.
 */
function wp_theme_get_language_from_header(): ?string {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return null;
    }

    $accept_language = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']));

    // Parse Accept-Language header (e.g., "en-US,en;q=0.9,ru;q=0.8")
    $languages = [];
    $parts = explode(',', (string) $accept_language);

    foreach ($parts as $part) {
        $part = trim($part);
        if (str_contains($part, ';')) {
            [$lang, $quality] = explode(';', $part, 2);
            $quality = (float) str_replace('q=', '', $quality);
        } else {
            $lang = $part;
            $quality = 1.0;
        }

        // Extract language code (e.g., "en" from "en-US")
        $lang_code = strtolower(substr(trim($lang), 0, 2));
        $languages[$lang_code] = $quality;
    }

    // Sort by quality (highest first)
    arsort($languages);

    // Get available Polylang languages
    if (!wp_theme_is_polylang_active()) {
        return null;
    }

    $available_languages = pll_languages_list(['fields' => 'slug']);

    // Find first matching language
    foreach (array_keys($languages) as $lang_code) {
        if (in_array($lang_code, $available_languages, true)) {
            return $lang_code;
        }
    }

    return null;
}


/**
 * Switch Polylang language for REST API requests.
 *
 * @param string $language_code Language code to switch to.
 */
function wp_theme_switch_polylang_language(string $language_code): bool {
    if (!wp_theme_is_polylang_active()) {
        return false;
    }

    $available_languages = pll_languages_list(['fields' => 'slug']);

    if (!in_array($language_code, $available_languages, true)) {
        return false;
    }

    // Set language using WordPress action (Polylang 3.x approach)
    do_action('pll_language_defined', $language_code);

    // Set global language variable
    global $polylang;
    if (isset($polylang)) {
        $polylang->curlang = $polylang->model->get_language($language_code);
    }

    return true;
}


/**
 * Get translated slug for a given slug and language.
 *
 * @param string $slug Original slug.
 * @param string $target_language Target language code.
 * @return string|null Translated slug or null if not found.
 */
function wp_theme_get_translated_slug(string $slug, string $target_language): ?string {
    if (!wp_theme_is_polylang_active()) {
        return null;
    }

    // First, find the post by slug in any language
    $posts = get_posts([
        'name' => $slug,
        'post_type' => ['post', 'page'],
        'posts_per_page' => 1,
        'post_status' => 'publish',
        'suppress_filters' => false,
    ]);

    if (empty($posts)) {
        return null;
    }

    $post = $posts[0];

    // Get translated post ID using pll_get_post
    $translated_post_id = pll_get_post($post->ID, $target_language);

    if (!$translated_post_id) {
        return null;
    }

    // Get the translated post
    $translated_post = get_post($translated_post_id);

    if (!$translated_post) {
        return null;
    }

    return $translated_post->post_name;
}


/**
 * Initialize language detection for REST API requests.
 */
function wp_theme_init_rest_language_detection(): void {
    // Only run for REST API requests
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }

    // Only run if Polylang is active
    if (!wp_theme_is_polylang_active()) {
        return;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();

    if ($detected_language) {
        wp_theme_switch_polylang_language($detected_language);
    }
}


// Hook into REST API initialization
add_action('rest_api_init', 'wp_theme_init_rest_language_detection', 1);


/**
 * Add language information to REST API responses.
 *
 * @param WP_REST_Response $response
 * @param WP_Post $post
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function wp_theme_add_language_to_rest_response($response, $post, $request) {
    if (!wp_theme_is_polylang_active()) {
        return $response;
    }

    $current_language = pll_current_language();
    $response->data['language'] = $current_language;

    return $response;
}


/**
 * Handle empty results for slug-based requests using a different approach.
 */
function wp_theme_handle_empty_slug_results_v3($response, $post, $request) {
    // Only handle GET requests for pages/posts
    if ($request->get_method() !== 'GET') {
        return $response;
    }

    $route = $request->get_route();
    if (!preg_match('/^\/wp\/v2\/(pages|posts)(?:\/(\d+))?$/', (string) $route, $matches)) {
        return $response;
    }

    $post_type = $matches[1];
    $post_id = $matches[2] ?? null;

    // Only handle slug-based requests (no post ID)
    if ($post_id) {
        return $response;
    }

    $slug = $request->get_param('slug');
    if (!$slug) {
        return $response;
    }

    // Check if response is empty
    $data = $response->get_data();
    if (!empty($data)) {
        return $response;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();
    if (!$detected_language) {
        return $response;
    }

    // Switch language
    wp_theme_switch_polylang_language($detected_language);

    // Try to find translated slug
    $translated_slug = wp_theme_get_translated_slug($slug, $detected_language);
    if ($translated_slug && $translated_slug !== $slug) {
        // Create new request with translated slug
        $new_request = new WP_REST_Request('GET', $route);
        $new_request->set_param('slug', $translated_slug);

        // Copy other parameters
        foreach ($request->get_params() as $key => $value) {
            if ($key !== 'slug') {
                $new_request->set_param($key, $value);
            }
        }

        // Execute the new request using WP_Query directly
        $query_args = [
            'post_type' => $post_type,
            'name' => $translated_slug,
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ];

        $query = new WP_Query($query_args);

        if ($query->have_posts()) {
            $post = $query->posts[0];

            // Create response manually
            $new_response = new WP_REST_Response();
            $new_response->set_data([$post]);
            $new_response->set_status(200);

            return $new_response;
        }
    }

    return $response;
}


/**
 * Handle empty results for slug-based requests using a different approach.
 */
function wp_theme_handle_empty_slug_results_v2($response, $request) {
    // Only handle GET requests for pages/posts
    if ($request->get_method() !== 'GET') {
        return $response;
    }

    $route = $request->get_route();
    if (!preg_match('/^\/wp\/v2\/(pages|posts)(?:\/(\d+))?$/', (string) $route, $matches)) {
        return $response;
    }

    $post_type = $matches[1];
    $post_id = $matches[2] ?? null;

    // Only handle slug-based requests (no post ID)
    if ($post_id) {
        return $response;
    }

    $slug = $request->get_param('slug');
    if (!$slug) {
        return $response;
    }

    // Check if response is empty
    $data = $response->get_data();
    if (!empty($data)) {
        return $response;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();
    if (!$detected_language) {
        return $response;
    }

    // Switch language
    wp_theme_switch_polylang_language($detected_language);

    // Try to find translated slug
    $translated_slug = wp_theme_get_translated_slug($slug, $detected_language);
    if ($translated_slug && $translated_slug !== $slug) {
        // Create new request with translated slug
        $new_request = new WP_REST_Request('GET', $route);
        $new_request->set_param('slug', $translated_slug);

        // Copy other parameters
        foreach ($request->get_params() as $key => $value) {
            if ($key !== 'slug') {
                $new_request->set_param($key, $value);
            }
        }

        // Execute the new request using WP_Query directly
        $query_args = [
            'post_type' => $post_type,
            'name' => $translated_slug,
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ];

        $query = new WP_Query($query_args);

        if ($query->have_posts()) {
            $post = $query->posts[0];

            // Create response manually
            $new_response = new WP_REST_Response();
            $new_response->set_data([$post]);
            $new_response->set_status(200);

            return $new_response;
        }
    }

    return $response;
}


/**
 * Handle empty results for slug-based requests.
 */
function wp_theme_handle_empty_slug_results($response, $request) {
    // Only handle GET requests for pages/posts
    if ($request->get_method() !== 'GET') {
        return $response;
    }

    $route = $request->get_route();
    if (!preg_match('/^\/wp\/v2\/(pages|posts)(?:\/(\d+))?$/', (string) $route, $matches)) {
        return $response;
    }

    $post_id = $matches[2] ?? null;

    // Only handle slug-based requests (no post ID)
    if ($post_id) {
        return $response;
    }

    $slug = $request->get_param('slug');
    if (!$slug) {
        return $response;
    }

    // Check if response is empty
    $data = $response->get_data();
    if (!empty($data)) {
        return $response;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();
    if (!$detected_language) {
        return $response;
    }

    // Switch language
    wp_theme_switch_polylang_language($detected_language);

    // Try to find translated slug
    $translated_slug = wp_theme_get_translated_slug($slug, $detected_language);
    if ($translated_slug && $translated_slug !== $slug) {
        // Create new request with translated slug
        $new_request = new WP_REST_Request('GET', $route);
        $new_request->set_param('slug', $translated_slug);

        // Copy other parameters
        foreach ($request->get_params() as $key => $value) {
            if ($key !== 'slug') {
                $new_request->set_param($key, $value);
            }
        }

        // Execute the new request
        $new_result = rest_do_request($new_request);

        // If we found results, return them
        if (!is_wp_error($new_result) && !empty($new_result->get_data())) {
            return $new_result;
        }
    }

    return $response;
}


/**
 * Handle slug-based requests for translated content.
 */
function wp_theme_handle_translated_slug_request($result, $server, $request) {
    // Only handle GET requests for pages/posts
    if ($request->get_method() !== 'GET') {
        return $result;
    }

    $route = $request->get_route();
    if (!preg_match('/^\/wp\/v2\/(pages|posts)(?:\/(\d+))?$/', (string) $route, $matches)) {
        return $result;
    }

    $post_id = $matches[2] ?? null;

    // Only handle slug-based requests (no post ID)
    if ($post_id) {
        return $result;
    }

    $slug = $request->get_param('slug');
    if (!$slug) {
        return $result;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();
    if (!$detected_language) {
        return $result;
    }

    // Switch language
    wp_theme_switch_polylang_language($detected_language);

    // Try to find translated slug
    $translated_slug = wp_theme_get_translated_slug($slug, $detected_language);
    if ($translated_slug && $translated_slug !== $slug) {
        // Create new request with translated slug
        $new_request = new WP_REST_Request('GET', $route);
        $new_request->set_param('slug', $translated_slug);

        // Copy other parameters
        foreach ($request->get_params() as $key => $value) {
            if ($key !== 'slug') {
                $new_request->set_param($key, $value);
            }
        }

        // Execute the new request
        $new_result = $server->dispatch($new_request);

        // If we found results, return them
        if (!is_wp_error($new_result) && !empty($new_result->get_data())) {
            return $new_result;
        }
    }

    return $result;
}


// Add language info to posts and pages
add_filter('rest_prepare_post', 'wp_theme_add_language_to_rest_response', 10, 3);
add_filter('rest_prepare_page', 'wp_theme_add_language_to_rest_response', 10, 3);

// Handle empty results for slug-based requests - removed due to recursion issues

// Handle empty results for slug-based requests - removed due to recursion issues

// Handle translated slug requests - removed due to recursion issues


/**
 * Add language parameter to standard WordPress REST API endpoints.
 */
function wp_theme_add_language_to_rest_query($args, $request) {
    if (!wp_theme_is_polylang_active()) {
        return $args;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();

    if ($detected_language) {
        // Switch language
        wp_theme_switch_polylang_language($detected_language);

        // Add language filter to query
        $args['lang'] = $detected_language;

        // If searching by slug, try to find the translated version
        if (isset($args['post_name__in']) && !empty($args['post_name__in'])) {
            $original_slug = $args['post_name__in'][0];
            $translated_slug = wp_theme_get_translated_slug($original_slug, $detected_language);
            if ($translated_slug && $translated_slug !== $original_slug) {
                $args['post_name__in'] = [$translated_slug];
            }
        }
    }

    return $args;
}


// Apply language filter to posts and pages queries
add_filter('rest_post_query', 'wp_theme_add_language_to_rest_query', 10, 2);
add_filter('rest_page_query', 'wp_theme_add_language_to_rest_query', 10, 2);


/**
 * Add language support to all custom post types REST API.
 */
function wp_theme_add_language_to_cpt_rest_query(array $args, $request): array {
    if (!wp_theme_is_polylang_active()) {
        return $args;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();

    if ($detected_language) {
        // Switch language
        wp_theme_switch_polylang_language($detected_language);

        // Add language filter to query
        $args['lang'] = $detected_language;
    }

    return $args;
}


// Apply language filter to all custom post types
add_filter('rest_query_vars', function($vars) {
    $vars[] = 'lang';
    return $vars;
});

// Hook into all post type queries
add_action('rest_api_init', function(): void {
    $post_types = get_post_types(['public' => true, 'show_in_rest' => true], 'names');

    foreach ($post_types as $post_type) {
        add_filter("rest_{$post_type}_query", 'wp_theme_add_language_to_cpt_rest_query', 10, 2);
    }
});
