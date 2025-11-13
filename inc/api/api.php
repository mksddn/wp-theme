<?php
/**
 * REST API settings and customization for theme.
 *
 * @package wp-theme
 */

// // Enable CORS on JSON API WordPress
// function add_cors_http_header()
// {
// header("Access-Control-Allow-Origin: *");
// }
// add_action('init', 'add_cors_http_header');


/**
 * Allow only REST API and wp-admin, redirect everything else to /wp-admin
 */
add_action('template_redirect', function (): void {
    // Allow access to admin and REST API
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    wp_redirect(admin_url());
    exit;
});


/**
 * Disable /wp/v2/users endpoint.
 */
add_filter(
    'rest_endpoints',
    function ( array $endpoints ): array {
        // Remove endpoint for getting users.
        if (isset( $endpoints['/wp/v2/users'] )) {
            unset( $endpoints['/wp/v2/users'] );
        }

        // Remove endpoint for getting single user by ID.
        if (isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] )) {
            unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
        }

        return $endpoints;
    }
);




/**
 * Custom endpoint to get Options Page data by slug.
 */
require get_template_directory() . '/inc/api/custom-route-options.php';

/**
 * Enhance search endpoint with excerpt and featured_media support.
 */
require get_template_directory() . '/inc/api/search-enhancements.php';

/**
 * Add enhanced featured_media support to posts REST API.
 */
require get_template_directory() . '/inc/api/featured-media-enhancements.php';

/**
 * Add enhanced categories and tags support to posts REST API.
 */
require get_template_directory() . '/inc/api/categories-tags-enhancements.php';


/**
 * Complete REST API disable for unauthorized users.
 */
// add_filter('rest_authentication_errors', function ($result) {
// if (!is_user_logged_in()) {
// return new WP_Error(
// 'rest_forbidden',
// __('You must be authorized to access the API.', 'text-domain'),
// ['status' => 401]
// );
// }
// return $result;
// });


/**
 * Auto-enable show_in_rest for all ACF field groups when Headless CMS is enabled.
 *
 * This functionality automatically:
 * 1. Sets show_in_rest = 1 for all new ACF field groups when Headless CMS mode is active
 * 2. Updates existing ACF field groups when Headless CMS setting is toggled
 * 3. Ensures all ACF fields are available in REST API when using WordPress as headless CMS
 */
add_filter( 'acf/register_field_group', 'wp_theme_acf_auto_show_in_rest', 10, 1 );
add_filter( 'acf/prepare_field_group_for_import', 'wp_theme_acf_auto_show_in_rest', 10, 1 );


/**
 * Automatically enables show_in_rest for ACF field groups when Headless CMS mode is active.
 *
 * @param array $field_group Field group data.
 * @return array Modified field group data.
 */
function wp_theme_acf_auto_show_in_rest( array $field_group ): array {
    // Check if Headless CMS mode is enabled
    $settings = wp_theme_get_settings();

    if (isset($settings['headless']) && $settings['headless']) {
        // Force enable show_in_rest for all field groups in headless mode
        $field_group['show_in_rest'] = 1;
    } elseif (!isset($field_group['ID'])) {
        // For new field groups (not yet saved), set show_in_rest = 1 by default
        $field_group['show_in_rest'] = 1;
    }

    return $field_group;
}


/**
 * Update existing ACF field groups when Headless CMS setting changes.
 */
add_action( 'update_option_wp_theme_settings', 'wp_theme_update_acf_show_in_rest_on_setting_change', 10, 2 );


/**
 * Fix HTML entities in REST API responses for better readability.
 */
add_filter('rest_prepare_post', 'wp_theme_decode_html_entities_in_rest_response', 20, 3);
add_filter('rest_prepare_page', 'wp_theme_decode_html_entities_in_rest_response', 20, 3);
add_filter('rest_prepare_post', 'wp_theme_decode_acf_html_entities', 25, 3);
add_filter('rest_prepare_page', 'wp_theme_decode_acf_html_entities', 25, 3);


/**
 * Decode HTML entities in REST API responses.
 *
 * @param WP_REST_Response $response
 * @param WP_Post $_post
 * @param WP_REST_Request $_request
 */
function wp_theme_decode_html_entities_in_rest_response($response, $_post, $_request): WP_REST_Response {
    if (!$response instanceof WP_REST_Response) {
        return $response;
    }

    $data = $response->get_data();

    // Decode HTML entities in title
    if (isset($data['title']['rendered'])) {
        $data['title']['rendered'] = html_entity_decode($data['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Decode HTML entities in content
    if (isset($data['content']['rendered'])) {
        $data['content']['rendered'] = html_entity_decode($data['content']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Decode HTML entities in excerpt
    if (isset($data['excerpt']['rendered'])) {
        $data['excerpt']['rendered'] = html_entity_decode($data['excerpt']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $response->set_data($data);
    return $response;
}


/**
 * Decode HTML entities and filter empty ACF fields for REST API responses.
 *
 * @param WP_REST_Response $response
 * @param WP_Post $post
 * @param WP_REST_Request $_request
 */
function wp_theme_decode_acf_html_entities($response, $post, $_request): WP_REST_Response {
    if (!$response instanceof WP_REST_Response) {
        return $response;
    }

    $data = $response->get_data();

    // Process ACF fields if they exist
    if (function_exists('get_fields') && isset($post->ID)) {
        $acf_fields = get_fields($post->ID);
        if ($acf_fields && is_array($acf_fields)) {
            $decoded_acf_fields = wp_theme_decode_html_entities_recursive($acf_fields);
            $filtered_acf_fields = wp_theme_filter_empty_acf_fields($decoded_acf_fields);
            $data['acf'] = $filtered_acf_fields;
        }
    }

    $response->set_data($data);
    return $response;
}


/**
 * Recursively decode HTML entities in arrays and strings.
 *
 * @param mixed $data Data to process
 * @return mixed Processed data
 */
function wp_theme_decode_html_entities_recursive($data) {
    if (is_string($data)) {
        return html_entity_decode($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if (is_array($data)) {
        return array_map(wp_theme_decode_html_entities_recursive(...), $data);
    }

    if (is_object($data)) {
        $object_vars = get_object_vars($data);
        foreach ($object_vars as $key => $value) {
            $data->$key = wp_theme_decode_html_entities_recursive($value);
        }

        return $data;
    }

    return $data;
}


/**
 * Filter out empty ACF fields from API response.
 * Removes fields with false values and empty strings to prevent frontend errors.
 *
 * @param array $acf_fields ACF fields array
 * @return array Filtered ACF fields
 */
function wp_theme_filter_empty_acf_fields($acf_fields) {
    if (!is_array($acf_fields)) {
        return $acf_fields;
    }

    $filtered = [];

    foreach ($acf_fields as $key => $value) {
        // Skip fields with false values (empty ACF fields)
        if ($value === false) {
            continue;
        }

        // Skip fields with empty strings
        if ($value === '') {
            continue;
        }

        // Recursively filter nested arrays/objects
        if (is_array($value) || is_object($value)) {
            $filtered_value = wp_theme_filter_empty_acf_fields($value);

            // Only include if the filtered value is not empty
            if (!empty($filtered_value)) {
                $filtered[$key] = $filtered_value;
            }
        } else {
            // Include non-false and non-empty values
            $filtered[$key] = $value;
        }
    }

    return $filtered;
}


/**
 * Updates show_in_rest for all existing ACF field groups when Headless CMS setting changes.
 *
 * @param array $old_value Previous settings.
 * @param array $new_value New settings.
 */
function wp_theme_update_acf_show_in_rest_on_setting_change( array $old_value, array $new_value ): void {
    // Check if headless setting changed
    $old_headless = isset($old_value['headless']) && (bool) $old_value['headless'];
    $new_headless = isset($new_value['headless']) && (bool) $new_value['headless'];

    if ($old_headless !== $new_headless) {
        wp_theme_update_all_acf_show_in_rest($new_headless);
    }
}


/**
 * Updates show_in_rest for all ACF field groups.
 *
 * @param bool $enable Whether to enable show_in_rest.
 * @psalm-suppress UnusedForeachValue
 */


function wp_theme_update_all_acf_show_in_rest( bool $enable ): void {
    if (!function_exists('acf_get_field_groups')) {
        return;
    }

    $field_groups = acf_get_field_groups();

    foreach ($field_groups as $group_item) {
        $group_data = acf_get_field_group($group_item['key']);

        if ($group_data) {
            // Update show_in_rest setting
            $group_data['show_in_rest'] = $enable ? 1 : 0;

            // Save the updated field group
            acf_update_field_group($group_data);
        }
    }

    // Clear ACF cache
    if (function_exists('acf_get_store')) {
        $store = acf_get_store('field-groups');
        if ($store !== null) {
            $store->reset();
        }
    }
}