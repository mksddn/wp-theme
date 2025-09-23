<?php


/**
 * Styles and scripts enqueue for theme.
 *
 * @package wp-theme
 */
function wp_starter_scripts(): void {
    // Get theme version for cache busting
    $theme_version = wp_get_theme()->get('Version');

    // wp_enqueue_style( $handle, $src = false, $deps = array(), $ver = false, $media = 'all' ).
    wp_enqueue_style( 'my-styles', get_template_directory_uri() . '/css/index.css', [], $theme_version ); // Enqueue custom styles.

    // wp_enqueue_script( $handle, $src = false, $deps = array(), $ver = false, $in_footer = false ).
    wp_enqueue_script( 'my-scripts', get_template_directory_uri() . '/js/index.js', [], $theme_version ); // Enqueue custom scripts.

    wp_enqueue_script( 'contact-form', get_template_directory_uri() . '/js/contact-form.js', [], $theme_version ); // Enqueue form handler.
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
