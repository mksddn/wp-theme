<?php
/**
 * Restrict Tools admin menu to users with the administrator role.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


function wp_theme_user_has_administrator_role(): bool {
    if (! is_user_logged_in()) {
        return false;
    }

    return in_array('administrator', (array) wp_get_current_user()->roles, true);
}


function wp_theme_remove_tools_menu_for_non_administrators(): void {
    if (wp_theme_user_has_administrator_role()) {
        return;
    }

    remove_menu_page('tools.php');
}

add_action('admin_menu', 'wp_theme_remove_tools_menu_for_non_administrators', 999);


function wp_theme_block_tools_pages_for_non_administrators(): void {
    if (wp_theme_user_has_administrator_role()) {
        return;
    }

    global $pagenow;

    $tools_pages = [
        'tools.php',
        'import.php',
        'export.php',
        'site-health.php',
        'export-personal-data.php',
        'erase-personal-data.php',
    ];

    if (! in_array($pagenow, $tools_pages, true)) {
        return;
    }

    wp_safe_redirect(admin_url());
    exit;
}

add_action('admin_init', 'wp_theme_block_tools_pages_for_non_administrators');
