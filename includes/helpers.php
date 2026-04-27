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
    if ($max > 0) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
        } elseif (strlen($v) > $max) {
            $v = substr($v, 0, $max);
        }
    }
    return $v;
}

/** Format money per config locale (simple 2-decimal fallback). */
function format_money($amount): string {
    return number_format((float)$amount, 2, '.', ',');
}

/** Configured currency symbol. */
function currency_symbol(): string {
    $symbol = (string)($GLOBALS['CONFIG']['currency_symbol'] ?? '$');
    return $symbol !== '' ? $symbol : '$';
}

/** Currency-prefixed amount string. */
function money_with_currency($amount): string {
    return currency_symbol() . format_money($amount);
}

/** Default E.164 country code used by phone inputs. */
function default_phone_country_code(): string {
    $cc = (string)($GLOBALS['CONFIG']['default_phone_country_code'] ?? '+1');
    return preg_match('/^\+\d{1,4}$/', $cc) ? $cc : '+1';
}

/** Business timezone name from config, with a safe fallback. */
function business_timezone_name(): string {
    $tz = (string)($GLOBALS['CONFIG']['business_timezone'] ?? 'UTC');
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
}

/** Shared DateTimeZone for business-local date math. */
function business_timezone_obj(): DateTimeZone {
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone(business_timezone_name());
    return $tz;
}

/** Current business-local timestamp. */
function business_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', business_timezone_obj());
}

/** Today's date in the business timezone. */
function business_today(): string {
    return business_now()->format('Y-m-d');
}

/** Booking statuses that still reserve a slot. */
function booking_active_statuses(): array {
    return ['pending', 'confirmed'];
}

/** Booking statuses that count toward revenue. */
function booking_revenue_statuses(): array {
    return ['confirmed', 'completed'];
}

/** All supported booking statuses. */
function booking_all_statuses(): array {
    return ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];
}

/** Human label for a booking status. */
function booking_status_label(string $status): string {
    return ucwords(str_replace('_', ' ', $status));
}

/** Notify a customer when an existing booking changes status. */
function send_booking_status_change_email(int $booking_id, string $old_status, string $new_status): bool {
    if ($old_status === $new_status) return true;
    if (!in_array($new_status, booking_all_statuses(), true)) return false;
    if (!function_exists('send_mail')) return false;

    $booking = db_fetch(
        "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
                st.name AS staff_name
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         JOIN staff st   ON st.id = b.staff_id
         WHERE b.id = ?",
        [$booking_id]
    );
    if (!$booking || !is_valid_email((string)($booking['customer_email'] ?? ''))) {
        return false;
    }

    $old_status_label = booking_status_label($old_status);
    $new_status_label = booking_status_label($new_status);
    $subject = 'Your booking status changed to ' . $new_status_label;

    ob_start();
    include APP_ROOT . '/includes/email-templates/status-change.php';
    $html = ob_get_clean();
    return @send_mail((string)$booking['customer_email'], $subject, $html);
}

/** Build absolute URL using app_url. */
function app_url(string $path = ''): string {
    $base = rtrim((string)($GLOBALS['CONFIG']['app_url'] ?? ''), '/');
    if ($base === '') {
        // Fallback to current scheme/host if app_url not set.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($host === '') $host = 'localhost';
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

/** Accent color for native controls; falls back to primary_color. */
function accent_color(): string {
    $c = (string)($GLOBALS['CONFIG']['accent_color'] ?? '');
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : primary_color();
}

/** Config booleans may come from JSON booleans or string-ish values. */
function config_bool(string $key, bool $default = false): bool {
    $v = $GLOBALS['CONFIG'][$key] ?? $default;
    if (is_bool($v)) return $v;
    if (is_string($v)) return !in_array(strtolower(trim($v)), ['0', 'false', 'no', 'off', ''], true);
    return (bool)$v;
}

/** Whether the admin UI should render in dark mode. */
function dark_mode_enabled(): bool {
    return config_bool('dark_mode', false);
}

/** Whether the admin Import / export page should be visible and reachable. */
function import_export_enabled(): bool {
    return config_bool('show_import_export', true);
}

/** Whether the admin Settings page should be visible and reachable. */
function settings_page_enabled(): bool {
    return config_bool('show_settings_page', true);
}

/** Rows per page for admin tables with pagination. */
function admin_rows_per_page(): int {
    $configured = $GLOBALS['CONFIG']['admin_rows_per_page']
        ?? $GLOBALS['CONFIG']['bookings_per_page']
        ?? 25;
    return max(5, min(200, (int)$configured));
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
    if ($h === 24 && $i === 0) return time_format() === '12h' ? '12:00 AM' : '24:00';
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

/** Best-effort MIME sniffing for uploaded files. */
function uploaded_file_mime(array $file): string {
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
            return $mime;
        }
    }
    return function_exists('mime_content_type') ? (string)mime_content_type($tmp) : '';
}

/**
 * Resize and save an uploaded image to improve loading time.
 *
 * Falls back to move_uploaded_file() if GD is unavailable.
 */
function save_uploaded_image(array $file, string $dest_dir, string $filename, int $max_w, int $max_h): bool {
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        return false;
    }
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
        return false;
    }

    $dest = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!function_exists('imagecreatefromstring') || !function_exists('getimagesize')) {
        $ok = move_uploaded_file($file['tmp_name'], $dest);
        if ($ok) @chmod($dest, 0644);
        return $ok;
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) return false;
    $bytes = @file_get_contents($file['tmp_name']);
    if ($bytes === false) return false;

    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;

    $src_w = max(1, (int)$info[0]);
    $src_h = max(1, (int)$info[1]);
    $scale = min($max_w / $src_w, $max_h / $src_h, 1);
    $dst_w = max(1, (int)round($src_w * $scale));
    $dst_h = max(1, (int)round($src_h * $scale));

    $dst = imagecreatetruecolor($dst_w, $dst_h);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dst_w, $dst_h, $transparent);
    } else {
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dst_w, $dst_h, $white);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);

    $ok = false;
    if ($ext === 'png') {
        $ok = imagepng($dst, $dest, 6);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $ok = imagewebp($dst, $dest, 82);
    } else {
        $ok = imagejpeg($dst, $dest, 82);
    }

    imagedestroy($dst);
    imagedestroy($src);
    if ($ok) @chmod($dest, 0644);
    return $ok;
}

/**
 * Save an uploaded image as an exact centered square thumbnail.
 *
 * Non-square sources are center-cropped to the largest possible square before
 * resizing. Square sources are resized directly to the target dimensions.
 */
function save_uploaded_square_image(array $file, string $dest_dir, string $filename, int $size): bool {
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        return false;
    }
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
        return false;
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('getimagesize')) {
        return false;
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) return false;
    $bytes = @file_get_contents($file['tmp_name']);
    if ($bytes === false) return false;

    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;

    $src_w = max(1, (int)$info[0]);
    $src_h = max(1, (int)$info[1]);
    $crop_size = min($src_w, $src_h);
    $crop_x = intdiv($src_w - $crop_size, 2);
    $crop_y = intdiv($src_h - $crop_size, 2);

    $dst = imagecreatetruecolor($size, $size);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
    } else {
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $size, $size, $white);
    }

    imagecopyresampled(
        $dst,
        $src,
        0,
        0,
        $crop_x,
        $crop_y,
        $size,
        $size,
        $crop_size,
        $crop_size
    );

    $dest = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    $ok = false;
    if ($ext === 'png') {
        $ok = imagepng($dst, $dest, 6);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $ok = imagewebp($dst, $dest, 82);
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $ok = imagejpeg($dst, $dest, 82);
    }

    imagedestroy($dst);
    imagedestroy($src);
    if ($ok) @chmod($dest, 0644);
    return $ok;
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
    $now = business_now();
    $today = $now->format('Y-m-d');
    $time = $now->format('H:i');
    try {
        $elapsed = db_all(
            "SELECT id, status
               FROM bookings
              WHERE status = 'confirmed'
                AND (booking_date < ?
                     OR (booking_date = ? AND end_time <= ?))",
            [$today, $today, $time]
        );
        foreach ($elapsed as $row) {
            $updated = db_exec(
                "UPDATE bookings SET status = 'completed' WHERE id = ? AND status = 'confirmed'",
                [(int)$row['id']]
            );
            if ($updated > 0) {
                send_booking_status_change_email((int)$row['id'], (string)$row['status'], 'completed');
            }
        }
    } catch (Throwable $e) {
        // Swallow — auto-complete is best-effort.
        error_log('auto_complete failed: ' . $e->getMessage());
    }
}
