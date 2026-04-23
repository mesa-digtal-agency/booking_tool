<?php
/**
 * One-time installer.
 *
 * Steps:
 *  1) Validates config.json
 *  2) Creates DB schema (SQLite or MySQL)
 *  3) Creates the first admin account
 *  4) Seeds default working hours for the new admin
 *
 * Refuses to run a second time once an admin already exists.
 * Delete this file after successful setup.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$step = $_GET['step'] ?? 'form';
$errors = [];
$notices = [];

// Short-circuit: if an admin already exists, deny setup.
try {
    $pdo = db();
    $has_staff = false;
    try {
        $has_staff = (int)db_scalar("SELECT COUNT(*) FROM staff WHERE role = 'admin'") > 0;
    } catch (Throwable $e) {
        // staff table may not exist yet — that's OK, we'll create it.
        $has_staff = false;
    }
} catch (Throwable $e) {
    $has_staff = false;
}

if ($has_staff) {
    http_response_code(403);
    echo render_page('Setup already complete', '
        <div class="box">
            <h1>Setup already complete</h1>
            <p>An admin account already exists. For security, please delete <code>setup.php</code> from your server.</p>
            <p><a href="/admin/login.php">Go to admin login →</a></p>
        </div>');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use CSRF for setup too.
    if (!hash_equals($_SESSION['_csrf_setup'] ?? '', $_POST['_csrf'] ?? '')) {
        $errors[] = 'Session expired. Please reload the page and try again.';
    } else {
        $name = str_in($_POST, 'name', 120);
        $email = strtolower(str_in($_POST, 'email', 190));
        $pwd = (string)($_POST['password'] ?? '');
        $pwd2 = (string)($_POST['password_confirm'] ?? '');

        if ($name === '') $errors[] = 'Name is required.';
        if (!is_valid_email($email)) $errors[] = 'Valid email is required.';
        if (strlen($pwd) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($pwd !== $pwd2) $errors[] = 'Passwords do not match.';

        if (!$errors) {
            try {
                run_migrations($pdo);
                create_first_admin($name, $email, $pwd);
                // Rotate setup token + CSRF so the form can't be re-submitted.
                unset($_SESSION['_csrf_setup']);
                $notices[] = 'Setup complete! You can now log in.';
                $step = 'done';
            } catch (Throwable $e) {
                error_log('Setup failed: ' . $e->getMessage());
                $errors[] = 'Setup failed: ' . $e->getMessage();
            }
        }
    }
}

if (empty($_SESSION['_csrf_setup'])) {
    $_SESSION['_csrf_setup'] = bin2hex(random_bytes(16));
}

if ($step === 'done') {
    echo render_page('Setup complete', '
        <div class="box">
            <h1>All done ✓</h1>
            <p>Your admin account has been created.</p>
            <p style="color:#b91c1c"><strong>Important:</strong> delete <code>setup.php</code> from your server now.</p>
            <p><a class="btn" href="/admin/login.php">Go to admin login →</a></p>
        </div>');
    exit;
}

echo render_page('Installer', render_form($errors, $notices));

// ---------------------------------------------------------------------------

function run_migrations(PDO $pdo): void {
    $driver = db_driver();
    $sql = file_get_contents(APP_ROOT . '/schema.sql');
    if ($sql === false) throw new RuntimeException('schema.sql not found.');

    if ($driver === 'sqlite') {
        $sql = str_replace('{{AUTO_PK}}', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = str_replace('{{NOW}}', "CURRENT_TIMESTAMP", $sql);
    } else {
        $sql = str_replace('{{AUTO_PK}}', 'INT AUTO_INCREMENT PRIMARY KEY', $sql);
        $sql = str_replace('{{NOW}}', "CURRENT_TIMESTAMP", $sql);
    }

    // Strip SQL line comments so the statement splitter doesn't glue them to the first real statement.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)));
    $pdo->beginTransaction();
    try {
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_first_admin(string $name, string $email, string $password): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $id = db_insert(
        "INSERT INTO staff (name, email, role, password_hash, is_active) VALUES (?, ?, 'admin', ?, 1)",
        [$name, $email, $hash]
    );
    // Seed Mon–Fri 9–17, weekends off.
    for ($dow = 0; $dow <= 6; $dow++) {
        $off = ($dow === 0 || $dow === 6) ? 1 : 0;
        db_insert(
            "INSERT INTO working_hours (staff_id, day_of_week, start_time, end_time, is_off) VALUES (?, ?, '09:00', '17:00', ?)",
            [$id, $dow, $off]
        );
    }
}

function render_form(array $errors, array $notices): string {
    $cfg = $GLOBALS['CONFIG'];
    $csrf = $_SESSION['_csrf_setup'];
    $db = e($cfg['db_type']) . ($cfg['db_type'] === 'sqlite' ? ' (' . e($cfg['db_path']) . ')' : ' (' . e($cfg['db_host']) . '/' . e($cfg['db_name']) . ')');
    $err_html = '';
    if ($errors) {
        $err_html = '<div class="alert err"><ul>';
        foreach ($errors as $er) $err_html .= '<li>' . e($er) . '</li>';
        $err_html .= '</ul></div>';
    }
    $note_html = '';
    foreach ($notices as $n) $note_html .= '<div class="alert ok">' . e($n) . '</div>';

    return '
    <div class="box">
        <h1>Install ' . e(business_name()) . '</h1>
        <p class="muted">Database: <code>' . $db . '</code></p>
        ' . $err_html . $note_html . '
        <form method="post">
            <input type="hidden" name="_csrf" value="' . e($csrf) . '">
            <label>Your name<input name="name" required value="' . e($_POST['name'] ?? '') . '"></label>
            <label>Email<input type="email" name="email" required value="' . e($_POST['email'] ?? '') . '"></label>
            <label>Password <span class="muted">(min 8 chars)</span><input type="password" name="password" required minlength="8"></label>
            <label>Confirm password<input type="password" name="password_confirm" required minlength="8"></label>
            <button class="btn" type="submit">Create admin account</button>
        </form>
        <p class="muted small">This creates the database schema and your first admin user.</p>
    </div>';
}

function render_page(string $title, string $content): string {
    $primary = primary_color();
    return '<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . e($title) . ' · ' . e(business_name()) . '</title>
<style>
  :root { --p: ' . e($primary) . '; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f7f7f9; margin: 0; color: #111827; }
  .wrap { max-width: 520px; margin: 8vh auto; padding: 24px; }
  .box { background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 10px 25px rgba(0,0,0,.06); }
  h1 { margin: 0 0 8px; font-size: 22px; }
  label { display:block; margin: 12px 0; font-size: 14px; color:#374151; }
  input { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px; font-size: 15px; margin-top: 4px; }
  input:focus { outline: none; border-color: var(--p); box-shadow: 0 0 0 3px rgba(124,58,237,.15); }
  .btn { display: inline-block; margin-top: 14px; background: var(--p); color: #fff; border: 0; padding: 10px 18px; border-radius: 10px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .btn:hover { filter: brightness(.92); }
  .muted { color:#6b7280; font-size: 13px; }
  .small { font-size: 12px; }
  .alert { padding: 10px 12px; border-radius: 10px; margin: 10px 0; font-size: 14px; }
  .alert.err { background: #fef2f2; color:#991b1b; }
  .alert.ok  { background: #ecfdf5; color:#065f46; }
  code { background: #f3f4f6; padding: 1px 6px; border-radius: 6px; }
</style></head>
<body><div class="wrap">' . $content . '</div></body></html>';
}
