<?php
/**
 * src/index.php
 * Main Dashboard
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

$user_id = get_current_user_id();
$user_name = get_current_user_name();

$conn = db_connect();

if (is_current_user_admin()) {
    // 1. Fetch counts for admin widgets
    // Unread/unseen sick leaves
    $stmt_sick_count = $conn->query("SELECT COUNT(*) FROM sick_leave_reports WHERE is_seen = 0");
    $unread_sick = $stmt_sick_count->fetchColumn();

    // Pending extracurricular
    $stmt_aud_count = $conn->query("SELECT COUNT(*) FROM extracurricular_requests WHERE status = 'pending'");
    $pending_aud = $stmt_aud_count->fetchColumn();

    // Pending exemptions
    $stmt_exempt_count = $conn->query("SELECT COUNT(*) FROM exemption_requests WHERE status = 'pending'");
    $pending_exemptions = $stmt_exempt_count->fetchColumn();

    // 2. Fetch today's absent teachers
    // Sick today
    $stmt_sick_today = $conn->query("
        SELECT t.name, t.kuerzel, s.notes, s.date_from, s.date_to
        FROM sick_leave_reports s
        JOIN teachers t ON s.teacher_id = t.id
        WHERE CURDATE() BETWEEN s.date_from AND s.date_to
    ");
    $sick_today = $stmt_sick_today->fetchAll();

    // Exempt today
    $stmt_exempt_today = $conn->query("
        SELECT t.name, t.kuerzel, r.reason_type, r.reason, r.date_from, r.date_to, r.hourly_exemption, r.hour_from, r.hour_to
        FROM exemption_requests r
        JOIN teachers t ON r.teacher_id = t.id
        WHERE r.status = 'approved' AND CURDATE() BETWEEN r.date_from AND r.date_to
    ");
    $exempt_today = $stmt_exempt_today->fetchAll();

    // Extracurricular trips today
    $stmt_extra_today = $conn->query("
        SELECT t.name, t.kuerzel, r.event_name, r.class_name, r.destination, r.event_date, r.event_date_to
        FROM extracurricular_requests r
        JOIN teachers t ON r.teacher_id = t.id
        WHERE r.status = 'approved' AND CURDATE() BETWEEN r.event_date AND IFNULL(r.event_date_to, r.event_date)
    ");
    $extra_today = $stmt_extra_today->fetchAll();

    $total_absent = count($sick_today) + count($exempt_today) + count($extra_today);

    // 3. Fetch today's calendar events (both local absent events & external IServ events)
    require_once __DIR__ . '/includes/calendar_helper.php';
    
    // Fetch local events:
    $events = [];
    
    foreach ($sick_today as $s) {
        $events[] = [
            'type' => 'sick',
            'title' => '🤒 Krank: ' . $s['name'] . ' (' . $s['kuerzel'] . ')',
            'details' => $s['notes'] ? 'Notizen: ' . $s['notes'] : ''
        ];
    }
    
    foreach ($exempt_today as $e) {
        $time_info = $e['hourly_exemption'] ? " (Stunde {$e['hour_from']}-{$e['hour_to']})" : "";
        $events[] = [
            'type' => 'exempt',
            'title' => '🏖️ Freigestellt: ' . $e['name'] . ' (' . $e['kuerzel'] . ')' . $time_info,
            'details' => 'Grund: ' . $e['reason_type'] . ($e['reason'] ? ' - ' . $e['reason'] : '')
        ];
    }
    
    foreach ($extra_today as $ex) {
        $events[] = [
            'type' => 'extra',
            'title' => '🚌 Ausflug (' . $ex['class_name'] . '): ' . $ex['event_name'],
            'details' => 'Ziel: ' . $ex['destination'] . ' - Leitung: ' . $ex['name']
        ];
    }

    // Fetch IServ events and filter for today
    $iserv_url = 'https://rstn.de/iserv/public/calendar?key=f5c7249d68e573f308af152f75f832e8';
    $iserv_events = get_iserv_events($iserv_url);
    $today = date('Y-m-d');
    foreach ($iserv_events as $ev) {
        if ($today >= $ev['start'] && $today <= $ev['end']) {
            $events[] = [
                'type' => 'iserv',
                'title' => $ev['title'],
                'details' => $ev['details']
            ];
        }
    }

    require_once __DIR__ . '/includes/twig_setup.php';
    $csrf_token = get_csrf_token();
    $flash_success = $_SESSION['flash_success'] ?? null;
    $flash_error = $_SESSION['flash_error'] ?? null;
    unset($_SESSION['flash_success'], $_SESSION['flash_error']);

    echo $twig->render('admin_dashboard_new.twig', [
        'current_user_name' => $user_name,
        'is_admin' => true,
        'is_logged_in' => true,
        'csrf_token' => $csrf_token,
        'flash_success' => $flash_success,
        'flash_error' => $flash_error,
        'counts' => [
            'unread_sick' => (int)$unread_sick,
            'pending_aud' => (int)$pending_aud,
            'pending_exemptions' => (int)$pending_exemptions
        ],
        'sick_today' => $sick_today,
        'exempt_today' => $exempt_today,
        'extra_today' => $extra_today,
        'total_absent' => $total_absent,
        'today_events' => $events
    ]);
    exit;
}

// Fetch summary of current requests for normal teachers
try {
    // Extracurricular
    $stmt1 = $conn->prepare("SELECT COUNT(*) FROM extracurricular_requests WHERE teacher_id = ? AND status = 'pending'");
    $stmt1->execute([$user_id]);
    $pending_extra = $stmt1->fetchColumn();

    // Exemptions
    $stmt2 = $conn->prepare("SELECT COUNT(*) FROM exemption_requests WHERE teacher_id = ? AND status = 'pending'");
    $stmt2->execute([$user_id]);
    $pending_exemptions = $stmt2->fetchColumn();

    // Sick leaves (Total recent)
    $stmt3 = $conn->prepare("SELECT COUNT(*) FROM sick_leave_reports WHERE teacher_id = ? AND date_from >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt3->execute([$user_id]);
    $recent_sick = $stmt3->fetchColumn();

    // Queries (Rückfragen)
    $stmt4 = $conn->prepare("SELECT COUNT(*) FROM extracurricular_requests WHERE teacher_id = ? AND status = 'query'");
    $stmt4->execute([$user_id]);
    $queries_extra = $stmt4->fetchColumn();

    $stmt5 = $conn->prepare("SELECT COUNT(*) FROM exemption_requests WHERE teacher_id = ? AND status = 'query'");
    $stmt5->execute([$user_id]);
    $queries_exempt = $stmt5->fetchColumn();
    
    $total_queries = (int)$queries_extra + (int)$queries_exempt;

} catch (PDOException $e) {
    // If table doesn't exist yet, we catch the error gracefully
    $pending_extra = $pending_exemptions = $recent_sick = 0;
    $total_queries = 0;
}

try {
    $stmt_classes = $conn->prepare("SELECT id, name FROM classes ORDER BY name ASC");
    $stmt_classes->execute();
    $all_classes = $stmt_classes->fetchAll();

    // Fetch selected classes for this teacher
    $stmt_selected = $conn->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
    $stmt_selected->execute([$user_id]);
    $selected_class_ids = $stmt_selected->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $all_classes = [];
    $selected_class_ids = [];
}

require_once __DIR__ . '/includes/twig_setup.php';

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('dashboard.twig', [
    'current_user_name' => $user_name,
    'current_date' => date('d.m.Y'),
    'pending_extra' => (int)$pending_extra,
    'pending_exemptions' => (int)$pending_exemptions,
    'recent_sick' => (int)$recent_sick,
    'total_queries' => $total_queries ?? 0,
    'all_classes' => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]",
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true,
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
