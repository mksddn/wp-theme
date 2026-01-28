<?php
/**
 * Main theme functions and hooks.
 *
 * @package wp-theme
 */


/**
 * Load theme translations before includes.
 */
function wp_theme_load_textdomain(): void {
    $locale = is_admin() ? get_user_locale() : get_locale();
    $mofile = get_template_directory() . '/languages/wp-theme-' . $locale . '.mo';

    if (file_exists( $mofile )) {
        load_textdomain( 'wp-theme', $mofile );
    }

    load_theme_textdomain( 'wp-theme', get_template_directory() . '/languages' );
}


wp_theme_load_textdomain();


function theme_setup(): void {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ] );
    add_theme_support( 'align-wide' );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'custom-logo', [
        'flex-height'          => true,
        'flex-width'           => true,
        'unlink-homepage-logo' => true,
    ] );
}


add_action( 'after_setup_theme', 'theme_setup' );


/**
 * Theme Settings page and helpers.
 */
require_once get_template_directory() . '/inc/theme-features.php';


/**
 * GitHub Theme Updater.
 */
require_once get_template_directory() . '/inc/github-updater.php';
