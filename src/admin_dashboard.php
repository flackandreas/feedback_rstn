<?php
/**
 * src/admin_dashboard.php
 * Dashboard for Schulleitung to manage incoming requests separately.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$conn = db_connect();

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['teacher_csv'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: CSRF Token ungültig.";
        header("Location: /admin_dashboard.php");
        exit;
    }
    
    $file = $_FILES['teacher_csv'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                // Determine delimiter by reading first line
                $first_line = fgets($handle);
                $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
                rewind($handle);
                
                // Skip header row
                fgetcsv($handle, 1000, $delimiter);
                
                $success_count = 0;
                $skip_count = 0;
                
                $stmt = $conn->prepare("INSERT IGNORE INTO teachers (kuerzel, name, email, passwort_hash) VALUES (?, ?, ?, ?)");
                $default_pw_hash = password_hash('lehrer', PASSWORD_DEFAULT);
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
                    if (count($data) >= 2) {
                        $kuerzel = trim($data[0]);
                        $name = trim($data[1]);
                        $email = isset($data[2]) ? trim($data[2]) : null;
                        
                        if (!empty($kuerzel) && !empty($name)) {
                            if ($email === '') $email = null;
                            
                            $stmt->execute([$kuerzel, $name, $email, $default_pw_hash]);
                            if ($stmt->rowCount() > 0) {
                                $success_count++;
                            } else {
                                $skip_count++;
                            }
                        }
                    }
                }
                fclose($handle);
                $_SESSION['flash_success'] = "Import abgeschlossen: $success_count hinzugefügt, $skip_count übersprungen (bereits vorhanden).";
            } else {
                $_SESSION['flash_error'] = "Fehler beim Lesen der hochgeladenen Datei.";
            }
        } else {
            $_SESSION['flash_error'] = "Nur .csv Dateien sind erlaubt.";
        }
    } else {
        $_SESSION['flash_error'] = "Fehler beim Hochladen der Datei.";
    }
    header("Location: /admin_dashboard.php");
    exit;
}

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
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.date_to >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from ASC
");
$sick_leaves_current = $stmt_sick_curr->fetchAll();

// 1.1 Sick leaves (Historie)
$stmt_sick_hist = $conn->query("
    SELECT r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link
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

// 3. Extracurricular requests (Ausflüge) grouped and augmented
$stmt_extra = $conn->query("
    SELECT r.id, 'Ausflug' as type, r.class_name as class, r.class_name, r.event_date, r.destination, 
           CONCAT_WS('<br>', 
               IF(r.aud_type IS NOT NULL AND r.aud_type != '', CONCAT('<strong>Art:</strong> ', r.aud_type), NULL),
               CONCAT('<strong>Ziel:</strong> ', r.destination),
               IF(p.name IS NOT NULL, CONCAT('<strong>Begleitung:</strong> ', p.name), NULL)
           ) as details, 
           r.aud_type, r.event_date as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel,
           r.role, r.companion, r.event_name, r.costs, r.transport,
           r.start_time, r.start_location, r.return_time, r.return_location,
           r.return_trip_arranged, r.supervisors, r.consent_form, r.schedule_notified
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    LEFT JOIN teachers p ON r.participating_teacher_id = p.id
    ORDER BY r.aud_type ASC, FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$extra_requests = $stmt_extra->fetchAll(PDO::FETCH_ASSOC);

// Summary for AUD requests and finding Unassigned teachers
$aud_list = ['AUD 1', 'AUD 2', 'AUD 3', 'AUD 4', 'AUD 6', 'AUD 7'];
$aud_assigned = [];
$assigned_teacher_ids = [];

foreach ($aud_list as $aud) {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.id, t.name, t.kuerzel 
        FROM extracurricular_requests r
        JOIN teachers t ON (r.teacher_id = t.id OR r.participating_teacher_id = t.id)
        WHERE r.aud_type = ?
    ");
    $stmt->execute([$aud]);
    $list = $stmt->fetchAll();
    $aud_assigned[$aud] = $list;
    foreach ($list as $l) {
        $assigned_teacher_ids[] = $l['id'];
    }
}
$assigned_teacher_ids = array_unique($assigned_teacher_ids);

// Unassigned Teachers
if (empty($assigned_teacher_ids)) {
    $stmt_unassigned = $conn->query("SELECT id, name, kuerzel FROM teachers WHERE is_admin = 0 ORDER BY name ASC");
} else {
    $inClause = implode(',', array_map('intval', $assigned_teacher_ids));
    $stmt_unassigned = $conn->query("SELECT id, name, kuerzel FROM teachers WHERE is_admin = 0 AND id NOT IN ($inClause) ORDER BY name ASC");
}
$unassigned_teachers = $stmt_unassigned->fetchAll();

// Construct copy text:
$copy_text = "=== Übersicht Außerunterrichtliche Veranstaltungen ===\n\n";
foreach ($aud_list as $aud) {
    $copy_text .= "[$aud]\n";
    if (empty($aud_assigned[$aud])) {
         $copy_text .= "  Keine Lehrkräfte\n";
    } else {
         foreach($aud_assigned[$aud] as $t) {
             $copy_text .= "  - " . $t['name'] . " (" . $t['kuerzel'] . ")\n";
         }
    }
    $copy_text .= "\n";
}
$copy_text .= "[Nicht eingeteilte Lehrkräfte]\n";
if (empty($unassigned_teachers)) {
    $copy_text .= "  Alle Lehrkräfte sind eingeteilt.\n";
} else {
    foreach ($unassigned_teachers as $t) {
        $copy_text .= "  - " . $t['name'] . " (" . $t['kuerzel'] . ")\n";
    }
}

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
    'extra_requests' => $extra_requests,
    'copy_text' => $copy_text,
    'aud_assigned' => $aud_assigned,
    'unassigned_teachers' => $unassigned_teachers,
    'app_settings' => $app_settings,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
