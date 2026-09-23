<?php
/**
 * Security Hardening checks shared by Overview and Site Health.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Whether the current WordPress environment type is production.
 */
function wp_theme_security_hardening_is_production(): bool {
    return 'production' === wp_get_environment_type();
}


/**
 * Whether DISALLOW_FILE_EDIT was defined by the theme, not wp-config.php.
 */
function wp_theme_security_hardening_file_edit_defined_by_theme(): bool {
    return defined('WP_THEME_DEFINED_DISALLOW_FILE_EDIT') && WP_THEME_DEFINED_DISALLOW_FILE_EDIT;
}


/**
 * Whether WP_DEBUG_DISPLAY is effectively on (including the WP_DEBUG default).
 */
function wp_theme_security_hardening_is_debug_display_on(): bool {
    if (defined('WP_DEBUG_DISPLAY')) {
        return (bool) WP_DEBUG_DISPLAY;
    }

    return defined('WP_DEBUG') && WP_DEBUG;
}


/**
 * Whether the site is served over HTTPS.
 */
function wp_theme_security_hardening_is_https(): bool {
    if (is_ssl()) {
        return true;
    }

    return 'https' === wp_parse_url(home_url(), PHP_URL_SCHEME);
}


/**
 * Load plugin.php helpers when missing (Site Health / late admin).
 */
function wp_theme_security_hardening_ensure_plugin_admin(): void {
    if (! function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
}


/**
 * Whether a plugin is active on the site or network.
 *
 * @param string $plugin Plugin basename, e.g. two-factor/two-factor.php.
 */
function wp_theme_security_hardening_is_plugin_active(string $plugin): bool {
    wp_theme_security_hardening_ensure_plugin_admin();

    if (is_plugin_active($plugin)) {
        return true;
    }

    return is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin);
}


/**
 * Known two-factor plugin basenames.
 *
 * Child themes and plugins may extend the list via
 * `wp_theme_security_hardening_two_factor_plugins`.
 *
 * @return string[]
 */
function wp_theme_security_hardening_two_factor_plugins(): array {
    $plugins = array(
        'two-factor/two-factor.php',
        'wp-2fa/wp-2fa.php',
    );

    /**
     * Filter the list of recognized two-factor plugin basenames.
     *
     * @param string[] $plugins Plugin basenames, e.g. two-factor/two-factor.php.
     */
    $filtered = apply_filters('wp_theme_security_hardening_two_factor_plugins', $plugins);

    if (! is_array($filtered)) {
        return $plugins;
    }

    $normalized = array();
    foreach ($filtered as $plugin) {
        if (! is_string($plugin) || '' === $plugin) {
            continue;
        }

        $normalized[] = $plugin;
    }

    return array_values(array_unique($normalized));
}


/**
 * Whether a known two-factor plugin is active.
 */
function wp_theme_security_hardening_is_two_factor_plugin_active(): bool
{
    return array_any(wp_theme_security_hardening_two_factor_plugins(), fn(string $plugin): bool => wp_theme_security_hardening_is_plugin_active($plugin));
}


/**
 * Catalog of hardening checks (callbacks, Site Health, labels).
 *
 * @return array<string, array{label:string, callback:string, site_health:bool, skip_cron?:bool}>
 */
function wp_theme_security_hardening_catalog(): array {
    static $base = null;

    if (! is_array($base)) {
        $base = array(
            'debug_display'    => array(
                'label'       => __('Debug display (WP_DEBUG_DISPLAY)', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_debug_display',
                'site_health' => false,
            ),
            'ssl_admin'        => array(
                'label'       => __('Force SSL for admin (FORCE_SSL_ADMIN)', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_ssl_admin',
                'site_health' => true,
            ),
            'two_factor'       => array(
                'label'       => __('Two-factor authentication plugin', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_two_factor',
                'site_health' => true,
            ),
            'two_factor_users' => array(
                'label'       => __('Two-factor authentication for administrators', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_two_factor_users',
                'site_health' => true,
            ),
            'updates'          => array(
                'label'       => __('Plugin and theme updates', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_updates',
                'site_health' => true,
            ),
            'uploads_php'      => array(
                'label'       => __('PHP execution in uploads', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_uploads_php',
                'site_health' => true,
                'skip_cron'   => true,
            ),
            'file_edit_source' => array(
                'label'       => __('File editor constant (DISALLOW_FILE_EDIT)', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_file_edit_source',
                'site_health' => true,
            ),
        );
    }

    /**
     * Filter the Security Hardening check catalog.
     *
     * @param array<string, array{label:string, callback:string, site_health:bool, skip_cron?:bool}> $catalog Checks keyed by id.
     */
    $filtered = apply_filters('wp_theme_security_hardening_catalog', $base);

    return is_array($filtered) ? $filtered : $base;
}


/**
 * Label for a check id.
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_check_label(string $id): string {
    $catalog = wp_theme_security_hardening_catalog();

    if (isset($catalog[ $id ]['label']) && is_string($catalog[ $id ]['label'])) {
        return $catalog[ $id ]['label'];
    }

    return __('Security Hardening', 'wp-theme');
}


/**
 * Run one check and apply environment softening.
 *
 * @param string $id Check id.
 * @return array<string, mixed>|null
 */
function wp_theme_security_hardening_run_check(string $id): ?array {
    $catalog = wp_theme_security_hardening_catalog();
    if (! isset($catalog[ $id ]['callback']) || ! is_callable($catalog[ $id ]['callback'])) {
        return null;
    }

    $item = call_user_func($catalog[ $id ]['callback']);
    if (! is_array($item) || empty($item['id'])) {
        return null;
    }

    return wp_theme_security_hardening_apply_environment($item);
}


/**
 * Build one check item.
 *
 * @param string $id          Check id.
 * @param string $status      critical, warning, good, skipped, or unknown.
 * @param string $title       Short title.
 * @param string $description Explanation.
 * @param bool   $elevated    Only raise severity on production.
 * @param bool   $site_health Include in Site Health.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_make_item(
    string $id,
    string $status,
    string $title,
    string $description,
    bool $elevated = false,
    bool $site_health = true
): array {
    return array(
        'id'          => $id,
        'status'      => $status,
        'title'       => $title,
        'description' => $description,
        'elevated'    => $elevated,
        'site_health' => $site_health,
        'base_status' => $status,
    );
}


/**
 * Soften elevated checks outside production.
 *
 * Critical and warning results stay visible but are not scored as issues.
 * Checks that already passed keep the Passed group.
 *
 * @param array<string, mixed> $item Check item.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_apply_environment(array $item): array {
    $item['base_status'] = (string) ($item['status'] ?? 'unknown');

    if (empty($item['elevated']) || wp_theme_security_hardening_is_production()) {
        return $item;
    }

    if ('critical' !== $item['status'] && 'warning' !== $item['status']) {
        return $item;
    }

    $item['status']      = 'skipped';
    $item['description'] = trim(
        $item['description'] . ' ' . __(
            'This is not marked as a warning or critical issue because the environment type is not production.',
            'wp-theme'
        )
    );

    return $item;
}


/**
 * Collect all hardening results (cached per request).
 *
 * @return array<int, array<string, mixed>>
 */
function wp_theme_security_hardening_get_results(): array {
    static $results = null;

    if (is_array($results)) {
        return $results;
    }

    $results = array();
    foreach (array_keys(wp_theme_security_hardening_catalog()) as $id) {
        $item = wp_theme_security_hardening_run_check((string) $id);
        if (! is_array($item)) {
            continue;
        }

        $results[] = $item;
    }

    return $results;
}


/**
 * WP_DEBUG_DISPLAY — Overview only (Site Health already has a core test).
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_debug_display(): array {
    $title = wp_theme_security_hardening_check_label('debug_display');

    if (! wp_theme_security_hardening_is_debug_display_on()) {
        return wp_theme_security_hardening_make_item(
            'debug_display',
            'good',
            $title,
            __('Error display is off. Debug output will not be printed to site visitors.', 'wp-theme'),
            true,
            false
        );
    }

    return wp_theme_security_hardening_make_item(
        'debug_display',
        'critical',
        $title,
        __('WP_DEBUG_DISPLAY is on (or defaults to on because WP_DEBUG is on). On production this can leak paths and errors to visitors. Set WP_DEBUG_DISPLAY to false in wp-config.php.', 'wp-theme'),
        true,
        false
    );
}


/**
 * FORCE_SSL_ADMIN — warning on HTTPS sites when not true.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_ssl_admin(): array {
    $forced = defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN;
    $title  = wp_theme_security_hardening_check_label('ssl_admin');

    if ($forced) {
        return wp_theme_security_hardening_make_item(
            'ssl_admin',
            'good',
            $title,
            __('FORCE_SSL_ADMIN is enabled.', 'wp-theme')
        );
    }

    if (! wp_theme_security_hardening_is_https()) {
        return wp_theme_security_hardening_make_item(
            'ssl_admin',
            'skipped',
            $title,
            __('The site URL is not HTTPS, so FORCE_SSL_ADMIN is not required yet.', 'wp-theme')
        );
    }

    return wp_theme_security_hardening_make_item(
        'ssl_admin',
        'warning',
        $title,
        __('The site is served over HTTPS but FORCE_SSL_ADMIN is not true. Add it in wp-config.php so wp-admin always uses HTTPS.', 'wp-theme')
    );
}


/**
 * Active 2FA plugin — warning on production when no known plugin is active.
 *
 * Warning (not critical): only a short built-in list is recognized. Extend
 * it with `wp_theme_security_hardening_two_factor_plugins` for other plugins.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_two_factor(): array {
    $title = wp_theme_security_hardening_check_label('two_factor');

    if (wp_theme_security_hardening_is_two_factor_plugin_active()) {
        return wp_theme_security_hardening_make_item(
            'two_factor',
            'good',
            $title,
            __('A recognized two-factor authentication plugin is active.', 'wp-theme'),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'two_factor',
        'warning',
        $title,
        __(
            'No recognized two-factor plugin is active (built-in list: Two Factor, WP 2FA). On production administrators should use 2FA. ' .
            'If another 2FA plugin is already in use, extend the list with the wp_theme_security_hardening_two_factor_plugins filter.',
            'wp-theme'
        ),
        true
    );
}


/**
 * Per-user 2FA for administrators (official Two Factor API only).
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_two_factor_users(): array {
    $title = wp_theme_security_hardening_check_label('two_factor_users');

    if (! class_exists('Two_Factor_Core') || ! method_exists(Two_Factor_Core::class, 'is_user_using_two_factor')) {
        if (! wp_theme_security_hardening_is_two_factor_plugin_active()) {
            return array();
        }

        return wp_theme_security_hardening_make_item(
            'two_factor_users',
            'unknown',
            $title,
            __('A two-factor plugin is active, but per-user status cannot be checked automatically for this plugin.', 'wp-theme'),
            true
        );
    }

    $max_check = 50;
    $query     = new WP_User_Query(
        array(
            'role'        => 'administrator',
            'number'      => $max_check,
            'fields'      => array('ID'),
            'count_total' => true,
        )
    );

    $users       = $query->get_results();
    $checked     = is_array($users) ? count($users) : 0;
    $total_found = (int) $query->get_total();
    $without     = 0;

    foreach ($users as $user) {
        $user_id = (int) (is_object($user) ? $user->ID : $user);
        if ($user_id <= 0) {
            continue;
        }

        if (! Two_Factor_Core::is_user_using_two_factor($user_id)) {
            ++$without;
        }
    }

    if ($checked <= 0) {
        return wp_theme_security_hardening_make_item(
            'two_factor_users',
            'unknown',
            $title,
            __('No administrators were found to check for two-factor authentication.', 'wp-theme'),
            true
        );
    }

    if ($without > 0) {
        return wp_theme_security_hardening_make_item(
            'two_factor_users',
            'critical',
            $title,
            sprintf(
                /* translators: 1: administrators without 2FA, 2: administrators checked */
                __('%1$d of %2$d checked administrators do not have two-factor authentication enabled.', 'wp-theme'),
                $without,
                $checked
            ),
            true
        );
    }

    if ($total_found > $checked) {
        return wp_theme_security_hardening_make_item(
            'two_factor_users',
            'unknown',
            $title,
            sprintf(
                /* translators: 1: administrators checked, 2: total administrators */
                __('The first %1$d of %2$d administrators have two-factor authentication enabled. Additional administrators were not checked.', 'wp-theme'),
                $checked,
                $total_found
            ),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'two_factor_users',
        'good',
        $title,
        sprintf(
            /* translators: %d: number of administrators checked */
            __('All %d checked administrators have two-factor authentication enabled.', 'wp-theme'),
            $checked
        ),
        true
    );
}


/**
 * Available plugin or theme updates — warning on production.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_updates(): array {
    if (! function_exists('get_plugin_updates') || ! function_exists('get_theme_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    $title          = wp_theme_security_hardening_check_label('updates');
    $plugin_updates = function_exists('get_plugin_updates') ? get_plugin_updates() : array();
    $theme_updates  = function_exists('get_theme_updates') ? get_theme_updates() : array();

    $plugin_count = is_array($plugin_updates) ? count($plugin_updates) : 0;
    $theme_count  = is_array($theme_updates) ? count($theme_updates) : 0;

    if ($plugin_count <= 0 && $theme_count <= 0) {
        return wp_theme_security_hardening_make_item(
            'updates',
            'good',
            $title,
            __('No plugin or theme updates are waiting in the admin.', 'wp-theme'),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'updates',
        'warning',
        $title,
        sprintf(
            /* translators: 1: plugin update count, 2: theme update count */
            __('%1$d plugin update(s) and %2$d theme update(s) are available. Review and apply them through your usual process (admin, git, or CI).', 'wp-theme'),
            $plugin_count,
            $theme_count
        ),
        true
    );
}


/**
 * Pause Query Monitor HTTP collection if the collector is present.
 *
 * @return object|null Collector to resume, or null.
 */
function wp_theme_security_hardening_qm_http_pause(): ?object {
    if (! class_exists('QM_Collectors') || ! method_exists(QM_Collectors::class, 'get')) {
        return null;
    }

    $collector = QM_Collectors::get('http');
    if (! is_object($collector) || ! method_exists($collector, 'tear_down')) {
        return null;
    }

    $collector->tear_down();

    return $collector;
}


/**
 * Resume Query Monitor HTTP collection after a paused probe.
 *
 * @param object|null $collector Collector returned by the pause helper.
 */
function wp_theme_security_hardening_qm_http_resume(?object $collector): void {
    if (is_object($collector) && method_exists($collector, 'set_up')) {
        $collector->set_up();
    }
}


/**
 * Send the uploads PHP probe without logging it in Query Monitor.
 *
 * The probe expects 403/404 on a missing file; QM would otherwise flag
 * HTTP and 4xx as errors on the Security Hardening screen.
 *
 * @param string               $url  Probe URL.
 * @param array<string, mixed> $args Request args for wp_remote_request().
 * @return array<string, mixed>|WP_Error
 */
function wp_theme_security_hardening_remote_probe(string $url, array $args): array|WP_Error {
    $collector = wp_theme_security_hardening_qm_http_pause();

    try {
        return wp_remote_request($url, $args);
    } finally {
        wp_theme_security_hardening_qm_http_resume($collector);
    }
}


/**
 * Probe a non-existent PHP URL under uploads. Never creates files.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_uploads_php(): array {
    $title   = wp_theme_security_hardening_check_label('uploads_php');
    $uploads = wp_upload_dir();
    $baseurl = is_array($uploads) ? (string) ($uploads['baseurl'] ?? '') : '';

    if ('' === $baseurl || ! empty($uploads['error'])) {
        return wp_theme_security_hardening_make_item(
            'uploads_php',
            'unknown',
            $title,
            __('The uploads URL could not be determined, so PHP execution in uploads could not be probed.', 'wp-theme')
        );
    }

    $probe_url = trailingslashit($baseurl) . 'wp-theme-hardening-' . wp_generate_password(12, false, false) . '.php';
    $args      = array(
        'method'              => 'HEAD',
        'timeout'             => 3,
        'redirection'         => 0,
        'sslverify'           => true,
        'limit_response_size' => 256,
    );

    $response = wp_theme_security_hardening_remote_probe($probe_url, $args);

    if (! is_wp_error($response) && 405 === (int) wp_remote_retrieve_response_code($response)) {
        $args['method'] = 'GET';
        $response       = wp_theme_security_hardening_remote_probe($probe_url, $args);
    }

    if (is_wp_error($response)) {
        return wp_theme_security_hardening_make_item(
            'uploads_php',
            'unknown',
            $title,
            __('The probe request failed (timeout, DNS, loopback, or TLS). This does not confirm missing protection.', 'wp-theme')
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);

    if (403 === $code) {
        return wp_theme_security_hardening_make_item(
            'uploads_php',
            'good',
            $title,
            __('A request for a non-existent PHP file in uploads returned HTTP 403. The web server is blocking PHP there.', 'wp-theme')
        );
    }

    if (404 === $code || 200 === $code) {
        return wp_theme_security_hardening_make_item(
            'uploads_php',
            'warning',
            $title,
            sprintf(
                /* translators: %d: HTTP status code */
                __('The uploads PHP probe returned HTTP %d. A 403 response would confirm that PHP is blocked. This is not proof that uploads are executable.', 'wp-theme'),
                $code
            )
        );
    }

    return wp_theme_security_hardening_make_item(
        'uploads_php',
        'unknown',
        $title,
        sprintf(
            /* translators: %d: HTTP status code */
            __('The uploads PHP probe returned HTTP %d. Protection could not be verified automatically.', 'wp-theme'),
            $code
        )
    );
}


/**
 * DISALLOW_FILE_EDIT origin: theme vs wp-config.
 *
 * Theme-defined counts as passed: the editor is already disabled. Prefer
 * wp-config.php for earlier bootstrap, but do not warn on every fresh site.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_file_edit_source(): array {
    $title = wp_theme_security_hardening_check_label('file_edit_source');

    if (wp_theme_security_hardening_file_edit_defined_by_theme()) {
        return wp_theme_security_hardening_make_item(
            'file_edit_source',
            'good',
            $title,
            __('DISALLOW_FILE_EDIT is enabled by the theme. Prefer declaring it in wp-config.php before the theme loads so it still applies if the theme fails to load.', 'wp-theme'),
            true
        );
    }

    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
        return wp_theme_security_hardening_make_item(
            'file_edit_source',
            'good',
            $title,
            __('DISALLOW_FILE_EDIT is defined before the theme loads (typically wp-config.php).', 'wp-theme'),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'file_edit_source',
        'warning',
        $title,
        __('DISALLOW_FILE_EDIT is not true. The plugin and theme file editor in wp-admin is available. Set it to true in wp-config.php before the theme loads.', 'wp-theme'),
        true
    );
}


/**
 * Tools tab that holds the copy-paste fix for a check.
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_tab_for_check(string $id): string {
    if ('uploads_php' === $id) {
        return 'web-server';
    }

    if (in_array($id, array('ssl_admin', 'file_edit_source', 'debug_display'), true)) {
        return 'wp-config';
    }

    return 'overview';
}


/**
 * Tools screen URL for a check (relevant tab).
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_admin_url_for_check(string $id): string {
    return wp_theme_security_hardening_tab_url(
        wp_theme_security_hardening_tab_for_check($id)
    );
}


/**
 * Map a hardening check to a Site Health status.
 *
 * Critical findings stay critical only on production. Off production they
 * still appear as recommended improvements so Site Health lists them.
 * Not-applicable skipped checks (e.g. FORCE_SSL_ADMIN on HTTP) pass.
 *
 * @param array<string, mixed> $item Check item.
 * @return array{status:string, color:string}
 */
function wp_theme_security_hardening_site_health_status(array $item): array {
    $status      = (string) ($item['status'] ?? 'unknown');
    $base_status = (string) ($item['base_status'] ?? $status);
    $elevated    = ! empty($item['elevated']);
    $production  = wp_theme_security_hardening_is_production();

    if ('good' === $status) {
        return array(
            'status' => 'good',
            'color'  => 'blue',
        );
    }

    if ('skipped' === $status && (! $elevated || $production)) {
        return array(
            'status' => 'good',
            'color'  => 'blue',
        );
    }

    if ('critical' === $base_status && $production) {
        return array(
            'status' => 'critical',
            'color'  => 'red',
        );
    }

    return array(
        'status' => 'recommended',
        'color'  => 'orange',
    );
}


/**
 * Format a check as a Site Health direct test result.
 *
 * @param string $id Check id.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_format_site_health_test(string $id): array {
    $item  = wp_theme_security_hardening_run_check($id);
    $label = wp_theme_security_hardening_check_label($id);

    if (! is_array($item)) {
        return array(
            'label'       => $label,
            'status'      => 'good',
            'badge'       => array(
                'label' => __('Security', 'wp-theme'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html__('This check does not apply right now.', 'wp-theme') . '</p>',
            'actions'     => wp_theme_security_hardening_site_health_actions($id),
            'test'        => 'wp_theme_security_hardening_' . $id,
        );
    }

    $mapped = wp_theme_security_hardening_site_health_status($item);

    return array(
        'label'       => (string) $item['title'],
        'status'      => $mapped['status'],
        'badge'       => array(
            'label' => __('Security', 'wp-theme'),
            'color' => $mapped['color'],
        ),
        'description' => '<p>' . esc_html((string) $item['description']) . '</p>',
        'actions'     => wp_theme_security_hardening_site_health_actions($id),
        'test'        => 'wp_theme_security_hardening_' . $id,
    );
}


/**
 * Site Health actions HTML linking to the Tools screen.
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_site_health_actions(string $id): string {
    if (! current_user_can('manage_options')) {
        return '';
    }

    return sprintf(
        '<p><a href="%s">%s</a></p>',
        esc_url(wp_theme_security_hardening_admin_url_for_check($id)),
        esc_html(wp_theme_security_hardening_action_label_for_tab(wp_theme_security_hardening_tab_for_check($id)))
    );
}


/**
 * Human-readable status for Site Health → Info.
 *
 * @param array<string, mixed> $item Check item.
 */
function wp_theme_security_hardening_status_label(array $item): string {
    $status   = (string) ($item['status'] ?? 'unknown');
    $elevated = ! empty($item['elevated']);

    if ('skipped' === $status && ! $elevated) {
        return __('Not applicable', 'wp-theme');
    }

    $labels = array(
        'critical' => __('Critical', 'wp-theme'),
        'warning'  => __('Warning', 'wp-theme'),
        'good'     => __('Passed', 'wp-theme'),
        'skipped'  => __('Not scored', 'wp-theme'),
        'unknown'  => __('Could not check automatically', 'wp-theme'),
    );

    return $labels[ $status ] ?? $status;
}


/**
 * Add a Security Hardening section to Site Health → Info.
 *
 * @param array<string, mixed> $info Debug information.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_debug_information(array $info): array {
    $server = wp_theme_security_hardening_detect_server();
    $fields = array(
        'environment' => array(
            'label' => __('Environment type', 'wp-theme'),
            'value' => wp_get_environment_type(),
        ),
        'web_server'  => array(
            'label' => __('Web server', 'wp-theme'),
            'value' => $server['label'] . ('' !== $server['raw'] ? ' (' . $server['raw'] . ')' : ''),
        ),
    );

    foreach (wp_theme_security_hardening_get_results() as $item) {
        $id     = (string) ($item['id'] ?? '');
        $status = (string) ($item['status'] ?? 'unknown');
        if ('' === $id) {
            continue;
        }

        $fields[ $id ] = array(
            'label' => (string) $item['title'],
            'value' => wp_theme_security_hardening_status_label($item),
            'debug' => $status,
        );
    }

    $info['wp-theme-security-hardening'] = array(
        'label'       => __('Security Hardening', 'wp-theme'),
        'description' => __('Theme security checks. Open Tools → Security Hardening for copy-paste fixes.', 'wp-theme'),
        'fields'      => $fields,
    );

    return $info;
}
