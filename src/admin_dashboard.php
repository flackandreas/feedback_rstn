<?php
/**
 * src/admin_dashboard.php
 * Dashboard for Schulleitung to manage incoming requests separately.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$conn = db_connect();

// (CSV Import moved to admin_system.php)


// Handle Settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: CSRF Token ungültig.";
    } else {
        $report_email = $_POST['report_email'] ?? '';
        $report_time = $_POST['report_time'] ?? '';
        $report_interval = $_POST['report_interval'] ?? '';

        $stmt = $conn->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$report_email, 'report_email']);
        $stmt->execute([$report_time, 'report_time']);
        $stmt->execute([$report_interval, 'report_interval']);
        
        $_SESSION['flash_success'] = "Bericht-Einstellungen gespeichert.";
    }
    header("Location: /admin_dashboard.php");
    exit;
}

// Fetch App Settings
$stmt_settings = $conn->query("SELECT setting_key, setting_value FROM app_settings");
$app_settings = [];
while ($row = $stmt_settings->fetch()) {
    $app_settings[$row['setting_key']] = $row['setting_value'];
}

// 2. Exemption requests (Freistellungen)
$stmt_exempt = $conn->query("
    SELECT r.id, r.reason_type, r.days_of_week, r.classes, r.hourly_exemption, r.hour_from, r.hour_to, r.reason,
           r.date_from, r.date_to, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$exempt_requests = $stmt_exempt->fetchAll(PDO::FETCH_ASSOC);

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/twig_setup.php';

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (isset($_GET['error']) && $_GET['error'] === 'csrf') {
    $flash_error = "Sicherheitsfehler: Bitte Aktion erneut ausführen.";
}

echo $twig->render('admin_dashboard.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'exempt_requests' => $exempt_requests,
    'app_settings' => $app_settings,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
