<?php
/**
 * Security Hardening admin screen markup.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Allowed admin tabs.
 *
 * @return array<string, string>
 */
function wp_theme_security_hardening_tabs(): array {
    return array(
        'overview'   => __('Overview', 'wp-theme'),
        'web-server' => __('Web server', 'wp-theme'),
        'wp-config'  => __('wp-config', 'wp-theme'),
    );
}


/**
 * Current tab from the request.
 */
function wp_theme_security_hardening_current_tab(): string {
    $tab = 'overview';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
    if (isset($_GET['tab'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
        $tab = sanitize_key(wp_unslash((string) $_GET['tab']));
    }

    if ('nginx' === $tab || 'apache' === $tab) {
        return 'web-server';
    }

    $tabs = wp_theme_security_hardening_tabs();
    if (! isset($tabs[ $tab ])) {
        return 'overview';
    }

    return $tab;
}


/**
 * Admin URL for a tab.
 *
 * @param string $tab Tab slug.
 */
function wp_theme_security_hardening_tab_url(string $tab): string {
    return add_query_arg(
        array(
            'page' => wp_theme_security_hardening_page_slug(),
            'tab'  => $tab,
        ),
        admin_url('tools.php')
    );
}


/**
 * Detect the web server family using WordPress globals from vars.php.
 *
 * @global bool $is_apache
 * @global bool $is_nginx
 * @global bool $is_iis7
 * @global bool $is_IIS
 * @global bool $is_litespeed
 * @return array{family:string, label:string, raw:string}
 */
function wp_theme_security_hardening_detect_server(): array {
    global $is_apache, $is_nginx, $is_iis7, $is_IIS, $is_litespeed;

    $raw = '';
    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        $raw = sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE']));
    }

    if (! empty($is_nginx)) {
        return array(
            'family' => 'nginx',
            'label'  => 'Nginx',
            'raw'    => $raw,
        );
    }

    if (! empty($is_litespeed)) {
        return array(
            'family' => 'apache',
            'label'  => 'LiteSpeed',
            'raw'    => $raw,
        );
    }

    if (! empty($is_apache)) {
        return array(
            'family' => 'apache',
            'label'  => 'Apache',
            'raw'    => $raw,
        );
    }

    if (! empty($is_iis7) || ! empty($is_IIS)) {
        return array(
            'family' => 'unknown',
            'label'  => 'IIS',
            'raw'    => $raw,
        );
    }

    return array(
        'family' => 'unknown',
        'label'  => __('Unknown', 'wp-theme'),
        'raw'    => $raw,
    );
}


/**
 * Nginx location snippet (uploads PHP block).
 */
function wp_theme_security_hardening_nginx_snippet(): string {
    return <<<'NGINX'
location ~* ^/wp-content/uploads/.*\.(php[0-9]*|phtml|phar)(\.|/|$) {
    return 403;
}
NGINX;
}


/**
 * Nginx verification commands with the real uploads URL.
 */
function wp_theme_security_hardening_nginx_verify_commands(): string {
    $uploads = wp_upload_dir();
    $baseurl = is_array($uploads) ? (string) ($uploads['baseurl'] ?? '') : '';

    if ('' === $baseurl || ! empty($uploads['error'])) {
        $url = home_url('/wp-content/uploads/security-test.php');
    } else {
        $url = trailingslashit($baseurl) . 'security-test.php';
    }

    return "sudo nginx -t && sudo systemctl reload nginx\ncurl -I " . $url . "\n";
}


/**
 * Apache FilesMatch snippet for uploads/.htaccess only.
 */
function wp_theme_security_hardening_apache_htaccess_snippet(): string {
    return <<<'APACHE'
<FilesMatch "\.(php[0-9]*|phtml|phar)(\.|$)">
    Require all denied
</FilesMatch>
APACHE;
}


/**
 * Apache VirtualHost snippet scoped to the uploads directory.
 */
function wp_theme_security_hardening_apache_vhost_snippet(): string {
    $uploads = wp_upload_dir();
    $basedir = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';
    $basedir = (string) wp_normalize_path($basedir);
    $basedir = str_replace('"', '', $basedir);

    if ('' === $basedir) {
        $basedir = '/path/to/wp-content/uploads';
    }

    return '<Directory "' . $basedir . '">' . "\n"
        . '    <FilesMatch "\.(php[0-9]*|phtml|phar)(\.|$)">' . "\n"
        . '        Require all denied' . "\n"
        . '    </FilesMatch>' . "\n"
        . '</Directory>' . "\n";
}


/**
 * wp-config.php constants snippet.
 */
function wp_theme_security_hardening_wp_config_snippet(): string {
    return <<<'PHP'
define( 'DISALLOW_FILE_EDIT', true );
define( 'FORCE_SSL_ADMIN', true );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
define( 'SAVEQUERIES', false );
define( 'WP_ENVIRONMENT_TYPE', 'production' );
PHP;
}


/**
 * Render a readonly snippet with a Copy button.
 *
 * @param string $id      Element id.
 * @param string $code    Snippet body.
 * @param bool   $is_pre  Use pre instead of textarea.
 * @param int    $rows    Textarea rows.
 * @param string $heading Optional heading above the snippet.
 */
function wp_theme_security_hardening_render_copyable(
    string $id,
    string $code,
    bool $is_pre = false,
    int $rows = 6,
    string $heading = ''
): void {
    echo '<div class="wp-theme-security-hardening-copy">';
    echo '<div class="wp-theme-security-hardening-copy-toolbar">';
    if ('' !== $heading) {
        echo '<h3>' . esc_html($heading) . '</h3>';
    }

    echo '<button type="button" class="button wp-theme-security-hardening-copy-button" data-copy-target="' . esc_attr($id) . '">';
    echo esc_html__('Copy', 'wp-theme');
    echo '</button>';
    echo '</div>';

    if ($is_pre) {
        echo '<pre class="code" id="' . esc_attr($id) . '">' . esc_html($code) . '</pre>';
    } else {
        echo '<textarea readonly="readonly" class="large-text code" id="' . esc_attr($id) . '" rows="' . esc_attr((string) $rows) . '">';
        echo esc_textarea($code);
        echo '</textarea>';
    }

    echo '</div>';
}


/**
 * Render the Tools page.
 */
function wp_theme_security_hardening_render_admin_page(): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Sorry, you are not allowed to do this.', 'wp-theme'));
    }

    $tabs    = wp_theme_security_hardening_tabs();
    $current = wp_theme_security_hardening_current_tab();
    ?>
    <div class="wrap wp-theme-security-hardening">
        <h1><?php esc_html_e('Security Hardening', 'wp-theme'); ?></h1>

        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label) : ?>
                <?php $active = $current === $slug; ?>
                <a
                    href="<?php echo esc_url(wp_theme_security_hardening_tab_url($slug)); ?>"
                    class="nav-tab<?php echo $active ? ' nav-tab-active' : ''; ?>"
                    <?php echo $active ? ' aria-current="page"' : ''; ?>
                >
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        match ($current) {
            'web-server' => wp_theme_security_hardening_render_tab_web_server(),
            'wp-config' => wp_theme_security_hardening_render_tab_wp_config(),
            default => wp_theme_security_hardening_render_tab_overview(),
        };
    ?>
    </div>
    <?php
}


/**
 * Overview tab.
 *
 * Cards group by inherent severity (base_status). Deferred elevated findings
 * outside production stay in Critical or Warnings with a badge; color always
 * matches the group.
 */
function wp_theme_security_hardening_render_tab_overview(): void {
    $env = wp_get_environment_type();
    $groups = array(
        'critical' => array(
            'title'           => __('Critical', 'wp-theme'),
            'class'           => 'notice-error',
            'items'           => array(),
            'hide_when_empty' => false,
        ),
        'warning'  => array(
            'title'           => __('Warnings', 'wp-theme'),
            'class'           => 'notice-warning',
            'items'           => array(),
            'hide_when_empty' => false,
        ),
        'unknown'  => array(
            'title'           => __('Could not check automatically', 'wp-theme'),
            'class'           => 'notice-info',
            'items'           => array(),
            'hide_when_empty' => true,
        ),
        'good'     => array(
            'title'           => __('Passed', 'wp-theme'),
            'class'           => 'notice-success',
            'items'           => array(),
            'hide_when_empty' => false,
        ),
    );

    foreach (wp_theme_security_hardening_get_results() as $item) {
        $status = (string) ($item['status'] ?? 'unknown');

        // Not-applicable checks (e.g. FORCE_SSL_ADMIN on HTTP) stay off Overview.
        if ('skipped' === $status) {
            continue;
        }

        // Deferred findings keep their group by inherent severity.
        if ('not_counted' === $status) {
            $base = (string) ($item['base_status'] ?? 'unknown');
            $status = in_array($base, array('critical', 'warning'), true) ? $base : 'unknown';
        }

        if (! isset($groups[ $status ])) {
            $status = 'unknown';
        }

        $groups[ $status ]['items'][] = $item;
    }

    // Counted findings first, then deferred; catalog order within each part.
    foreach (array('critical', 'warning') as $key) {
        $counted  = array();
        $deferred = array();

        foreach ($groups[ $key ]['items'] as $item) {
            if ('not_counted' === (string) ($item['status'] ?? '')) {
                $deferred[] = $item;
            } else {
                $counted[] = $item;
            }
        }

        $groups[ $key ]['items'] = array_merge($counted, $deferred);
    }
    ?>
    <div class="wp-theme-security-hardening-meta">
        <p>
            <strong><?php esc_html_e('Environment type', 'wp-theme'); ?>:</strong>
            <code><?php echo esc_html($env); ?></code>
        </p>
        <p class="description">
            <?php
            esc_html_e(
                'While the environment type is not production, checks marked Not counted outside production stay in Critical or Warnings but are not counted. Checks without that badge are counted in every environment.',
                'wp-theme'
            );
            ?>
            <a href="<?php echo esc_url(wp_theme_security_hardening_tab_url('wp-config')); ?>">
                <?php esc_html_e('How to set WP_ENVIRONMENT_TYPE', 'wp-theme'); ?>
            </a>
        </p>
    </div>

    <?php foreach ($groups as $group) : ?>
        <?php if (array() !== $group['items'] || empty($group['hide_when_empty'])) : ?>
            <div class="wp-theme-security-hardening-group">
                <h2><?php echo esc_html($group['title']); ?></h2>
                <?php if (array() === $group['items']) : ?>
                    <p><?php esc_html_e('None.', 'wp-theme'); ?></p>
                <?php else : ?>
                    <?php foreach ($group['items'] as $item) : ?>
                        <?php
                        $is_deferred = 'not_counted' === (string) ($item['status'] ?? '');
                        ?>
                        <div class="notice <?php echo esc_attr($group['class']); ?> inline">
                            <p>
                                <strong><?php echo esc_html((string) $item['title']); ?></strong>
                                <?php if ($is_deferred) : ?>
                                    <span class="wp-theme-security-hardening-badge">
                                        <?php esc_html_e('Not counted outside production', 'wp-theme'); ?>
                                    </span>
                                <?php endif; ?>
                                <br>
                                <?php echo esc_html((string) $item['description']); ?>
                            </p>
                            <?php wp_theme_security_hardening_render_check_action($item); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php
}


/**
 * Link from a check that is not Passed to its fix screen, if any.
 *
 * @param array<string, mixed> $item Check item.
 */
function wp_theme_security_hardening_render_check_action(array $item): void {
    $id     = isset($item['id']) ? (string) $item['id'] : '';
    $status = isset($item['status']) ? (string) $item['status'] : '';

    if ('' === $id || 'good' === $status) {
        return;
    }

    $action = wp_theme_security_hardening_action_for_check($id);
    if (! is_array($action)) {
        return;
    }

    echo '<p class="wp-theme-security-hardening-action">';
    echo '<a href="' . esc_url($action['url']) . '">';
    echo esc_html($action['label']);
    echo '</a>';
    echo '</p>';
}


/**
 * Web server tab: show instructions for the detected server.
 */
function wp_theme_security_hardening_render_tab_web_server(): void {
    $server = wp_theme_security_hardening_detect_server();
    ?>
    <div class="wp-theme-security-hardening-meta">
        <p>
            <strong><?php esc_html_e('Detected web server:', 'wp-theme'); ?></strong>
            <?php echo esc_html($server['label']); ?>
            <?php if ('' !== $server['raw']) : ?>
                <code><?php echo esc_html($server['raw']); ?></code>
            <?php endif; ?>
        </p>
        <?php if ('unknown' !== $server['family']) : ?>
            <p class="description">
                <?php esc_html_e('Detected by WordPress from the server signature. A reverse proxy can hide the real server.', 'wp-theme'); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php if ('nginx' === $server['family']) : ?>
        <h2><?php echo esc_html($server['label']); ?></h2>
        <?php wp_theme_security_hardening_render_section_nginx(); ?>
        <details class="wp-theme-security-hardening-other-server">
            <summary><?php esc_html_e('Apache / LiteSpeed instructions', 'wp-theme'); ?></summary>
            <?php wp_theme_security_hardening_render_section_apache(); ?>
        </details>
    <?php elseif ('apache' === $server['family']) : ?>
        <p class="description">
            <?php esc_html_e('If production uses Nginx, open the Nginx instructions below.', 'wp-theme'); ?>
        </p>
        <h2><?php echo esc_html($server['label']); ?></h2>
        <?php wp_theme_security_hardening_render_section_apache(); ?>
        <details class="wp-theme-security-hardening-other-server">
            <summary><?php esc_html_e('Nginx instructions', 'wp-theme'); ?></summary>
            <?php wp_theme_security_hardening_render_section_nginx(); ?>
        </details>
    <?php else : ?>
        <div class="notice notice-warning inline">
            <p>
                <?php esc_html_e('WordPress could not tell whether this site runs Nginx or Apache. A reverse proxy can hide the real server. Both instruction sets are shown.', 'wp-theme'); ?>
            </p>
        </div>
        <h2><?php esc_html_e('Nginx', 'wp-theme'); ?></h2>
        <?php wp_theme_security_hardening_render_section_nginx(); ?>
        <h2><?php esc_html_e('Apache', 'wp-theme'); ?></h2>
        <?php wp_theme_security_hardening_render_section_apache(); ?>
    <?php endif; ?>
    <?php
}


/**
 * Nginx instructions.
 */
function wp_theme_security_hardening_render_section_nginx(): void {
    ?>
    <p>
        <?php esc_html_e('Add this location to the site nginx config, above the general PHP handler. The theme does not change nginx and does not run these commands.', 'wp-theme'); ?>
    </p>
    <?php
    wp_theme_security_hardening_render_copyable(
        'wp-theme-hardening-nginx-snippet',
        wp_theme_security_hardening_nginx_snippet(),
        false,
        6,
        __('Nginx location', 'wp-theme')
    );
    ?>
    <p>
        <?php esc_html_e('After saving the config, test and reload nginx, then request a dummy PHP file. The expected response is HTTP 403.', 'wp-theme'); ?>
    </p>
    <?php
    wp_theme_security_hardening_render_copyable(
        'wp-theme-hardening-nginx-verify',
        wp_theme_security_hardening_nginx_verify_commands(),
        false,
        4,
        __('Reload and test', 'wp-theme')
    );
}


/**
 * Apache / LiteSpeed instructions.
 */
function wp_theme_security_hardening_render_section_apache(): void {
    ?>
    <p>
        <?php esc_html_e('Paste the first snippet only into wp-content/uploads/.htaccess.', 'wp-theme'); ?>
        <?php esc_html_e('Do not put it in the site-root .htaccess or in unscoped VirtualHost extra directives: that FilesMatch would deny PHP for the whole site.', 'wp-theme'); ?>
    </p>
    <?php
    wp_theme_security_hardening_render_copyable(
        'wp-theme-hardening-apache-htaccess',
        wp_theme_security_hardening_apache_htaccess_snippet(),
        false,
        6,
        __('uploads/.htaccess', 'wp-theme')
    );
    ?>
    <p>
        <?php esc_html_e('If you use a root-owned VirtualHost, paste the Directory block instead. It is already limited to the uploads path of this site. The theme does not create or overwrite .htaccess.', 'wp-theme'); ?>
    </p>
    <?php
    wp_theme_security_hardening_render_copyable(
        'wp-theme-hardening-apache-vhost',
        wp_theme_security_hardening_apache_vhost_snippet(),
        false,
        8,
        __('VirtualHost', 'wp-theme')
    );
}


/**
 * wp-config tab.
 */
function wp_theme_security_hardening_render_tab_wp_config(): void {
    ?>
    <p>
        <?php esc_html_e('Copy these constants into wp-config.php, above the "That\'s all, stop editing!" line. The theme does not change that file.', 'wp-theme'); ?>
    </p>
    <p>
        <?php esc_html_e('FORCE_SSL_ADMIN redirects wp-admin and login to HTTPS.', 'wp-theme'); ?>
    </p>
    <p>
        <?php esc_html_e('WP_DEBUG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, and SAVEQUERIES should be false on production so visitors and logs do not receive debug output.', 'wp-theme'); ?>
    </p>
    <p>
        <?php esc_html_e('WP_ENVIRONMENT_TYPE accepts only production, staging, development, and local. Without this constant WordPress treats the site as production.', 'wp-theme'); ?>
    </p>
    <?php if (wp_theme_security_hardening_file_edit_defined_by_theme()) : ?>
        <p class="description">
            <?php esc_html_e('DISALLOW_FILE_EDIT is currently set by the theme. Declaring it in wp-config.php before the theme loads is slightly more reliable if the theme fails to load.', 'wp-theme'); ?>
        </p>
    <?php endif; ?>
    <?php
    wp_theme_security_hardening_render_copyable(
        'wp-theme-hardening-wp-config-snippet',
        wp_theme_security_hardening_wp_config_snippet(),
        false,
        9,
        'wp-config.php'
    );
}
