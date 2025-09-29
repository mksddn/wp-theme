<?php
/**
 * Category thumbnails functionality.
 *
 * @package wp-theme
 */

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Add thumbnail field to category forms.
 */
function add_category_thumbnail_field(): void {
    ?>
    <div class="form-field">
        <label for="category_thumbnail"><?php esc_html_e('Category Thumbnail', 'wp-theme'); ?></label>
        <input type="hidden" id="category_thumbnail" name="category_thumbnail" value="" />
        <div id="category_thumbnail_preview" style="margin-top: 10px;"></div>
        <button type="button" class="button" id="upload_category_thumbnail" aria-label="<?php esc_attr_e('Upload category thumbnail image', 'wp-theme'); ?>"><?php esc_html_e('Upload Image', 'wp-theme'); ?>Upload Image</button>
        <button type="button" class="button" id="remove_category_thumbnail" style="display: none;" aria-label="<?php esc_attr_e('Remove category thumbnail image', 'wp-theme'); ?>"><?php esc_html_e('Remove Image', 'wp-theme'); ?>Remove Image</button>
    </div>
    <?php
}


function edit_category_thumbnail_field($term): void {
    $thumbnail_id = get_term_meta($term->term_id, 'category_thumbnail', true);
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="category_thumbnail"><?php esc_html_e('Category Thumbnail', 'wp-theme'); ?></label>
        </th>
        <td>
            <input type="hidden" id="category_thumbnail" name="category_thumbnail" value="<?php echo esc_attr($thumbnail_id); ?>" />
            <div id="category_thumbnail_preview" style="margin-top: 10px;">
                <?php if ($thumbnail_id): ?>
                    <?php echo wp_get_attachment_image($thumbnail_id, 'thumbnail'); ?>
                <?php endif; ?>
            </div>
            <button type="button" class="button" id="upload_category_thumbnail" aria-label="<?php esc_attr_e('Upload category thumbnail image', 'wp-theme'); ?>"><?php esc_html_e('Upload Image', 'wp-theme'); ?>Upload Image</button>
            <button type="button" class="button" id="remove_category_thumbnail" 
                <?php echo $thumbnail_id ? '' : 'style="display: none;"'; ?> 
                aria-label="<?php esc_attr_e('Remove category thumbnail image', 'wp-theme'); ?>">
                <?php esc_html_e('Remove Image', 'wp-theme'); ?>Remove Image
            </button>
        </td>
    </tr>
    <?php
}


/**
 * Save category thumbnail.
 */
function save_category_thumbnail(string $term_id): void {
    // Verify nonce for security
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-tag_' . $term_id)) {
        return;
    }

    if (isset($_POST['category_thumbnail'])) {
        $thumbnail_id = sanitize_text_field(wp_unslash($_POST['category_thumbnail']));
        update_term_meta($term_id, 'category_thumbnail', $thumbnail_id);
    }
}


/**
 * Get category thumbnail.
 */
function get_category_thumbnail($term_id, $size = 'medium'): ?string {
    $thumbnail_id = get_term_meta($term_id, 'category_thumbnail', true);

    if (!$thumbnail_id) {
        return null;
    }

    return wp_get_attachment_image($thumbnail_id, $size, false, [
        'alt' => get_term($term_id)->name ?? '',
        'class' => 'category-thumbnail'
    ]);
}


/**
 * Display category thumbnail.
 */
function the_category_thumbnail($term_id, $size = 'medium'): void {
    $thumbnail = get_category_thumbnail($term_id, $size);
    if ($thumbnail) {
        echo wp_kses_post($thumbnail);
    }
}


/**
 * Check if category has thumbnail.
 */
function category_has_thumbnail($term_id): bool {
    return !empty(get_term_meta($term_id, 'category_thumbnail', true));
}


/**
 * Admin scripts for media uploader.
 */
function category_thumbnail_admin_scripts(): void {
    $screen = get_current_screen();
    if ($screen && in_array($screen->id, ['edit-category', 'category'])) {
        wp_enqueue_media();
        $admin_js_uri = get_theme_file_uri( 'js/category-thumbnail-admin.js' );
        wp_enqueue_script('category-thumbnail-admin', $admin_js_uri, ['jquery'], wp_get_theme( get_stylesheet() )->get('Version'), true);
    }
}


/**
 * Register category_thumbnail field for REST API.
 */
function register_category_thumbnail_rest_field(): void {
    register_rest_field('category', 'thumbnail', [
        'get_callback' => 'get_category_thumbnail_rest',
        'update_callback' => 'update_category_thumbnail_rest',
        'schema' => [
            'description' => 'Category thumbnail image',
            'type' => 'object',
            'context' => ['view', 'edit'],
            'properties' => [
                'id' => [
                    'description' => 'Image ID',
                    'type' => 'integer',
                ],
                'url' => [
                    'description' => 'Image URL',
                    'type' => 'string',
                    'format' => 'uri',
                ],
                'sizes' => [
                    'description' => 'Image sizes',
                    'type' => 'object',
                ],
            ],
        ],
    ]);
}


/**
 * Get category thumbnail for REST API.
 */
function get_category_thumbnail_rest(array $object): ?array {
    $thumbnail_id = get_term_meta($object['id'], 'category_thumbnail', true);

    if (!$thumbnail_id) {
        return null;
    }

    $image = wp_get_attachment_image_src($thumbnail_id, 'full');
    if (!$image) {
        return null;
    }

    return [
        'id' => (int) $thumbnail_id,
        'url' => $image[0],
        'sizes' => [
            'thumbnail' => wp_get_attachment_image_src($thumbnail_id, 'thumbnail'),
            'medium' => wp_get_attachment_image_src($thumbnail_id, 'medium'),
            'large' => wp_get_attachment_image_src($thumbnail_id, 'large'),
            'full' => wp_get_attachment_image_src($thumbnail_id, 'full'),
        ],
    ];
}


/**
 * Update category thumbnail via REST API.
 */
function update_category_thumbnail_rest($value, $object): bool {
    if (!isset($value['id'])) {
        return false;
    }

    $thumbnail_id = (int) $value['id'];

    // Verify the attachment exists
    if (!wp_get_attachment_image_src($thumbnail_id)) {
        return false;
    }

    return update_term_meta($object->term_id, 'category_thumbnail', $thumbnail_id);
}


// Hooks
add_action('category_add_form_fields', 'add_category_thumbnail_field');
add_action('category_edit_form_fields', 'edit_category_thumbnail_field');
add_action('created_category', 'save_category_thumbnail');
add_action('edited_category', 'save_category_thumbnail');
add_action('admin_enqueue_scripts', 'category_thumbnail_admin_scripts');
add_action('rest_api_init', 'register_category_thumbnail_rest_field');
