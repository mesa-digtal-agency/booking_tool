<?php
/**
 * Bootstrap: loads config, sets timezone, starts session, opens DB.
 * Every public entry point (index.php, api/*.php, admin/*.php, setup.php) requires this file.
 */

// PHP 7.4+
if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('PHP 7.4+ required.');
}

// Strict error handling — surface issues in dev, log in prod.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ROOT', dirname(__DIR__));

/**
 * Load config.json. Strips comment keys starting with "_comment".
 */
function load_config(): array {
    $path = APP_ROOT . '/config.json';
    if (!is_file($path)) {
        http_response_code(500);
        exit('Missing config.json. Copy config.example.json to config.json and edit it.');
    }
    $raw = file_get_contents($path);
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        http_response_code(500);
        exit('config.json is not valid JSON.');
    }
    foreach (array_keys($cfg) as $k) {
        if (strpos($k, '_comment') === 0) {
            unset($cfg[$k]);
        }
    }
    // Defaults
    $cfg += [
        'db_type' => 'sqlite',
        'db_path' => 'data/booking.sqlite',
        'db_host' => 'localhost',
        'db_port' => 3306,
        'db_name' => '',
        'db_user' => '',
        'db_password' => '',
        'business_name' => 'My Salon',
        'business_timezone' => 'UTC',
        'business_logo_url' => '',
        'logo_path' => '',
        'logo_mode' => 'logo_and_name',
        'app_url' => '',
        'primary_color' => '#7c3aed',
        'currency_symbol' => '$',
        'default_phone_country_code' => '+1',
        'mail_driver' => 'mail',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
        'from_email' => 'no-reply@example.com',
        'from_name' => 'My Salon',
        'slot_interval_minutes' => 30,
        'time_format' => '24h',
    ];
    return $cfg;
}

$CONFIG = load_config();
$GLOBALS['CONFIG'] = $CONFIG;

// Timezone
@date_default_timezone_set($CONFIG['business_timezone'] ?: 'UTC');

// Session config (secure defaults, SameSite=Lax)
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('BOOKSID');
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

/**
 * Lightweight per-request migration shim for tables added after v1.
 * Safe to run on every request — the CREATE TABLE uses IF NOT EXISTS.
 */
function ensure_migrations(): void {
    static $ran = false;
    if ($ran) return;
    try {
        $pdo = db();
        $driver = db_driver();
        $auto_pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id {$auto_pk},
            staff_id INTEGER NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
        )");

        // services.image — added in v1.x; upgrade older installs.
        if (!column_exists('services', 'image')) {
            $pdo->exec("ALTER TABLE services ADD COLUMN image VARCHAR(255)");
        }
        if (!column_exists('blocked_slots', 'repeat_until')) {
            $pdo->exec("ALTER TABLE blocked_slots ADD COLUMN repeat_until VARCHAR(10)");
        }
        if (!column_exists('blocked_slots', 'repeat_mode')) {
            $pdo->exec("ALTER TABLE blocked_slots ADD COLUMN repeat_mode VARCHAR(20)");
        }

        $ran = true;
    } catch (Throwable $e) {
        error_log('ensure_migrations: ' . $e->getMessage());
    }
}

/** True if $table has $column, driver-agnostic. */
function column_exists(string $table, string $column): bool {
    try {
        if (db_driver() === 'sqlite') {
            $rows = db_all("PRAGMA table_info(" . $table . ")");
            foreach ($rows as $r) {
                if (strcasecmp($r['name'], $column) === 0) return true;
            }
            return false;
        }
        $row = db_fetch(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}
