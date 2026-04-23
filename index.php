<?php
/**
 * Root entry point.
 *
 * - If the DB hasn't been set up yet, send visitors to /setup.php.
 * - If a staff member is already logged in, send them to /admin/.
 * - Otherwise, send them to the login page.
 *
 * The public customer booking page lives at /book.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';

try {
    // If the staff table doesn't exist yet, the app hasn't been installed.
    $any_admin = (int)db_scalar("SELECT COUNT(*) FROM staff WHERE role = 'admin'") > 0;
} catch (Throwable $e) {
    $any_admin = false;
}

if (!$any_admin) {
    redirect('/setup.php');
}
if (is_logged_in()) {
    redirect('/admin/index.php');
}
redirect('/admin/login.php');
