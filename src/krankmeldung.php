<?php
/**
 * src/krankmeldung.php
 * Form Controller for "Krankmeldung"
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_login();

$user_id = get_current_user_id();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } else {
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($date_from) || empty($date_to)) {
            $_SESSION['flash_error'] = "Bitte füllen Sie den Zeitraum der Krankmeldung aus.";
        } else {
            try {
                $conn = db_connect();
                $stmt = $conn->prepare("INSERT INTO sick_leave_reports (teacher_id, date_from, date_to, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $date_from, $date_to, $notes]);
                
                $_SESSION['flash_success'] = "Ihre Krankmeldung wurde erfolgreich übermittelt.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert Error (Sick Leave): " . $e->getMessage());
            }
        }
        
        // Post/Redirect/Get pattern
        header("Location: /krankmeldung.php");
        exit;
    }
}

$conn = db_connect();
$stmt = $conn->prepare("SELECT date_from, date_to FROM sick_leave_reports WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('form_krankmeldung.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'requests' => $requests,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
