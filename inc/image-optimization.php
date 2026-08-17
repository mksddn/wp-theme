<?php
/**
 * Image optimization functionality
 *
 * Real upload-time resize/re-encode via WP_Image_Editor (Imagick/GD).
 * big_image_size_threshold remains a WordPress fallback only.
 *
 * @package WP_Theme
 * @since 1.0.0
 */

// Prevent direct access to this file.
if (! defined('ABSPATH')) {
    exit('Direct access forbidden.');
}


/**
 * MIME types that can be resized / re-encoded safely.
 *
 * GIF is skipped to preserve animation; SVG is not a raster image.
 *
 * @since 1.3.0
 * @return string[]
 */
function wp_theme_image_opt_supported_mimes(): array {
    return array(
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    );
}


/**
 * Whether upload-time image optimization is enabled.
 *
 * Single switch for reject-by-MB, resize, and compress. Legacy
 * image_opt_quality is treated as the same flag for older saves.
 *
 * @since 1.3.0
 */
function wp_theme_is_image_upload_opt_enabled(): bool {
    $settings = wp_theme_get_settings();

    return ! empty($settings['image_opt_upload_limits']) || ! empty($settings['image_opt_quality']);
}


/**
 * Initialize image optimizations based on theme settings.
 *
 * @since 1.0.0
 */
function wp_theme_image_optimization_init(): void {
    $settings = wp_theme_get_settings();

    // Always fix metadata errors regardless of settings.
    add_filter('wp_get_attachment_metadata', 'wp_theme_fix_image_metadata', 10, 2);

    if (wp_theme_is_image_upload_opt_enabled()) {
        add_filter('wp_handle_upload_prefilter', 'wp_theme_optimize_image_upload');
        add_filter('wp_handle_sideload_prefilter', 'wp_theme_optimize_image_upload');
        // Fallback only: WP may still scale if our editor path fails.
        add_filter('big_image_size_threshold', 'wp_theme_set_big_image_size_threshold');
        // Resize + compress before intermediate sizes are built.
        add_filter('wp_handle_upload', 'wp_theme_optimize_uploaded_file', 10, 2);
        // Quality for WP-generated intermediate sizes / editor saves.
        add_filter('jpeg_quality', 'wp_theme_set_jpeg_quality');
        add_filter('wp_editor_set_quality', 'wp_theme_set_image_quality', 10, 2);
        // If WP fallback still keeps a full original beside -scaled, drop it.
        add_filter('wp_generate_attachment_metadata', 'wp_theme_maybe_delete_full_original', 20, 2);
    }

    if (! empty($settings['image_opt_priority_loading'])) {
        add_filter('wp_get_attachment_image_attributes', 'wp_theme_add_priority_loading', 10, 3);
    }

    if (! empty($settings['image_opt_remove_sizes_list'])) {
        wp_theme_remove_intermediate_sizes();
        add_filter('intermediate_image_sizes_advanced', 'wp_theme_filter_intermediate_sizes');
    }

    if (is_admin()) {
        add_action('admin_post_wp_theme_reoptimize_images', 'wp_theme_handle_reoptimize_images');
        add_action('admin_notices', 'wp_theme_reoptimize_admin_notice');
    }
}


add_action('after_setup_theme', 'wp_theme_image_optimization_init');


/**
 * Log Image Editor failures so silent fails are visible.
 *
 * @since 1.3.0
 * @param string          $context Context label (resize, save, load, …).
 * @param WP_Error|mixed  $error   Error object or message.
 * @param string          $file    File path.
 */
function wp_theme_log_image_editor_error(string $context, $error, string $file = ''): void {
    $message = is_wp_error($error) ? $error->get_error_message() : (string) $error;

    if ('' === $message) {
        $message = 'Unknown image editor error.';
    }

    error_log(
        sprintf(
            '[wp-theme image-opt] %s failed%s: %s',
            $context,
            $file ? ' for ' . $file : '',
            $message
        )
    );
}


/**
 * Resolve compression quality for a mime type from theme settings.
 *
 * @since 1.3.0
 * @param string $mime_type Image mime type.
 * @return int|null Quality 1–100, or null to keep editor default.
 */
function wp_theme_get_image_opt_quality_for_mime(string $mime_type): ?int {
    if (! wp_theme_is_image_upload_opt_enabled()) {
        return null;
    }

    $settings = wp_theme_get_settings();

    if (in_array($mime_type, array('image/jpeg', 'image/jpg'), true)) {
        return (int) $settings['image_opt_jpeg_quality'];
    }

    return (int) $settings['image_opt_quality_value'];
}


/**
 * Raise Imagick resource limits so large camera JPEGs can be decoded.
 *
 * Shared-host defaults often hit "cache resources exhausted" on 4K–8K images.
 *
 * @since 1.3.0
 */
function wp_theme_configure_imagick_resources(): void {
    if (! class_exists('Imagick') || ! is_callable(array('Imagick', 'setResourceLimit'))) {
        return;
    }

    $limits = array(
        'RESOURCETYPE_AREA'   => 256 * 1024 * 1024,
        'RESOURCETYPE_DISK'   => 2 * 1024 * 1024 * 1024,
        'RESOURCETYPE_FILE'   => 192,
        'RESOURCETYPE_MAP'    => 512 * 1024 * 1024,
        'RESOURCETYPE_MEMORY' => 256 * 1024 * 1024,
    );

    foreach ($limits as $constant_name => $value) {
        if (! defined('Imagick::' . $constant_name)) {
            continue;
        }

        try {
            Imagick::setResourceLimit(constant('Imagick::' . $constant_name), $value);
        } catch (Exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Host may forbid changing Magick limits; GD fallback still available.
        }
    }
}


/**
 * Whether an Image Editor error looks like Imagick cache/memory exhaustion.
 *
 * @since 1.3.0
 * @param WP_Error|mixed $error Error from editor load/resize/save.
 */
function wp_theme_is_imagick_resource_error($error): bool {
    if (! is_wp_error($error)) {
        return false;
    }

    $message = strtolower((string) $error->get_error_message());

    return (str_contains($message, 'cache resources exhausted'))
        || (str_contains($message, 'openpixelcache'))
        || (str_contains($message, 'memory allocation failed'))
        || (str_contains($message, 'insufficient memory'));
}


/**
 * Force GD-only editor list after Imagick cache failure.
 *
 * @since 1.3.0
 * @param string[] $editors Editor class names.
 * @return string[]
 */
function wp_theme_image_editors_gd_only(array $editors): array {
    unset($editors);
    return array('WP_Image_Editor_GD');
}


/**
 * Run resize/re-encode with the current image editor stack.
 *
 * @since 1.3.0
 * @param string $file_path Absolute path.
 * @param string $mime_type Mime type.
 * @param bool   $needs_resize Whether to downscale.
 * @param bool   $needs_reencode Whether to re-encode.
 * @param int    $max_dimension Max long side.
 * @param bool   $force_gd Force GD editor.
 * @return true|WP_Error
 */
function wp_theme_optimize_image_file_with_editor(
    string $file_path,
    string $mime_type,
    bool $needs_resize,
    bool $needs_reencode,
    int $max_dimension,
    bool $force_gd = false
) {
    if ($force_gd) {
        add_filter('wp_image_editors', 'wp_theme_image_editors_gd_only', 100);
    } else {
        wp_theme_configure_imagick_resources();
    }

    $editor = wp_get_image_editor($file_path);

    if ($force_gd) {
        remove_filter('wp_image_editors', 'wp_theme_image_editors_gd_only', 100);
    }

    if (is_wp_error($editor)) {
        wp_theme_log_image_editor_error($force_gd ? 'load_gd' : 'load', $editor, $file_path);
        return $editor;
    }

    if ($needs_resize) {
        $resized = $editor->resize($max_dimension, $max_dimension, false);
        if (is_wp_error($resized)) {
            wp_theme_log_image_editor_error($force_gd ? 'resize_gd' : 'resize', $resized, $file_path);
            unset($editor);
            return $resized;
        }
    }

    $quality = wp_theme_get_image_opt_quality_for_mime($mime_type);
    if (null !== $quality) {
        $quality_set = $editor->set_quality($quality);
        if (is_wp_error($quality_set)) {
            wp_theme_log_image_editor_error('set_quality', $quality_set, $file_path);
            unset($editor);
            return $quality_set;
        }
    }

    $directory = dirname($file_path);
    $basename  = wp_basename($file_path);
    $temp_path = trailingslashit($directory) . 'opt-' . wp_unique_filename($directory, $basename);
    $saved     = $editor->save($temp_path, $mime_type);

    unset($editor);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    if (is_wp_error($saved)) {
        wp_theme_log_image_editor_error($force_gd ? 'save_gd' : 'save', $saved, $file_path);
        if (file_exists($temp_path)) {
            wp_delete_file($temp_path);
        }

        return $saved;
    }

    $saved_path = $saved['path'] ?? $temp_path;

    if (! file_exists($saved_path)) {
        $error = new WP_Error('image_opt_save_missing', __('Optimized file was not written.', 'wp-theme'));
        wp_theme_log_image_editor_error('save', $error, $file_path);
        return $error;
    }

    $original_size = (int) filesize($file_path);
    $new_size      = (int) filesize($saved_path);

    if (! $needs_resize && $needs_reencode && $new_size >= $original_size) {
        wp_delete_file($saved_path);
        return true;
    }

    $replaced = rename($saved_path, $file_path);
    if (! $replaced) {
        $copied = copy($saved_path, $file_path);
        wp_delete_file($saved_path);
        if (! $copied) {
            $error = new WP_Error('image_opt_replace', __('Unable to replace image with optimized file.', 'wp-theme'));
            wp_theme_log_image_editor_error('replace', $error, $file_path);
            return $error;
        }
    }

    clearstatcache(true, $file_path);

    return true;
}


/**
 * Optimize a raster image file in place (resize and/or re-encode).
 *
 * @since 1.3.0
 * @param string $file_path Absolute path to the image file.
 * @param string $mime_type Mime type.
 * @param array  $args {
 *     Optional. Override defaults from theme settings.
 *
 *     @type bool     $allow_resize  Whether to downscale oversized images.
 *     @type bool     $allow_reencode Whether to re-encode with quality settings.
 *     @type int|null $max_dimension Max long side in px.
 * }
 * @return true|WP_Error True when unchanged or optimized; WP_Error on failure.
 */
function wp_theme_optimize_image_file(string $file_path, string $mime_type = '', array $args = []) {
    if (! file_exists($file_path) || ! is_readable($file_path) || ! is_writable($file_path)) {
        $error = new WP_Error('image_opt_unreadable', __('Image file is missing or not writable.', 'wp-theme'));
        wp_theme_log_image_editor_error('access', $error, $file_path);
        return $error;
    }

    if ('' === $mime_type) {
        $filetype  = wp_check_filetype($file_path);
        $mime_type = $filetype['type'] ?: '';
    }

    if (! in_array($mime_type, wp_theme_image_opt_supported_mimes(), true)) {
        return true;
    }

    $settings = wp_theme_get_settings();
    $enabled  = wp_theme_is_image_upload_opt_enabled();
    $args     = wp_parse_args(
        $args,
        array(
            'allow_resize'   => $enabled,
            'allow_reencode' => $enabled,
            'max_dimension'  => (int) $settings['image_opt_max_dimension'],
        )
    );

    $image_size = @getimagesize($file_path);
    if (! $image_size) {
        $error = new WP_Error('image_opt_getsize', __('Unable to read image dimensions.', 'wp-theme'));
        wp_theme_log_image_editor_error('getimagesize', $error, $file_path);
        return $error;
    }

    $width          = $image_size[0];
    $height         = $image_size[1];
    $max_dimension  = max(1, (int) $args['max_dimension']);
    $needs_resize   = ! empty($args['allow_resize']) && max($width, $height) > $max_dimension;
    $needs_reencode = ! empty($args['allow_reencode']);

    if (! $needs_resize && ! $needs_reencode) {
        return true;
    }

    $previous_memory = ini_get('memory_limit');
    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('image');
    } else {
        // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
        @ini_set('memory_limit', '512M');
    }

    $result = wp_theme_optimize_image_file_with_editor(
        $file_path,
        $mime_type,
        $needs_resize,
        $needs_reencode,
        $max_dimension,
        false
    );

    // Shared hosting Imagick often fails on large JPEGs — retry once with GD.
    if (is_wp_error($result) && wp_theme_is_imagick_resource_error($result)) {
        wp_theme_log_image_editor_error('imagick_fallback_gd', $result, $file_path);
        $result = wp_theme_optimize_image_file_with_editor(
            $file_path,
            $mime_type,
            $needs_resize,
            $needs_reencode,
            $max_dimension,
            true
        );
    }

    if (is_string($previous_memory) && '' !== $previous_memory) {
        // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
        @ini_set('memory_limit', $previous_memory);
    }

    return $result;
}


/**
 * Reject uploads that exceed the configured max file size (MB).
 *
 * Files over the limit are rejected (not compressed). Dimension/quality
 * optimization runs later on wp_handle_upload after a successful move.
 *
 * @since 1.0.0
 * @param array $file Single $_FILES element.
 */
function wp_theme_optimize_image_upload(array $file): array {
    if (! empty($file['error'])) {
        return $file;
    }

    $file_type = wp_check_filetype($file['name']);
    $is_image  = str_starts_with((string) $file['type'], 'image/')
        || in_array($file_type['ext'], array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'svg'), true);

    if (! $is_image) {
        return $file;
    }

    $settings = wp_theme_get_settings();
    $max_size = (int) $settings['image_opt_max_file_size'] * 1024 * 1024;

    if ((int) $file['size'] > $max_size) {
        $file['error'] = sprintf(
            /* translators: %d: maximum upload size in megabytes */
            __('Image is too large. Maximum size is %d MB.', 'wp-theme'),
            (int) $settings['image_opt_max_file_size']
        );
    }

    return $file;
}


/**
 * Resize and/or re-encode the file after it was moved into uploads.
 *
 * Runs before wp_generate_attachment_metadata(), so intermediate sizes
 * are built from the already-optimized original.
 *
 * @since 1.3.0
 * @param array  $upload  Upload data (file, url, type).
 * @param string $context Upload context (upload|sideload).
 */
function wp_theme_optimize_uploaded_file(array $upload, string $context = 'upload'): array {
    unset($context);

    if (! empty($upload['error']) || empty($upload['file']) || empty($upload['type'])) {
        return $upload;
    }

    if (! in_array($upload['type'], wp_theme_image_opt_supported_mimes(), true)) {
        return $upload;
    }

    $result = wp_theme_optimize_image_file($upload['file'], $upload['type']);
    if (is_wp_error($result)) {
        // Do not fail the upload; WP big_image_size_threshold may still help.
        return $upload;
    }

    return $upload;
}


/**
 * Remove intermediate image sizes based on theme settings.
 *
 * @since 1.0.0
 */
function wp_theme_remove_intermediate_sizes(): void {
    $settings        = wp_theme_get_settings();
    $sizes_to_remove = $settings['image_opt_remove_sizes_list'];

    foreach ($sizes_to_remove as $size) {
        remove_image_size($size);
    }
}


/**
 * Filter intermediate image sizes to prevent generation of unwanted sizes.
 *
 * @since 1.0.0
 * @param array $sizes Intermediate sizes.
 */
function wp_theme_filter_intermediate_sizes(array $sizes): array {
    $settings        = wp_theme_get_settings();
    $sizes_to_remove = $settings['image_opt_remove_sizes_list'];

    foreach ($sizes_to_remove as $size_to_remove) {
        unset($sizes[ $size_to_remove ]);
    }

    return $sizes;
}


/**
 * Fix image metadata to prevent undefined array key errors.
 * Ensures width and height are always present in image metadata.
 *
 * @since 1.0.0
 * @param array|false $metadata      Attachment metadata.
 * @param int         $attachment_id Attachment ID.
 * @return array
 */
function wp_theme_fix_image_metadata($metadata, $attachment_id) {
    if (! is_array($metadata)) {
        $metadata = array();
    }

    if (! isset($metadata['width']) || ! isset($metadata['height'])) {
        $file_path = get_attached_file($attachment_id);
        if ($file_path && file_exists($file_path)) {
            $image_info = getimagesize($file_path);
            if ($image_info) {
                $metadata['width']  = $image_info[0];
                $metadata['height'] = $image_info[1];
            }
        }

        $metadata['width']  ??= 0;
        $metadata['height'] ??= 0;
    }

    $metadata['sizes'] ??= array();

    return $metadata;
}


/**
 * Set big image size threshold as a WordPress fallback scaler.
 *
 * Primary resize happens in wp_theme_optimize_image_file() on upload.
 * This remains in case the Image Editor path fails silently.
 *
 * @since 1.0.0
 * @param int|false $threshold Current threshold.
 */
function wp_theme_set_big_image_size_threshold($threshold): int {
    unset($threshold);
    $settings = wp_theme_get_settings();
    return (int) $settings['image_opt_max_dimension'];
}


/**
 * Delete WordPress-kept full original after a successful -scaled downscale.
 *
 * Primary upload path already overwrites the file in place. This covers the
 * WP big_image_size_threshold fallback that still stores original_image.
 *
 * @since 1.3.0
 * @param array $metadata      Attachment metadata.
 * @param int   $attachment_id Attachment ID.
 */
function wp_theme_maybe_delete_full_original(array $metadata, int $attachment_id): array {
    if (empty($metadata['original_image'])) {
        return $metadata;
    }

    $attached = get_attached_file($attachment_id);
    if (! $attached) {
        return $metadata;
    }

    // Prefer path from in-progress metadata: DB meta is not updated yet during this filter.
    $original_path = path_join(dirname($attached), $metadata['original_image']);

    if ($original_path && file_exists($original_path)) {
        // Never delete the file that is currently the attached main file.
        if (realpath($original_path) !== realpath($attached)) {
            wp_delete_file($original_path);
        }
    }

    unset($metadata['original_image']);

    return $metadata;
}


/**
 * Add priority loading for above-the-fold images.
 *
 * @since 1.0.0
 * @param array        $attr       Image attributes.
 * @param WP_Post      $attachment Attachment post.
 * @param string|int[] $_size      Requested size.
 */
function wp_theme_add_priority_loading(array $attr, $attachment, $_size): array {
    unset($_size);

    if (is_singular() && has_post_thumbnail() && $attachment->ID === get_post_thumbnail_id()) {
        $attr['fetchpriority'] = 'high';
    }

    if (! isset($attr['decoding'])) {
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
    return (int) $settings['image_opt_jpeg_quality'];
}


/**
 * Set image quality for non-JPEG formats (and JPEG via wp_editor_set_quality).
 *
 * @since 1.0.0
 * @param int    $quality   Default quality.
 * @param string $mime_type Mime type being saved.
 */
function wp_theme_set_image_quality(int $quality = 82, string $mime_type = 'image/jpeg'): int {
    unset($quality);
    $resolved = wp_theme_get_image_opt_quality_for_mime($mime_type);
    return $resolved ?? (int) wp_theme_get_settings()['image_opt_quality_value'];
}


/**
 * Generate responsive image HTML.
 *
 * @since 1.0.0
 * @param int          $attachment_id Attachment ID.
 * @param string|int[] $size          Image size.
 * @param array        $attr          Extra attributes.
 */
function wp_theme_get_responsive_image($attachment_id, $size = 'large', $attr = []): string {
    $image = wp_get_attachment_image_src($attachment_id, $size);

    if (! $image) {
        return '';
    }

    $srcset = wp_get_attachment_image_srcset($attachment_id, $size);
    $sizes  = wp_get_attachment_image_sizes($attachment_id, $size);
    $alt    = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    $default_attr = array(
        'src'      => $image[0],
        'alt'      => $alt ?: get_the_title($attachment_id),
        'class'    => 'responsive-image',
        'decoding' => 'async',
    );

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
 * Whether an attachment should be re-optimized.
 *
 * Targets images wider/taller than max dimension, or with a kept full original.
 *
 * @since 1.3.0
 * @param int $attachment_id Attachment ID.
 */
function wp_theme_attachment_needs_reoptimize(int $attachment_id): bool {
    $settings      = wp_theme_get_settings();
    $max_dimension = (int) $settings['image_opt_max_dimension'];
    $metadata      = wp_get_attachment_metadata($attachment_id);

    if (! empty($metadata['original_image'])) {
        return true;
    }

    $width  = isset($metadata['width']) ? (int) $metadata['width'] : 0;
    $height = isset($metadata['height']) ? (int) $metadata['height'] : 0;

    if (max($width, $height) > $max_dimension) {
        return true;
    }

    $file = get_attached_file($attachment_id);
    if ($file && file_exists($file)) {
        $size = @getimagesize($file);
        if ($size && max($size[0], $size[1]) > $max_dimension) {
            return true;
        }
    }

    return false;
}


/**
 * Re-optimize a single attachment and regenerate metadata.
 *
 * @since 1.3.0
 * @param int $attachment_id Attachment ID.
 * @return true|WP_Error
 */
function wp_theme_reoptimize_attachment(int $attachment_id) {
    if ('attachment' !== get_post_type($attachment_id) || ! wp_attachment_is_image($attachment_id)) {
        $error = new WP_Error('image_opt_not_image', __('Attachment is not an image.', 'wp-theme'));
        wp_theme_log_image_editor_error('reoptimize', $error, 'attachment #' . $attachment_id);
        return $error;
    }

    $mime_type = get_post_mime_type($attachment_id);
    if (! in_array($mime_type, wp_theme_image_opt_supported_mimes(), true)) {
        $error = new WP_Error(
            'image_opt_unsupported',
            sprintf(
                /* translators: %s: mime type */
                __('Image type is not supported for optimization: %s', 'wp-theme'),
                $mime_type ?: __('unknown', 'wp-theme')
            )
        );
        wp_theme_log_image_editor_error('reoptimize', $error, 'attachment #' . $attachment_id);
        return $error;
    }

    $attached = get_attached_file($attachment_id);
    if (! $attached || ! file_exists($attached)) {
        $error = new WP_Error('image_opt_missing', __('Attached file is missing on disk.', 'wp-theme'));
        wp_theme_log_image_editor_error('reoptimize', $error, 'attachment #' . $attachment_id);
        return $error;
    }

    $original_path = function_exists('wp_get_original_image_path')
        ? (string) wp_get_original_image_path($attachment_id)
        : '';

    $source = ($original_path && file_exists($original_path)) ? $original_path : $attached;

    // If working from a separate full original, optimize a copy onto the attached path.
    if ($source !== $attached) {
        if (! @copy($source, $attached)) {
            $error = new WP_Error('image_opt_copy', __('Unable to copy original image onto attached file.', 'wp-theme'));
            wp_theme_log_image_editor_error('copy_original', $error, $source);
            return $error;
        }
    }

    $result = wp_theme_optimize_image_file(
        $attached,
        $mime_type,
        array(
            'allow_resize'   => true,
            'allow_reencode' => true,
        )
    );

    if (is_wp_error($result)) {
        return $result;
    }

    // Drop the separate full original we copied from — attached file is now the source of truth.
    if ($original_path && file_exists($original_path) && realpath($original_path) !== realpath($attached)) {
        wp_delete_file($original_path);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $metadata = wp_generate_attachment_metadata($attachment_id, $attached);
    if (is_wp_error($metadata)) {
        wp_theme_log_image_editor_error('regenerate_metadata', $metadata, $attached);
        return $metadata;
    }

    if (is_array($metadata) && ! empty($metadata['original_image'])) {
        $metadata = wp_theme_maybe_delete_full_original($metadata, $attachment_id);
    }

    wp_update_attachment_metadata($attachment_id, $metadata);

    return true;
}


/**
 * Collect attachment IDs that need re-optimization.
 *
 * @since 1.3.0
 * @param int $limit Max number of IDs to return (0 = no limit).
 * @return int[]
 */
function wp_theme_get_reoptimize_candidate_ids(int $limit = 0): array {
    $query_args = array(
        'post_type'              => 'attachment',
        'post_mime_type'         => wp_theme_image_opt_supported_mimes(),
        'post_status'            => 'inherit',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );

    $ids        = get_posts($query_args);
    $candidates = array();

    foreach ($ids as $id) {
        $attachment_id = (int) $id;
        if (wp_theme_attachment_needs_reoptimize($attachment_id)) {
            $candidates[] = $attachment_id;
            if ($limit > 0 && count($candidates) >= $limit) {
                break;
            }
        }
    }

    return $candidates;
}


/**
 * Re-optimize multiple existing attachments.
 *
 * @since 1.3.0
 * @param int $limit Max attachments to process (0 = all candidates).
 * @return array{processed:int,success:int,failed:int,errors:array<int,string>}
 */
function wp_theme_reoptimize_existing_images(int $limit = 50): array {
    $ids    = wp_theme_get_reoptimize_candidate_ids($limit);
    $result = array(
        'processed' => 0,
        'success'   => 0,
        'failed'    => 0,
        'errors'    => array(),
    );

    foreach ($ids as $attachment_id) {
        ++$result['processed'];
        $optimized = wp_theme_reoptimize_attachment($attachment_id);
        if ($optimized instanceof WP_Error) {
            ++$result['failed'];
            $result['errors'][ $attachment_id ] = $optimized->get_error_message();
            continue;
        }

        ++$result['success'];
    }

    return $result;
}


/**
 * Admin-post handler: re-optimize oversized library images.
 *
 * @since 1.3.0
 */
function wp_theme_handle_reoptimize_images(): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to do this.', 'wp-theme'));
    }

    check_admin_referer('wp_theme_reoptimize_images');

    $limit  = isset($_GET['limit']) ? absint(wp_unslash($_GET['limit'])) : 50;
    $limit  = $limit > 0 ? min($limit, 200) : 50;
    $result = wp_theme_reoptimize_existing_images($limit);

    set_transient(
        'wp_theme_reoptimize_notice_' . get_current_user_id(),
        $result,
        MINUTE_IN_SECONDS * 5
    );

    $redirect = wp_get_referer();
    if (! $redirect) {
        $redirect = admin_url('themes.php?page=wp-theme-settings');
    }

    wp_safe_redirect($redirect);
    exit;
}


/**
 * Show admin notice after a re-optimize run.
 *
 * @since 1.3.0
 */
function wp_theme_reoptimize_admin_notice(): void {
    if (! current_user_can('manage_options')) {
        return;
    }

    $key    = 'wp_theme_reoptimize_notice_' . get_current_user_id();
    $result = get_transient($key);
    if (! is_array($result)) {
        return;
    }

    delete_transient($key);

    $message = sprintf(
        /* translators: 1: success count, 2: failed count, 3: processed count */
        __('Image optimization finished: %1$d done, %2$d failed (%3$d total).', 'wp-theme'),
        (int) ($result['success'] ?? 0),
        (int) ($result['failed'] ?? 0),
        (int) ($result['processed'] ?? 0)
    );

    $notice_class = empty($result['failed']) ? 'success' : 'warning';

    echo '<div class="notice notice-' . esc_attr($notice_class) . ' is-dismissible">';
    echo '<p>' . esc_html($message) . '</p>';

    $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array();
    if ($errors !== []) {
        echo '<ul style="list-style:disc;margin-left:1.5em;">';
        foreach ($errors as $attachment_id => $error_message) {
            $attachment_id = (int) $attachment_id;
            $edit_link     = get_edit_post_link($attachment_id, 'raw');
            $title         = get_the_title($attachment_id);
            if (! is_string($title) || '' === $title) {
                $title = '#' . $attachment_id;
            }

            echo '<li>';
            if ($edit_link) {
                echo '<a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a>';
            } else {
                echo esc_html($title);
            }

            echo ' — ' . esc_html((string) $error_message);
            echo '</li>';
        }

        echo '</ul>';
        echo '<p class="description">' . esc_html__('Details are also written to the PHP error log with the prefix [wp-theme image-opt].', 'wp-theme') . '</p>';
    }

    echo '</div>';
}


/**
 * Legacy helper: regenerate metadata for all images (no resize/re-encode).
 *
 * Prefer wp_theme_reoptimize_existing_images() for fixing heavy originals.
 *
 * @since 1.0.0
 */
function wp_theme_optimize_existing_images(): void {
    $attachments = get_posts(
        array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
        )
    );

    foreach ($attachments as $attachment) {
        $file_path = get_attached_file($attachment->ID);
        if ($file_path && file_exists($file_path)) {
            wp_generate_attachment_metadata($attachment->ID, $file_path);
        }
    }
}


/**
 * Register WP-CLI command for bulk re-optimization.
 *
 * ## OPTIONS
 *
 * [--limit=<n>]
 * : Max attachments to process. Default: 50. Use 0 for all candidates.
 *
 * [--dry-run]
 * : List candidates without modifying files.
 *
 * ## EXAMPLES
 *
 *     wp theme optimize-images
 *     wp theme optimize-images --limit=0
 *     wp theme optimize-images --dry-run
 *
 * @since 1.3.0
 * @param array $args       Positional args.
 * @param array $assoc_args Associative args.
 */
function wp_theme_cli_optimize_images(array $args = array(), array $assoc_args = array()): void {
    unset($args);

    if (! class_exists('WP_CLI')) {
        return;
    }

    $limit   = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 50;
    $dry_run = isset($assoc_args['dry-run']);
    $ids     = wp_theme_get_reoptimize_candidate_ids(max($limit, 0));

    WP_CLI::log(sprintf('Found %d candidate attachment(s).', count($ids)));

    if ($dry_run) {
        foreach ($ids as $id) {
            WP_CLI::log('Would optimize attachment #' . $id);
        }

        WP_CLI::success('Dry run complete.');
        return;
    }

    $result = array(
        'processed' => 0,
        'success'   => 0,
        'failed'    => 0,
    );

    foreach ($ids as $attachment_id) {
        ++$result['processed'];
        $optimized = wp_theme_reoptimize_attachment($attachment_id);
        if ($optimized instanceof WP_Error) {
            ++$result['failed'];
            WP_CLI::warning(
                sprintf(
                    '#%d: %s',
                    $attachment_id,
                    $optimized->get_error_message()
                )
            );
            continue;
        }

        ++$result['success'];
        WP_CLI::log('Optimized attachment #' . $attachment_id);
    }

    WP_CLI::success(
        sprintf(
            'Done: %d success, %d failed (%d processed).',
            $result['success'],
            $result['failed'],
            $result['processed']
        )
    );
}


if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('theme optimize-images', 'wp_theme_cli_optimize_images');
}
