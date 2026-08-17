<?php
/**
 * Content seeder: placeholder values for local development.
 *
 * Creates sample entities and fills core WordPress and ACF fields.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Path to the theme placeholder image file.
 *
 * @since 1.3.0
 */
function wp_theme_content_seed_get_placeholder_path(): string {
    $relative = apply_filters(
        'wp_theme_content_seed_placeholder_relative_path',
        'assets/images/placeholder.svg'
    );

    return trailingslashit(get_template_directory()) . ltrim($relative, '/');
}


/**
 * Normalize seeder run arguments.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Raw arguments.
 * @return array<string, mixed>
 */
function wp_theme_content_seed_normalize_args(array $args): array {
    $defaults = array(
        'dry_run'         => false,
        'overwrite'       => false,
        'create_entities' => true,
        'post_type'       => null,
        'limit'           => null,
    );

    $args = wp_parse_args($args, $defaults);

    if (isset($args['post_type']) && is_string($args['post_type']) && '' === $args['post_type']) {
        $args['post_type'] = null;
    }

    if (isset($args['limit'])) {
        $args['limit'] = max(0, (int) $args['limit']);
        if (0 === $args['limit']) {
            $args['limit'] = null;
        }
    }

    return $args;
}


/**
 * Whether a value should be treated as empty.
 *
 * @since 1.3.0
 * @param mixed $value Field value.
 */
function wp_theme_content_seed_is_empty($value): bool {
    if (null === $value || false === $value || '' === $value) {
        return true;
    }

    return is_array($value) && array() === $value;
}


/**
 * Initial stats array for a seeder run.
 *
 * @since 1.3.0
 * @return array<string, mixed>
 */
function wp_theme_content_seed_initial_stats(): array {
    return array(
        'created' => 0,
        'seeded'  => 0,
        'skipped' => 0,
        'failed'  => 0,
        'log'     => array(),
    );
}


/**
 * Target count of sample entities per post type or taxonomy.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Seeder run arguments.
 */
function wp_theme_content_seed_entities_per_type(array $args): int {
    if (! empty($args['limit'])) {
        return (int) $args['limit'];
    }

    return (int) apply_filters('wp_theme_content_seed_entities_per_type', 3, $args);
}


/**
 * Collect location rule values from active ACF field groups.
 *
 * @since 1.3.0
 * @param string $param ACF location param, e.g. post_type or taxonomy.
 * @return string[]
 */
function wp_theme_content_seed_get_acf_location_values(string $param): array {
    if (! function_exists('acf_get_field_groups')) {
        return array();
    }

    $values = array();
    $groups = acf_get_field_groups();

    if (! is_array($groups)) {
        return array();
    }

    foreach ($groups as $group) {
        if (! is_array($group) || empty($group['location']) || ! is_array($group['location'])) {
            continue;
        }

        foreach ($group['location'] as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                if (($rule['param'] ?? '') !== $param) {
                    continue;
                }

                if (($rule['operator'] ?? '==') !== '==') {
                    continue;
                }

                $value = $rule['value'] ?? '';

                if (is_string($value) && '' !== $value) {
                    $values[] = $value;
                }
            }
        }
    }

    return array_values(array_unique($values));
}


/**
 * Post types allowed for entity creation and seeding.
 *
 * Default: post, page, and public CPTs referenced in ACF field groups.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Seeder run arguments.
 * @return string[]
 */
function wp_theme_content_seed_get_allowed_post_types(array $args = array()): array {
    $base_types = array('post', 'page');
    $acf_types  = wp_theme_content_seed_get_acf_location_values('post_type');
    $post_types = array_values(
        array_unique(
            array_merge($base_types, $acf_types)
        )
    );

    $public_types = get_post_types(
        array(
            'public' => true,
        ),
        'names'
    );

    unset($public_types['attachment']);

    $post_types = array_values(
        array_intersect($post_types, $public_types)
    );

    /**
     * Filter allowed post types for the content seeder.
     *
     * @since 1.3.0
     * @param string[]             $post_types Allowed post type slugs.
     * @param array<string, mixed> $args       Seeder run arguments.
     */
    return apply_filters('wp_theme_content_seed_post_types', $post_types, $args);
}


/**
 * Taxonomies allowed for sample term creation and seeding.
 *
 * Default: category, post_tag, and taxonomies referenced in ACF field groups.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Seeder run arguments.
 * @return string[]
 */
function wp_theme_content_seed_get_allowed_taxonomies(array $args = array()): array {
    $base_taxonomies = array('category', 'post_tag');
    $acf_taxonomies  = wp_theme_content_seed_get_acf_location_values('taxonomy');
    $taxonomies      = array_values(
        array_unique(
            array_merge($base_taxonomies, $acf_taxonomies)
        )
    );

    $public_taxonomies = get_taxonomies(
        array(
            'public' => true,
        ),
        'names'
    );

    $taxonomies = array_values(
        array_intersect($taxonomies, $public_taxonomies)
    );

    /**
     * Filter allowed taxonomies for the content seeder.
     *
     * @since 1.3.0
     * @param string[]             $taxonomies Allowed taxonomy slugs.
     * @param array<string, mixed> $args       Seeder run arguments.
     */
    return apply_filters('wp_theme_content_seed_taxonomies', $taxonomies, $args);
}


/**
 * Public post types included in the seeder run.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Seeder run arguments.
 * @return string[]
 */
function wp_theme_content_seed_get_target_post_types(array $args): array {
    $post_types = wp_theme_content_seed_get_allowed_post_types($args);

    if (! empty($args['post_type'])) {
        $requested = (string) $args['post_type'];

        if (in_array($requested, $post_types, true)) {
            return array($requested);
        }

        return array();
    }

    return $post_types;
}


/**
 * Get or import the shared theme placeholder attachment.
 *
 * @since 1.3.0
 * @param bool $ensure Import the file when missing.
 */
function wp_theme_content_seed_get_placeholder_attachment_id(bool $ensure = true): ?int {
    static $cached = null;

    if (null !== $cached) {
        return $cached > 0 ? $cached : null;
    }

    $option_key = 'wp_theme_content_seed_placeholder_attachment_id';
    $stored     = (int) get_option($option_key, 0);

    if ($stored > 0 && get_post($stored) instanceof WP_Post) {
        $cached = $stored;

        return $stored;
    }

    $existing = get_posts(
        array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_wp_theme_content_seed_placeholder',
            'meta_value'     => '1',
        )
    );

    if (! empty($existing)) {
        $cached = (int) $existing[0];

        if ($ensure) {
            update_option($option_key, $cached);
        }

        return $cached;
    }

    if (! $ensure) {
        $cached = 0;

        return null;
    }

    $file_path = wp_theme_content_seed_get_placeholder_path();

    if (! is_readable($file_path)) {
        $cached = 0;

        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $file_name = basename($file_path);
    $contents  = file_get_contents($file_path);

    if (false === $contents) {
        $cached = 0;

        return null;
    }

    $upload = wp_upload_bits($file_name, null, $contents);

    if (! empty($upload['error'])) {
        $cached = 0;

        return null;
    }

    $file_type   = wp_check_filetype($file_name, null);
    $attachment  = array(
        'post_mime_type' => $file_type['type'] ?? 'image/svg+xml',
        'post_title'     => 'Theme Placeholder',
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    $attachment_id = wp_insert_attachment($attachment, $upload['file']);

    if (is_wp_error($attachment_id) || ! $attachment_id) {
        $cached = 0;

        return null;
    }

    $metadata = wp_generate_attachment_metadata((int) $attachment_id, $upload['file']);

    if (! is_wp_error($metadata) && is_array($metadata)) {
        wp_update_attachment_metadata((int) $attachment_id, $metadata);
    }

    update_post_meta((int) $attachment_id, '_wp_theme_content_seed_placeholder', '1');
    update_option($option_key, (int) $attachment_id);

    $cached = (int) $attachment_id;

    return $cached;
}


/**
 * Public URL for the theme placeholder image.
 *
 * @since 1.3.0
 */
function wp_theme_content_seed_get_placeholder_url(): string {
    $attachment_id = wp_theme_content_seed_get_placeholder_attachment_id(false);

    if ($attachment_id) {
        $url = wp_get_attachment_url($attachment_id);

        if (is_string($url) && '' !== $url) {
            return $url;
        }
    }

    return trailingslashit(get_template_directory_uri()) . 'assets/images/placeholder.svg';
}


/**
 * Rich HTML sample for post content and WYSIWYG fields.
 *
 * @since 1.3.0
 * @param string $title Context title.
 */
function wp_theme_content_seed_rich_html(string $title): string {
    $title     = wp_strip_all_tags($title);
    $image_url = wp_theme_content_seed_get_placeholder_url();
    $image     = sprintf(
        '<figure class="wp-block-image size-large"><img src="%1$s" alt="%2$s" loading="lazy" /></figure>',
        esc_url($image_url),
        esc_attr($title)
    );

    $html = sprintf(
        '<h2>%1$s</h2>
<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sample introduction for <strong>%1$s</strong>.</p>
<h3>Key points</h3>
<ul>
<li>First sample point with practical details.</li>
<li>Second point for layout testing.</li>
<li>Third point to check list spacing.</li>
</ul>
<blockquote><p>Sample quote block for %1$s — useful to preview typography.</p></blockquote>
%2$s
<h3>Numbered steps</h3>
<ol>
<li>Prepare the local environment.</li>
<li>Run the content seeder.</li>
<li>Review the generated layout.</li>
</ol>
<p>Closing paragraph with a <a href="https://example.com">sample link</a>.</p>',
        esc_html($title),
        $image
    );

    return apply_filters('wp_theme_content_seed_rich_html', $html, $title);
}


/**
 * Get first post ID for given post types.
 *
 * @since 1.3.0
 * @param string[] $post_types Allowed post types.
 */
function wp_theme_content_seed_get_sample_post_id(array $post_types = array()): ?int {
    if ([] === $post_types) {
        $post_types = wp_theme_content_seed_get_target_post_types(array());
    }

    foreach ($post_types as $post_type) {
        $posts = get_posts(
            array(
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
            )
        );

        if (! empty($posts)) {
            return (int) $posts[0];
        }
    }

    return null;
}


/**
 * Get first term ID for a taxonomy.
 *
 * @since 1.3.0
 * @param string $taxonomy Taxonomy slug.
 */
function wp_theme_content_seed_get_sample_term_id(string $taxonomy): ?int {
    $terms = get_terms(
        array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 1,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        )
    );

    if (is_wp_error($terms) || empty($terms)) {
        return null;
    }

    return (int) $terms[0]->term_id;
}


/**
 * Create missing sample posts for public post types.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args  Seeder run arguments.
 * @param array<string, mixed> $stats Mutable stats accumulator.
 */
function wp_theme_content_seed_ensure_posts(array $args, array &$stats): void {
    if (empty($args['create_entities'])) {
        return;
    }

    $per_type = wp_theme_content_seed_entities_per_type($args);

    foreach (wp_theme_content_seed_get_target_post_types($args) as $post_type) {
        $existing = get_posts(
            array(
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        $needed = max(0, $per_type - count($existing));

        for ($i = 1; $i <= $needed; $i++) {
            $index      = count($existing) + $i;
            $type_obj   = get_post_type_object($post_type);
            $type_label = $type_obj->labels->singular_name ?? $post_type;
            $title      = sprintf('Sample %s %d', $type_label, $index);
            $slug       = sanitize_title(sprintf('sample-%s-%d', $post_type, $index));

            if ($args['dry_run']) {
                ++$stats['created'];
                $stats['log'][] = sprintf(
                    /* translators: 1: post type slug, 2: post title */
                    __('[dry-run] Would create %1$s: %2$s', 'wp-theme'),
                    $post_type,
                    $title
                );
                continue;
            }

            $post_id = wp_insert_post(
                array(
                    'post_type'    => $post_type,
                    'post_status'  => 'publish',
                    'post_title'   => $title,
                    'post_name'    => $slug,
                    'post_content' => '',
                    'meta_input'   => array(
                        '_wp_theme_content_seed_entity' => '1',
                    ),
                ),
                true
            );

            if (is_wp_error($post_id)) {
                ++$stats['failed'];
                $stats['log'][] = sprintf(
                    /* translators: 1: post type slug, 2: post title, 3: error message */
                    __('Failed to create %1$s "%2$s": %3$s', 'wp-theme'),
                    $post_type,
                    $title,
                    $post_id->get_error_message()
                );
                continue;
            }

            ++$stats['created'];
            $stats['log'][] = sprintf(
                /* translators: 1: post type slug, 2: post ID, 3: post title */
                __('Created %1$s #%2$d: %3$s', 'wp-theme'),
                $post_type,
                (int) $post_id,
                $title
            );
        }
    }
}


/**
 * Create missing sample terms for public taxonomies.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args  Seeder run arguments.
 * @param array<string, mixed> $stats Mutable stats accumulator.
 */
function wp_theme_content_seed_ensure_terms(array $args, array &$stats): void {
    if (empty($args['create_entities']) || ! empty($args['post_type'])) {
        return;
    }

    $per_taxonomy = wp_theme_content_seed_entities_per_type($args);
    $taxonomies   = wp_theme_content_seed_get_allowed_taxonomies($args);

    foreach ($taxonomies as $taxonomy) {
        $terms = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            )
        );

        if (is_wp_error($terms)) {
            continue;
        }

        $needed = max(0, $per_taxonomy - count($terms));

        for ($i = 1; $i <= $needed; $i++) {
            $index = count($terms) + $i;
            $name  = sprintf('Sample Term %d', $index);
            $slug  = sanitize_title(sprintf('sample-term-%s-%d', $taxonomy, $index));

            if ($args['dry_run']) {
                ++$stats['created'];
                $stats['log'][] = sprintf(
                    /* translators: 1: taxonomy slug, 2: term name */
                    __('[dry-run] Would create term %1$s: %2$s', 'wp-theme'),
                    $taxonomy,
                    $name
                );
                continue;
            }

            $result = wp_insert_term(
                $name,
                $taxonomy,
                array(
                    'slug' => $slug,
                )
            );

            if (is_wp_error($result)) {
                ++$stats['failed'];
                $stats['log'][] = sprintf(
                    /* translators: 1: taxonomy slug, 2: term name, 3: error message */
                    __('Failed to create term %1$s "%2$s": %3$s', 'wp-theme'),
                    $taxonomy,
                    $name,
                    $result->get_error_message()
                );
                continue;
            }

            ++$stats['created'];
            $stats['log'][] = sprintf(
                /* translators: 1: taxonomy slug, 2: term name */
                __('Created term %1$s: %2$s', 'wp-theme'),
                $taxonomy,
                $name
            );
        }
    }
}


/**
 * Create missing sample entities before seeding field values.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args  Seeder run arguments.
 * @param array<string, mixed> $stats Mutable stats accumulator.
 */
function wp_theme_content_seed_ensure_entities(array $args, array &$stats): void {
    wp_theme_content_seed_ensure_posts($args, $stats);
    wp_theme_content_seed_ensure_terms($args, $stats);
}


/**
 * First key from ACF choices array.
 *
 * @since 1.3.0
 * @param mixed $choices Field choices.
 */
function wp_theme_content_seed_first_choice($choices): string {
    if (! is_array($choices) || [] === $choices) {
        return '';
    }

    $keys = array_keys($choices);

    return (string) reset($keys);
}


/**
 * Build placeholder value for a single ACF field definition.
 *
 * @since 1.3.0
 * @param array<string, mixed> $field ACF field array.
 */
function wp_theme_content_seed_acf_value_for_field(array $field, bool $ensure_attachment = true): string|int|array|null {
    $type  = isset($field['type']) ? (string) $field['type'] : '';
    $label = isset($field['label']) ? (string) $field['label'] : 'Field';

    switch ($type) {
        case 'text':
        case 'password':
            return 'Sample: ' . $label;

        case 'textarea':
            return 'Lorem ipsum dolor sit amet. Sample content for ' . $label . '.';

        case 'wysiwyg':
            return wp_theme_content_seed_rich_html($label);

        case 'number':
        case 'range':
            if (isset($field['min']) && '' !== $field['min']) {
                return (int) $field['min'];
            }
            return 42;

        case 'email':
            return 'dev@example.com';

        case 'url':
            return 'https://example.com';

        case 'true_false':
            return 1;

        case 'select':
        case 'radio':
        case 'button_group':
            return wp_theme_content_seed_first_choice($field['choices'] ?? array());

        case 'checkbox':
            $choice = wp_theme_content_seed_first_choice($field['choices'] ?? array());

            return '' !== $choice ? array($choice) : array();

        case 'color_picker':
            return '#0073aa';

        case 'date_picker':
            return gmdate('Ymd');

        case 'date_time_picker':
            return gmdate('Y-m-d H:i:s');

        case 'time_picker':
            return gmdate('H:i:s');

        case 'link':
            return array(
                'title'  => $label,
                'url'    => 'https://example.com',
                'target' => '',
            );

        case 'image':
        case 'file':
            return wp_theme_content_seed_get_placeholder_attachment_id($ensure_attachment);

        case 'gallery':
            $attachment_id = wp_theme_content_seed_get_placeholder_attachment_id($ensure_attachment);

            return $attachment_id ? array($attachment_id) : array();

        case 'post_object':
        case 'relationship':
            $post_types = isset($field['post_type']) && is_array($field['post_type'])
                ? $field['post_type']
                : array();

            return wp_theme_content_seed_get_sample_post_id($post_types);

        case 'taxonomy':
            $taxonomy = isset($field['taxonomy']) ? (string) $field['taxonomy'] : '';

            if ('' === $taxonomy) {
                return null;
            }
            return wp_theme_content_seed_get_sample_term_id($taxonomy);

        case 'oembed':
            return 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

        case 'google_map':
            return array(
                'address' => 'Moscow',
                'lat'     => 55.75,
                'lng'     => 37.62,
            );

        case 'group':
            $row        = array();
            $sub_fields = acf_get_fields($field);

            if (is_array($sub_fields)) {
                foreach ($sub_fields as $sub_field) {
                    if (! is_array($sub_field) || empty($sub_field['name'])) {
                        continue;
                    }

                    $row[ $sub_field['name'] ] = wp_theme_content_seed_acf_value_for_field($sub_field, $ensure_attachment);
                }
            }
            return $row;

        case 'repeater':
            $row        = array();
            $sub_fields = acf_get_fields($field);

            if (is_array($sub_fields)) {
                foreach ($sub_fields as $sub_field) {
                    if (! is_array($sub_field) || empty($sub_field['name'])) {
                        continue;
                    }

                    $row[ $sub_field['name'] ] = wp_theme_content_seed_acf_value_for_field($sub_field, $ensure_attachment);
                }
            }
            return [] !== $row ? array($row) : array();

        case 'flexible_content':
            $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : array();

            if ([] === $layouts) {
                return array();
            }

            $layout = reset($layouts);
            if (! is_array($layout) || empty($layout['name'])) {
                return array();
            }

            $row = array(
                'acf_fc_layout' => (string) $layout['name'],
            );

            $sub_fields = isset($layout['sub_fields']) && is_array($layout['sub_fields'])
                ? $layout['sub_fields']
                : array();

            foreach ($sub_fields as $sub_field) {
                if (! is_array($sub_field) || empty($sub_field['name'])) {
                    continue;
                }

                $row[ $sub_field['name'] ] = wp_theme_content_seed_acf_value_for_field($sub_field, $ensure_attachment);
            }
            return array($row);

        default:
            return null;
    }
}


/**
 * Seed core WordPress fields for a post.
 *
 * @since 1.3.0
 * @param int                  $post_id Post ID.
 * @param array<string, mixed> $args    Seeder run arguments.
 * @param array<string, mixed> $stats   Mutable stats accumulator.
 */
function wp_theme_content_seed_post(int $post_id, array $args, array &$stats): void {
    $post = get_post($post_id);

    if (! $post instanceof WP_Post) {
        return;
    }

    $post_type_object = get_post_type_object($post->post_type);
    $type_label       = $post_type_object->labels->singular_name ?? $post->post_type;
    $sample_title     = wp_theme_content_seed_is_empty($post->post_title)
        ? sprintf('Sample %s #%d', $type_label, $post_id)
        : (string) $post->post_title;

    $updates          = array(
        'ID' => $post_id,
    );
    $candidate_fields = array();

    if ($args['overwrite'] || wp_theme_content_seed_is_empty($post->post_title)) {
        $updates['post_title'] = $sample_title;
        $candidate_fields[]    = 'post_title';
    } else {
        ++$stats['skipped'];
    }

    $content_plain = trim(wp_strip_all_tags($post->post_content));
    if ($args['overwrite'] || wp_theme_content_seed_is_empty($content_plain)) {
        $updates['post_content'] = wp_theme_content_seed_rich_html($sample_title);
        $candidate_fields[]      = 'post_content';
    } else {
        ++$stats['skipped'];
    }

    if ($args['overwrite'] || wp_theme_content_seed_is_empty($post->post_excerpt)) {
        $updates['post_excerpt'] = sprintf(
            'Sample excerpt for %s.',
            $sample_title
        );
        $candidate_fields[]      = 'post_excerpt';
    } else {
        ++$stats['skipped'];
    }

    $updates = apply_filters('wp_theme_content_seed_post_data', $updates, $post, $args);

    $fields_to_update = array();
    foreach ($candidate_fields as $field_name) {
        if (isset($updates[ $field_name ])) {
            $fields_to_update[] = $field_name;
        }
    }

    if ([] !== $fields_to_update && $args['dry_run']) {
        foreach ($fields_to_update as $field_name) {
            ++$stats['seeded'];
            $stats['log'][] = sprintf(
                /* translators: 1: field name, 2: post ID */
                __('[dry-run] Would seed %1$s (#%2$d).', 'wp-theme'),
                $field_name,
                $post_id
            );
        }
    } elseif ([] !== $fields_to_update) {
        $result = wp_update_post($updates, true);

        if (is_wp_error($result)) {
            foreach ($fields_to_update as $field_name) {
                ++$stats['failed'];
                $stats['log'][] = sprintf(
                    /* translators: 1: field name, 2: post ID, 3: error message */
                    __('Failed %1$s (#%2$d): %3$s', 'wp-theme'),
                    $field_name,
                    $post_id,
                    $result->get_error_message()
                );
            }
        } else {
            foreach ($fields_to_update as $field_name) {
                ++$stats['seeded'];
                $stats['log'][] = sprintf(
                    /* translators: 1: field name, 2: post ID */
                    __('Seeded %1$s (#%2$d).', 'wp-theme'),
                    $field_name,
                    $post_id
                );
            }
        }
    }

    if ($args['overwrite'] || ! has_post_thumbnail($post_id)) {
        $attachment_id = wp_theme_content_seed_get_placeholder_attachment_id(! $args['dry_run']);

        if ($attachment_id) {
            if ($args['dry_run']) {
                ++$stats['seeded'];
                $stats['log'][] = sprintf(
                    /* translators: %d: post ID */
                    __('[dry-run] Would seed featured_image (#%d).', 'wp-theme'),
                    $post_id
                );
            } elseif (set_post_thumbnail($post_id, $attachment_id)) {
                ++$stats['seeded'];
                $stats['log'][] = sprintf(
                    /* translators: %d: post ID */
                    __('Seeded featured_image (#%d).', 'wp-theme'),
                    $post_id
                );
            } else {
                ++$stats['failed'];
                $stats['log'][] = sprintf(
                    /* translators: %d: post ID */
                    __('Failed featured_image (#%d).', 'wp-theme'),
                    $post_id
                );
            }
        }
    } else {
        ++$stats['skipped'];
    }

    $taxonomies = get_object_taxonomies($post->post_type, 'names');

    foreach ($taxonomies as $taxonomy) {
        $term_ids = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));

        if (! $args['overwrite'] && ! is_wp_error($term_ids) && [] !== $term_ids) {
            ++$stats['skipped'];
            continue;
        }

        $term_id = wp_theme_content_seed_get_sample_term_id($taxonomy);

        if (! $term_id) {
            ++$stats['skipped'];
            continue;
        }

        if ($args['dry_run']) {
            ++$stats['seeded'];
            $stats['log'][] = sprintf(
                /* translators: 1: taxonomy slug, 2: post ID */
                __('[dry-run] Would seed taxonomy %1$s (#%2$d).', 'wp-theme'),
                $taxonomy,
                $post_id
            );
            continue;
        }

        $result = wp_set_object_terms($post_id, array($term_id), $taxonomy, false);

        if (is_wp_error($result)) {
            ++$stats['failed'];
            $stats['log'][] = sprintf(
                /* translators: 1: taxonomy slug, 2: post ID, 3: error message */
                __('Failed taxonomy %1$s (#%2$d): %3$s', 'wp-theme'),
                $taxonomy,
                $post_id,
                $result->get_error_message()
            );
        } else {
            ++$stats['seeded'];
            $stats['log'][] = sprintf(
                /* translators: 1: taxonomy slug, 2: post ID */
                __('Seeded taxonomy %1$s (#%2$d).', 'wp-theme'),
                $taxonomy,
                $post_id
            );
        }
    }
}


/**
 * Seed core WordPress fields for a taxonomy term.
 *
 * @since 1.3.0
 * @param WP_Term              $term  Term object.
 * @param array<string, mixed> $args  Seeder run arguments.
 * @param array<string, mixed> $stats Mutable stats accumulator.
 */
function wp_theme_content_seed_term(WP_Term $term, array $args, array &$stats): void {
    if ($args['overwrite'] || wp_theme_content_seed_is_empty($term->description)) {
        $description = sprintf('Sample description for %s.', $term->name);

        if ($args['dry_run']) {
            ++$stats['seeded'];
            $stats['log'][] = sprintf(
                /* translators: %d: term ID */
                __('[dry-run] Would seed term_description (term_%d).', 'wp-theme'),
                $term->term_id
            );
        } else {
            $result = wp_update_term(
                $term->term_id,
                $term->taxonomy,
                array(
                    'description' => $description,
                )
            );

            if (is_wp_error($result)) {
                ++$stats['failed'];
                $stats['log'][] = sprintf(
                    /* translators: 1: term ID, 2: error message */
                    __('Failed term_description (term_%1$d): %2$s', 'wp-theme'),
                    $term->term_id,
                    $result->get_error_message()
                );
            } else {
                ++$stats['seeded'];
                $stats['log'][] = sprintf(
                    /* translators: %d: term ID */
                    __('Seeded term_description (term_%d).', 'wp-theme'),
                    $term->term_id
                );
            }
        }
    } else {
        ++$stats['skipped'];
    }
}


/**
 * Seed ACF fields for one entity (post, option page, term).
 *
 * @since 1.3.0
 * @param int|string           $entity_id Entity identifier for ACF.
 * @param array<string, mixed> $group_args Arguments for acf_get_field_groups().
 * @param array<string, mixed> $args      Seeder run arguments.
 * @param array<string, mixed> $stats     Mutable stats accumulator.
 */
function wp_theme_content_seed_acf_entity($entity_id, array $group_args, array $args, array &$stats): void {
    if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
        return;
    }

    $groups = acf_get_field_groups($group_args);

    if (! is_array($groups) || [] === $groups) {
        return;
    }

    foreach ($groups as $group) {
        if (! is_array($group) || empty($group['key'])) {
            continue;
        }

        $fields = acf_get_fields($group);

        if (! is_array($fields)) {
            continue;
        }

        wp_theme_content_seed_acf_field_list($entity_id, $fields, $args, $stats);
    }
}


/**
 * Seed a list of top-level ACF fields.
 *
 * @since 1.3.0
 * @param int|string              $entity_id Entity identifier.
 * @param array<int, array<mixed>> $fields    ACF field definitions.
 * @param array<string, mixed>    $args      Seeder run arguments.
 * @param array<string, mixed>    $stats     Mutable stats accumulator.
 */
function wp_theme_content_seed_acf_field_list($entity_id, array $fields, array $args, array &$stats): void {
    foreach ($fields as $field) {
        if (! is_array($field) || empty($field['name']) || empty($field['key'])) {
            continue;
        }

        $field_name = (string) $field['name'];
        $field_key  = (string) $field['key'];
        $field_type = isset($field['type']) ? (string) $field['type'] : '';

        if ('tab' === $field_type || 'message' === $field_type || 'accordion' === $field_type) {
            continue;
        }

        $current = function_exists('get_field') ? get_field($field_key, $entity_id) : null;

        if (! $args['overwrite'] && ! wp_theme_content_seed_is_empty($current)) {
            ++$stats['skipped'];
            continue;
        }

        $value = wp_theme_content_seed_acf_value_for_field($field, ! $args['dry_run']);

        if (wp_theme_content_seed_is_empty($value) && null !== $value) {
            ++$stats['skipped'];
            $stats['log'][] = sprintf(
                /* translators: 1: field name, 2: entity ID, 3: ACF field type */
                __('Skipped acf:%1$s (%2$s): no placeholder for type "%3$s".', 'wp-theme'),
                $field_name,
                (string) $entity_id,
                $field_type
            );
            continue;
        }

        if (null === $value) {
            ++$stats['skipped'];
            $stats['log'][] = sprintf(
                /* translators: 1: field name, 2: entity ID, 3: ACF field type */
                __('Skipped acf:%1$s (%2$s): unsupported type "%3$s".', 'wp-theme'),
                $field_name,
                (string) $entity_id,
                $field_type
            );
            continue;
        }

        $value = apply_filters('wp_theme_content_seed_value', $value, $field, $entity_id, $args);

        if ($args['dry_run']) {
            ++$stats['seeded'];
            $stats['log'][] = sprintf(
                /* translators: 1: field name, 2: entity ID */
                __('[dry-run] Would seed acf:%1$s (%2$s).', 'wp-theme'),
                $field_name,
                (string) $entity_id
            );
            continue;
        }

        $updated = function_exists('update_field')
            ? update_field($field_key, $value, $entity_id)
            : false;

        if ($updated) {
            ++$stats['seeded'];
            $stats['log'][] = sprintf(
                /* translators: 1: field name, 2: entity ID */
                __('Seeded acf:%1$s (%2$s).', 'wp-theme'),
                $field_name,
                (string) $entity_id
            );
        } else {
            ++$stats['failed'];
            $stats['log'][] = sprintf(
                /* translators: 1: field name, 2: entity ID */
                __('Failed acf:%1$s (%2$s).', 'wp-theme'),
                $field_name,
                (string) $entity_id
            );
        }
    }
}


/**
 * Collect posts to seed.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Seeder run arguments.
 * @return int[]
 */
function wp_theme_content_seed_get_post_ids(array $args): array {
    $post_types = wp_theme_content_seed_get_target_post_types($args);

    if ([] === $post_types) {
        return array();
    }

    $post_ids = array();

    foreach ($post_types as $post_type) {
        $query_args = array(
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        );

        if (! empty($args['limit'])) {
            $query_args['posts_per_page'] = (int) $args['limit'];
        }

        $posts = get_posts($query_args);

        if ([] !== $posts) {
            $post_ids = array_merge($post_ids, array_map(intval(...), $posts));
        }
    }

    return $post_ids;
}


/**
 * Collect ACF options pages to seed.
 *
 * @since 1.3.0
 * @return array<int, array<string, mixed>>
 */
function wp_theme_content_seed_get_options_pages(): array {
    if (function_exists('acf_options_page')) {
        try {
            $pages = acf_options_page()->get_pages();
            if (is_array($pages) && [] !== $pages) {
                return $pages;
            }
        } catch (Exception $e) {
            unset($e);
        }
    }

    if (function_exists('acf_get_options_pages')) {
        $pages = acf_get_options_pages();

        return is_array($pages) ? $pages : array();
    }

    return array();
}


/**
 * Run content seeder for all matching entities.
 *
 * @since 1.3.0
 * @param array<string, mixed> $args Run arguments.
 * @return array<string, mixed>
 */
function wp_theme_content_seed_run(array $args = array()): array {
    $args  = wp_theme_content_seed_normalize_args($args);
    $stats = wp_theme_content_seed_initial_stats();

    wp_theme_content_seed_ensure_entities($args, $stats);

    if (! $args['dry_run']) {
        wp_theme_content_seed_get_placeholder_attachment_id(true);
    }

    foreach (wp_theme_content_seed_get_post_ids($args) as $post_id) {
        wp_theme_content_seed_post($post_id, $args, $stats);

        if (function_exists('acf_get_field_groups')) {
            wp_theme_content_seed_acf_entity(
                $post_id,
                array(
                    'post_id' => $post_id,
                ),
                $args,
                $stats
            );
        }
    }

    if (empty($args['post_type'])) {
        if (function_exists('acf_get_field_groups')) {
            foreach (wp_theme_content_seed_get_options_pages() as $page) {
                if (! is_array($page)) {
                    continue;
                }

                $post_id   = $page['post_id'] ?? 'option';
                $menu_slug = isset($page['menu_slug']) ? (string) $page['menu_slug'] : '';

                if ('' === $menu_slug) {
                    wp_theme_content_seed_acf_entity(
                        $post_id,
                        array(
                            'options_page' => 'acf-options',
                        ),
                        $args,
                        $stats
                    );
                    continue;
                }

                wp_theme_content_seed_acf_entity(
                    $post_id,
                    array(
                        'options_page' => $menu_slug,
                    ),
                    $args,
                    $stats
                );
            }
        }

        $taxonomies = wp_theme_content_seed_get_allowed_taxonomies($args);

        foreach ($taxonomies as $taxonomy) {
            $term_query_args = array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            );

            if (! empty($args['limit'])) {
                $term_query_args['number'] = (int) $args['limit'];
            }

            $terms = get_terms($term_query_args);

            if (is_wp_error($terms) || ! is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (! $term instanceof WP_Term) {
                    continue;
                }

                wp_theme_content_seed_term($term, $args, $stats);

                if (function_exists('acf_get_field_groups')) {
                    wp_theme_content_seed_acf_entity(
                        'term_' . (int) $term->term_id,
                        array(
                            'post_id' => 'term_' . (int) $term->term_id,
                        ),
                        $args,
                        $stats
                    );
                }
            }
        }
    }

    return $stats;
}


/**
 * Register hidden admin page (no menu item).
 *
 * @since 1.3.0
 */
function wp_theme_content_seed_register_admin_page(): void {
    add_submenu_page(
        null,
        __('Content Seeder', 'wp-theme'),
        __('Content Seeder', 'wp-theme'),
        'manage_options',
        'wp-theme-content-seeder',
        'wp_theme_content_seed_render_admin_page'
    );
}


add_action('admin_menu', 'wp_theme_content_seed_register_admin_page');


/**
 * Render hidden content seeder admin page.
 *
 * @since 1.3.0
 */
function wp_theme_content_seed_render_admin_page(): void {
    if (! current_user_can('manage_options')) {
        return;
    }

    $allowed_slugs = wp_theme_content_seed_get_allowed_post_types(array());
    $post_types    = array();

    foreach ($allowed_slugs as $post_type) {
        $object = get_post_type_object($post_type);

        if ($object instanceof WP_Post_Type) {
            $post_types[ $post_type ] = $object;
        }
    }

    $confirm_message = esc_js(__('Create missing entities and fill content fields with placeholders?', 'wp-theme'));
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Content Seeder', 'wp-theme'); ?></h1>
        <p><?php esc_html_e('Creates sample posts and terms when needed, then fills WordPress and ACF fields with placeholder values. Post types: pages, posts, and CPTs with ACF field groups.', 'wp-theme'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_attr($confirm_message); ?>');">
            <?php wp_nonce_field('wp_theme_content_seed'); ?>
            <input type="hidden" name="action" value="wp_theme_content_seed">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Options', 'wp-theme'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="create_entities" value="1" checked>
                            <?php esc_html_e('Create missing sample posts and terms', 'wp-theme'); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dry_run" value="1">
                            <?php esc_html_e('Dry run (preview only, no database writes)', 'wp-theme'); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="overwrite" value="1">
                            <?php esc_html_e('Overwrite existing field values', 'wp-theme'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_theme_content_seed_post_type"><?php esc_html_e('Post type', 'wp-theme'); ?></label>
                    </th>
                    <td>
                        <select name="post_type" id="wp_theme_content_seed_post_type">
                            <option value=""><?php esc_html_e('All (posts, options, terms)', 'wp-theme'); ?></option>
                            <?php foreach ($post_types as $post_type => $object) : ?>
                                <option value="<?php echo esc_attr($post_type); ?>">
                                    <?php echo esc_html($object->labels->singular_name ?? $post_type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_theme_content_seed_limit"><?php esc_html_e('Limit', 'wp-theme'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="limit" id="wp_theme_content_seed_limit" value="" min="1" class="small-text">
                        <p class="description"><?php esc_html_e('Optional. Max entities per post type / taxonomy, and max posts to seed.', 'wp-theme'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Run seeder', 'wp-theme'), 'primary', 'submit', false); ?>
        </form>
    </div>
    <?php
}


/**
 * Handle admin-post seeder submission.
 *
 * @since 1.3.0
 */
function wp_theme_content_seed_handle_admin_post(): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to do this.', 'wp-theme'));
    }

    check_admin_referer('wp_theme_content_seed');

    $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash((string) $_POST['post_type'])) : '';
    $limit_raw = isset($_POST['limit']) ? sanitize_text_field(wp_unslash((string) $_POST['limit'])) : '';
    $limit     = '' !== $limit_raw ? absint($limit_raw) : null;

    $result = wp_theme_content_seed_run(
        array(
            'create_entities' => ! empty($_POST['create_entities']),
            'dry_run'         => ! empty($_POST['dry_run']),
            'overwrite'       => ! empty($_POST['overwrite']),
            'post_type'       => '' !== $post_type ? $post_type : null,
            'limit'           => $limit,
        )
    );

    set_transient(
        'wp_theme_content_seed_notice_' . get_current_user_id(),
        $result,
        MINUTE_IN_SECONDS * 5
    );

    $redirect = wp_get_referer();
    if (! $redirect) {
        $redirect = admin_url('admin.php?page=wp-theme-content-seeder');
    }

    wp_safe_redirect($redirect);
    exit;
}


add_action('admin_post_wp_theme_content_seed', 'wp_theme_content_seed_handle_admin_post');


/**
 * Show admin notice after seeder run.
 *
 * @since 1.3.0
 */
function wp_theme_content_seed_admin_notice(): void {
    if (! current_user_can('manage_options')) {
        return;
    }

    $key    = 'wp_theme_content_seed_notice_' . get_current_user_id();
    $result = get_transient($key);

    if (! is_array($result)) {
        return;
    }

    delete_transient($key);

    $message = sprintf(
        /* translators: 1: created count, 2: seeded count, 3: skipped count, 4: failed count */
        __('Content seeder finished: %1$d created, %2$d seeded, %3$d skipped, %4$d failed.', 'wp-theme'),
        (int) ($result['created'] ?? 0),
        (int) ($result['seeded'] ?? 0),
        (int) ($result['skipped'] ?? 0),
        (int) ($result['failed'] ?? 0)
    );

    $notice_class = empty($result['failed']) ? 'success' : 'warning';

    echo '<div class="notice notice-' . esc_attr($notice_class) . ' is-dismissible">';
    echo '<p>' . esc_html($message) . '</p>';

    $log = isset($result['log']) && is_array($result['log']) ? $result['log'] : array();
    if ([] !== $log) {
        echo '<details><summary>' . esc_html__('Details', 'wp-theme') . '</summary>';
        echo '<ul style="list-style:disc;margin-left:1.5em;">';
        foreach (array_slice($log, 0, 50) as $line) {
            echo '<li>' . esc_html((string) $line) . '</li>';
        }

        echo '</ul></details>';
    }

    echo '</div>';
}


add_action('admin_notices', 'wp_theme_content_seed_admin_notice');


/**
 * WP-CLI command: seed content fields.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Preview changes without writing to the database.
 *
 * [--overwrite]
 * : Overwrite existing field values.
 *
 * [--no-create]
 * : Do not create missing sample posts and terms.
 *
 * [--post-type=<slug>]
 * : Limit seeding to one post type.
 *
 * [--limit=<n>]
 * : Max entities per type and max posts to seed.
 *
 * ## EXAMPLES
 *
 *     wp theme seed-content --dry-run
 *     wp theme seed-content --overwrite
 *     wp theme seed-content --post-type=page --limit=5
 *
 * @since 1.3.0
 * @param array<int, string>   $args       Positional args.
 * @param array<string, mixed> $assoc_args Associative args.
 */
function wp_theme_cli_seed_content(array $args = array(), array $assoc_args = array()): void {
    unset($args);

    if (! class_exists('WP_CLI')) {
        return;
    }

    $run_args = array(
        'create_entities' => ! isset($assoc_args['no-create']),
        'dry_run'         => isset($assoc_args['dry-run']),
        'overwrite'       => isset($assoc_args['overwrite']),
        'post_type'       => isset($assoc_args['post-type']) ? sanitize_key((string) $assoc_args['post-type']) : null,
        'limit'           => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : null,
    );

    $result = wp_theme_content_seed_run($run_args);

    foreach ($result['log'] as $line) {
        WP_CLI::log((string) $line);
    }

    WP_CLI::success(
        sprintf(
            'Done: %d created, %d seeded, %d skipped, %d failed.',
            (int) ($result['created'] ?? 0),
            (int) ($result['seeded'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            (int) ($result['failed'] ?? 0)
        )
    );
}


if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('theme seed-content', 'wp_theme_cli_seed_content');
}
