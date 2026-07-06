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

// Check for autologin token
if (isset($_GET['autologin']) && $_GET['autologin'] === '1') {
    $kuerzel = trim($_GET['kuerzel'] ?? '');
    $token = $_GET['token'] ?? '';
    
    if (!empty($kuerzel) && !empty($token)) {
        $conn = db_connect();
        $stmt = $conn->prepare("SELECT * FROM teachers WHERE kuerzel = ? LIMIT 1");
        $stmt->execute([$kuerzel]);
        $user = $stmt->fetch();
        
        if ($user) {
            $sso_secret = 'SchulHub_SSO_Secret_Key_2026';
            $time_bucket = floor(time() / 300);
            $token_valid = false;
            for ($i = 0; $i <= 1; $i++) {
                $bucket = $time_bucket - $i;
                $expected = hash('sha256', $user['kuerzel'] . $sso_secret . $bucket);
                if (hash_equals($expected, $token)) {
                    $token_valid = true;
                    break;
                }
            }
            
            if ($token_valid) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_kuerzel'] = $user['kuerzel'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['force_password_change'] = $user['force_password_change'];
                
                header("Location: /index.php");
                exit;
            }
        }
    }
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
            $_SESSION['force_password_change'] = $user['force_password_change'];
            
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
