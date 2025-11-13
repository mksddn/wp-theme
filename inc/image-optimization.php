<?php
/**
 * Image optimization functionality
 *
 * Modernized for WordPress 5.5+ which includes:
 * - Built-in lazy loading (WordPress 5.5+)
 * - Built-in WebP support (WordPress 5.8+)
 * - Automatic responsive images with srcset
 *
 * @package WP_Theme
 * @since 1.0.0
 */

// Prevent direct access to this file.
if (! defined('ABSPATH')) {
    exit('Direct access forbidden.');
}


/**
 * Initialize image optimizations based on theme settings.
 *
 * @since 1.0.0
 */
function wp_theme_image_optimization_init(): void {
    $settings = wp_theme_get_settings();

    // Always fix metadata errors regardless of settings
    add_filter('wp_get_attachment_metadata', 'wp_theme_fix_image_metadata', 10, 2);

    // Upload limits
    if ($settings['image_opt_upload_limits']) {
        add_filter('wp_handle_upload_prefilter', 'wp_theme_optimize_image_upload');
    }

    // Quality settings
    if ($settings['image_opt_quality']) {
        add_filter('jpeg_quality', 'wp_theme_set_jpeg_quality');
        add_filter('wp_editor_set_quality', 'wp_theme_set_image_quality');
    }

    // Priority loading
    if ($settings['image_opt_priority_loading']) {
        add_filter('wp_get_attachment_image_attributes', 'wp_theme_add_priority_loading', 10, 3);
    }

    // Size optimization
    if (!empty($settings['image_opt_remove_sizes_list'])) {
        wp_theme_remove_intermediate_sizes();
        add_filter('intermediate_image_sizes_advanced', 'wp_theme_filter_intermediate_sizes');
        add_filter('big_image_size_threshold', '__return_false');
    }
}


add_action('after_setup_theme', 'wp_theme_image_optimization_init');


/**
 * Remove intermediate image sizes based on theme settings.
 *
 * @since 1.0.0
 */
function wp_theme_remove_intermediate_sizes(): void {
    $settings = wp_theme_get_settings();
    $sizes_to_remove = $settings['image_opt_remove_sizes_list'];

    foreach ($sizes_to_remove as $size) {
        remove_image_size($size);
    }
}


/**
 * Filter intermediate image sizes to prevent generation of unwanted sizes.
 *
 * @since 1.0.0
 */
function wp_theme_filter_intermediate_sizes(array $sizes): array {
    $settings = wp_theme_get_settings();
    $sizes_to_remove = $settings['image_opt_remove_sizes_list'];

    // Remove unwanted sizes from the array
    foreach ($sizes_to_remove as $size_to_remove) {
        unset($sizes[$size_to_remove]);
    }

    return $sizes;
}


/**
 * Fix image metadata to prevent undefined array key errors.
 * Ensures width and height are always present in image metadata.
 *
 * @since 1.0.0
 */
function wp_theme_fix_image_metadata($metadata, $attachment_id) {
    // Ensure metadata is array
    if (!is_array($metadata)) {
        $metadata = [];
    }

    // Fix missing width/height
    if (!isset($metadata['width']) || !isset($metadata['height'])) {
        $file_path = get_attached_file($attachment_id);
        if ($file_path && file_exists($file_path)) {
            $image_info = getimagesize($file_path);
            if ($image_info) {
                $metadata['width'] = $image_info[0];
                $metadata['height'] = $image_info[1];
            }
        }

        // Set fallback values if still missing
        $metadata['width'] ??= 0;
        $metadata['height'] ??= 0;
    }

    // Ensure sizes array exists
    $metadata['sizes'] ??= [];

    return $metadata;
}


/**
 * Optimize image uploads with size and dimension limits.
 *
 * @since 1.0.0
 */
function wp_theme_optimize_image_upload(array $file): array {
    $settings = wp_theme_get_settings();
    $max_size = $settings['image_opt_max_file_size'] * 1024 * 1024;
    $max_dimension = $settings['image_opt_max_dimension'];

    // Check file size
    if ($file['size'] > $max_size) {
        $file['error'] = sprintf(
            esc_html__('Image size too large. Maximum size is %dMB.', 'wp-theme'),
            $settings['image_opt_max_file_size']
        );
        return $file;
    }

    // Check dimensions
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info && ($image_info[0] > $max_dimension || $image_info[1] > $max_dimension)) {
        $file['error'] = sprintf(
            esc_html__('Image dimensions too large. Maximum dimension is %dpx.', 'wp-theme'),
            $max_dimension
        );
        return $file;
    }

    return $file;
}


/**
 * Add priority loading for above-the-fold images.
 * WordPress 5.5+ has built-in lazy loading, so we only handle priority loading.
 *
 * @since 1.0.0
 */
function wp_theme_add_priority_loading(array $attr, $attachment, $_size): array {
    // Add fetchpriority="high" for above-the-fold images
    if (is_singular() && has_post_thumbnail() && $attachment->ID === get_post_thumbnail_id()) {
        $attr['fetchpriority'] = 'high';
    }

    // Add decoding="async" for better performance
    if (!isset($attr['decoding'])) {
        $attr['decoding'] = 'async';
    }

    return $attr;
}


/**
 * Set JPEG quality for better compression.
 *
 * @since 1.0.0
 */
function wp_theme_set_jpeg_quality(): int {
    $settings = wp_theme_get_settings();
    return $settings['image_opt_jpeg_quality'];
}


/**
 * Set image quality for all formats.
 *
 * @since 1.0.0
 */
function wp_theme_set_image_quality(): int {
    $settings = wp_theme_get_settings();
    return $settings['image_opt_quality_value'];
}


/**
 * Generate responsive image HTML.
 *
 * @since 1.0.0
 */
function wp_theme_get_responsive_image($attachment_id, $size = 'large', $attr = []): string {
    $image = wp_get_attachment_image_src($attachment_id, $size);

    if (!$image) {
        return '';
    }

    $srcset = wp_get_attachment_image_srcset($attachment_id, $size);
    $sizes = wp_get_attachment_image_sizes($attachment_id, $size);
    $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    $default_attr = [
        'src'      => $image[0],
        'alt'      => $alt ?: get_the_title($attachment_id),
        'class'    => 'responsive-image',
        'decoding' => 'async',
    ];

    if ($srcset) {
        $default_attr['srcset'] = $srcset;
    }

    if ($sizes) {
        $default_attr['sizes'] = $sizes;
    }

    $attr = wp_parse_args($attr, $default_attr);

    $html = '<img';
    foreach ($attr as $name => $value) {
        $html .= ' ' . $name . '="' . esc_attr($value) . '"';
    }

    return $html . '>';
}


/**
 * Optimize existing images.
 *
 * @since 1.0.0
 */
function wp_theme_optimize_existing_images(): void {
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'post_status'    => 'inherit',
    ]);

    foreach ($attachments as $attachment) {
        $file_path = get_attached_file($attachment->ID);
        if ($file_path && file_exists($file_path)) {
            wp_generate_attachment_metadata($attachment->ID, $file_path);
        }
    }
}


