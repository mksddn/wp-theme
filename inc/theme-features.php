<?php
/**
 * Theme Settings page (Appearance → Theme Settings).
 */

if (! defined('ABSPATH')) {
    exit;
}


function wp_theme_get_default_settings(): array {
    $defaults = [
        // Admin Features
        'disable_comments'   => true,
        'cyr2lat'            => true,
        'woocommerce_support'=> false,
        'plugins_logger'     => true,
        'duplicate_post'     => true,
        'thumbnail_column'   => false,
        'polylang_rest_api'  => false,
        // Content Features
        'disable_gutenberg'  => false,
        'page_excerpt'       => true,
        'clean_archive_title'=> false,
        'clean_pagination'   => false,
        'category_thumbnails'=> false,
        // Headless CMS
        'headless'           => false,
        // SEO Features
        'schema_markup'      => false,
        // Security features
        'security_xmlrpc'           => true,
        'security_x_pingback'       => true,
        'security_hide_version'     => true,
        'security_disable_editing'  => true,
        'security_disable_enumeration' => true,
        'security_csp_header'       => true,
        'security_headers'          => true,
        'security_directory_browsing' => true,
        'security_login_limits'     => true,
        // Search features
        'search_empty_handling'     => true,
        'search_post_types'         => false,
        'search_post_types_list'    => ['post', 'page'],
        'search_exclude_ids_list'   => [],
        'search_exclude_slugs_list' => [],
        // ACF features
        'acf_local_json'            => true,
        // Performance features
        'performance_remove_emojis'  => true,
        'performance_disable_embeds' => true,
        'performance_optimize_queries'=> true,
        'performance_cleanup_head'   => true,
        'performance_disable_wp_cron'=> false,
        'performance_limit_revisions'=> true,
        'performance_revisions_limit'=> 3,
        // Image optimization features (modernized for WordPress 5.5+)
        'image_opt_upload_limits'     => true,
        'image_opt_quality'           => true,
        'image_opt_priority_loading'  => true,
        'image_opt_max_file_size'     => 5,
        'image_opt_max_dimension'     => 2560,
        'image_opt_jpeg_quality'      => 85,
        'image_opt_quality_value'     => 85,
        'image_opt_remove_sizes_list' => ['thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048'],
        // Media features (moved from Admin)
        'file_size_column'            => true,
        'svg_support'                 => true,
    ];

    // Allow other modules to add their default settings
    return apply_filters('wp_theme_default_settings', $defaults);
}


function wp_theme_get_settings(bool $clear_cache = false): array {
    static $cached;

    if ($clear_cache) {
        $cached = null;
        return [];
    }

    if (isset($cached)) {
        return $cached;
    }

    $defaults = wp_theme_get_default_settings();
    $opts = get_option('wp_theme_settings', []);
    if (! is_array($opts)) {
        $opts = [];
    }

    $cached = array_merge($defaults, $opts);
    return $cached;
}


function wp_theme_settings(): array {
    return wp_theme_get_settings();
}


/**
 * Clear theme settings cache.
 *
 * @since 1.0.0
 */
function wp_theme_clear_settings_cache(): void {
    // Clear static cache by calling the function with a flag
    wp_theme_get_settings(true);
}


/**
 * Ensure theme settings exist in database.
 * This helps prevent settings loss during migration.
 *
 * @since 1.0.0
 */
function wp_theme_ensure_settings_exist(): void {
    $existing_settings = get_option('wp_theme_settings', null);

    // If settings don't exist or are empty, initialize with defaults
    if (in_array($existing_settings, [null, false, []], true)) {
        $defaults = wp_theme_get_default_settings();
        update_option('wp_theme_settings', $defaults, true);
        wp_theme_clear_settings_cache();
    }
}


/**
 * Hook to ensure settings exist after migration or theme switch.
 */
add_action('after_switch_theme', 'wp_theme_ensure_settings_exist');
add_action('init', 'wp_theme_ensure_settings_exist', 5);

/**
 * Hook for All-in-One WP Migration plugin.
 * Ensures settings are preserved or recreated after migration.
 */
add_action('ai1wm_import_completed', 'wp_theme_ensure_settings_exist');
add_action('ai1wm_restore_completed', 'wp_theme_ensure_settings_exist');


/**
 * Handle theme settings export.
 *
 * @since 1.0.0
 */
function wp_theme_handle_export_settings(): void {
    if (!isset($_POST['wp_theme_export_settings'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'wp-theme'));
    }

    if (!isset($_POST['wp_theme_export_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['wp_theme_export_nonce'])), 'wp_theme_export_settings')) {
        wp_die(esc_html__('Invalid nonce', 'wp-theme'));
    }

    $settings = get_option('wp_theme_settings', wp_theme_get_default_settings());
    $json = wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        wp_die(esc_html__('Failed to encode settings', 'wp-theme'));
    }

    $filename = 'wp-theme-settings-' . gmdate('Y-m-d-His') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));

    echo esc_html( $json );
    exit;
}


add_action('admin_init', 'wp_theme_handle_export_settings');


/**
 * Handle theme settings import.
 *
 * @since 1.0.0
 */
function wp_theme_handle_import_settings(): void {
    if (!isset($_POST['wp_theme_import_settings'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'wp-theme'));
    }

    if (!isset($_POST['wp_theme_import_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['wp_theme_import_nonce'])), 'wp_theme_import_settings')) {
        wp_die(esc_html__('Invalid nonce', 'wp-theme'));
    }

    if (!isset($_FILES['wp_theme_settings_file']) || !isset($_FILES['wp_theme_settings_file']['error']) || $_FILES['wp_theme_settings_file']['error'] !== UPLOAD_ERR_OK) {
        add_action('admin_notices', function(): void {
            echo wp_kses_post('<div class="notice notice-error"><p>' . esc_html__('File upload error. Please try again.', 'wp-theme') . '</p></div>');
        });
        return;
    }

    if (!isset($_FILES['wp_theme_settings_file']['tmp_name'])) {
        add_action('admin_notices', function(): void {
            echo wp_kses_post('<div class="notice notice-error"><p>' . esc_html__('File upload error. Please try again.', 'wp-theme') . '</p></div>');
        });
        return;
    }

    $tmp_name = sanitize_text_field($_FILES['wp_theme_settings_file']['tmp_name']);
    $file_content = file_get_contents($tmp_name);

    if ($file_content === false) {
        add_action('admin_notices', function(): void {
            echo wp_kses_post('<div class="notice notice-error"><p>' . esc_html__('Failed to read file.', 'wp-theme') . '</p></div>');
        });
        return;
    }

    $settings = json_decode($file_content, true);

    if (!is_array($settings)) {
        $json_error = json_last_error_msg();
        add_action('admin_notices', function() use ($json_error): void {
            echo wp_kses_post('<div class="notice notice-error"><p>' . esc_html__('Invalid JSON format. Error:', 'wp-theme') . ' ' . esc_html($json_error) . '</p></div>');
        });
        return;
    }

    // Sanitize imported settings
    $sanitized_settings = wp_theme_settings_sanitize($settings);

    // Update settings
    update_option('wp_theme_settings', $sanitized_settings);
    wp_theme_clear_settings_cache();

    add_action('admin_notices', function(): void {
        echo wp_kses_post('<div class="notice notice-success"><p>' . esc_html__('Settings imported successfully!', 'wp-theme') . '</p></div>');
    });
}


add_action('admin_init', 'wp_theme_handle_import_settings');


function wp_theme_settings_admin_menu(): void {
    add_options_page(
        __('Theme Features', 'wp-theme'),
        __('Theme Features', 'wp-theme'),
        'manage_options',
        'wp-theme-settings',
        'wp_theme_render_settings_page'
    );
}


add_action('admin_menu', 'wp_theme_settings_admin_menu');


function wp_theme_settings_register(): void {
    register_setting(
        'wp_theme_settings_group',
        'wp_theme_settings',
        [
            'type'              => 'array',
            'sanitize_callback' => 'wp_theme_settings_sanitize',
            'default'           => wp_theme_get_default_settings(),
        ]
    );

    // Admin Features Section
    add_settings_section(
        'wp_theme_section_admin',
        __('Admin Features', 'wp-theme'),
        'wp_theme_admin_section_callback',
        'wp-theme-settings'
    );
    add_settings_field(
        'wp_theme_admin_features',
        __('Admin Features', 'wp-theme'),
        'wp_theme_render_admin_features',
        'wp-theme-settings',
        'wp_theme_section_admin'
    );

    // Content Features Section
    add_settings_section(
        'wp_theme_section_content',
        __('Content Features', 'wp-theme'),
        'wp_theme_content_section_callback',
        'wp-theme-settings'
    );
    add_settings_field(
        'wp_theme_content_features',
        __('Content Features', 'wp-theme'),
        'wp_theme_render_content_features',
        'wp-theme-settings',
        'wp_theme_section_content'
    );

    // Security Features Section
    add_settings_section(
        'wp_theme_section_security',
        __('Security Features', 'wp-theme'),
        'wp_theme_security_section_callback',
        'wp-theme-settings'
    );
    add_settings_field(
        'wp_theme_security_features',
        __('Security Features', 'wp-theme'),
        'wp_theme_render_security_features',
        'wp-theme-settings',
        'wp_theme_section_security'
    );

    // Search Features Section
    add_settings_section(
        'wp_theme_section_search',
        __('Search Features', 'wp-theme'),
        'wp_theme_search_section_callback',
        'wp-theme-settings'
    );
    add_settings_field(
        'wp_theme_search_features',
        __('Search Features', 'wp-theme'),
        'wp_theme_render_search_features',
        'wp-theme-settings',
        'wp_theme_section_search'
    );

    // Image Optimization Section
    add_settings_section(
        'wp_theme_section_image_opt',
        __('Image Optimization', 'wp-theme'),
        'wp_theme_image_opt_section_callback',
        'wp-theme-settings'
    );
    add_settings_field(
        'wp_theme_image_opt_features',
        __('Image Optimization', 'wp-theme'),
        'wp_theme_render_image_opt_features',
        'wp-theme-settings',
        'wp_theme_section_image_opt'
    );

    // Performance Features Section
    add_settings_section(
        'wp_theme_section_performance',
        __('Performance Features', 'wp-theme'),
        'wp_theme_performance_section_callback',
        'wp-theme-settings'
    );
    add_settings_field(
        'wp_theme_performance_features',
        __('Performance Features', 'wp-theme'),
        'wp_theme_render_performance_features',
        'wp-theme-settings',
        'wp_theme_section_performance'
    );

    // Allow other modules to register their settings sections
    do_action('wp_theme_register_settings_sections');
}


add_action('admin_init', 'wp_theme_settings_register');


/**
 * Clear settings cache on theme activation.
 *
 * @since 1.0.0
 */
add_action('after_switch_theme', 'wp_theme_clear_settings_cache');


/**
 * Clear settings cache when options are updated.
 *
 * @since 1.0.0
 */
add_action('update_option_wp_theme_settings', 'wp_theme_clear_settings_cache');


/**
 * Clear settings cache when settings are saved via form.
 *
 * @since 1.0.0
 */
    add_action('admin_init', function(): void {
        // Check if we're saving theme settings
        // Verify nonce generated by settings_fields('wp_theme_settings_group')
        if (isset($_POST['option_page']) && sanitize_text_field(wp_unslash((string) $_POST['option_page'])) === 'wp_theme_settings_group' && (isset($_POST['_wpnonce']) && check_admin_referer('wp_theme_settings_group-options'))) {
            // Clear cache after form submission
            add_action('admin_notices', function(): void {
                wp_theme_clear_settings_cache();
            });
        }
    });


    /**
     * Sanitize numeric value within range
     */
    function wp_theme_sanitize_numeric_range($value, $min, $max, $default): int {
        if (!isset($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }


    /**
     * Sanitize boolean value
     */
    function wp_theme_sanitize_boolean($value): bool {
        return isset($value) && (int) $value === 1;
    }


    function wp_theme_settings_sanitize($input): array {
        $defaults = wp_theme_get_default_settings();
        $output = is_array($input) ? $input : [];

        // Boolean settings grouped by category
        $boolean_settings = [
        // Admin Features
        'disable_comments', 'cyr2lat', 'woocommerce_support', 'plugins_logger', 'duplicate_post', 'thumbnail_column', 'polylang_rest_api',
        // Content Features
        'disable_gutenberg', 'page_excerpt', 'clean_archive_title', 'clean_pagination', 'category_thumbnails',
        // Headless CMS
        'headless',
        // SEO Features
        'schema_markup',
        // Security Features
        'security_xmlrpc', 'security_x_pingback', 'security_hide_version', 'security_disable_editing',
        'security_disable_enumeration', 'security_headers', 'security_csp_header', 'security_directory_browsing', 'security_login_limits',
        // Search Features
        'search_empty_handling', 'search_post_types',
        // ACF Features
        'acf_local_json',
        // Performance Features
        'performance_remove_emojis', 'performance_disable_embeds', 'performance_optimize_queries',
        'performance_cleanup_head', 'performance_disable_wp_cron', 'performance_limit_revisions',
        // Image Optimization Features
        'image_opt_upload_limits', 'image_opt_quality', 'image_opt_priority_loading',
        // Media Features
        'file_size_column', 'svg_support'
        ];

        // Sanitize boolean values
        foreach ($boolean_settings as $key) {
            $output[$key] = wp_theme_sanitize_boolean($output[$key] ?? false);
        }

        // Search post types sanitization
        $allowed_post_types = get_post_types(['public' => true], 'names');
        $selected_post_types = isset($output['search_post_types_list']) && is_array($output['search_post_types_list']) ? array_values(array_intersect($allowed_post_types, array_map(sanitize_text_field(...), $output['search_post_types_list']))) : [];
        $output['search_post_types_list'] = $selected_post_types;

        // Search exclude IDs sanitization
        $exclude_ids_input = $output['search_exclude_ids_list'] ?? '';
        if (is_string($exclude_ids_input)) {
            $exclude_ids = array_map(intval(...), array_filter(explode(',', $exclude_ids_input)));
        } else {
            $exclude_ids = is_array($exclude_ids_input) ? array_map(intval(...), $exclude_ids_input) : [];
        }

        $output['search_exclude_ids_list'] = array_filter($exclude_ids, fn(int $id): bool => $id > 0); // Only positive integers

        // Search exclude slugs sanitization
        $exclude_slugs_input = $output['search_exclude_slugs_list'] ?? '';
        if (is_string($exclude_slugs_input)) {
            $exclude_slugs = array_map(sanitize_title(...), array_filter(array_map(trim(...), explode(',', $exclude_slugs_input))));
        } else {
            $exclude_slugs = is_array($exclude_slugs_input) ? array_map(sanitize_title(...), array_filter(array_map(trim(...), $exclude_slugs_input))) : [];
        }

        $output['search_exclude_slugs_list'] = array_values(array_filter($exclude_slugs, fn($slug): bool => $slug !== ''));

        // Numeric settings sanitization
        $numeric_settings = [
        'performance_revisions_limit' => [1, 10, $defaults['performance_revisions_limit']],
        'image_opt_max_file_size' => [1, 50, $defaults['image_opt_max_file_size']],
        'image_opt_max_dimension' => [1000, 8000, $defaults['image_opt_max_dimension']],
        'image_opt_jpeg_quality' => [60, 100, $defaults['image_opt_jpeg_quality']],
        'image_opt_quality_value' => [60, 100, $defaults['image_opt_quality_value']],
        ];

        foreach ($numeric_settings as $key => $config) {
            $output[$key] = wp_theme_sanitize_numeric_range($output[$key] ?? null, $config[0], $config[1], $config[2]);
        }

        // Image optimization sizes list sanitization
        $allowed_remove_sizes = ['thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048'];
        $selected_remove_sizes = isset($output['image_opt_remove_sizes_list']) && is_array($output['image_opt_remove_sizes_list']) ?
        array_values(array_intersect($allowed_remove_sizes, array_map(sanitize_text_field(...), $output['image_opt_remove_sizes_list']))) : [];
        $output['image_opt_remove_sizes_list'] = $selected_remove_sizes;

        // Allow other modules to sanitize their settings
        $output = apply_filters('wp_theme_sanitize_settings', $output, $defaults);

        $result = array_merge($defaults, $output);

        // Clear cache after sanitization
        wp_theme_clear_settings_cache();

        return $result;
    }


    function wp_theme_render_settings_page(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Check if settings are loaded from database or using defaults
        $db_settings = get_option('wp_theme_settings', false);
        $settings_source = $db_settings !== false ? 'database' : 'defaults';
        ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p><?php esc_html_e('Configure theme features and functionality. Enable or disable specific features based on your needs.', 'wp-theme'); ?></p>
        
        <?php if ($settings_source === 'defaults'): ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Warning:', 'wp-theme'); ?></strong> <?php esc_html_e('Settings are using default values. Database option \'wp_theme_settings\' not found. Save settings to create the option.', 'wp-theme'); ?></p>
        </div>
        <?php endif; ?>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('wp_theme_settings_group');
            do_settings_sections('wp-theme-settings');
            submit_button(__('Save Theme Features', 'wp-theme'));
            ?>
        </form>
        
        <div class="wp-theme-settings-info" style="margin-top: 30px; padding: 15px; background: #f1f1f1; border-left: 4px solid #0073aa;">
            <h3><?php esc_html_e('About Theme Features', 'wp-theme'); ?></h3>
            <p><?php esc_html_e('This page allows you to enable or disable various theme features. Changes take effect immediately after saving.', 'wp-theme'); ?></p>
            <p><strong><?php esc_html_e('Note:', 'wp-theme'); ?></strong> <?php esc_html_e('Some features require specific plugins to be installed and activated.', 'wp-theme'); ?></p>
            <p><strong><?php esc_html_e('Settings source:', 'wp-theme'); ?></strong> <code><?php echo esc_html($settings_source); ?></code></p>
        </div>
        
        <div class="wp-theme-settings-backup" style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ccc;">
            <h3><?php esc_html_e('Backup &amp; Restore Settings', 'wp-theme'); ?></h3>
            <p><?php esc_html_e('Use these tools to backup or restore theme settings. Useful for migrations.', 'wp-theme'); ?></p>
            
            <div style="margin-bottom: 15px;">
                <h4><?php esc_html_e('Export Settings', 'wp-theme'); ?></h4>
                <form method="post" action="">
                    <?php wp_nonce_field('wp_theme_export_settings', 'wp_theme_export_nonce'); ?>
                    <input type="hidden" name="wp_theme_export_settings" value="1">
                    <button type="submit" class="button"><?php esc_html_e('Export Settings as JSON', 'wp-theme'); ?></button>
                </form>
            </div>
            
            <div>
                <h4><?php esc_html_e('Import Settings', 'wp-theme'); ?></h4>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field('wp_theme_import_settings', 'wp_theme_import_nonce'); ?>
                    <input type="file" name="wp_theme_settings_file" accept=".json">
                    <button type="submit" name="wp_theme_import_settings" class="button"><?php esc_html_e('Import Settings from JSON', 'wp-theme'); ?></button>
                </form>
            </div>
        </div>
    </div>
        <?php
    }


    // Section callbacks

    function wp_theme_admin_section_callback(): void {
        echo '<p>' . esc_html__('Admin interface enhancements, WordPress core modifications, and administrative tools.', 'wp-theme') . '</p>';
    }


    function wp_theme_content_section_callback(): void {
        echo '<p>' . esc_html__('Content editing, display, and formatting features including editor settings.', 'wp-theme') . '</p>';
    }


    function wp_theme_security_section_callback(): void {
        echo '<p>' . esc_html__('WordPress security enhancements and hardening measures.', 'wp-theme') . '</p>';
    }


    function wp_theme_search_section_callback(): void {
        echo '<p>' . esc_html__('Search functionality enhancements and optimizations.', 'wp-theme') . '</p>';
    }


    function wp_theme_image_opt_section_callback(): void {
        echo '<p>' . esc_html__('Image optimization features, media library enhancements, and file format support for better performance and user experience.', 'wp-theme') . '</p>';
    }


    function wp_theme_performance_section_callback(): void {
        echo '<p>' . esc_html__('Basic performance optimizations to improve site speed and reduce server load. These settings are safe to enable and won\'t conflict with most plugins.', 'wp-theme') . '</p>';
    }


    // Feature render functions

    function wp_theme_render_admin_features(): void {
        $opts = wp_theme_get_settings();
        $features = [
        'headless'          => '<b>' . __('Enable headless CMS mode (API only, no theme assets)', 'wp-theme') . '</b> - ' . __('Disables frontend theme, enables API-only usage', 'wp-theme'),
        'cyr2lat'           => '<b>' . __('Cyr to Lat (transliteration)', 'wp-theme') . '</b> - ' . __('Converts Cyrillic characters to Latin in URLs and filenames', 'wp-theme'),
        'acf_local_json'    => '<b>' . __('Enable ACF Local JSON with automatic path management', 'wp-theme') . '</b> - ' . __('Saves ACF field groups as JSON files in theme', 'wp-theme'),
        'disable_comments'  => '<b>' . __('Disable comments', 'wp-theme') . '</b> - ' . __('Completely removes comment functionality from posts and pages', 'wp-theme'),
        'duplicate_post'    => '<b>' . __('Duplicate Post feature', 'wp-theme') . '</b> - ' . __('Adds duplicate button to post edit screens', 'wp-theme'),
        'plugins_logger'    => '<b>' . __('Plugins logger', 'wp-theme') . '</b> - ' . __('Logs plugin activation/deactivation events', 'wp-theme'),
        'woocommerce_support'=> '<b>' . __('WooCommerce support', 'wp-theme') . '</b> - ' . __('Adds theme support for WooCommerce plugin', 'wp-theme'),
        'polylang_rest_api' => '<b>' . __('Polylang REST API language detection', 'wp-theme') . '</b> - ' . __('Automatically detects language from Accept-Language header in REST API requests', 'wp-theme'),
        ];
        wp_theme_render_checkboxes($features, $opts);
    }


    function wp_theme_render_content_features(): void {
        $opts = wp_theme_get_settings();
        $features = [
        'disable_gutenberg' => '<b>' . __('Disable Gutenberg', 'wp-theme') . '</b> - ' . __('Reverts to classic editor for all post types', 'wp-theme'),
        'page_excerpt'      => '<b>' . __('Add excerpt support to pages', 'wp-theme') . '</b> - ' . __('Enables excerpt field for pages in admin', 'wp-theme'),
        'clean_archive_title'=> '<b>' . __('Remove "Category: ", "Tag: " etc. from archive title', 'wp-theme') . '</b> - ' . __('Shows only the term name', 'wp-theme'),
        'clean_pagination'  => '<b>' . __('Remove H2 from pagination template', 'wp-theme') . '</b> - ' . __('Removes default heading wrapper from pagination', 'wp-theme'),
        'thumbnail_column'  => '<b>' . __('Posts list: thumbnail column', 'wp-theme') . '</b> - ' . __('Shows featured image thumbnails in posts list', 'wp-theme'),
        'category_thumbnails'=> '<b>' . __('Category thumbnails', 'wp-theme') . '</b> - ' . __('Adds thumbnail support for categories with admin interface and REST API', 'wp-theme'),
        'schema_markup'     => '<b>' . __('Schema.org structured data markup', 'wp-theme') . '</b> - ' . __('Adds structured data for better SEO', 'wp-theme'),
        ];
        wp_theme_render_checkboxes($features, $opts);
    }


    // Helper function for rendering checkboxes
    function wp_theme_render_checkboxes(array $features, array $opts): void {
        foreach ($features as $key => $label) {
            $checked = (isset($opts[$key]) && $opts[$key] === true) ? 'checked' : '';
            echo wp_kses(
            '<p><label><input type="checkbox" name="wp_theme_settings[' . esc_attr($key) . ']" value="1" ' . esc_attr($checked) . '> ' . wp_kses_post($label) . '</label></p>',
            [
                'p'     => [],
                'label' => [],
                'input' => [ 'type' => true, 'name' => true, 'value' => true, 'checked' => true ],
                'b'     => [],
            ]
            );
        }
    }


    function wp_theme_render_security_features(): void {
        $opts = wp_theme_get_settings();
        $features = [
        'security_xmlrpc'           => '<b>' . __('Disable XML-RPC', 'wp-theme') . '</b> - ' . __('Blocks XML-RPC requests to prevent brute force attacks', 'wp-theme'),
        'security_x_pingback'       => '<b>' . __('Disable X-Pingback header', 'wp-theme') . '</b> - ' . __('Removes pingback header to hide WordPress', 'wp-theme'),
        'security_hide_version'     => '<b>' . __('Hide WordPress version', 'wp-theme') . '</b> - ' . __('Removes version info from head and RSS feeds', 'wp-theme'),
        'security_disable_editing'  => '<b>' . __('Disable file editing in admin', 'wp-theme') . '</b> - ' . __('Prevents theme/plugin editing via admin', 'wp-theme'),
        'security_disable_enumeration' => '<b>' . __('Disable user enumeration', 'wp-theme') . '</b> - ' . __('Blocks user discovery via author archives', 'wp-theme'),
        'security_headers'          => '<b>' . __('Add security headers (except CSP)', 'wp-theme') . '</b> - ' . __('Adds X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS', 'wp-theme'),
        'security_csp_header'       => '<b>' . __('Add Content-Security-Policy (CSP) header', 'wp-theme') . '</b> - ' . __('Sends CSP header to restrict resource loading', 'wp-theme'),
        'security_directory_browsing' => '<b>' . __('Disable directory browsing', 'wp-theme') . '</b> - ' . __('Prevents directory listing on server', 'wp-theme'),
        'security_login_limits'     => '<b>' . __('Limit login attempts', 'wp-theme') . '</b> - ' . __('Adds basic login attempt limiting', 'wp-theme'),
        ];
        wp_theme_render_checkboxes($features, $opts);
    }


    function wp_theme_render_search_features(): void {
        $opts = wp_theme_get_settings();

        // Basic search behavior
        $features = [
        'search_empty_handling' => '<b>' . __('Stop query execution for empty search terms', 'wp-theme') . '</b> - ' . __('Prevents database queries when search is empty', 'wp-theme'),
        ];
        wp_theme_render_checkboxes($features, $opts);

        // Post types filtering
        $available_post_types = get_post_types(['public' => true], 'objects');
        echo '<p><strong>' . esc_html__('Select post types to include in search (leave empty to search all):', 'wp-theme') . '</strong></p>';

        foreach ($available_post_types as $post_type) {
            $checked = in_array($post_type->name, $opts['search_post_types_list'], true) ? 'checked' : '';
            $checkbox_html  = '<p><label><input type="checkbox" name="wp_theme_settings[search_post_types_list][]" value="' . esc_attr($post_type->name) . '" ' . esc_attr($checked) . '> ';
            $checkbox_html .= esc_html($post_type->label) . ' <code>(' . esc_html($post_type->name) . ')</code></label></p>';
            echo wp_kses(
                $checkbox_html,
                [
                    'p' => [], 'label' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'checked' => true],
                    'code' => []
                ]
            );
        }

        // Content exclusion by IDs
        echo '<div style="margin-top: 10px;">';
        echo '<p><strong>' . esc_html__('Post/Page IDs to exclude:', 'wp-theme') . '</strong></p>';
        $exclude_ids_text = implode(', ', $opts['search_exclude_ids_list']);
        $exclude_ids_placeholder = esc_attr__('1, 5, 10', 'wp-theme');
        $exclude_ids_label = esc_html__('Enter IDs separated by commas', 'wp-theme');
        $html = '<p>';
        $html .= '<input type="text" name="wp_theme_settings[search_exclude_ids_list]" ';
        $html .= 'value="' . esc_attr($exclude_ids_text) . '" ';
        $html .= 'placeholder="' . $exclude_ids_placeholder . '" style="width: 200px;"> ';
        $html .= '<small>' . $exclude_ids_label . '</small>';
        $html .= '</p>';
        echo wp_kses(
            $html,
            [
            'p' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'style' => true],
            'small' => []
            ]
        );
        echo '</div>';

        // Content exclusion by slugs
        echo '<div style="margin-top: 10px;">';
        echo '<p><strong>' . esc_html__('Slugs to exclude:', 'wp-theme') . '</strong></p>';
        $exclude_slugs_text = implode(', ', $opts['search_exclude_slugs_list']);
        $exclude_slugs_placeholder = esc_attr__('about-us, contact', 'wp-theme');
        $exclude_slugs_label = esc_html__('Enter slugs separated by commas', 'wp-theme');
        $html = '<p>';
        $html .= '<input type="text" name="wp_theme_settings[search_exclude_slugs_list]" ';
        $html .= 'value="' . esc_attr($exclude_slugs_text) . '" ';
        $html .= 'placeholder="' . $exclude_slugs_placeholder . '" style="width: 200px;"> ';
        $html .= '<small>' . $exclude_slugs_label . '</small>';
        $html .= '</p>';
        echo wp_kses(
            $html,
            [
            'p' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'style' => true],
            'small' => []
            ]
        );
        echo '</div>';
    }


    function wp_theme_render_image_opt_features(): void {
        $opts = wp_theme_get_settings();

        // Basic optimization features (modernized for WordPress 5.5+)
        $features = [
        'image_opt_upload_limits'    => '<b>' . __('Enable upload size and dimension limits', 'wp-theme') . '</b> - ' . __('Restricts image upload size and dimensions', 'wp-theme'),
        'image_opt_quality'          => '<b>' . __('Optimize image quality', 'wp-theme') . '</b> - ' . __('Sets JPEG compression quality for better file sizes', 'wp-theme'),
        'image_opt_priority_loading' => '<b>' . __('Enable priority loading for above-the-fold images', 'wp-theme') . '</b> - ' . __('Loads featured images immediately', 'wp-theme'),
        'file_size_column'           => '<b>' . __('Media Library: file size column', 'wp-theme') . '</b> - ' . __('Shows file size in Media Library list view', 'wp-theme'),
        'svg_support'                => '<b>' . __('SVG support', 'wp-theme') . '</b> - ' . __('Allows safe SVG file uploads with sanitization', 'wp-theme'),
        ];

        // Note: WordPress 5.5+ has built-in lazy loading and responsive images
        // WordPress 5.8+ has built-in WebP support
        wp_theme_render_checkboxes($features, $opts);

        // Upload limits
        echo '<h4>' . esc_html__('Upload Limits', 'wp-theme') . '</h4>';
        $max_file_label = esc_html__('Maximum image size (MB):', 'wp-theme');
        $max_file_value = esc_attr($opts['image_opt_max_file_size']);
        $max_file_html = '<p><label>' . $max_file_label . ' ';
        $max_file_html .= '<input type="number" name="wp_theme_settings[image_opt_max_file_size]" ';
        $max_file_html .= 'value="' . $max_file_value . '" min="1" max="50" style="width: 80px;"></label></p>';
        echo wp_kses($max_file_html, ['p' => [], 'label' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'min' => true, 'max' => true, 'style' => true]]);
        $max_dim_label = esc_html__('Maximum dimension (px):', 'wp-theme');
        $max_dim_value = esc_attr($opts['image_opt_max_dimension']);
        $max_dim_html = '<p><label>' . $max_dim_label . ' ';
        $max_dim_html .= '<input type="number" name="wp_theme_settings[image_opt_max_dimension]" ';
        $max_dim_html .= 'value="' . $max_dim_value . '" min="1000" max="8000" style="width: 100px;"></label></p>';
        echo wp_kses($max_dim_html, ['p' => [], 'label' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'min' => true, 'max' => true, 'style' => true]]);

        // Quality settings
        echo '<h4>' . esc_html__('Image Quality', 'wp-theme') . '</h4>';
        $jpeg_quality_label = esc_html__('JPEG Quality (%):', 'wp-theme');
        $jpeg_quality_value = esc_attr($opts['image_opt_jpeg_quality']);
        $jpeg_html = '<p><label>' . $jpeg_quality_label . ' ';
        $jpeg_html .= '<input type="number" name="wp_theme_settings[image_opt_jpeg_quality]" ';
        $jpeg_html .= 'value="' . $jpeg_quality_value . '" min="60" max="100" style="width: 80px;"></label></p>';
        echo wp_kses($jpeg_html, ['p' => [], 'label' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'min' => true, 'max' => true, 'style' => true]]);
        $general_quality_label = esc_html__('General Quality (%):', 'wp-theme');
        $general_quality_value = esc_attr($opts['image_opt_quality_value']);
        $general_html = '<p><label>' . $general_quality_label . ' ';
        $general_html .= '<input type="number" name="wp_theme_settings[image_opt_quality_value]" ';
        $general_html .= 'value="' . $general_quality_value . '" min="60" max="100" style="width: 80px;"></label></p>';
        echo wp_kses($general_html, ['p' => [], 'label' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'min' => true, 'max' => true, 'style' => true]]);

        // Remove intermediate sizes
        $allowed_sizes = [
        'thumbnail' => __('Thumbnail (150x150px)', 'wp-theme'),
        'medium' => __('Medium (300x300px)', 'wp-theme'),
        'medium_large' => __('Medium Large (768x768px)', 'wp-theme'),
        'large' => __('Large (1024x1024px)', 'wp-theme'),
        '1536x1536' => __('1536x1536 (WordPress 5.3+ large size)', 'wp-theme'),
        '2048x2048' => __('2048x2048 (WordPress 5.3+ large size)', 'wp-theme')
        ];
        echo '<h4>' . esc_html__('Remove Intermediate Sizes', 'wp-theme') . '</h4>';
        echo '<p><strong>' . esc_html__('Select sizes to remove (leave empty to keep all sizes):', 'wp-theme') . '</strong></p>';

        foreach ($allowed_sizes as $size => $description) {
            $checked = in_array($size, $opts['image_opt_remove_sizes_list'], true) ? 'checked' : '';
            echo wp_kses(
            '<p><label><input type="checkbox" name="wp_theme_settings[image_opt_remove_sizes_list][]" value="' . esc_attr($size) . '" ' . esc_attr($checked) . '> ' . esc_html($description) . '</label></p>',
            ['p' => [], 'label' => [], 'input' => ['type' => true, 'name' => true, 'value' => true, 'checked' => true]]
            );
        }
    }


    function wp_theme_render_performance_features(): void {
        $opts = wp_theme_get_settings();

        // Basic performance features
        $features = [
        'performance_remove_emojis'  => '<b>' . __('Remove WordPress emoji scripts', 'wp-theme') . '</b> - ' . __('Removes emoji detection and styles for faster loading', 'wp-theme'),
        'performance_disable_embeds' => '<b>' . __('Disable WordPress embeds', 'wp-theme') . '</b> - ' . __('Removes oEmbed functionality to reduce HTTP requests', 'wp-theme'),
        'performance_optimize_queries'=> '<b>' . __('Optimize database queries', 'wp-theme') . '</b> - ' . __('Removes unnecessary meta links and queries', 'wp-theme'),
        'performance_cleanup_head'   => '<b>' . __('Clean up unnecessary head elements', 'wp-theme') . '</b> - ' . __('Removes feed links and adjacent post links', 'wp-theme'),
        'performance_disable_wp_cron'=> '<b>' . __('Disable WordPress cron (use server cron)', 'wp-theme') . '</b> - ' . __('Disables WP cron for better performance', 'wp-theme'),
        'performance_limit_revisions'=> '<b>' . __('Limit post revisions', 'wp-theme') . '</b> - ' . __('Limits number of post revisions to reduce database size', 'wp-theme'),
        ];
        wp_theme_render_checkboxes($features, $opts);

        // Revisions limit input
        if (isset($opts['performance_limit_revisions']) && $opts['performance_limit_revisions']) {
            $limit_value = esc_attr($opts['performance_revisions_limit'] ?? 3);
            echo wp_kses(
            '<p><label>' . esc_html__('Max revisions per post:', 'wp-theme') . ' <input type="number" name="wp_theme_settings[performance_revisions_limit]" value="' . $limit_value . '" min="1" max="10" style="width: 60px;"></label></p>',
            [ 'p' => [], 'label' => [], 'input' => [ 'type' => true, 'name' => true, 'value' => true, 'min' => true, 'max' => true, 'style' => true ] ]
            );
        }
    }


    // Conditionally enable features from Theme Settings
    $settings = wp_theme_settings(); // Cache settings to avoid multiple calls

    if ($settings['disable_comments']) {
        // Disable all comments across the site
        require_once get_template_directory() . '/inc/disable-comments.php';
    }

    if ($settings['cyr2lat']) {
        // Convert Cyrillic to Latin
        require_once get_template_directory() . '/inc/cyr2lat.php';
    }

    if ($settings['disable_gutenberg']) {
        // Turn off Gutenberg editor
        require_once get_template_directory() . '/inc/disable-gutenberg.php';
    }

    if ($settings['file_size_column']) {
        // Add file size column in Media Library
        require_once get_template_directory() . '/inc/file-size-column.php';
    }

    if ($settings['plugins_logger']) {
        // Log plugins changes
        require_once get_template_directory() . '/inc/plugins-logger.php';
    }

    if ($settings['duplicate_post']) {
        // Duplicate post feature
        require_once get_template_directory() . '/inc/duplicate-post.php';
    }

    if ($settings['svg_support']) {
        // Allow SVG uploads safely
        require_once get_template_directory() . '/inc/svg-support.php';
    }

    if ($settings['thumbnail_column']) {
        // Add thumbnail column in admin lists
        require_once get_template_directory() . '/inc/thumbnail-column.php';
    }


    /**
     * Frontend vs Headless (controlled via Theme Settings)
     */
    if ($settings['headless']) {
        require_once get_template_directory() . '/inc/api/api.php';
    } else {
        require_once get_template_directory() . '/inc/styles-n-scripts.php';
    }

    /**
     * Page excerpt support (controlled via Theme Settings)
     */
    if ($settings['page_excerpt']) {


        function wp_theme_add_excerpt_page(): void {
            add_post_type_support('page', 'excerpt');
        }


        add_action('init', 'wp_theme_add_excerpt_page');
    }

    /**
     * Always enable excerpt support for all post types in REST API context
     */
    add_action('init', function(): void {
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            add_post_type_support($post_type, 'excerpt');
        }
    });

    /**
     * Clean archive titles (controlled via Theme Settings)
     */
    if ($settings['clean_archive_title']) {
        add_filter('get_the_archive_title', fn($title): ?string => preg_replace('~^[^:]+: ~', '', (string) $title));
    }

    /**
     * Clean pagination template (controlled via Theme Settings)
     */
    if ($settings['clean_pagination']) {
        add_filter('navigation_markup_template', 'wp_theme_navigation_template', 10, 2);


        function wp_theme_navigation_template($_template, $_class): string {
            return '
<nav class="navigation %1$s" role="navigation">
<div class="nav-links">%3$s</div>
</nav>
';
        }


    }

    /**
     * WooCommerce support (controlled via Theme Settings)
     */
    if ($settings['woocommerce_support'] && class_exists('WooCommerce')) {
        add_action('after_setup_theme', 'wp_theme_woocommerce_support');


        /**
         * Adds WooCommerce support to the theme.
         */
        function wp_theme_woocommerce_support(): void {
            add_theme_support('woocommerce');
        }


    }

    /**
     * Schema markup support (controlled via Theme Settings)
     */
    if ($settings['schema_markup']) {
        require_once get_template_directory() . '/inc/schema-markup.php';
    }

    /**
     * Security features (controlled via Theme Settings)
     */
    require_once get_template_directory() . '/inc/security-features.php';

    /**
     * Performance features (controlled via Theme Settings)
     */
    require_once get_template_directory() . '/inc/performance-settings.php';

    /**
     * Search features (controlled via Theme Settings)
     */
    require_once get_template_directory() . '/inc/search-settings.php';

    /**
     * ACF Local JSON features (controlled via Theme Settings)
     */
    if ($settings['acf_local_json']) {
        require_once get_template_directory() . '/inc/acf-json-settings.php';
    }

    /**
     * Image optimization features (controlled via Theme Settings)
     */
    require_once get_template_directory() . '/inc/image-optimization.php';

    /**
     * Category thumbnails features (controlled via Theme Settings)
     */
    if ($settings['category_thumbnails']) {
        require_once get_template_directory() . '/inc/category-thumbnails.php';
    }

    /**
     * Polylang REST API features (controlled via Theme Settings)
     */
    if ($settings['polylang_rest_api']) {
        require_once get_template_directory() . '/inc/api/polylang-rest-api.php';
    }

    /**
     * ACF features:
     * - Hide content editor: disable Gutenberg when ACF field group hides content editor.
     * - Repeater styling: visually separate ACF repeater fields.
     */
    if (class_exists('ACF')) {
        require_once get_template_directory() . '/inc/acf-hide-content-editor.php';
        require_once get_template_directory() . '/inc/acf-repeater-styling.php';
    }

    // Allow other modules to apply their settings
    do_action('wp_theme_apply_settings');