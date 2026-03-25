<?php
/**
 * src/antrag_ausserunterrichtlich.php
 * Form Controller for "Antrag auf außerunterrichtliche Veranstaltung"
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_login();

// Run DB migration silently on first load
require_once __DIR__ . '/run_alter_extra.php';

$user_id = get_current_user_id();
$edit_id = $_GET['edit_id'] ?? $_POST['edit_id'] ?? null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } else {
        $class_name = trim($_POST['class_name'] ?? '');
        $event_date = $_POST['event_date'] ?? '';
        $destination = trim($_POST['destination'] ?? '');
        $aud_type = $_POST['aud_type'] ?? null;
        $participating_teacher_id = !empty($_POST['participating_teacher_id']) ? $_POST['participating_teacher_id'] : null;
        $role              = trim($_POST['role'] ?? '');
        $companion_select_1= trim($_POST['companion_select_1'] ?? '');
        $companion_extra   = trim($_POST['companion_extra'] ?? '');
        $companion = trim(implode(', ', array_filter([$companion_select_1, $companion_extra])));
        $event_name        = trim($_POST['event_name'] ?? '');
        $costs             = trim($_POST['costs'] ?? '');
        $transport         = trim($_POST['transport'] ?? '');
        $start_time        = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $start_location    = trim($_POST['start_location'] ?? '');
        $return_time       = !empty($_POST['return_time']) ? $_POST['return_time'] : null;
        $return_location   = trim($_POST['return_location'] ?? '');
        $return_trip_arranged = isset($_POST['return_trip_arranged']) ? 1 : 0;
        $supervisors       = trim($_POST['supervisors'] ?? '');
        $consent_form      = in_array($_POST['consent_form'] ?? '', ['ja', 'nein']) ? $_POST['consent_form'] : null;
        $schedule_notified = isset($_POST['schedule_notified']) ? 1 : 0;
        
        if (empty($class_name) || empty($event_date) || empty($destination)) {
            $_SESSION['flash_error'] = "Bitte füllen Sie alle Pflichtfelder aus (Klasse, Datum, Ziel).";
        } else {
            try {
                $conn = db_connect();
                
                if ($edit_id) {
                    // Check ownership and current status
                    $stmt_check = $conn->prepare("SELECT status, modified_after_approval FROM extracurricular_requests WHERE id = ? AND teacher_id = ?");
                    $stmt_check->execute([$edit_id, $user_id]);
                    $ext = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($ext) {
                        $is_modified = ($ext['status'] === 'approved') ? 1 : $ext['modified_after_approval'];
                        
                        $stmt = $conn->prepare("
                            UPDATE extracurricular_requests SET
                            role=?, class_name=?, companion=?, event_date=?, event_name=?, destination=?, costs=?, transport=?, start_time=?, start_location=?, return_time=?, return_location=?, return_trip_arranged=?, supervisors=?, consent_form=?, schedule_notified=?, aud_type=?, participating_teacher_id=?, modified_after_approval=?
                            WHERE id = ? AND teacher_id = ?
                        ");
                        $stmt->execute([
                            $role, $class_name, $companion, $event_date, $event_name, $destination,
                            $costs, $transport, $start_time, $start_location, $return_time, $return_location,
                            $return_trip_arranged, $supervisors, $consent_form, $schedule_notified, $aud_type, $participating_teacher_id, $is_modified,
                            $edit_id, $user_id
                        ]);
                        
                        // Notify admin if approved request was modified
                        if ($is_modified && $ext['status'] === 'approved') {
                            require_once __DIR__ . '/includes/mailer.php';
                            $stmt_admin = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'report_email'");
                            $admin_email = $stmt_admin->fetchColumn();
                            
                            if ($admin_email) {
                                $admin_subject = "Änderung an bereits genehmigtem Antrag (ID $edit_id)";
                                $admin_body = "<h3>Achtung: Änderung!</h3>
                                    <p>Der bereits genehmigte Antrag von <strong>" . get_current_user_name() . "</strong> 
                                    für die Veranstaltung <strong>$event_name</strong> (Klasse $class_name) wurde nachträglich geändert.</p>
                                    <p>Bitte überprüfen Sie die Änderungen im Admin-Dashboard.</p>";
                                send_notification_email($admin_email, $admin_subject, $admin_body);
                            }
                        }
                        
                        $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich aktualisiert.";
                    } else {
                        $_SESSION['flash_error'] = "Antrag konnte nicht gefunden werden oder fehlende Berechtigung.";
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO extracurricular_requests 
                        (teacher_id, role, class_name, companion, event_date, event_name, destination, costs, transport, start_time, start_location, return_time, return_location, return_trip_arranged, supervisors, consent_form, schedule_notified, aud_type, participating_teacher_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user_id, $role, $class_name, $companion, $event_date, $event_name, $destination,
                        $costs, $transport, $start_time, $start_location, $return_time, $return_location,
                        $return_trip_arranged, $supervisors, $consent_form, $schedule_notified, $aud_type, $participating_teacher_id
                    ]);
                    $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich eingereicht.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert Error (Extracurricular): " . $e->getMessage());
            }
        }
        
        // Post/Redirect/Get pattern
        header("Location: /antrag_ausserunterrichtlich.php");
        exit;
    }
}

$conn = db_connect();

$edit_request = null;
if ($edit_id) {
    $stmt_edit = $conn->prepare("SELECT * FROM extracurricular_requests WHERE id = ? AND teacher_id = ?");
    $stmt_edit->execute([$edit_id, $user_id]);
    $edit_request = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if (!$edit_request) {
        $_SESSION['flash_error'] = "Antrag konnte nicht geladen werden.";
        header("Location: /meine_antraege.php");
        exit;
    }
    
    // Parse companions to match form field names
    $comps = array_map('trim', explode(',', $edit_request['companion'] ?? ''));
    $edit_request['companion_select_1'] = $comps[0] ?? '';
    $edit_request['companion_extra'] = implode(', ', array_slice($comps, 1));
}

$stmt = $conn->prepare("SELECT class_name, event_date, destination, status, aud_type, participating_teacher_id FROM extracurricular_requests WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

$stmt_teachers = $conn->prepare("SELECT id, name, kuerzel FROM teachers WHERE id != ? ORDER BY name ASC");
$stmt_teachers->execute([$user_id]);
$all_teachers = $stmt_teachers->fetchAll();

$stmt_teachers_full = $conn->prepare("SELECT id, name, kuerzel FROM teachers ORDER BY name ASC");
$stmt_teachers_full->execute();
$all_teachers_full = $stmt_teachers_full->fetchAll();

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('form_ausserunterrichtlich.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'requests' => $requests,
    'edit_request' => $edit_request,
    'all_teachers' => $all_teachers,
    'all_teachers_full' => $all_teachers_full,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
