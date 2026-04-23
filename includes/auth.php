<?php
/**
 * Session-based auth for admin panel.
 * Stores {staff_id, role, name, email} in $_SESSION['user'].
 */

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']['id']);
}

function is_admin(): bool {
    return is_logged_in() && ($_SESSION['user']['role'] ?? '') === 'admin';
}

/**
 * Attempt login. On success populates the session and returns true.
 */
function attempt_login(string $email, string $password): bool {
    $row = db_fetch(
        "SELECT id, name, email, role, password_hash, is_active
         FROM staff WHERE email = ? LIMIT 1",
        [strtolower(trim($email))]
    );
    if (!$row || !$row['is_active']) return false;
    if (!password_verify($password, $row['password_hash'])) return false;

    // Regenerate session ID to prevent fixation.
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role'],
    ];
    // Rotate CSRF token on login.
    unset($_SESSION['_csrf']);

    // Opportunistic rehash if algorithm changed.
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        db_exec("UPDATE staff SET password_hash = ? WHERE id = ?", [$new, $row['id']]);
    }
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('/admin/login.php');
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Admin access required.');
    }
}

/** Staff may only act on their own scope unless they are an admin. */
function scope_staff_id(): ?int {
    if (!is_logged_in()) return null;
    if (is_admin()) return null; // null = no restriction
    return (int)$_SESSION['user']['id'];
}
