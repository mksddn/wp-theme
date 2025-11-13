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
function wp_theme_is_polylang_active(): bool
{
    return function_exists('pll_languages_list') && function_exists('pll_current_language');
}


/**
 * Get language from Accept-Language header.
 */
function wp_theme_get_language_from_header(): ?string
{
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
function wp_theme_switch_polylang_language(string $language_code): bool
{
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
function wp_theme_get_translated_slug(string $slug, string $target_language): ?string
{
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
 * Get translated post ID for a given post ID and language.
 *
 * @param int $post_id Original post ID.
 * @param string $target_language Target language code.
 * @return int|null Translated post ID or null if not found.
 */
function wp_theme_get_translated_post_id(int $post_id, string $target_language): ?int
{
    if (!wp_theme_is_polylang_active()) {
        return null;
    }

    // Get translated post ID using pll_get_post
    $translated_post_id = pll_get_post($post_id, $target_language);

    if (!$translated_post_id) {
        return null;
    }

    // Verify the translated post exists and is published
    $translated_post = get_post($translated_post_id);
    if (!$translated_post || $translated_post->post_status !== 'publish') {
        return null;
    }

    return $translated_post_id;
}


/**
 * Check if REST API request is from Gutenberg editor.
 *
 * @param WP_REST_Request|null $request Optional request object.
 * @return bool True if request is from editor.
 */
function wp_theme_is_editor_rest_request($request = null): bool
{
    // Check if user is logged in and can edit posts
    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        return false;
    }

    // If request object is provided, check context parameter
    if ($request instanceof WP_REST_Request) {
        $context = $request->get_param('context');
        if ($context === 'edit') {
            return true;
        }

        // Check if this is a direct ID request (editor loads posts by ID)
        $route = $request->get_route();
        if (preg_match('/^\/wp\/v2\/(posts|pages)\/(\d+)$/', (string) $route)) {
            return true;
        }
    }

    // Check if request has context=edit parameter in GET or POST
    if (isset($_GET['context']) && $_GET['context'] === 'edit') {
        return true;
    }

    // Verify nonce for POST data
    if (isset($_POST['context']) && $_POST['context'] === 'edit' && (isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wp_rest'))) {
        return true;
    }

    // Check HTTP_REFERER header if available
    if (isset($_SERVER['HTTP_REFERER'])) {
        $referer = sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER']));
        $admin_url = admin_url();
        if (str_starts_with($referer, $admin_url)) {
            return true;
        }
    }

    return false;
}


/**
 * Initialize language detection for REST API requests.
 */
function wp_theme_init_rest_language_detection(): void
{
    // Only run for REST API requests
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }

    // Only run if Polylang is active
    if (!wp_theme_is_polylang_active()) {
        return;
    }

    // Skip language switching for editor requests
    if (wp_theme_is_editor_rest_request()) {
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
 * @param WP_Post $_post
 * @param WP_REST_Request $_request
 * @return WP_REST_Response
 */
function wp_theme_add_language_to_rest_response($response, $_post, $_request)
{
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
function wp_theme_handle_empty_slug_results_v3($response, $post, $request)
{
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
function wp_theme_handle_empty_slug_results_v2($response, $request)
{
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
function wp_theme_handle_empty_slug_results($response, $request)
{
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
function wp_theme_handle_translated_slug_request($result, $server, $request)
{
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


/**
 * Switch language for REST API responses based on Accept-Language header.
 */
function wp_theme_switch_language_for_rest_response($response, $post, $_request)
{
    // Only handle if Polylang is active
    if (!wp_theme_is_polylang_active()) {
        return $response;
    }

    // Skip language switching for editor requests
    if (wp_theme_is_editor_rest_request($_request)) {
        return $response;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();
    if (!$detected_language) {
        return $response;
    }

    // Switch language
    wp_theme_switch_polylang_language($detected_language);

    // Get translated post ID
    $translated_post_id = wp_theme_get_translated_post_id($post->ID, $detected_language);

    if ($translated_post_id && $translated_post_id !== $post->ID) {
        // Get the translated post
        $translated_post = get_post($translated_post_id);

        if ($translated_post) {
            // Create new response with translated post data
            $new_response = new WP_REST_Response();

            // Get the original response data
            $data = $response->get_data();

            // Update with translated post data
            $data['title']['rendered'] = $translated_post->post_title;
            $data['content']['rendered'] = apply_filters('the_content', $translated_post->post_content);

            $new_response->set_data($data);
            $new_response->set_status($response->get_status());

            // Copy headers
            foreach ($response->get_headers() as $key => $value) {
                $new_response->header($key, $value);
            }

            return $new_response;
        }
    }

    return $response;
}


// Apply language switching to posts and pages
add_filter('rest_prepare_post', 'wp_theme_switch_language_for_rest_response', 5, 3);
add_filter('rest_prepare_page', 'wp_theme_switch_language_for_rest_response', 5, 3);

// Handle empty results for slug-based requests - removed due to recursion issues

// Handle empty results for slug-based requests - removed due to recursion issues

// Handle translated slug requests - removed due to recursion issues


/**
 * Add language parameter to standard WordPress REST API endpoints.
 */
function wp_theme_add_language_to_rest_query(array $args, $_request): array
{
    if (!wp_theme_is_polylang_active()) {
        return $args;
    }

    // Skip language switching for editor requests
    if (wp_theme_is_editor_rest_request($_request)) {
        return $args;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();

    if ($detected_language) {
        // Switch language
        wp_theme_switch_polylang_language($detected_language);

        // Add language filter to query
        $args['lang'] = $detected_language;

        // Handle slug-based requests
        if (isset($args['post_name__in']) && !empty($args['post_name__in'])) {
            $original_slug = $args['post_name__in'][0];
            $translated_slug = wp_theme_get_translated_slug($original_slug, $detected_language);
            if ($translated_slug && $translated_slug !== $original_slug) {
                $args['post_name__in'] = [$translated_slug];
            }
        }

        // Handle ID-based requests
        if (isset($args['p']) && !empty($args['p'])) {
            $original_id = (int) $args['p'];
            $translated_id = wp_theme_get_translated_post_id($original_id, $detected_language);
            if ($translated_id && $translated_id !== $original_id) {
                $args['p'] = $translated_id;
            }
        }

        // Handle post__in requests (multiple IDs)
        if (isset($args['post__in']) && !empty($args['post__in'])) {
            $translated_ids = [];
            foreach ($args['post__in'] as $original_id) {
                $translated_id = wp_theme_get_translated_post_id((int) $original_id, $detected_language);
                $translated_ids[] = $translated_id ?: $original_id;
            }

            $args['post__in'] = $translated_ids;
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
function wp_theme_add_language_to_cpt_rest_query(array $args, $_request): array
{
    if (!wp_theme_is_polylang_active()) {
        return $args;
    }

    // Skip language switching for editor requests
    if (wp_theme_is_editor_rest_request($_request)) {
        return $args;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();

    if ($detected_language) {
        // Switch language
        wp_theme_switch_polylang_language($detected_language);

        // Add language filter to query
        $args['lang'] = $detected_language;

        // Handle slug-based requests
        if (isset($args['post_name__in']) && !empty($args['post_name__in'])) {
            $original_slug = $args['post_name__in'][0];
            $translated_slug = wp_theme_get_translated_slug($original_slug, $detected_language);
            if ($translated_slug && $translated_slug !== $original_slug) {
                $args['post_name__in'] = [$translated_slug];
            }
        }

        // Handle ID-based requests
        if (isset($args['p']) && !empty($args['p'])) {
            $original_id = (int) $args['p'];
            $translated_id = wp_theme_get_translated_post_id($original_id, $detected_language);
            if ($translated_id && $translated_id !== $original_id) {
                $args['p'] = $translated_id;
            }
        }

        // Handle post__in requests (multiple IDs)
        if (isset($args['post__in']) && !empty($args['post__in'])) {
            $translated_ids = [];
            foreach ($args['post__in'] as $original_id) {
                $translated_id = wp_theme_get_translated_post_id((int) $original_id, $detected_language);
                $translated_ids[] = $translated_id ?: $original_id;
            }

            $args['post__in'] = $translated_ids;
        }
    }

    return $args;
}


// Apply language filter to all custom post types
add_filter('rest_query_vars', function ($vars) {
    $vars[] = 'lang';
    return $vars;
});

// Hook into all post type queries
add_action('rest_api_init', function (): void {
    $post_types = get_post_types(['public' => true, 'show_in_rest' => true], 'names');

    foreach ($post_types as $post_type) {
        add_filter("rest_{$post_type}_query", 'wp_theme_add_language_to_cpt_rest_query', 10, 2);
    }
});


/**
 * Handle language switching for direct ID requests by modifying the route.
 */
function wp_theme_handle_id_route_modification($result, $server, $request)
{
    // Only handle GET requests
    if ($request->get_method() !== 'GET') {
        return $result;
    }

    // Check if this is a direct ID request (e.g., /wp/v2/posts/123)
    $route = $request->get_route();
    if (!preg_match('/^\/wp\/v2\/([^\/]+)\/(\d+)$/', (string) $route, $matches)) {
        return $result;
    }

    $post_type = $matches[1];
    $post_id = (int) $matches[2];

    // Only handle if Polylang is active
    if (!wp_theme_is_polylang_active()) {
        return $result;
    }

    // Skip language switching for editor requests
    if (wp_theme_is_editor_rest_request($request)) {
        return $result;
    }

    // Get language from Accept-Language header
    $detected_language = wp_theme_get_language_from_header();
    if (!$detected_language) {
        return $result;
    }

    // Switch language
    wp_theme_switch_polylang_language($detected_language);

    // Try to get translated post ID
    $translated_post_id = wp_theme_get_translated_post_id($post_id, $detected_language);

    if ($translated_post_id && $translated_post_id !== $post_id) {
        // Create new request with translated post ID
        $new_route = "/wp/v2/{$post_type}/{$translated_post_id}";
        $new_request = new WP_REST_Request('GET', $new_route);

        // Copy all parameters
        foreach ($request->get_params() as $key => $value) {
            $new_request->set_param($key, $value);
        }

        // Execute the new request
        $new_result = rest_do_request($new_request);

        // If we found results, return them
        if (!is_wp_error($new_result) && !empty($new_result->get_data())) {
            return $new_result;
        }
    }

    return $result;
}


// Hook into REST API requests to handle direct ID requests
add_filter('rest_request_before_callbacks', 'wp_theme_handle_id_route_modification', 5, 3);
