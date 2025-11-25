<?php

/**
 * Plugin Name: Plugin Domain Logger
 * Description: This plugin logs the text domain of activated and deactivated plugins to a `plugins.txt` file in the active theme directory.
 * Version: 1.0
 * Author: mksddn
 */

// Hook to log plugin activation
function log_plugin_activation( string $plugin ): void {
    // Get full plugin data
    $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

    // Extract the plugin's text domain
    $plugin_text_domain = $plugin_data['TextDomain'];

    // Set the path to the plugins.txt file in the active theme directory (child or parent)
    $log_file = get_stylesheet_directory() . '/plugins.txt';

    // Check if the log file exists
    if (file_exists( $log_file )) {
        // Read the file contents into an array of lines
        $lines = @file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

        // If the text domain already exists in the file, do not add it again
        if (is_array($lines) && in_array( $plugin_text_domain, $lines )) {
            return;
        }
    } else {
        // If the file doesn't exist, create it
        @file_put_contents( $log_file, '', LOCK_EX );
    }

    // Append the text domain to the log file if it hasn't been added yet
    @file_put_contents( $log_file, $plugin_text_domain . "\n", FILE_APPEND | LOCK_EX );
}


add_action( 'activated_plugin', 'log_plugin_activation', 10, 1 );

// Hook to log plugin deactivation
function log_plugin_deactivation( string $plugin ): void {
    // Get full plugin data
    $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

    // Extract the plugin's text domain
    $plugin_text_domain = $plugin_data['TextDomain'];

    // Set the path to the plugins.txt file in the active theme directory (child or parent)
    $log_file = get_stylesheet_directory() . '/plugins.txt';

    // Check if the log file exists
    if (file_exists( $log_file )) {
        // Read the file contents into an array of lines
        $lines = @file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

        // Search for the text domain and remove it from the array if found
        if (is_array($lines) && ( $key = array_search( $plugin_text_domain, $lines, true ) ) !== false) {
            unset( $lines[ $key ] );

            // Rewrite the file without the removed text domain
            @file_put_contents( $log_file, implode( "\n", $lines ) . "\n", LOCK_EX );
        }
    }
}


add_action( 'deactivated_plugin', 'log_plugin_deactivation', 10, 1 );
