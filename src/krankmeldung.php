<?php
/**
 * src/krankmeldung.php
 * Form Controller for "Krankmeldung"
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

$user_id = get_current_user_id();
$edit_id = $_GET['id'] ?? null;
$editing_request = null;
$conn = db_connect();

if ($edit_id) {
    $stmt = $conn->prepare("SELECT * FROM sick_leave_reports WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $editing_request = $stmt->fetch();
    if (!$editing_request) {
        $edit_id = null; // invalid ID or not belonging to user
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $post_edit_id = $_POST['edit_id'] ?? null;
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } else {
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        $material_link = trim($_POST['material_link'] ?? '');
        
        // Handle file upload
        $attachment_path = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['application/pdf', 'image/jpeg', 'image/png'];
            if (in_array($_FILES['attachment']['type'], $allowed_types)) {
                $filename = uniqid('au_') . '_' . basename($_FILES['attachment']['name']);
                $upload_dir = __DIR__ . '/public/uploads/';
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $filename)) {
                    $attachment_path = 'uploads/' . $filename;
                }
            } else {
                $_SESSION['flash_error'] = "Ungültiges Dateiformat. Bitte laden Sie ein PDF, JPG oder PNG hoch.";
            }
        }
        
        if (empty($date_from) || empty($date_to)) {
            $_SESSION['flash_error'] = "Bitte füllen Sie den Zeitraum der Krankmeldung aus.";
        } elseif (!isset($_SESSION['flash_error'])) {
            try {
                if ($post_edit_id) {
                    // Update
                    if ($attachment_path) {
                        $stmt = $conn->prepare("UPDATE sick_leave_reports SET date_from = ?, date_to = ?, notes = ?, material_link = ?, attachment_path = ?, modified_at = NOW() WHERE id = ? AND teacher_id = ?");
                        $stmt->execute([$date_from, $date_to, $notes, $material_link, $attachment_path, $post_edit_id, $user_id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE sick_leave_reports SET date_from = ?, date_to = ?, notes = ?, material_link = ?, modified_at = NOW() WHERE id = ? AND teacher_id = ?");
                        $stmt->execute([$date_from, $date_to, $notes, $material_link, $post_edit_id, $user_id]);
                    }
                    $_SESSION['flash_success'] = "Ihre Krankmeldung wurde erfolgreich aktualisiert.";
                } else {
                    // Insert
                    $stmt = $conn->prepare("INSERT INTO sick_leave_reports (teacher_id, date_from, date_to, notes, material_link, attachment_path) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $date_from, $date_to, $notes, $material_link, $attachment_path]);
                    $_SESSION['flash_success'] = "Ihre Krankmeldung wurde erfolgreich übermittelt.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert/Update Error (Sick Leave): " . $e->getMessage());
            }
        }
        header("Location: /krankmeldung.php");
        exit;
    }
}

$stmt = $conn->prepare("SELECT id, date_from, date_to, attachment_path, created_at FROM sick_leave_reports WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
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
    'editing_request' => $editing_request,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
