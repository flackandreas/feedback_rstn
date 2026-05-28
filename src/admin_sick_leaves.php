<?php
/**
 * src/admin_sick_leaves.php
 * Sickness reports administration for Schulleitung.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$conn = db_connect();

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: CSRF Token ungültig.";
    } else {
        $action = $_POST['action'];
        if ($action === 'mark_seen') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("UPDATE sick_leave_reports SET is_seen = 1 WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_success'] = "Krankmeldung wurde als gelesen markiert.";
        } elseif ($action === 'mark_all_seen') {
            $conn->query("UPDATE sick_leave_reports SET is_seen = 1 WHERE is_seen = 0");
            $_SESSION['flash_success'] = "Alle Krankmeldungen wurden als gelesen markiert.";
        }
    }
    header("Location: /admin_sick_leaves.php");
    exit;
}

// 1. Neue Krankmeldungen (is_seen = 0)
$stmt_new = $conn->query("
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, r.modified_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.is_seen = 0
    ORDER BY r.created_at DESC
");
$sick_leaves_new = $stmt_new->fetchAll();

// 2. Laufende Krankmeldungen (is_seen = 1, date_to >= DATE_SUB(CURDATE(), INTERVAL 2 DAY))
$stmt_curr = $conn->query("
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, r.modified_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.is_seen = 1 AND r.date_to >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from ASC
");
$sick_leaves_current = $stmt_curr->fetchAll();

// 3. Vergangene Krankmeldungen (is_seen = 1, date_to < DATE_SUB(CURDATE(), INTERVAL 2 DAY))
$stmt_hist = $conn->query("
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, r.modified_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.is_seen = 1 AND r.date_to < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from DESC
");
$sick_leaves_old_raw = $stmt_hist->fetchAll();

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

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/twig_setup.php';

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_sick_leaves.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'sick_leaves_new' => $sick_leaves_new,
    'sick_leaves_current' => $sick_leaves_current,
    'sick_history_by_teacher' => $sick_history_by_teacher,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
