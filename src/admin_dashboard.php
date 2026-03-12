<?php
/**
 * src/admin_dashboard.php
 * Dashboard for Schulleitung to manage incoming requests separately.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$conn = db_connect();

// 1. Sick leaves (Krankmeldungen)
$stmt_sick = $conn->query("
    SELECT r.id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, 'Info' as status, r.created_at, t.name as teacher_name, t.kuerzel
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    ORDER BY r.created_at DESC
");
$sick_leaves = $stmt_sick->fetchAll();

// 2. Exemption requests (Freistellungen)
$stmt_exempt = $conn->query("
    SELECT r.id, 'Freistellung' as type, r.reason as details, r.date_from as date_main, r.date_to, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$exempt_requests = $stmt_exempt->fetchAll();

// 3. Extracurricular requests (Ausflüge)
$stmt_extra = $conn->query("
    SELECT r.id, 'Ausflug' as type, r.class_name as class, r.destination as details, r.event_date as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$extra_requests = $stmt_extra->fetchAll();

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
    'sick_leaves' => $sick_leaves,
    'exempt_requests' => $exempt_requests,
    'extra_requests' => $extra_requests,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
