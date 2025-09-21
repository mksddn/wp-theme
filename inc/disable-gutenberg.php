<?php
/*
Plugin Name: Disable Gutenberg
Description: Disables the Gutenberg editor and removes related styles, scripts, and hooks.
Version: 1.0
Author: mksddn
*/

// Prevent direct file access
if (! defined( 'ABSPATH' )) {
    exit;
}

// Initialize the plugin when plugins are loaded
did_action( 'plugins_loaded' )
    ? Disable_Gutenberg::init()
    : add_action( 'plugins_loaded', Disable_Gutenberg::init(...) );

final class Disable_Gutenberg {

    // Priority for high-priority filters
    private const FILTER_PRIORITY_HIGH = 100;


    /**
     * Initialize the plugin: disable Gutenberg and related features.
     */
    public static function init(): void {
        // Disable the block editor for all post types
        add_filter( 'use_block_editor_for_post_type', '__return_false', self::FILTER_PRIORITY_HIGH );

        // Remove global styles added by Gutenberg
        remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
        remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );

        // Remove block patterns support
        remove_theme_support( 'core-block-patterns' );

        // Disable basic block styles
        remove_action( 'wp_enqueue_scripts', 'wp_common_block_scripts_and_styles' );

        // Remove unnecessary filters for block processing
        remove_filter( 'the_content', 'do_blocks', 9 );
        remove_filter( 'widget_block_content', 'do_blocks', 9 );

        // Initialize admin-specific hooks
        add_action( 'admin_init', self::on_admin_init(...) );

        // Remove additional Gutenberg hooks
        self::remove_gutenberg_hooks();
    }


    /**
     * Admin-specific initialization: Adjust Gutenberg-related admin behavior.
     */
    public static function on_admin_init(): void {
        // Move the Privacy Policy notice back under the title field
        remove_action( 'admin_notices', [ WP_Privacy_Policy_Content::class, 'notice' ] );
        add_action( 'edit_form_after_title', [ WP_Privacy_Policy_Content::class, 'notice' ] );
    }


    /**
     * Remove various Gutenberg hooks for both admin and frontend.
     *
     * @param string $remove Specifies the scope of hooks to remove. Defaults to 'all'.
     */
    private static function remove_gutenberg_hooks( $remove = 'all' ): void {
        // Admin-related Gutenberg hooks
        self::remove_gutenberg_admin_hooks();

        // REST API-related Gutenberg hooks
        self::remove_gutenberg_rest_hooks();

        if ($remove !== 'all') {
            return;
        }

        // Remove Gutenberg scripts and styles
        self::remove_gutenberg_scripts_and_styles();
    }


    /**
     * Remove admin-specific Gutenberg hooks.
     */
    private static function remove_gutenberg_admin_hooks(): void {
        remove_action( 'admin_menu', 'gutenberg_menu' );
        remove_action( 'admin_init', 'gutenberg_redirect_demo' );
        remove_action( 'admin_notices', 'gutenberg_wordpress_version_notice' );
    }


    /**
     * Remove REST API-related Gutenberg hooks.
     */
    private static function remove_gutenberg_rest_hooks(): void {
        remove_action( 'rest_api_init', 'gutenberg_register_rest_widget_updater_routes' );
        remove_action( 'rest_api_init', 'gutenberg_register_rest_routes' );
        remove_action( 'rest_api_init', 'gutenberg_add_taxonomy_visibility_field' );
    }


    /**
     * Remove Gutenberg-related scripts and styles.
     */
    private static function remove_gutenberg_scripts_and_styles(): void {
        remove_action( 'wp_enqueue_scripts', 'gutenberg_register_scripts_and_styles' );
        remove_action( 'admin_enqueue_scripts', 'gutenberg_register_scripts_and_styles' );
    }


}
