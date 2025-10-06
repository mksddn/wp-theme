<?php
/**
 * Plugin Name: Allow SVG Uploads
 * Description: Enables SVG file uploads in WordPress, fixes MIME type, and displays SVGs in the Media Library.
 * Author: mksddn
 * Version: 1.0
 */

// Allow SVG file uploads by adding SVG to the list of allowed MIME types.
add_filter( 'upload_mimes', 'svg_upload_allow' );


function svg_upload_allow( array $mimes ): array {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
}


// Fix the MIME type for SVG files to ensure compatibility with WordPress.
add_filter( 'wp_check_filetype_and_ext', 'fix_svg_mime_type', 10, 5 );


function fix_svg_mime_type( array $data, $file, $filename, $mimes, $real_mime = '' ): array {
    // For WordPress 5.1 and newer
    if (version_compare( $GLOBALS['wp_version'], '5.1.0', '>=' )) {
        $dosvg = in_array( $real_mime, [ 'image/svg', 'image/svg+xml' ] );
    } else {
        $dosvg = ( '.svg' === strtolower( substr( (string) $filename, -4 ) ) );
    }

    // Fix MIME type and validate user permissions
    if ($dosvg) {
        if (current_user_can( 'manage_options' )) {
            // Allow SVG uploads for administrators
            $data['ext']  = 'svg';
            $data['type'] = 'image/svg+xml';
        } else {
            // Block SVG uploads for other users
            $data['ext']  = false;
            $data['type'] = false;
        }
    }

    return $data;
}


// Display SVG files as images in the WordPress Media Library.
add_filter( 'wp_prepare_attachment_for_js', 'show_svg_in_media_library' );


function show_svg_in_media_library( array $response ): array {
    if ($response['mime'] === 'image/svg+xml') {
        // Add the file URL to the image preview field
        $response['image'] = [
            'src' => $response['url'],
        ];
    }

    return $response;
}
