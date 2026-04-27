<?php
/**
 * src/admin_homework.php
 * Controller for managing homework assignments
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_login();
$user_id = get_current_user_id();

$conn = db_connect();
$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        die("Invalid CSRF token");
    }

    if ($action === 'create') {
        $klasse = $_POST['klasse'] ?? '';
        $fach = $_POST['fach'] ?? '';
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        
        $token = bin2hex(random_bytes(16));
        
        $stmt = $conn->prepare("INSERT INTO homework_assignments (teacher_id, klasse, fach, title, description, token) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$user_id, $klasse, $fach, $title, $description, $token])) {
            $_SESSION['flash_success'] = "Hausaufgabe erfolgreich erstellt.";
        } else {
            $_SESSION['flash_error'] = "Fehler beim Erstellen.";
        }
        header("Location: admin_homework.php");
        exit;
    } elseif ($action === 'delete_sub') {
        $submission_id = $_POST['submission_id'] ?? 0;
        $assignment_id = $_POST['assignment_id'] ?? 0;

        // Verify ownership
        $stmt_verify = $conn->prepare("
            SELECT s.image_path 
            FROM homework_submissions s
            JOIN homework_assignments a ON s.assignment_id = a.id
            WHERE s.id = ? AND a.teacher_id = ?
        ");
        $stmt_verify->execute([$submission_id, $user_id]);
        $submission = $stmt_verify->fetch();

        if ($submission) {
            // Delete file from disk
            $file_path = __DIR__ . '/../' . $submission['image_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }

            // Delete database record (evaluations will cascade)
            $stmt_del = $conn->prepare("DELETE FROM homework_submissions WHERE id = ?");
            if ($stmt_del->execute([$submission_id])) {
                $_SESSION['flash_success'] = "Einreichung erfolgreich gelöscht.";
            } else {
                $_SESSION['flash_error'] = "Fehler beim Löschen aus der Datenbank.";
            }
        } else {
            $_SESSION['flash_error'] = "Keine Berechtigung oder Einreichung nicht gefunden.";
        }
        
        header("Location: admin_homework.php?action=view&id=" . (int)$assignment_id);
        exit;
    }
}

if ($action === 'list') {
    $stmt = $conn->prepare("SELECT * FROM homework_assignments WHERE teacher_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $assignments = $stmt->fetchAll();

    // Fetch submission counts
    foreach ($assignments as &$assignment) {
        $stmt_sub = $conn->prepare("SELECT COUNT(*) FROM homework_submissions WHERE assignment_id = ?");
        $stmt_sub->execute([$assignment['id']]);
        $assignment['submission_count'] = $stmt_sub->fetchColumn();
    }

    echo $twig->render('admin_homework.twig', [
        'assignments' => $assignments,
        'csrf_token' => get_csrf_token(),
        'flash_success' => $_SESSION['flash_success'] ?? null,
        'flash_error' => $_SESSION['flash_error'] ?? null,
        'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]",
        'is_logged_in' => true,
        'is_admin' => is_current_user_admin(),
        'current_user_name' => get_current_user_name()
    ]);
    unset($_SESSION['flash_success'], $_SESSION['flash_error']);
} elseif ($action === 'view') {
    $assignment_id = $_GET['id'] ?? 0;
    
    $stmt = $conn->prepare("SELECT * FROM homework_assignments WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$assignment_id, $user_id]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        die("Assignment not found or permission denied.");
    }

    $stmt_subs = $conn->prepare("
        SELECT s.*, e.teacher_notes, e.student_feedback, e.score 
        FROM homework_submissions s 
        LEFT JOIN homework_evaluations e ON s.id = e.submission_id 
        WHERE s.assignment_id = ? 
        ORDER BY s.created_at DESC
    ");
    $stmt_subs->execute([$assignment_id]);
    $submissions = $stmt_subs->fetchAll();

    echo $twig->render('admin_homework_details.twig', [
        'assignment' => $assignment,
        'submissions' => $submissions,
        'is_logged_in' => true,
        'is_admin' => is_current_user_admin(),
        'current_user_name' => get_current_user_name(),
        'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]"
    ]);
}
