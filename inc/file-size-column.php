<?php
/*
Plugin Name: File Size Column for Media Library
Description: Adds a sortable column to the WordPress Media Library to display and sort files by size.
Version: 1.0
Author: mksddn
*/

// Add file size column to media library
define( 'FILESIZE_META_KEY', '_filesize' );


function add_filesize_column( array $columns ): array {
    $columns['filesize'] = __( 'File Size', 'textdomain' );
    return $columns;
}


add_filter( 'manage_upload_columns', 'add_filesize_column' );


function display_filesize_column( $column_name, $post_id ): void {
    if ($column_name === 'filesize') {
        $file_path = get_attached_file( $post_id );
        if (file_exists( $file_path )) {
            $file_size = filesize( $file_path );
            echo esc_html( size_format( $file_size ) );
        } else {
            echo esc_html__( 'N/A', 'textdomain' );
        }
    }
}


add_action( 'manage_media_custom_column', 'display_filesize_column', 10, 2 );


function add_filesize_column_styles(): void {
    echo '<style>
        .column-filesize { width: 10%; }
    </style>';
}


add_action( 'admin_head', 'add_filesize_column_styles' );

// Make file size column sortable
function make_filesize_column_sortable( array $sortable_columns ): array {
    $sortable_columns['filesize'] = 'filesize';
    return $sortable_columns;
}


add_filter( 'manage_upload_sortable_columns', 'make_filesize_column_sortable' );


function sort_filesize_column( array $vars ) {
    if (isset( $vars['orderby'] ) && $vars['orderby'] === 'filesize') {
        return array_merge(
            $vars,
            [
                'meta_key' => FILESIZE_META_KEY,
                'orderby'  => 'meta_value_num',
            ]
        );
    }

    return $vars;
}


add_filter( 'request', 'sort_filesize_column' );

// Save file size metadata
function save_filesize_metadata( $meta_id, $post_id, $meta_key, $meta_value ): void {
    if ($meta_key === '_wp_attached_file') {
        $file_path = get_attached_file( $post_id );
        if (file_exists( $file_path )) {
            $file_size = filesize( $file_path );
            update_post_meta( $post_id, FILESIZE_META_KEY, $file_size );
        }
    }
}


add_action( 'added_post_meta', 'save_filesize_metadata', 10, 4 );
add_action( 'updated_post_meta', 'save_filesize_metadata', 10, 4 );


function update_filesize_on_upload( $post_id ): void {
    $file_path = get_attached_file( $post_id );
    if (file_exists( $file_path )) {
        $file_size = filesize( $file_path );
        update_post_meta( $post_id, FILESIZE_META_KEY, $file_size );
    }
}


add_action( 'add_attachment', 'update_filesize_on_upload' );

// Update file size metadata for existing attachments
function update_filesize_for_existing_attachments(): void {
    $attachments = get_posts(
        [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
        ]
    );

    foreach ($attachments as $attachment) {
        $file_path = get_attached_file( $attachment->ID );
        if (file_exists( $file_path )) {
            $file_size = filesize( $file_path );
            update_post_meta( $attachment->ID, FILESIZE_META_KEY, $file_size );
        }
    }
}


// Trigger metadata update on plugin load (for MU-Plugin)
function initialize_filesize_metadata_update(): void {
    update_filesize_for_existing_attachments();
}


initialize_filesize_metadata_update();
