<?php
/**
 * src/login.php
 * Login interface
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect to dashboard if already logged in
if (is_logged_in()) {
    header("Location: /index.php");
    exit;
}

$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $kuerzel = trim($_POST['kuerzel'] ?? '');
    $password = $_POST['passwort'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error_msg = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } elseif (empty($kuerzel) || empty($password)) {
        $error_msg = "Bitte Kürzel und Passwort eingeben.";
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
            $error_msg = "Falsches Kürzel oder Passwort.";
        }
    }
}

// Generate new CSRF token for the form
$csrf_token = get_csrf_token();
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="content-box" style="width: 100%; max-width: 400px; padding: 40px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h1 style="color: var(--primary-color); margin-bottom: 5px;">E-Anträge</h1>
        <p style="color: var(--text-muted); font-size: 0.9em;">Lehrer-Login</p>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="status error">
            <?= htmlspecialchars($error_msg) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        
        <div class="form-group">
            <label for="kuerzel">Kürzel:</label>
            <input type="text" id="kuerzel" name="kuerzel" required autofocus>
        </div>

        <div class="form-group">
            <label for="passwort">Passwort:</label>
            <input type="password" id="passwort" name="passwort" required>
        </div>

        <div style="margin-top: 25px;">
            <button type="submit" class="button-primary" style="width: 100%;">Anmelden</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
