<?php
use WP2FA\Utils\Settings_Utils;

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
 * Plugin basenames intentionally hidden from the admin Plugins screen.
 *
 * Empty by default. Child themes and plugins may allowlist via
 * `wp_theme_security_hardening_hidden_plugin_basenames`.
 *
 * @return string[]
 */
function wp_theme_security_hardening_hidden_plugin_basenames(): array {
    $plugins = array();

    /**
     * Filter the list of plugin basenames allowed to be missing from get_plugins().
     *
     * @param string[] $plugins Plugin basenames, e.g. folder/file.php.
     */
    $filtered = apply_filters('wp_theme_security_hardening_hidden_plugin_basenames', $plugins);

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
 * Scan WP_PLUGIN_DIR for plugin basenames with a valid Plugin Name header.
 *
 * Mirrors get_plugins() depth (root PHP and one subdirectory level) but does
 * not apply the all_plugins filter or touch the plugins object-cache group.
 *
 * @return string[]|null Basenames relative to WP_PLUGIN_DIR, or null if unreadable.
 */
function wp_theme_security_hardening_scan_plugin_basenames(): ?array {
    static $done      = false;
    static $basenames = null;

    if ($done) {
        return $basenames;
    }

    $done = true;

    wp_theme_security_hardening_ensure_plugin_admin();

    if (! function_exists('get_plugin_data')) {
        return null;
    }

    if (! defined('WP_PLUGIN_DIR') || ! is_string(WP_PLUGIN_DIR) || '' === WP_PLUGIN_DIR) {
        return null;
    }

    $plugin_root = WP_PLUGIN_DIR;
    if (! is_dir($plugin_root) || ! is_readable($plugin_root)) {
        return null;
    }

    $dir = @opendir($plugin_root);
    if (false === $dir) {
        return null;
    }

    $candidate_files = array();

    while (false !== ($file = readdir($dir))) {
        if (str_starts_with($file, '.')) {
            continue;
        }

        $path = $plugin_root . '/' . $file;

        if (is_dir($path)) {
            $subdir = @opendir($path);
            if (false === $subdir) {
                continue;
            }

            while (false !== ($subfile = readdir($subdir))) {
                if (str_starts_with($subfile, '.')) {
                    continue;
                }

                if (str_ends_with($subfile, '.php')) {
                    $candidate_files[] = $file . '/' . $subfile;
                }
            }

            closedir($subdir);
            continue;
        }

        if (str_ends_with($file, '.php')) {
            $candidate_files[] = $file;
        }
    }

    closedir($dir);

    $found = array();
    foreach ($candidate_files as $plugin_file) {
        $plugin_data = get_plugin_data($plugin_root . '/' . $plugin_file, false, false);
        if (empty($plugin_data['Name'])) {
            continue;
        }

        $found[] = $plugin_file;
    }

    $basenames = array_values(array_unique($found));

    return $basenames;
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
            'debug_mode'        => array(
                'label'       => __('Debug mode', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_debug_mode',
                // Overview only: core Site Health already tests debug display/log.
                'site_health' => false,
            ),
            'ssl_admin'         => array(
                'label'       => __('Force SSL for admin (FORCE_SSL_ADMIN)', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_ssl_admin',
                'site_health' => true,
            ),
            'two_factor'        => array(
                'label'       => __('Two-factor authentication for administrators', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_two_factor',
                'site_health' => true,
            ),
            'user_registration' => array(
                'label'       => __('Open user registration', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_user_registration',
                'site_health' => true,
            ),
            'admin_login'       => array(
                'label'       => __('Predictable login names', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_admin_login',
                'site_health' => true,
            ),
            'updates'           => array(
                'label'       => __('Plugin and theme updates', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_updates',
                'site_health' => true,
            ),
            'hidden_plugins'    => array(
                'label'       => __('Hidden plugins', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_hidden_plugins',
                'site_health' => true,
                'skip_cron'   => true,
            ),
            'uploads_php'       => array(
                'label'       => __('PHP execution in uploads', 'wp-theme'),
                'callback'    => 'wp_theme_security_hardening_check_uploads_php',
                'site_health' => true,
                'skip_cron'   => true,
            ),
            'file_edit_source'  => array(
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
 * @param string $status      critical, warning, good, skipped, not_counted, or unknown.
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
 * Critical and warning findings keep base_status for Overview grouping and
 * color. status becomes not_counted so Site Health and exports can mark them
 * as deferred. Checks that already passed keep the Passed group.
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

    $item['status'] = 'not_counted';

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
 * Enabled debug-oriented constants (names only).
 *
 * Complements Site Health is_in_debug_mode (core still covers display/log
 * separately). This list feeds the merged Debug mode check.
 *
 * @return string[] Constant names that are currently true.
 */
function wp_theme_security_hardening_enabled_debug_constants(): array {
    $enabled = array();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        $enabled[] = 'WP_DEBUG';
    }

    if (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
        $enabled[] = 'WP_DEBUG_DISPLAY';
    } elseif (! defined('WP_DEBUG_DISPLAY') && defined('WP_DEBUG') && WP_DEBUG) {
        $enabled[] = 'WP_DEBUG_DISPLAY';
    }

    if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) {
        $enabled[] = 'SCRIPT_DEBUG';
    }

    if (defined('SAVEQUERIES') && SAVEQUERIES) {
        $enabled[] = 'SAVEQUERIES';
    }

    return array_values(array_unique($enabled));
}


/**
 * Debug mode: WP_DEBUG_DISPLAY, WP_DEBUG, SCRIPT_DEBUG, SAVEQUERIES.
 *
 * Critical when visitors can see errors. Warning when other debug flags
 * are on. Site Health core still ships its own display/log test.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_debug_mode(): array {
    $title   = wp_theme_security_hardening_check_label('debug_mode');
    $enabled = wp_theme_security_hardening_enabled_debug_constants();

    if (array() === $enabled) {
        return wp_theme_security_hardening_make_item(
            'debug_mode',
            'good',
            $title,
            __('WP_DEBUG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, and SAVEQUERIES are off.', 'wp-theme'),
            true
        );
    }

    $names = implode(', ', $enabled);

    if (wp_theme_security_hardening_is_debug_display_on()) {
        return wp_theme_security_hardening_make_item(
            'debug_mode',
            'critical',
            $title,
            sprintf(
                /* translators: %s: comma-separated constant names */
                __('Debug constants are enabled: %s. Visitors may see error details. Set them to false in wp-config.php.', 'wp-theme'),
                $names
            ),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'debug_mode',
        'warning',
        $title,
        sprintf(
            /* translators: %s: comma-separated constant names */
            __('Debug constants are enabled: %s. Set them to false in wp-config.php on production.', 'wp-theme'),
            $names
        ),
        true
    );
}


/**
 * Any open membership registration (stricter than core Site Health).
 *
 * Core insecure_registration only fails when the default role is editor or
 * administrator. Corporate sites should keep registration closed entirely.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_user_registration(): array {
    $title = wp_theme_security_hardening_check_label('user_registration');

    if (! get_option('users_can_register')) {
        return wp_theme_security_hardening_make_item(
            'user_registration',
            'good',
            $title,
            __('Anyone can register is disabled in Settings → General.', 'wp-theme'),
            true
        );
    }

    $default_role = (string) get_option('default_role', 'subscriber');
    $privileged   = in_array($default_role, array('editor', 'administrator'), true);

    if ($privileged) {
        return wp_theme_security_hardening_make_item(
            'user_registration',
            'critical',
            $title,
            sprintf(
                /* translators: %s: default role slug */
                __('Anyone can register is enabled and the default role is %s. Disable registration in Settings → General.', 'wp-theme'),
                $default_role
            ),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'user_registration',
        'critical',
        $title,
        __('Anyone can register is enabled. Disable registration in Settings → General.', 'wp-theme'),
        true
    );
}


/**
 * Logins that should not exist (classic brute-force targets).
 *
 * Child themes and plugins may extend the list via
 * `wp_theme_security_hardening_risky_admin_logins`.
 *
 * @return string[]
 */
function wp_theme_security_hardening_risky_admin_logins(): array {
    $logins = array(
        'admin',
        'administrator',
        'root',
        'wpadmin',
        'wp-admin',
    );

    /**
     * Filter the list of risky administrator login names.
     *
     * @param string[] $logins User login names, e.g. admin.
     */
    $filtered = apply_filters('wp_theme_security_hardening_risky_admin_logins', $logins);

    if (! is_array($filtered)) {
        return $logins;
    }

    $normalized = array();
    foreach ($filtered as $login) {
        if (! is_string($login) || '' === $login) {
            continue;
        }

        $normalized[] = $login;
    }

    return array_values(array_unique($normalized));
}


/**
 * Presence of a privileged user with a predictable default login.
 *
 * Only accounts that can manage_options are scored. Subscriber-level
 * accounts with common names are ignored to avoid false positives.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_admin_login(): array {
    $title  = wp_theme_security_hardening_check_label('admin_login');
    $found  = array();
    $logins = wp_theme_security_hardening_risky_admin_logins();

    foreach ($logins as $login) {
        $user = get_user_by('login', $login);
        if (! $user instanceof WP_User) {
            continue;
        }

        if (! user_can($user, 'manage_options')) {
            continue;
        }

        $found[] = $login;
    }

    if (array() === $found) {
        return wp_theme_security_hardening_make_item(
            'admin_login',
            'good',
            $title,
            __('No administrator accounts use a predictable default login name.', 'wp-theme'),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'admin_login',
        'warning',
        $title,
        sprintf(
            /* translators: %s: comma-separated user logins */
            __('An administrator account exists with a predictable login (%s). Rename that account so attackers cannot target a default username.', 'wp-theme'),
            implode(', ', $found)
        ),
        true
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
            __('The site URL is not HTTPS.', 'wp-theme')
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
 * Two-factor authentication for administrators.
 *
 * One card: plugin presence plus whether administrators must use 2FA.
 * Official Two Factor is checked per user. WP 2FA is checked by policy.
 * Extend recognized plugins with wp_theme_security_hardening_two_factor_plugins.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_two_factor(): array {
    $title = wp_theme_security_hardening_check_label('two_factor');

    if (! wp_theme_security_hardening_is_two_factor_plugin_active()) {
        return wp_theme_security_hardening_make_item(
            'two_factor',
            'warning',
            $title,
            __('No recognized two-factor plugin is active. On production administrators should use two-factor authentication.', 'wp-theme'),
            true
        );
    }

    if (class_exists('Two_Factor_Core') && method_exists(Two_Factor_Core::class, 'is_user_using_two_factor')) {
        return wp_theme_security_hardening_check_two_factor_core_users($title);
    }

    if (wp_theme_security_hardening_is_plugin_active('wp-2fa/wp-2fa.php')) {
        return wp_theme_security_hardening_check_wp2fa_administrators($title);
    }

    return wp_theme_security_hardening_make_item(
        'two_factor',
        'unknown',
        $title,
        __('A two-factor plugin is active, but this check could not confirm that administrators are required to use it. Require two-factor authentication for the administrator role in the plugin settings.', 'wp-theme'),
        true
    );
}


/**
 * Whether WP 2FA requires 2FA for the administrator role.
 *
 * True and false are definite. Null means the saved policy is not a role rule
 * this check can confirm (for example, a list of specific users).
 *
 * @param array<string, mixed> $policy Stored WP 2FA policy.
 */
function wp_theme_security_hardening_wp2fa_administrator_required(array $policy): ?bool {
    $enforcement = isset($policy['enforcement-policy']) ? sanitize_key((string) $policy['enforcement-policy']) : '';

    if ('do-not-enforce' === $enforcement) {
        return false;
    }

    if ('all-users' === $enforcement) {
        $excluded = wp_theme_security_hardening_wp2fa_role_list($policy['excluded_roles'] ?? array());

        return ! in_array('administrator', $excluded, true);
    }

    if ('certain-roles-only' === $enforcement) {
        $enforced = wp_theme_security_hardening_wp2fa_role_list($policy['enforced_roles'] ?? array());

        return in_array('administrator', $enforced, true);
    }

    if ('superadmins-siteadmins-only' === $enforcement) {
        return true;
    }

    return null;
}


/**
 * Normalize a WP 2FA role list (array or comma-separated string).
 *
 * @param mixed $value Raw policy value.
 * @return string[]
 */
function wp_theme_security_hardening_wp2fa_role_list(mixed $value): array {
    if (is_string($value)) {
        $value = explode(',', $value);
    }

    if (! is_array($value)) {
        return array();
    }

    $roles = array();
    foreach ($value as $role) {
        if (! is_string($role)) {
            continue;
        }

        $role = sanitize_key(trim($role));
        if ('' === $role) {
            continue;
        }

        $roles[] = $role;
    }

    return $roles;
}


/**
 * Stored WP 2FA policy, or null when the plugin has not saved one.
 *
 * @return array<string, mixed>|null
 */
function wp_theme_security_hardening_wp2fa_policy(): ?array {
    $name = defined('WP_2FA_POLICY_SETTINGS_NAME') ? WP_2FA_POLICY_SETTINGS_NAME : 'wp_2fa_policy';

    if (class_exists(Settings_Utils::class) && method_exists(Settings_Utils::class, 'get_option')) {
        $stored = Settings_Utils::get_option($name, array());
    } elseif (is_multisite()) {
        $stored = get_site_option($name, array());
    } else {
        $stored = get_option($name, array());
    }

    if (! is_array($stored) || array() === $stored) {
        return null;
    }

    return $stored;
}


/**
 * WP 2FA: pass when the administrator role is required to use 2FA.
 *
 * @param string $title Check title.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_wp2fa_administrators(string $title): array {
    $policy   = wp_theme_security_hardening_wp2fa_policy();
    $required = is_array($policy) ? wp_theme_security_hardening_wp2fa_administrator_required($policy) : null;

    if (true === $required) {
        return wp_theme_security_hardening_make_item(
            'two_factor',
            'good',
            $title,
            __('WP 2FA requires two-factor authentication for the administrator role.', 'wp-theme'),
            true
        );
    }

    if (false === $required) {
        return wp_theme_security_hardening_make_item(
            'two_factor',
            'critical',
            $title,
            __(
                'WP 2FA does not require two-factor authentication for the administrator role. Require it for that role in the plugin policy.',
                'wp-theme'
            ),
            true
        );
    }

    return wp_theme_security_hardening_make_item(
        'two_factor',
        'unknown',
        $title,
        __(
            'WP 2FA is active, but this check could not confirm that the administrator role is required to use two-factor authentication. Set that requirement in the plugin policy.',
            'wp-theme'
        ),
        true
    );
}


/**
 * Official Two Factor plugin: each administrator must have 2FA enabled.
 *
 * @param string $title Check title.
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_two_factor_core_users(string $title): array {
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
            'two_factor',
            'unknown',
            $title,
            __('No administrators were found to check for two-factor authentication.', 'wp-theme'),
            true
        );
    }

    if ($without > 0) {
        return wp_theme_security_hardening_make_item(
            'two_factor',
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
            'two_factor',
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
        'two_factor',
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
            __('%1$d plugin update(s) and %2$d theme update(s) are available. Review and apply them.', 'wp-theme'),
            $plugin_count,
            $theme_count
        ),
        true
    );
}


/**
 * Plugins present on disk with a Plugin Name header but missing from get_plugins().
 *
 * A mismatch usually means something removed entries via the all_plugins filter.
 *
 * @return array<string, mixed>
 */
function wp_theme_security_hardening_check_hidden_plugins(): array {
    $title = wp_theme_security_hardening_check_label('hidden_plugins');

    wp_theme_security_hardening_ensure_plugin_admin();

    $on_disk = wp_theme_security_hardening_scan_plugin_basenames();
    if (null === $on_disk) {
        return wp_theme_security_hardening_make_item(
            'hidden_plugins',
            'unknown',
            $title,
            __('The plugins directory could not be read, so hidden plugins could not be checked.', 'wp-theme')
        );
    }

    if (! function_exists('get_plugins')) {
        return wp_theme_security_hardening_make_item(
            'hidden_plugins',
            'unknown',
            $title,
            __('Plugin listing helpers are unavailable, so hidden plugins could not be checked.', 'wp-theme')
        );
    }

    $visible   = get_plugins();
    $visible   = is_array($visible) ? array_keys($visible) : array();
    $allowlist = wp_theme_security_hardening_hidden_plugin_basenames();
    $hidden    = array();

    foreach ($on_disk as $basename) {
        if (in_array($basename, $allowlist, true)) {
            continue;
        }

        if (in_array($basename, $visible, true)) {
            continue;
        }

        $hidden[] = $basename;
    }

    sort($hidden, SORT_STRING);

    if (array() === $hidden) {
        return wp_theme_security_hardening_make_item(
            'hidden_plugins',
            'good',
            $title,
            __('Every plugin with a valid header under wp-content/plugins appears in the admin Plugins list.', 'wp-theme')
        );
    }

    return wp_theme_security_hardening_make_item(
        'hidden_plugins',
        'critical',
        $title,
        sprintf(
            /* translators: %s: comma-separated plugin basenames */
            __('Plugins with a valid header exist on disk but are missing from the admin Plugins list: %s. Review those paths under wp-content/plugins.', 'wp-theme'),
            implode(', ', $hidden)
        )
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
            __('The probe request failed. Open the Web server tab to add protection for PHP in uploads.', 'wp-theme')
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
                __('The uploads PHP probe returned HTTP %d. A 403 response would confirm that PHP is blocked. This is not proof that uploads are executable. Open the Web server tab for the fix.', 'wp-theme'),
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
            __('The uploads PHP probe returned HTTP %d. Open the Web server tab to add protection for PHP in uploads.', 'wp-theme'),
            $code
        )
    );
}


/**
 * DISALLOW_FILE_EDIT origin: theme vs wp-config.
 *
 * Theme-defined counts as passed: the editor is already disabled. Prefer
 * wp-config.php for earlier bootstrap; that tip lives on the wp-config tab.
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
            __('DISALLOW_FILE_EDIT is enabled. The plugin and theme file editor in wp-admin is disabled.', 'wp-theme'),
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
        __('DISALLOW_FILE_EDIT is not true. The plugin and theme file editor in wp-admin is available. Set it to true in wp-config.php.', 'wp-theme'),
        true
    );
}


/**
 * Tools tab that holds the copy-paste fix for a check, or empty when none.
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_tab_for_check(string $id): string {
    if ('uploads_php' === $id) {
        return 'web-server';
    }

    if (in_array($id, array('ssl_admin', 'file_edit_source', 'debug_mode'), true)) {
        return 'wp-config';
    }

    return '';
}


/**
 * Admin action link for a failed or inconclusive check.
 *
 * @param string $id Check id.
 * @return array{url:string, label:string}|null
 */
function wp_theme_security_hardening_action_for_check(string $id): ?array {
    $tab = wp_theme_security_hardening_tab_for_check($id);

    if ('web-server' === $tab) {
        return array(
            'url'   => wp_theme_security_hardening_tab_url('web-server'),
            'label' => __('Web server', 'wp-theme'),
        );
    }

    if ('wp-config' === $tab) {
        return array(
            'url'   => wp_theme_security_hardening_tab_url('wp-config'),
            'label' => 'wp-config.php',
        );
    }

    if ('two_factor' === $id) {
        if (wp_theme_security_hardening_is_two_factor_plugin_active()) {
            return array(
                'url'   => admin_url('users.php'),
                'label' => __('Users', 'wp-theme'),
            );
        }

        return array(
            'url'   => admin_url('plugins.php'),
            'label' => __('Plugins', 'wp-theme'),
        );
    }

    $external = array(
        'user_registration' => array(
            'url'   => admin_url('options-general.php'),
            'label' => __('Settings → General', 'wp-theme'),
        ),
        'admin_login'       => array(
            'url'   => admin_url('users.php'),
            'label' => __('Users', 'wp-theme'),
        ),
        'updates'           => array(
            'url'   => admin_url('update-core.php'),
            'label' => __('Updates', 'wp-theme'),
        ),
        'hidden_plugins'    => array(
            'url'   => admin_url('plugins.php'),
            'label' => __('Plugins', 'wp-theme'),
        ),
    );

    if (! isset($external[ $id ])) {
        return null;
    }

    return $external[ $id ];
}


/**
 * Tools screen URL for a check (relevant tab or overview).
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_admin_url_for_check(string $id): string {
    $action = wp_theme_security_hardening_action_for_check($id);

    if (is_array($action)) {
        return $action['url'];
    }

    return wp_theme_security_hardening_tab_url('overview');
}


/**
 * Map a hardening check to a Site Health status.
 *
 * Critical findings stay critical only on production. Off production they
 * still appear as recommended improvements so Site Health lists them.
 * Not-applicable skipped checks (e.g. FORCE_SSL_ADMIN on HTTP) pass.
 * Empty/null results are recommended, not good.
 *
 * @param array<string, mixed> $item Check item.
 * @return array{status:string, color:string}
 */
function wp_theme_security_hardening_site_health_status(array $item): array {
    $status      = (string) ($item['status'] ?? 'unknown');
    $base_status = (string) ($item['base_status'] ?? $status);
    $production  = wp_theme_security_hardening_is_production();

    if ('good' === $status) {
        return array(
            'status' => 'good',
            'color'  => 'blue',
        );
    }

    if ('skipped' === $status) {
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
            'status'      => 'recommended',
            'badge'       => array(
                'label' => __('Security', 'wp-theme'),
                'color' => 'orange',
            ),
            'description' => '<p>' . esc_html__('This check could not run.', 'wp-theme') . '</p>',
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
 * Site Health actions HTML linking to the fix screen.
 *
 * @param string $id Check id.
 */
function wp_theme_security_hardening_site_health_actions(string $id): string {
    if (! current_user_can('manage_options')) {
        return '';
    }

    $action = wp_theme_security_hardening_action_for_check($id);
    if (! is_array($action)) {
        return '';
    }

    return sprintf(
        '<p><a href="%s">%s</a></p>',
        esc_url($action['url']),
        esc_html($action['label'])
    );
}


/**
 * Human-readable status for Site Health → Info.
 *
 * Deferred findings keep their inherent severity in the label so exports do
 * not lose base_status.
 *
 * @param array<string, mixed> $item Check item.
 */
function wp_theme_security_hardening_status_label(array $item): string {
    $status = (string) ($item['status'] ?? 'unknown');

    if ('skipped' === $status) {
        return __('Not applicable', 'wp-theme');
    }

    $labels = array(
        'critical' => __('Critical', 'wp-theme'),
        'warning'  => __('Warning', 'wp-theme'),
        'good'     => __('Passed', 'wp-theme'),
        'unknown'  => __('Could not check automatically', 'wp-theme'),
    );

    if ('not_counted' === $status) {
        $base       = (string) ($item['base_status'] ?? 'unknown');
        $base_label = $labels[ $base ] ?? $base;

        return sprintf(
            /* translators: %s: Critical or Warning */
            __('%s, not counted outside production', 'wp-theme'),
            $base_label
        );
    }

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
