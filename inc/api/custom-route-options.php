<?php
/**
 * Custom REST API endpoints for Options Pages via ACF.
 *
 * @package wp-theme
 */


/**
 * Function to get all Options Pages via ACF.
 * @return mixed[]
 */
function get_all_options_pages(): array {
    $options_pages = [];

    // Via ACF Options Page API.
    if (function_exists( 'acf_options_page' )) {
        try {
            $acf_pages = acf_options_page()->get_pages();
            if (is_array( $acf_pages ) && $acf_pages !== []) {
                $options_pages = $acf_pages;
            }
        } catch (Exception $e) {
            error_log( 'ACF Options Page API error: ' . $e->getMessage() );
        }
    }

    // Via acf_get_options_pages (alternative method).
    if ($options_pages === [] && function_exists( 'acf_get_options_pages' )) {
        $acf_pages = acf_get_options_pages();
        if (is_array( $acf_pages ) && $acf_pages !== []) {
            $options_pages = $acf_pages;
        }
    }

    return $options_pages;
}


/**
 * Function to format Options Page data.
 *
 * @param array $page Options Page data.
 */
function format_options_page_data( $page ): array {
    if (! is_array( $page )) {
        return [];
    }

    $acf_data = get_fields( $page['post_id'] ?? '' ) ?: [];

    // Filter out empty ACF fields (false values)
    if (function_exists('wp_theme_filter_empty_acf_fields')) {
        $acf_data = wp_theme_filter_empty_acf_fields($acf_data);
    }

    return [
        'menu_slug'  => $page['menu_slug'] ?? '',
        'page_title' => $page['page_title'] ?? '',
        'menu_title' => $page['menu_title'] ?? '',
        'post_id'    => $page['post_id'] ?? '',
        'data'       => $acf_data,
    ];
}


/**
 * Custom endpoint to get Options Page data by slug.
 */
add_action(
    'rest_api_init',
    function (): void {
        register_rest_route(
            'custom/v1',
            '/options/(?P<slug>[a-zA-Z0-9_-]+)',
            [
                'methods'             => 'GET',
                'callback'            => function ( array $data ) {
                    $slug = sanitize_text_field( $data['slug'] );

                    // Get all Options Pages.
                    $options_pages = get_all_options_pages();

                    // Find Options Page by slug.
                    $target_page = null;
                    foreach ($options_pages as $page) {
                        if ($page['menu_slug'] === $slug) {
                            $target_page = $page;
                            break;
                        }
                    }

                    // If page not found.
                    if (! $target_page) {
                        return new WP_Error(
                            'options_page_not_found',
                            'Options Page with specified slug not found',
                            [ 'status' => 404 ]
                        );
                    }

                    // Get ACF data for this Options Page.
                    $options_data = get_fields( $target_page['post_id'] );

                    // Filter out empty ACF fields (false values)
                    if (function_exists('wp_theme_filter_empty_acf_fields')) {
                        $options_data = wp_theme_filter_empty_acf_fields($options_data ?: []);
                    }

                    // Return data without success and data wrapper.
                    return $options_data ?: (object) [];
                },
                'args'                => [
                    'slug' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
                'permission_callback' => fn(): true => true,
            ]
        );
    }
);

/**
 * Endpoint to get all Options Pages.
 */
add_action(
    'rest_api_init',
    function (): void {
        register_rest_route(
            'custom/v1',
            '/options',
            [
                'methods'             => 'GET',
                'callback'            => function (): array {
                    // Get all Options Pages.
                    $options_pages = get_all_options_pages();

                    $pages_data = [];
                    foreach ($options_pages as $page) {
                        $pages_data[] = format_options_page_data( $page );
                    }

                    return $pages_data;
                },
                'permission_callback' => fn(): true => true,
            ]
        );
    }
);
