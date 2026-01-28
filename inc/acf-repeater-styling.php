<?php
/**
 * Visually separate ACF repeater fields.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Styling ACF repeater rows.
 */
function stylize_acf_repeater_fields(): void {
    echo '<style>
		.acf-repeater tbody .acf-row:nth-child(even)>.acf-row-handle {
		   filter: brightness(0.9);
		}
	</style>';
}


if (class_exists('ACF')) {
    add_action('admin_head', 'stylize_acf_repeater_fields');
}
