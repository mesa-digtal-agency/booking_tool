<?php
/**
 * CSRF token generation & verification.
 * Tokens are stored in the session and sent with every POST form or AJAX request.
 */

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** HTML hidden input for forms. */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Meta tag for JS consumption. */
function csrf_meta(): string {
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

/**
 * Verify an incoming CSRF token from POST/header. Kills the request on failure.
 */
function csrf_verify(): void {
    $expected = $_SESSION['_csrf'] ?? '';
    $got = $_POST['_csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($got) || !is_string($expected) || $expected === '' || !hash_equals($expected, $got)) {
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            json_error('Invalid CSRF token.', 419);
        }
        http_response_code(419);
        exit('Invalid CSRF token. Please refresh and try again.');
    }
}
