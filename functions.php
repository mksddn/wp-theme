<?php
/**
 * Main theme functions and hooks.
 *
 * @package wp-theme
 */


/**
 * Theme settings.
 */
function theme_setup(): void {
    // add_theme_support('post-formats').
    // add_theme_support('automatic-feed-links').
    add_theme_support( 'post-thumbnails' );
    // add_theme_support('html5', array(
    // 'search-form',
    // 'comment-form',
    // 'comment-list',
    // 'gallery',
    // 'caption',
    // 'style',
    // 'script',
    // )).
    add_theme_support( 'title-tag' );
    add_theme_support( 'custom-logo', [ 'unlink-homepage-logo' => true ] );
}


add_action( 'after_setup_theme', 'theme_setup' );


/**
 * Programmatically set permalink structure to Post name.
 */
add_action(
    'after_switch_theme',
    function (): void {
        global $wp_rewrite;
        $desired_structure = '/%postname%/';
        if (get_option( 'permalink_structure' ) !== $desired_structure) {
            update_option( 'permalink_structure', $desired_structure );
            $wp_rewrite->set_permalink_structure( $desired_structure );
            flush_rewrite_rules();
        }

        // Update ACF show_in_rest settings if Headless CMS is enabled
        if (function_exists('wp_theme_get_settings')) {
            $settings = wp_theme_get_settings();
            if (isset($settings['headless']) && $settings['headless'] && function_exists('wp_theme_update_all_acf_show_in_rest')) {
                wp_theme_update_all_acf_show_in_rest(true);
            }
        }
    }
);

/**
 * Visually separate ACF repeater fields.
 */
if (class_exists( 'ACF' )) {


    /**
     * Styling ACF repeater rows.
     */
    function stylize_acf_repeater_fields(): void {
        echo '<style>
		.acf-repeater tbody .acf-row:nth-child(even)>.acf-row-handle {
		   filter: brightness(0.9);
		}
	</style>';
    }


    add_action( 'admin_head', 'stylize_acf_repeater_fields' );
}



/**
 * Theme Settings page and helpers.
 */
require_once get_template_directory() . '/inc/theme-features.php';


/**
 * GitHub Theme Updater.
 */
require_once get_template_directory() . '/inc/github-updater.php';


/**
 * Child theme compatibility: ignore parent front-page.php when a child theme is active.
 */
add_filter('frontpage_template', function ($template) {
    if (get_template() !== get_stylesheet()) {
        return '';
    }

    return $template;
}, 100);

/**
 * Register new image sizes.
 */
// if (function_exists('add_image_size')) {
// 300 width and unlimited height.
// add_image_size('category-thumb', 300, 9999);
// Image cropping.
// add_image_size('homepage-thumb', 220, 180, true);
// }

/**
 * Register menus.
 */
// register_nav_menus(array(
// 'main_menu' => esc_html__('Main Menu'),
// 'footer_menu' => esc_html__('Footer Menu'),
// ));



