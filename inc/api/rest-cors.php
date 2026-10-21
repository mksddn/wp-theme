<?php
/**
 * Headless REST CORS allowlist (replaces core rest_send_cors_headers when active).
 *
 * @package wp-theme
 */

if (! defined( 'ABSPATH' )) {
    exit;
}


/**
 * Allowed frontend origins: saved setting, filterable, normalized.
 *
 * @return string[]
 */
function wp_theme_get_rest_allowed_origins(): array {
    $settings = wp_theme_get_settings();
    $origins  = is_array( $settings['rest_allowed_origins'] ?? null ) ? $settings['rest_allowed_origins'] : array();

    /**
     * Filters the allowed REST CORS origins (e.g. to add localhost in development).
     *
     * @param string[] $origins Normalized origins.
     */
    $origins = apply_filters( 'wp_theme_rest_allowed_origins', $origins );

    if (! is_array( $origins )) {
        return array();
    }

    $normalized = array_map( static fn( $origin ): string => wp_theme_normalize_http_origin( (string) $origin ), $origins );

    return array_values( array_unique( array_filter( $normalized ) ) );
}


/**
 * Whether the CORS allowlist is active: Headless mode on and at least one origin.
 */
function wp_theme_is_rest_cors_active(): bool {
    $settings = wp_theme_get_settings();

    return ! empty( $settings['headless'] ) && array() !== wp_theme_get_rest_allowed_origins();
}


/**
 * Whether an Origin header value is allowed (listed origin or this site itself).
 *
 * @param string $origin Raw Origin header value.
 */
function wp_theme_is_allowed_rest_origin( string $origin ): bool {
    $normalized = wp_theme_normalize_http_origin( $origin );

    if ('' === $normalized) {
        return false;
    }

    // This site always counts as allowed: wp-admin (Gutenberg, ACF) calls the API from its own origin.
    $site_origins = array_map(
        static fn( string $url ): string => wp_theme_normalize_http_origin( $url ),
        array( home_url(), site_url() )
    );

    return in_array( $normalized, array_merge( wp_theme_get_rest_allowed_origins(), $site_origins ), true );
}


/**
 * CORS response headers for a request Origin (Allow-Credentials is intentionally never sent).
 *
 * @param string $origin Raw Origin header value, empty when absent.
 * @return array<string, string> Header name => value.
 */
function wp_theme_get_rest_cors_headers( string $origin ): array {
    $headers = array( 'Vary' => 'Origin' );

    if ('' !== $origin && wp_theme_is_allowed_rest_origin( $origin )) {
        $headers['Access-Control-Allow-Origin']  = wp_theme_normalize_http_origin( $origin );
        $headers['Access-Control-Allow-Methods'] = 'OPTIONS, GET, POST, PUT, PATCH, DELETE';
    }

    return $headers;
}


/**
 * Send CORS headers on rest_pre_serve_request (instead of the core handler).
 *
 * @param mixed $served Whether the request has already been served.
 * @return mixed
 */
function wp_theme_send_rest_cors_headers( $served ) {
    if (headers_sent()) {
        return $served;
    }

    // Drop core/plugin CORS headers that may already have been sent (e.g. reflect-any + credentials).
    header_remove( 'Access-Control-Allow-Origin' );
    header_remove( 'Access-Control-Allow-Methods' );
    header_remove( 'Access-Control-Allow-Credentials' );

    foreach (wp_theme_get_rest_cors_headers( (string) get_http_origin() ) as $name => $value) {
        // Vary may already hold other values; the rest must replace.
        header( $name . ': ' . $value, 'Vary' !== $name );
    }

    return $served;
}


/**
 * Swap the core CORS handler for the allowlist and disable JSONP (it bypasses CORS).
 */
function wp_theme_register_rest_cors(): void {
    if (! wp_theme_is_rest_cors_active()) {
        return;
    }

    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    // Priority 100: run after core (10) or a re-added plugin handler so our headers win.
    add_filter( 'rest_pre_serve_request', 'wp_theme_send_rest_cors_headers', 100 );
    add_filter( 'rest_jsonp_enabled', '__return_false' );
}


// Core registers rest_send_cors_headers on rest_api_init at priority 10; run after it.
add_action( 'rest_api_init', 'wp_theme_register_rest_cors', 15 );
