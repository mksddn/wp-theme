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
    function ( $endpoints ) {
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
 * Updates show_in_rest for all existing ACF field groups when Headless CMS setting changes.
 *
 * @param array $old_value Previous settings.
 * @param array $new_value New settings.
 */
function wp_theme_update_acf_show_in_rest_on_setting_change( $old_value, $new_value ): void {
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
 */
function wp_theme_update_all_acf_show_in_rest( bool $enable ): void {
    if (!function_exists('acf_get_field_groups')) {
        return;
    }

    $field_groups = acf_get_field_groups();

    foreach ($field_groups as $group) {
        $group_data = acf_get_field_group($group['key']);

        if ($group_data) {
            // Update show_in_rest setting
            $group_data['show_in_rest'] = $enable ? 1 : 0;

            // Save the updated field group
            acf_update_field_group($group_data);
        }
    }

    // Clear ACF cache
    if (function_exists('acf_get_store')) {
        acf_get_store('field-groups')->reset();
    }
}