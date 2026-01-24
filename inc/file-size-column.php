<?php
/*
Plugin Name: File Size Column for Media Library
Description: Adds a sortable column to the WordPress Media Library to display and sort files by size.
Version: 1.0
Author: mksddn
*/

// Add file size column to media library
define( 'FILESIZE_META_KEY', '_filesize' );
define( 'FILESIZE_BATCH_SIZE', 50 );
define( 'FILESIZE_UPDATE_PAGE_OPTION', 'filesize_metadata_update_page' );
define( 'FILESIZE_UPDATE_DONE_OPTION', 'filesize_metadata_update_done' );
define( 'FILESIZE_UPDATE_SCHEDULED_OPTION', 'filesize_metadata_update_scheduled' );


function add_filesize_column( array $columns ): array {
    $columns['filesize'] = __( 'File Size', 'wp-theme' );
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
            echo esc_html__( 'N/A', 'wp-theme' );
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
function save_filesize_metadata( $meta_id, $post_id, $meta_key, $_meta_value ): void {
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

// Update file size metadata for existing attachments in batches
function update_filesize_for_existing_attachments( int $page = 1, int $per_page = FILESIZE_BATCH_SIZE ): bool {
    $attachment_ids = get_posts(
        [
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]
    );

    if (empty( $attachment_ids )) {
        return false;
    }

    foreach ($attachment_ids as $attachment_id) {
        $file_path = get_attached_file( $attachment_id );
        if (file_exists( $file_path )) {
            $file_size = filesize( $file_path );
            update_post_meta( $attachment_id, FILESIZE_META_KEY, $file_size );
        }
    }

    return true;
}


function run_filesize_metadata_batch(): void {
    $page = (int) get_option( FILESIZE_UPDATE_PAGE_OPTION, 1 );
    $has_results = update_filesize_for_existing_attachments( $page );

    if ($has_results) {
        update_option( FILESIZE_UPDATE_PAGE_OPTION, $page + 1, false );
        return;
    }

    delete_option( FILESIZE_UPDATE_PAGE_OPTION );
    update_option( FILESIZE_UPDATE_DONE_OPTION, 1, false );
    delete_option( FILESIZE_UPDATE_SCHEDULED_OPTION );
}


// Schedule metadata update once in admin
function maybe_schedule_filesize_metadata_update(): void {
    if (! is_admin()) {
        return;
    }

    if (get_option( FILESIZE_UPDATE_DONE_OPTION )) {
        return;
    }

    if (get_option( FILESIZE_UPDATE_SCHEDULED_OPTION )) {
        return;
    }

    if (! current_user_can( 'manage_options' )) {
        return;
    }

    update_option( FILESIZE_UPDATE_SCHEDULED_OPTION, 1, false );
    wp_schedule_single_event( time() + 60, 'filesize_metadata_batch' );
}


add_action( 'filesize_metadata_batch', 'run_filesize_metadata_batch' );
add_action( 'admin_init', 'maybe_schedule_filesize_metadata_update' );
