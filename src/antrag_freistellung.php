<?php
/**
 * src/antrag_freistellung.php
 * Form Controller for "Antrag auf Freistellung"
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
        $reason = trim($_POST['reason'] ?? '');
        
        $days_of_week = isset($_POST['days_of_week']) && is_array($_POST['days_of_week']) ? implode(', ', $_POST['days_of_week']) : null;
        $classes = trim($_POST['classes'] ?? '');
        $hourly_exemption = isset($_POST['hourly_exemption']) ? 1 : 0;
        $hour_from = !empty($_POST['hour_from']) ? (int)$_POST['hour_from'] : null;
        $hour_to = !empty($_POST['hour_to']) ? (int)$_POST['hour_to'] : null;
        $reason_type = $_POST['reason_type'] ?? null;
        
        if (empty($date_from) || empty($date_to) || empty($reason) || empty($reason_type)) {
            $_SESSION['flash_error'] = "Bitte füllen Sie alle Pflichtfelder aus.";
        } else {
            try {
                $conn = db_connect();
                $stmt = $conn->prepare("INSERT INTO exemption_requests (teacher_id, date_from, date_to, reason, days_of_week, classes, hourly_exemption, hour_from, hour_to, reason_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $date_from, $date_to, $reason, $days_of_week, $classes, $hourly_exemption, $hour_from, $hour_to, $reason_type]);
                
                $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich eingereicht.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert Error (Exemption): " . $e->getMessage());
            }
        }
        
        // Post/Redirect/Get pattern
        header("Location: /antrag_freistellung.php");
        exit;
    }
}

$conn = db_connect();
$stmt = $conn->prepare("SELECT date_from, date_to, status FROM exemption_requests WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

$stmt_classes = $conn->prepare("SELECT id, name FROM classes ORDER BY name ASC");
$stmt_classes->execute();
$all_classes = $stmt_classes->fetchAll();

// Fetch selected classes for this teacher
$stmt_selected = $conn->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
$stmt_selected->execute([$user_id]);
$selected_class_ids = $stmt_selected->fetchAll(PDO::FETCH_COLUMN);

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('form_freistellung.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'requests' => $requests,
    'all_classes' => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
