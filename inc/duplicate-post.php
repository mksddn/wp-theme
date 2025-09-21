<?php
/**
 * Duplicate Post feature (theme-level).
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Add Duplicate link into posts/pages list actions.
 */
function theme_dp_add_link(array $actions, $post): array {
    if (! current_user_can('edit_posts')) {
        return $actions;
    }

    $url = wp_nonce_url(admin_url('admin.php?action=duplicate_post&post=' . (int) $post->ID), 'duplicate_post_' . (int) $post->ID);
    $actions['duplicate'] = sprintf(
        '<a href="%s" title="%s" rel="permalink">%s</a>',
        esc_url($url),
        esc_attr__('Duplicate this post', 'textdomain'),
        esc_html__('Duplicate', 'textdomain')
    );

    return $actions;
}


add_action('admin_init', function (): void {
    add_filter('post_row_actions', 'theme_dp_add_link', 10, 2);
    add_filter('page_row_actions', 'theme_dp_add_link', 10, 2);
});


/**
 * Handle duplicate action.
 */
function theme_dp_handle_action(): void {
    $action_raw = filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW);
    $action = is_string($action_raw) ? sanitize_text_field($action_raw) : '';
    if ($action !== 'duplicate_post') {
        return;
    }

    $post_id_raw = filter_input(INPUT_GET, 'post', FILTER_UNSAFE_RAW);
    $post_id = (int) $post_id_raw;
    $nonce_raw = filter_input(INPUT_GET, '_wpnonce', FILTER_UNSAFE_RAW);
    $nonce = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';

    if ($post_id <= 0 || empty($nonce)) {
        wp_die(esc_html__('Invalid parameters', 'textdomain'));
    }

    if (! wp_verify_nonce($nonce, 'duplicate_post_' . $post_id)) {
        wp_die(esc_html__('Security error', 'textdomain'));
    }

    if (! current_user_can('edit_posts')) {
        wp_die(esc_html__('Insufficient permissions', 'textdomain'));
    }

    $original_post = get_post($post_id);
    if (! $original_post) {
        wp_die(esc_html__('Post not found', 'textdomain'));
    }

    $duplicate_id = theme_dp_duplicate_post($original_post);
    if ($duplicate_id) {
        wp_safe_redirect(admin_url('post.php?post=' . (int) $duplicate_id . '&action=edit'));
        exit;
    }

    wp_die(esc_html__('Error creating duplicate', 'textdomain'));
}


add_action('admin_init', 'theme_dp_handle_action');


/**
 * Duplicate post with meta, taxonomies, thumbnail and attachments.
 */
function theme_dp_duplicate_post($original_post) {
    $post_data = [
        'post_title'            => $original_post->post_title . ' (copy)',
        'post_content'          => $original_post->post_content,
        'post_excerpt'          => $original_post->post_excerpt,
        'post_status'           => 'draft',
        'post_type'             => $original_post->post_type,
        'post_author'           => get_current_user_id(),
        'post_parent'           => $original_post->post_parent,
        'menu_order'            => $original_post->menu_order,
        'comment_status'        => $original_post->comment_status,
        'ping_status'           => $original_post->ping_status,
        'post_password'         => $original_post->post_password,
        'to_ping'               => $original_post->to_ping,
        'pinged'                => $original_post->pinged,
        'post_content_filtered' => $original_post->post_content_filtered,
        'post_mime_type'        => $original_post->post_mime_type,
        'guid'                  => '',
    ];

    $duplicate_id = wp_insert_post($post_data);
    if (is_wp_error($duplicate_id)) {
        return false;
    }

    // Taxonomies
    $taxonomies = get_object_taxonomies($original_post->post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($original_post->ID, $taxonomy, [ 'fields' => 'slugs' ]);
        wp_set_object_terms($duplicate_id, $terms, $taxonomy, false);
    }

    // Meta
    $meta_keys = get_post_custom_keys($original_post->ID);
    if ($meta_keys) {
        foreach ($meta_keys as $meta_key) {
            $meta_values = get_post_meta($original_post->ID, $meta_key, false);
            foreach ($meta_values as $meta_value) {
                add_post_meta($duplicate_id, $meta_key, $meta_value);
            }
        }
    }

    // ACF
    if (function_exists('get_fields') && function_exists('update_field')) {
        $acf_fields = get_fields($original_post->ID);
        if ($acf_fields) {
            foreach ($acf_fields as $field_key => $field_value) {
                update_field($field_key, $field_value, $duplicate_id);
            }
        }
    }

    // Thumbnail
    if (has_post_thumbnail($original_post->ID)) {
        $thumbnail_id = get_post_thumbnail_id($original_post->ID);
        set_post_thumbnail($duplicate_id, $thumbnail_id);
    }

    // Attachments for posts/pages
    if (in_array($original_post->post_type, [ 'post', 'page' ], true)) {
        $attachments = get_posts([
            'post_type'   => 'attachment',
            'post_parent' => $original_post->ID,
            'numberposts' => -1,
            'post_status' => 'any',
        ]);

        foreach ($attachments as $attachment) {
            $attachment_data = [
                'post_title'     => $attachment->post_title,
                'post_content'   => $attachment->post_content,
                'post_excerpt'   => $attachment->post_excerpt,
                'post_status'    => $attachment->post_status,
                'post_type'      => 'attachment',
                'post_parent'    => $duplicate_id,
                'menu_order'     => $attachment->menu_order,
                'post_mime_type' => $attachment->post_mime_type,
                'guid'           => '',
            ];

            $new_attachment_id = wp_insert_post($attachment_data);
            if (! is_wp_error($new_attachment_id)) {
                $attachment_meta_keys = get_post_custom_keys($attachment->ID);
                if ($attachment_meta_keys) {
                    foreach ($attachment_meta_keys as $meta_key) {
                        $meta_values = get_post_meta($attachment->ID, $meta_key, false);
                        foreach ($meta_values as $meta_value) {
                            add_post_meta($new_attachment_id, $meta_key, $meta_value);
                        }
                    }
                }
            }
        }
    }

    return $duplicate_id;
}


/**
 * Admin notice after duplication (optional, URL param: duplicated=1).
 */
function theme_dp_admin_notice(): void {
    $duplicated_raw = filter_input(INPUT_GET, 'duplicated', FILTER_UNSAFE_RAW);
    $duplicated = is_string($duplicated_raw) ? sanitize_text_field($duplicated_raw) : '';
    if ($duplicated === '1') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Post successfully duplicated!', 'textdomain') . '</p></div>';
    }
}


add_action('admin_notices', 'theme_dp_admin_notice');


