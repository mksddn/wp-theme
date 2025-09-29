<?php


/**
 * Styles and scripts enqueue for theme.
 *
 * @package wp-theme
 */
function wp_starter_scripts(): void {
    // Get theme version for cache busting
    $theme_version = wp_get_theme( get_stylesheet() )->get('Version');

    // Enqueue child or parent stylesheet using child-aware API.
    $css_uri = get_theme_file_uri( 'css/index.css' );
    wp_enqueue_style( 'my-styles', $css_uri, [], $theme_version );

    // Enqueue child or parent script using child-aware API.
    $main_js_uri = get_theme_file_uri( 'js/index.js' );
    wp_enqueue_script( 'my-scripts', $main_js_uri, [], $theme_version );

    $contact_js_uri = get_theme_file_uri( 'js/contact-form.js' );
    wp_enqueue_script( 'contact-form', $contact_js_uri, [], $theme_version );
    wp_localize_script(
        'contact-form',
        'contactFormData',
        [
            'ajaxUrl' => admin_url( 'admin-post.php' ), // Localize script to pass ajaxUrl.
        ]
    );
}


/**
 * Enqueue theme scripts and styles.
 */
add_action( 'wp_enqueue_scripts', 'wp_starter_scripts' );
