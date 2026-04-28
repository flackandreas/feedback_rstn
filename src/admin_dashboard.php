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

// 1. Sick leaves (Aktuelle)
$stmt_sick_curr = $conn->query("
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, r.modified_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.date_to >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from ASC
");
$sick_leaves_current = $stmt_sick_curr->fetchAll();

// 1.1 Sick leaves (Historie)
$stmt_sick_hist = $conn->query("
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, r.modified_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.date_to < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from DESC
");
$sick_leaves_old_raw = $stmt_sick_hist->fetchAll();

$sick_history_by_teacher = [];
foreach ($sick_leaves_old_raw as $r) {
    $tid = $r['teacher_id'];
    if (!isset($sick_history_by_teacher[$tid])) {
        $sick_history_by_teacher[$tid] = [
            'id' => $tid,
            'name' => $r['teacher_name'],
            'kuerzel' => $r['kuerzel'],
            'total_days' => 0,
            'records' => []
        ];
    }
    
    $d1 = new DateTime($r['date_main']);
    $d2 = new DateTime($r['date_to']);
    $days = $d2->diff($d1)->days + 1;
    
    $sick_history_by_teacher[$tid]['total_days'] += $days;
    $sick_history_by_teacher[$tid]['records'][] = $r;
}
usort($sick_history_by_teacher, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

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
    'sick_leaves_current' => $sick_leaves_current,
    'sick_history_by_teacher' => $sick_history_by_teacher,
    'exempt_requests' => $exempt_requests,
    'app_settings' => $app_settings,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
