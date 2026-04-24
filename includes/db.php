<?php
/**
 * PDO wrapper. Returns a shared PDO instance for either SQLite or MySQL
 * based on config. Throws on connection failure with a friendly message.
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = $GLOBALS['CONFIG'];
    $type = strtolower((string)$cfg['db_type']);

    try {
        if ($type === 'sqlite') {
            $path = $cfg['db_path'];
            if ($path === '' || $path === null) {
                throw new RuntimeException('db_path is empty in config.json');
            }
            // Resolve relative path against APP_ROOT.
            if ($path[0] !== '/' && !preg_match('/^[A-Za-z]:/', $path)) {
                $path = APP_ROOT . '/' . ltrim($path, '/');
            }
            $dir = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            $pdo->exec('PRAGMA journal_mode = WAL;');
        } elseif ($type === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['db_host'], (int)$cfg['db_port'], $cfg['db_name']
            );
            // Force UTC so CURRENT_TIMESTAMP matches gmdate() in PHP — keeps
            // the rate limiter and any other "N seconds ago" comparisons sane.
            $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_password'], [
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
            ]);
        } else {
            throw new RuntimeException("Unsupported db_type: {$type}");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('DB connection failed: ' . $e->getMessage());
        exit('Database unavailable. Please check config.json and run setup.php.');
    }
    return $pdo;
}

/** Driver name: 'sqlite' or 'mysql'. */
function db_driver(): string {
    return strtolower((string)$GLOBALS['CONFIG']['db_type']);
}

/** Fetch one row (assoc) or null. */
function db_fetch(string $sql, array $params = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $params = []): array {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Execute a statement, return last insert id as string. */
function db_insert(string $sql, array $params = []): string {
    $st = db()->prepare($sql);
    $st->execute($params);
    return db()->lastInsertId();
}

/** Execute a statement, return row count. */
function db_exec(string $sql, array $params = []): int {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** Fetch a single scalar value (first column of first row). */
function db_scalar(string $sql, array $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

/**
 * Begin a transaction that serializes writers.
 *
 * - SQLite: BEGIN IMMEDIATE acquires the write lock up-front, so two
 *   concurrent booking inserts cannot both pass the conflict check.
 * - MySQL: a regular transaction + SELECT ... FOR UPDATE downstream
 *   handles it; we just begin normally here.
 */
function db_begin_exclusive(): void {
    if (db_driver() === 'sqlite') {
        db()->exec('BEGIN IMMEDIATE');
    } else {
        db()->beginTransaction();
    }
}

/** Commit whichever transaction type is open. */
function db_commit(): void {
    if (db_driver() === 'sqlite') {
        db()->exec('COMMIT');
    } else {
        if (db()->inTransaction()) db()->commit();
    }
}

/** Roll back whichever transaction type is open. */
function db_rollback(): void {
    try {
        if (db_driver() === 'sqlite') {
            db()->exec('ROLLBACK');
        } else {
            if (db()->inTransaction()) db()->rollBack();
        }
    } catch (Throwable $e) { /* best effort */ }
}

/**
 * Lock-read clause for conflict detection. Appended to a SELECT on
 * MySQL as `FOR UPDATE` so the row-range is held until the transaction
 * commits. No-op on SQLite (BEGIN IMMEDIATE already serialized us).
 */
function db_for_update_clause(): string {
    return db_driver() === 'mysql' ? ' FOR UPDATE' : '';
}
