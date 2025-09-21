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
 * This filter automatically adds show_in_rest parameter for new field groups.
 */
add_filter( 'acf/register_field_group', 'acf_default_show_in_rest', 10, 1 );


/**
 * Adds show_in_rest for new ACF field groups.
 *
 * @param array $field_group Field group.
 * @return array
 */
function acf_default_show_in_rest( $field_group ) {
    // If field group is new (not yet saved), set show_in_rest = 1.
    if (! isset( $field_group['ID'] )) {
        $field_group['show_in_rest'] = 1;
    }

    return $field_group;
}


add_filter( 'acf/prepare_field_group_for_import', 'acf_default_show_in_rest_import' );


/**
 * Adds show_in_rest when importing ACF field group.
 *
 * @param array $field_group Field group.
 */
function acf_default_show_in_rest_import( array $field_group ): array {
    $field_group['show_in_rest'] = 1;
    return $field_group;
}