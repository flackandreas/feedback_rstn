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
        $companion         = trim($_POST['companion'] ?? '');
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
$stmt = $conn->prepare("SELECT class_name, event_date, destination, status, aud_type, participating_teacher_id FROM extracurricular_requests WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

$stmt_teachers = $conn->prepare("SELECT id, name, kuerzel FROM teachers WHERE id != ? ORDER BY name ASC");
$stmt_teachers->execute([$user_id]);
$all_teachers = $stmt_teachers->fetchAll();

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('form_ausserunterrichtlich.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'requests' => $requests,
    'all_teachers' => $all_teachers,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
