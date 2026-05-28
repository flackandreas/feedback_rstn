<?php
/**
 * src/meine_antraege.php
 * View for teachers to see all their past and current requests
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

$user_id = get_current_user_id();
$filter_status = $_GET['status'] ?? 'all';

$conn = db_connect();

// Base queries
$sql_extra  = "SELECT id, 'Ausflug' as type, class_name as details, event_date as date_main, event_date_to as date_end, status, created_at, modified_at, modified_after_approval FROM extracurricular_requests WHERE teacher_id = ?";
$sql_exempt = "SELECT id, 'Freistellung' as type, reason as details, date_from as date_main, date_to as date_end, status, created_at, NULL as modified_at, 0 as modified_after_approval FROM exemption_requests WHERE teacher_id = ?";
$sql_sick   = "SELECT id, 'Krankmeldung' as type, notes as details, date_from as date_main, date_to as date_end, 'approved' as status, created_at, modified_at, 0 as modified_after_approval FROM sick_leave_reports WHERE teacher_id = ?";

$params = [$user_id, $user_id, $user_id];

if ($filter_status !== 'all') {
    $sql_extra .= " AND status = ?";
    $sql_exempt .= " AND status = ?";
    // Sick leaves don't have a status in DB, we mock 'approved' for consistency so filter appropriately.
    if ($filter_status !== 'approved') {
        $sql_sick .= " AND 1=0"; // Don't show sick leaves if we are searching for pending/rejected
    }
    
    $params = [$user_id, $filter_status, $user_id, $filter_status, $user_id];
}

$query = "$sql_extra UNION ALL $sql_exempt UNION ALL $sql_sick ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('meine_antraege.twig', [
    'requests' => $requests,
    'filter_status' => $filter_status,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
