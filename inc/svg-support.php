<?php
/**
 * SVG uploads for users who can upload media, checked before the file is stored.
 *
 * Drawing markup and filter primitives are kept. Files with styles, scripts,
 * animation, or external images are rejected so a stripped logo is not saved.
 *
 * @package wp-theme
 */

if (! defined('ABSPATH')) {
    exit;
}


/**
 * Capability required to upload SVG files.
 *
 * Defaults to upload_files. Use the filter to restrict SVG to a stricter
 * capability such as manage_options.
 */
function wp_theme_svg_upload_capability(): string {
    $capability = apply_filters('wp_theme_svg_upload_capability', 'upload_files');

    return is_string($capability) && '' !== $capability ? $capability : 'upload_files';
}


/**
 * Allow SVG in the upload MIME list for users who can upload files.
 *
 * @param array<string, string> $mimes Allowed MIME types.
 * @return array<string, string>
 */
function wp_theme_svg_upload_allow(array $mimes): array {
    if (current_user_can(wp_theme_svg_upload_capability())) {
        $mimes['svg'] = 'image/svg+xml';
    }

    return $mimes;
}


add_filter('upload_mimes', 'wp_theme_svg_upload_allow');


/**
 * Accept SVG after WordPress MIME sniffing for users who can upload files.
 *
 * finfo often reports SVG as text/xml or text/plain, which makes core reject
 * the file. The temporary file is already sanitized by then.
 *
 * @param array<string, mixed> $data     Detected extension and MIME type.
 * @param string               $file     Absolute path to the uploaded file.
 * @param string               $filename Original filename.
 * @param array<string, string>|null $mimes Allowed MIME types.
 * @param string               $real_mime MIME type from finfo.
 * @return array<string, mixed>
 */
function wp_theme_fix_svg_mime_type(array $data, $file, $filename, $mimes, $real_mime = ''): array {
    $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

    if ('svg' !== $extension) {
        return $data;
    }

    if (! current_user_can(wp_theme_svg_upload_capability()) || ! wp_theme_file_contains_svg_root((string) $file)) {
        $data['ext']  = false;
        $data['type'] = false;

        return $data;
    }

    $data['ext']  = 'svg';
    $data['type'] = 'image/svg+xml';

    return $data;
}


add_filter('wp_check_filetype_and_ext', 'wp_theme_fix_svg_mime_type', 10, 5);


/**
 * Sanitize SVG on upload and on sideload before it is moved into uploads.
 *
 * @param array<string, mixed> $file A single $_FILES entry.
 * @return array<string, mixed>
 */
function wp_theme_sanitize_svg_upload(array $file): array {
    if (! empty($file['error']) || ! wp_theme_upload_is_svg($file)) {
        return $file;
    }

    if (! current_user_can(wp_theme_svg_upload_capability())) {
        $file['error'] = __('Sorry, you are not allowed to upload SVG files.', 'wp-theme');

        return $file;
    }

    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

    if ('' === $tmp_name || ! is_readable($tmp_name)) {
        $file['error'] = __('This SVG file could not be read.', 'wp-theme');

        return $file;
    }

    $result = wp_theme_sanitize_svg_file($tmp_name);

    if ($result instanceof WP_Error) {
        $file['error'] = $result->get_error_message();

        return $file;
    }

    clearstatcache(true, $tmp_name);

    $file['size'] = (int) filesize($tmp_name);
    $file['type'] = 'image/svg+xml';

    return $file;
}


/**
 * Sanitize an SVG file in place.
 *
 * @param string $path Absolute path to the file.
 * @return true|WP_Error
 */
function wp_theme_sanitize_svg_file(string $path): bool|WP_Error {
    if ('' === $path || ! is_readable($path) || ! is_writable($path)) {
        return new WP_Error('wp_theme_svg_unreadable', __('This SVG file could not be read.', 'wp-theme'));
    }

    $dirty = file_get_contents($path);

    if (! is_string($dirty) || '' === $dirty) {
        return new WP_Error('wp_theme_svg_empty_file', __('This SVG file is empty.', 'wp-theme'));
    }

    $clean = wp_theme_sanitize_svg_markup($dirty);

    if (is_wp_error($clean)) {
        return $clean;
    }

    if (false === file_put_contents($path, $clean)) {
        return new WP_Error('wp_theme_svg_unsaved', __('This SVG file could not be saved after sanitizing.', 'wp-theme'));
    }

    return true;
}


/**
 * Safety net for SVG that reached the library without the upload prefilters.
 *
 * wp_upload_bits() (XML-RPC uploads, some importers) never runs
 * wp_handle_upload_prefilter. Sanitize again when the attachment is created
 * and delete it if the file cannot be made safe. Sanitizing is idempotent.
 *
 * @param int $attachment_id Attachment post ID.
 */
function wp_theme_sanitize_svg_attachment(int $attachment_id): void {
    if ('image/svg+xml' !== get_post_mime_type($attachment_id)) {
        return;
    }

    $path = get_attached_file($attachment_id);

    if (! is_string($path) || '' === $path) {
        return;
    }

    if (is_wp_error(wp_theme_sanitize_svg_file($path))) {
        wp_delete_attachment($attachment_id, true);
    }
}


add_action('add_attachment', 'wp_theme_sanitize_svg_attachment');


add_filter('wp_handle_upload_prefilter', 'wp_theme_sanitize_svg_upload');
add_filter('wp_handle_sideload_prefilter', 'wp_theme_sanitize_svg_upload');


/**
 * Show SVG attachments as images in the media modal.
 *
 * @param array<string, mixed> $response Attachment data for JavaScript.
 * @return array<string, mixed>
 */
function wp_theme_show_svg_in_media_library(array $response): array {
    if (isset($response['mime'], $response['url']) && 'image/svg+xml' === $response['mime']) {
        $response['image'] = array(
            'src' => $response['url'],
        );
    }

    return $response;
}


add_filter('wp_prepare_attachment_for_js', 'wp_theme_show_svg_in_media_library');


/**
 * Keep drawing markup and drop editor junk. Reject active or remote content.
 *
 * @return string|WP_Error Sanitized document, or an error that blocks the upload.
 */
function wp_theme_sanitize_svg_markup(string $dirty): string|WP_Error {
    if (str_contains($dirty, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $dirty)) {
        return wp_theme_svg_invalid_error();
    }

    if (preg_match('/<\?(?:php|=)/i', $dirty)) {
        return wp_theme_svg_active_error();
    }

    $stripped = wp_theme_svg_strip_php($dirty);

    if ($stripped instanceof WP_Error) {
        return $stripped;
    }

    if ('' === $stripped) {
        return wp_theme_svg_invalid_error();
    }

    if (preg_match('/<\?xml-stylesheet\b/i', $stripped)) {
        return wp_theme_svg_remote_error();
    }

    $previous_errors = libxml_use_internal_errors(true);
    $dom             = new DOMDocument();
    $loaded          = $dom->loadXML($stripped, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);

    if (! $loaded || ! $dom->documentElement instanceof DOMElement) {
        return wp_theme_svg_invalid_error();
    }

    if ('svg' !== strtolower($dom->documentElement->localName)) {
        return wp_theme_svg_invalid_error();
    }

    $cleaned = wp_theme_svg_clean_element($dom->documentElement);

    if (is_wp_error($cleaned)) {
        return $cleaned;
    }

    wp_theme_svg_drop_editor_namespaces($dom);

    if (! $dom->documentElement->hasAttribute('xmlns')) {
        $dom->documentElement->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    }

    $clean = $dom->saveXML($dom->documentElement);

    if (! is_string($clean)) {
        return wp_theme_svg_invalid_error();
    }

    if (! preg_match('/<(?:path|rect|circle|ellipse|line|polyline|polygon|text|use)\b/i', $clean)) {
        return wp_theme_svg_empty_error();
    }

    return $clean;
}


/**
 * Remove PHP tags. A leftover processing instruction blocks the file.
 */
function wp_theme_svg_strip_php(string $svg): string|WP_Error {
    $previous = '';

    for ($pass = 0; $pass < 5 && $svg !== $previous; $pass++) {
        $previous = $svg;
        $next     = preg_replace('/<\?(?:php|=).*?\?>/is', '', $svg);

        if (! is_string($next)) {
            return wp_theme_svg_invalid_error();
        }

        $svg = $next;
    }

    if (preg_match('/<\?(?!xml\b)/i', $svg)) {
        return wp_theme_svg_active_error();
    }

    return $svg;
}


/**
 * Whether the element is in the SVG namespace (or has none, as in a bare file).
 *
 * Elements from other namespaces, such as XHTML, are never kept even when
 * their local name matches an allowed SVG element.
 */
function wp_theme_svg_is_svg_namespace(DOMElement $element): bool {
    $namespace = $element->namespaceURI;

    return null === $namespace || '' === $namespace || 'http://www.w3.org/2000/svg' === $namespace;
}


/**
 * Clean one element after its children. The root svg element stays in place.
 */
function wp_theme_svg_clean_element(DOMElement $element): bool|WP_Error {
    $children = array();

    foreach ($element->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        if ($child instanceof DOMElement) {
            $cleaned = wp_theme_svg_clean_element($child);

            if (is_wp_error($cleaned)) {
                return $cleaned;
            }

            continue;
        }

        if ($child instanceof DOMProcessingInstruction || $child instanceof DOMComment) {
            $element->removeChild($child);
        }
    }

    $name   = strtolower($element->localName);
    $is_svg = wp_theme_svg_is_svg_namespace($element);

    if (isset(wp_theme_svg_active_elements()[ $name ])) {
        return wp_theme_svg_active_error();
    }

    if (isset(wp_theme_svg_remote_elements()[ $name ])) {
        return wp_theme_svg_remote_error();
    }

    $attributes = wp_theme_svg_clean_attributes($element, $is_svg && isset(wp_theme_svg_allowed_elements()[ $name ]));

    if (is_wp_error($attributes)) {
        return $attributes;
    }

    if ($is_svg && isset(wp_theme_svg_allowed_elements()[ $name ])) {
        return true;
    }

    if ($is_svg && isset(wp_theme_svg_unwrap_elements()[ $name ])) {
        wp_theme_svg_unwrap_element($element);

        return true;
    }

    $parent = $element->parentNode;

    if ($parent instanceof DOMNode) {
        $parent->removeChild($element);
    }

    return true;
}


/**
 * Drop editor namespaces. Reject event handlers, styles, and remote URLs.
 *
 * Remote URLs on elements that will be removed do not block the upload: the
 * element goes away with the URL. javascript: and event handlers always block.
 */
function wp_theme_svg_clean_attributes(DOMElement $element, bool $keep_element): bool|WP_Error {
    $remove = array();

    foreach ($element->attributes ?? array() as $attr) {
        if (! $attr instanceof DOMAttr) {
            continue;
        }

        $local = strtolower($attr->localName);
        $qname = strtolower($attr->nodeName);

        if ('style' === $local || preg_match('/^on/i', $local) || preg_match('/^on/i', $qname)) {
            return wp_theme_svg_active_error();
        }

        if (str_starts_with($qname, 'xmlns')) {
            if (! wp_theme_svg_is_known_xmlns($attr)) {
                $remove[] = $attr;
            }

            continue;
        }

        $namespace = $attr->namespaceURI;

        if ('http://www.w3.org/XML/1998/namespace' === $namespace && 'base' === $local) {
            return wp_theme_svg_remote_error();
        }

        if ('http://www.w3.org/1999/xlink' === $namespace && 'href' !== $local) {
            $remove[] = $attr;

            continue;
        }

        if (is_string($namespace) && '' !== $namespace && 'http://www.w3.org/1999/xlink' !== $namespace && 'http://www.w3.org/XML/1998/namespace' !== $namespace) {
            $remove[] = $attr;

            continue;
        }

        $problem = wp_theme_svg_value_problem($attr->value);

        if ('active' === $problem) {
            return wp_theme_svg_active_error();
        }

        if ('href' === $local) {
            if (wp_theme_svg_is_local_ref($attr->value)) {
                continue;
            }

            if ($keep_element && '' !== trim($attr->value)) {
                return wp_theme_svg_remote_error();
            }

            $remove[] = $attr;

            continue;
        }

        if ('remote' === $problem && $keep_element) {
            return wp_theme_svg_remote_error();
        }
    }

    foreach ($remove as $attr) {
        if ($attr->namespaceURI) {
            $element->removeAttributeNS($attr->namespaceURI, $attr->localName);
        } else {
            $element->removeAttribute($attr->nodeName);
        }
    }

    return true;
}


/**
 * Classify an attribute value.
 *
 * Script schemes block anywhere. Remote targets only matter inside url(...);
 * href attributes are checked separately, so plain text such as
 * "see http://..." in a label is not treated as a remote resource.
 *
 * @return ''|'active'|'remote'
 */
function wp_theme_svg_value_problem(string $value): string {
    $compact = preg_replace('/\s+/', '', $value);

    if (! is_string($compact)) {
        return 'active';
    }

    if (preg_match('/(?:javascript|vbscript):/i', $compact)) {
        return 'active';
    }

    if (! preg_match('/url\s*\(/i', $value)) {
        return '';
    }

    if (! preg_match_all('/url\s*\(\s*([\'"]?)([^)]*)\1\s*\)/i', $value, $matches)) {
        return 'remote';
    }

    foreach ($matches[2] as $target) {
        if (1 !== preg_match('/^#[A-Za-z_][\w:.-]*$/', trim($target))) {
            return 'remote';
        }
    }

    return '';
}


/**
 * Internal fragment reference, such as "#gradient".
 */
function wp_theme_svg_is_local_ref(string $value): bool {
    return 1 === preg_match('/^#[A-Za-z_][\w:.-]*$/', trim($value));
}


/**
 * Whether this xmlns declaration is one SVG needs.
 */
function wp_theme_svg_is_known_xmlns(DOMAttr $attr): bool {
    $name = strtolower($attr->nodeName);

    if ('xmlns' !== $name && ! str_starts_with($name, 'xmlns:')) {
        return false;
    }

    return in_array(
        trim($attr->value),
        array(
            'http://www.w3.org/2000/svg',
            'http://www.w3.org/1999/xlink',
        ),
        true
    );
}


/**
 * Drop namespace declarations other than SVG, XLink, and XML.
 */
function wp_theme_svg_drop_editor_namespaces(DOMDocument $dom): void {
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//namespace::*');

    if (! $nodes instanceof DOMNodeList) {
        return;
    }

    $known = array(
        'http://www.w3.org/2000/svg',
        'http://www.w3.org/1999/xlink',
        'http://www.w3.org/XML/1998/namespace',
    );

    foreach ($nodes as $node) {
        if (! $node instanceof DOMNameSpaceNode || in_array($node->nodeValue, $known, true)) {
            continue;
        }

        $owner = $node->parentNode;

        if ($owner instanceof DOMElement && $owner->hasAttribute($node->nodeName)) {
            $owner->removeAttribute($node->nodeName);
        }
    }
}


/**
 * Move children to the parent and drop the wrapper.
 */
function wp_theme_svg_unwrap_element(DOMElement $element): void {
    $parent = $element->parentNode;

    if (! $parent instanceof DOMNode) {
        return;
    }

    while ($element->firstChild instanceof DOMNode) {
        $parent->insertBefore($element->firstChild, $element);
    }

    $parent->removeChild($element);
}


/**
 * Elements whose markup may run code or apply a style sheet.
 *
 * @return array<string, true>
 */
function wp_theme_svg_active_elements(): array {
    static $elements = null;

    $elements ??= array_fill_keys(
        array(
            'script',
            'style',
            'foreignobject',
            'animate',
            'animatetransform',
            'animatemotion',
            'set',
            'handler',
            'iframe',
            'embed',
            'object',
            'audio',
            'video',
            'canvas',
            'applet',
            'mpath',
        ),
        true
    );

    return $elements;
}


/**
 * Elements that pull in another file.
 *
 * @return array<string, true>
 */
function wp_theme_svg_remote_elements(): array {
    static $elements = null;

    $elements ??= array_fill_keys(
        array(
            'image',
            'feimage',
            'font',
            'font-face',
            'font-face-src',
            'font-face-uri',
            'font-face-format',
            'color-profile',
            'link',
            'cursor',
        ),
        true
    );

    return $elements;
}


/**
 * Drawing, text, and filter primitives. feImage is intentionally absent.
 *
 * @return array<string, true>
 */
function wp_theme_svg_allowed_elements(): array {
    static $elements = null;

    $elements ??= array_fill_keys(
        array(
            'svg',
            'g',
            'path',
            'rect',
            'circle',
            'ellipse',
            'line',
            'polyline',
            'polygon',
            'defs',
            'symbol',
            'use',
            'title',
            'desc',
            'lineargradient',
            'radialgradient',
            'stop',
            'clippath',
            'mask',
            'text',
            'tspan',
            'filter',
            'fegaussianblur',
            'feoffset',
            'feblend',
            'fecolormatrix',
            'femerge',
            'femergenode',
            'feflood',
            'fecomposite',
            'fecomponenttransfer',
            'fefuncr',
            'fefuncg',
            'fefuncb',
            'fefunca',
            'femorphology',
            'feconvolvematrix',
            'fediffuselighting',
            'fespecularlighting',
            'fedistantlight',
            'fepointlight',
            'fespotlight',
            'fetile',
            'feturbulence',
            'fedisplacementmap',
            'fedropshadow',
        ),
        true
    );

    return $elements;
}


/**
 * Wrappers whose children are the graphic. The wrapper itself is removed.
 *
 * @return array<string, true>
 */
function wp_theme_svg_unwrap_elements(): array {
    static $elements = null;

    $elements ??= array_fill_keys(array('a', 'switch', 'textpath'), true);

    return $elements;
}


/**
 * Whether this upload should be treated as SVG.
 *
 * @param array<string, mixed> $file A single $_FILES entry.
 */
function wp_theme_upload_is_svg(array $file): bool {
    $name = isset($file['name']) ? (string) $file['name'] : '';
    $type = isset($file['type']) ? strtolower((string) $file['type']) : '';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return 'svg' === $ext || 'image/svg+xml' === $type || 'image/svg' === $type;
}


/**
 * Whether the file looks like an SVG document.
 *
 * Reads only the start of the file. Used after sanitizing, when the root
 * element is near the top.
 */
function wp_theme_file_contains_svg_root(string $file): bool {
    if ('' === $file || ! is_readable($file)) {
        return false;
    }

    $chunk = file_get_contents($file, false, null, 0, 8192);

    return is_string($chunk) && 1 === preg_match('/<svg\b/i', $chunk);
}


/**
 * Upload error for styles, scripts, and animation.
 */
function wp_theme_svg_active_error(): WP_Error {
    return new WP_Error(
        'wp_theme_svg_active',
        __('This SVG file contains styles, scripts, or animation and was blocked. Export shapes with fill and stroke attributes, without a style block or animation.', 'wp-theme')
    );
}


/**
 * Upload error for external images and links that the graphic itself uses.
 */
function wp_theme_svg_remote_error(): WP_Error {
    return new WP_Error(
        'wp_theme_svg_remote',
        __('This SVG file contains an external image or link and was blocked.', 'wp-theme')
    );
}


/**
 * Upload error for an SVG without any drawable shape.
 */
function wp_theme_svg_empty_error(): WP_Error {
    return new WP_Error(
        'wp_theme_svg_empty',
        __('This SVG file has no drawable shapes (path, rect, circle, text, etc.) and was blocked.', 'wp-theme')
    );
}


/**
 * Upload error for markup that cannot be parsed into a drawing.
 */
function wp_theme_svg_invalid_error(): WP_Error {
    return new WP_Error(
        'wp_theme_svg_invalid',
        __('This SVG file was blocked because it could not be sanitized.', 'wp-theme')
    );
}
