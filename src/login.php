<?php
/**
 * src/login.php
 * Login Controller
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

// Redirect to dashboard if already logged in
if (is_logged_in()) {
    header("Location: /index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $kuerzel = trim($_POST['kuerzel'] ?? '');
    $password = $_POST['passwort'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } elseif (empty($kuerzel) || empty($password)) {
        $_SESSION['flash_error'] = "Bitte Kürzel und Passwort eingeben.";
    } else {
        $conn = db_connect();
        $user = authenticate_user($conn, $kuerzel, $password);

        if ($user) {
            // Login successful
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_kuerzel'] = $user['kuerzel'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = $user['is_admin'];
            
            header("Location: /index.php");
            exit;
        } else {
            // Give a small delay to prevent rapid brute-forcing
            sleep(1);
            $_SESSION['flash_error'] = "Falsches Kürzel oder Passwort.";
        }
    }
}

// Generate new CSRF token for the form
$csrf_token = get_csrf_token();

$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

echo $twig->render('login.twig', [
    'csrf_token' => $csrf_token,
    'flash_error' => $flash_error,
    'is_logged_in' => false
]);
