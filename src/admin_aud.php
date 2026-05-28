<?php
/**
 * src/admin_aud.php
 * Management of extracurricular activities (AUD).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_admin();

$conn = db_connect();

// 1. Extracurricular requests (Ausflüge) grouped and augmented
$stmt_extra = $conn->query("
    SELECT r.id, 'Ausflug' as type, r.class_name as class, r.class_name, r.event_date, r.event_date_to, r.destination, 
           CONCAT_WS('<br>', 
               IF(r.aud_type IS NOT NULL AND r.aud_type != '', CONCAT('<strong>Art:</strong> ', r.aud_type), NULL),
               CONCAT('<strong>Ziel:</strong> ', r.destination),
               IF(p.name IS NOT NULL, CONCAT('<strong>Begleitung:</strong> ', p.name), NULL)
           ) as details, 
           r.aud_type, r.event_date as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel,
           r.role, r.companion, r.event_name, r.costs, r.transport,
           r.start_time, r.start_location, r.return_time, r.return_location,
           r.return_trip_arranged, r.supervisors, r.consent_form, r.schedule_notified,
           r.modified_after_approval, r.modified_at
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    LEFT JOIN teachers p ON r.participating_teacher_id = p.id
    ORDER BY r.aud_type ASC, FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$extra_requests = $stmt_extra->fetchAll(PDO::FETCH_ASSOC);

// Summary for AUD requests - finding FREE teachers
$aud_list_for_ui = ['AUD 1', 'AUD 2', 'AUD 3', 'AUD 4', 'AUD 5', 'AUD 6', 'AUD 7', 'Sonstige'];
$aud_free = [];

$stmt_all = $conn->query("SELECT id, name, kuerzel FROM teachers WHERE is_admin = 0 ORDER BY name ASC");
$all_teachers = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

foreach ($aud_list_for_ui as $aud) {
    // Collect all assigned teachers for this AUD
    $stmt = $conn->prepare("
        SELECT r.teacher_id, r.participating_teacher_id, r.companion
        FROM extracurricular_requests r
        WHERE r.aud_type = ? AND r.status != 'rejected'
    ");
    $stmt->execute([$aud]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $assigned_ids = [];
    $assigned_names = [];
    
    foreach ($requests as $req) {
        if ($req['teacher_id']) $assigned_ids[] = $req['teacher_id'];
        if ($req['participating_teacher_id']) $assigned_ids[] = $req['participating_teacher_id'];
        
        $comp_str = $req['companion'];
        if (!empty($comp_str)) {
            $assigned_names[] = $comp_str;
        }
    }
    
    $free_for_aud = [];
    foreach ($all_teachers as $t) {
        $is_assigned = false;
        if (in_array($t['id'], $assigned_ids)) {
            $is_assigned = true;
        } else {
            foreach ($assigned_names as $comp_str) {
                if (strpos($comp_str, $t['name']) !== false || strpos($comp_str, "(" . $t['kuerzel'] . ")") !== false) {
                    $is_assigned = true;
                    break;
                }
            }
        }
        
        if (!$is_assigned) {
            $free_for_aud[] = $t;
        }
    }
    
    $aud_free[$aud] = $free_for_aud;
}

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_aud.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'extra_requests' => $extra_requests,
    'aud_free' => $aud_free,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
