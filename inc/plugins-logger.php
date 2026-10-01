<?php
/**
 * Plugin domain logger.
 *
 * Logs text domains of installed plugins to plugins.txt in the active theme
 * directory. Entries are added on install/activation and removed on delete.
 *
 * @package WP_Theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Path to plugins.txt in the active theme directory (child or parent).
 */
function wp_theme_plugins_log_file(): string {
    return get_stylesheet_directory() . '/plugins.txt';
}


/**
 * Update plugins.txt under an exclusive lock (read, modify, write).
 *
 * @param callable $callback Receives the list of identifiers; returns the new list.
 */
function wp_theme_plugins_log_update(callable $callback): void {
    $handle = @fopen(wp_theme_plugins_log_file(), 'c+');

    if (! $handle) {
        return;
    }

    if (flock($handle, LOCK_EX)) {
        $content = (string) stream_get_contents($handle);
        $lines   = array_values(
            array_filter(
                (array) preg_split('/\r?\n/', $content),
                static fn($line): bool => '' !== $line
            )
        );
        $updated = $callback($lines);

        if (is_array($updated) && $updated !== $lines) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode("\n", $updated) . ($updated !== [] ? "\n" : ''));
            fflush($handle);
        }

        flock($handle, LOCK_UN);
    }

    fclose($handle);
}


/**
 * Resolve a stable plugin identifier for plugins.txt.
 *
 * Prefers TextDomain; falls back to the plugin directory (or file) slug when
 * the header is empty or plugin files are already gone (e.g. after delete).
 *
 * @param string $plugin Plugin basename relative to WP_PLUGIN_DIR.
 * @return string Identifier, or empty string when unresolved.
 */
function wp_theme_plugins_log_get_identifier(string $plugin): string {
    if (! function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_path = WP_PLUGIN_DIR . '/' . $plugin;

    if (file_exists($plugin_path)) {
        $plugin_data = get_plugin_data($plugin_path, false, false);

        if (! empty($plugin_data['TextDomain'])) {
            return (string) $plugin_data['TextDomain'];
        }
    }

    if (str_contains($plugin, '/')) {
        return dirname($plugin);
    }

    return basename($plugin, '.php');
}


/**
 * Append a plugin identifier to plugins.txt if not already present.
 *
 * @param string $plugin Plugin basename relative to WP_PLUGIN_DIR.
 */
function wp_theme_plugins_log_add(string $plugin): void {
    $identifier = wp_theme_plugins_log_get_identifier($plugin);

    if ('' === $identifier) {
        return;
    }

    wp_theme_plugins_log_update(
        static function (array $lines) use ($identifier): array {
            if (! in_array($identifier, $lines, true)) {
                $lines[] = $identifier;
            }

            return $lines;
        }
    );
}


/**
 * Remove an identifier line from plugins.txt.
 *
 * @param string $identifier Plugin text domain or slug.
 */
function wp_theme_plugins_log_remove_identifier(string $identifier): void {
    if ('' === $identifier || ! file_exists(wp_theme_plugins_log_file())) {
        return;
    }

    wp_theme_plugins_log_update(
        static fn(array $lines): array => array_values(array_diff($lines, array($identifier)))
    );
}


/**
 * Log a newly installed plugin after the upgrader finishes.
 *
 * @param WP_Upgrader $upgrader WP_Upgrader instance.
 * @param array       $options  Array of bulk item update data.
 */
function wp_theme_plugins_log_on_install($upgrader, $options): void {
    if (! is_array($options)) {
        return;
    }

    if (( $options['type'] ?? '' ) !== 'plugin' || ( $options['action'] ?? '' ) !== 'install') {
        return;
    }

    if (! $upgrader instanceof Plugin_Upgrader) {
        return;
    }

    $plugin = $upgrader->plugin_info();

    if (! is_string($plugin) || '' === $plugin) {
        return;
    }

    wp_theme_plugins_log_add($plugin);
}


/**
 * Mutable store for identifiers captured on delete_plugin.
 *
 * @return object{pending: array<string, string>}
 */
function wp_theme_plugins_log_pending_store(): object {
    static $store = null;

    if (null === $store) {
        $store        = new stdClass();
        $store->pending = array();
    }

    return $store;
}


/**
 * Remember plugin identifier before files are removed from disk.
 *
 * @param string $plugin_file Plugin basename relative to WP_PLUGIN_DIR.
 */
function wp_theme_plugins_log_before_delete(string $plugin_file): void {
    $store = wp_theme_plugins_log_pending_store();

    $store->pending[ $plugin_file ] = wp_theme_plugins_log_get_identifier($plugin_file);
}


/**
 * Remove a plugin from the log after a successful delete.
 *
 * Uses the identifier captured on delete_plugin so TextDomain is available
 * even after plugin files are gone.
 *
 * @param string $plugin_file Plugin basename relative to WP_PLUGIN_DIR.
 * @param bool   $deleted     Whether the plugin was deleted successfully.
 */
function wp_theme_plugins_log_on_delete(string $plugin_file, bool $deleted): void {
    $store      = wp_theme_plugins_log_pending_store();
    $identifier = $store->pending[ $plugin_file ] ?? '';

    unset($store->pending[ $plugin_file ]);

    if ($deleted) {
        wp_theme_plugins_log_remove_identifier($identifier);
    }
}


add_action('activated_plugin', 'wp_theme_plugins_log_add', 10, 1);
add_action('upgrader_process_complete', 'wp_theme_plugins_log_on_install', 10, 2);
add_action('delete_plugin', 'wp_theme_plugins_log_before_delete', 10, 1);
add_action('deleted_plugin', 'wp_theme_plugins_log_on_delete', 10, 2);
