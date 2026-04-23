<?php
/**
 * Generic helpers: escaping, UUIDs, JSON responses, redirects, validation.
 */

/** Escape for HTML output. */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** RFC 4122 v4 UUID (128 bits). */
function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Send a JSON response and exit. */
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send a JSON error and exit. */
function json_error(string $message, int $status = 400, array $extra = []): void {
    json_response(['error' => $message] + $extra, $status);
}

/** Read JSON body. */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Require POST method (405 otherwise). */
function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        json_error('Method not allowed.', 405);
    }
}

/** Simple redirect, preserves status code semantics. */
function redirect(string $url, int $status = 302): void {
    header('Location: ' . $url, true, $status);
    exit;
}

/** Validate a YYYY-MM-DD date string. */
function is_valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

/** Validate a HH:MM time string. */
function is_valid_time(string $s): bool {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
}

/** Normalize HH:MM (accept HH:MM:SS and trim). */
function normalize_time(string $s): ?string {
    $s = trim($s);
    if (preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $s, $m)) {
        $h = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        return $h . ':' . $m[2];
    }
    return null;
}

/** Minutes since midnight for HH:MM. */
function time_to_minutes(string $t): int {
    [$h, $m] = explode(':', $t);
    return ((int)$h) * 60 + ((int)$m);
}

/** Minutes since midnight → HH:MM. */
function minutes_to_time(int $m): string {
    $m = max(0, min(24 * 60, $m));
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Validate email (basic). */
function is_valid_email(string $s): bool {
    return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

/** Very permissive phone validator: digits, spaces, +, -, (, ). */
function is_valid_phone(string $s): bool {
    return (bool)preg_match('/^[0-9+()\-\s]{6,32}$/', $s);
}

/** Fetch a trimmed string from $arr[$key], or '' if missing. */
function str_in(array $arr, string $key, int $max = 500): string {
    $v = $arr[$key] ?? '';
    if (!is_string($v) && !is_numeric($v)) return '';
    $v = trim((string)$v);
    if ($max > 0 && mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
}

/** Format money per config locale (simple 2-decimal fallback). */
function format_money($amount): string {
    return number_format((float)$amount, 2, '.', ',');
}

/** Build absolute URL using app_url. */
function app_url(string $path = ''): string {
    $base = rtrim((string)($GLOBALS['CONFIG']['app_url'] ?? ''), '/');
    if ($base === '') {
        // Fallback to current scheme/host if app_url not set.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host;
    }
    if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
    return $base . $path;
}

/** Business name convenience. */
function business_name(): string {
    return (string)($GLOBALS['CONFIG']['business_name'] ?? 'Salon');
}

/** Primary color. */
function primary_color(): string {
    $c = (string)($GLOBALS['CONFIG']['primary_color'] ?? '#7c3aed');
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : '#7c3aed';
}
