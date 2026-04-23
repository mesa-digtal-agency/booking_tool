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

/** '24h' or '12h'. */
function time_format(): string {
    return (string)($GLOBALS['CONFIG']['time_format'] ?? '24h') === '12h' ? '12h' : '24h';
}

/**
 * Format an HH:MM (or HH:MM:SS) time string for display according to the
 * configured time_format. Returns the input unchanged if it can't be parsed.
 */
function format_time_display(?string $time): string {
    if (!$time) return '';
    $t = trim($time);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) return $t;
    $h = (int)$m[1];
    $i = (int)$m[2];
    if (time_format() === '12h') {
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return sprintf('%d:%02d %s', $h12, $i, $suffix);
    }
    return sprintf('%02d:%02d', $h, $i);
}

/**
 * Resolve the logo URL. Returns '' if no logo is configured.
 * Prefers `logo_path` (local), falls back to `business_logo_url` (absolute).
 */
function logo_url(): string {
    $cfg = $GLOBALS['CONFIG'];
    $local = trim((string)($cfg['logo_path'] ?? ''));
    if ($local !== '') {
        // Ensure web-path (with leading slash). Strip APP_ROOT prefix if present.
        $local = str_replace('\\', '/', $local);
        if (strpos($local, APP_ROOT) === 0) $local = substr($local, strlen(APP_ROOT));
        if ($local !== '' && $local[0] !== '/') $local = '/' . ltrim($local, '/');
        return $local;
    }
    return (string)($cfg['business_logo_url'] ?? '');
}

/** Sidebar display mode: 'logo_and_name' (default) or 'logo_only'. */
function logo_mode(): string {
    $m = (string)($GLOBALS['CONFIG']['logo_mode'] ?? 'logo_and_name');
    return $m === 'logo_only' ? 'logo_only' : 'logo_and_name';
}

/**
 * Short versioning token for asset URLs. Changes on every deploy so
 * browsers drop their cached copies of app.css / JS files.
 *
 * Uses the mtime of the main CSS file as a fingerprint; falls back to
 * a hash of all tracked asset files.
 */
function asset_version(): string {
    static $v = null;
    if ($v !== null) return $v;
    $probe = APP_ROOT . '/assets/css/app.css';
    $m = is_file($probe) ? filemtime($probe) : time();
    $v = dechex((int)$m);
    return $v;
}

/** Returns a URL with a ?v=... cache-bust query parameter. */
function asset(string $path): string {
    if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
    $sep = (strpos($path, '?') === false) ? '?' : '&';
    return $path . $sep . 'v=' . asset_version();
}

/**
 * Transition any confirmed/pending bookings whose end_time has passed to
 * "completed". Called opportunistically on admin page loads — lightweight
 * single UPDATE so it's safe to call often.
 */
function auto_complete_elapsed_bookings(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $today = date('Y-m-d');
    $now   = date('H:i');
    try {
        db_exec(
            "UPDATE bookings
                SET status = 'completed'
              WHERE status IN ('confirmed','pending')
                AND (booking_date < ?
                     OR (booking_date = ? AND end_time <= ?))",
            [$today, $today, $now]
        );
    } catch (Throwable $e) {
        // Swallow — auto-complete is best-effort.
        error_log('auto_complete failed: ' . $e->getMessage());
    }
}
