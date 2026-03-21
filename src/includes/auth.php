<?php
/**
 * src/includes/auth.php
 * Session management and authentication checks.
 */

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: /login.php");
        exit;
    }
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_current_user_name() {
    return $_SESSION['user_name'] ?? null;
}

function get_current_user_kuerzel() {
    return $_SESSION['user_kuerzel'] ?? null;
}

function is_current_user_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function require_admin() {
    require_login();
    if (!is_current_user_admin()) {
        header("Location: /index.php");
        exit;
    }
}

/**
 * Validates a Kürzel and Password against the database.
 * Returns user data on success, false on failure.
 */
function authenticate_user($conn, $kuerzel, $password) {
    if (empty($kuerzel) || empty($password)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id, kuerzel, is_admin, passwort_hash, name FROM teachers WHERE kuerzel = :kuerzel LIMIT 1");
    $stmt->execute([':kuerzel' => $kuerzel]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['passwort_hash'])) {
        return $user;
    }

    return false;
}

/**
 * Simple CSRF token generation
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Simple CSRF token validation
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
?>
