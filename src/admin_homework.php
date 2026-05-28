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
        
        $context_image_path = null;
        if (isset($_FILES['context_image']) && $_FILES['context_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/public/uploads/context/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $ext = pathinfo($_FILES['context_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('ctx_') . '.' . $ext;
            if (move_uploaded_file($_FILES['context_image']['tmp_name'], $uploadDir . $filename)) {
                $context_image_path = 'uploads/context/' . $filename;
            }
        }

        $token = bin2hex(random_bytes(16));
        
        $stmt = $conn->prepare("INSERT INTO homework_assignments (teacher_id, klasse, fach, title, description, token, context_image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$user_id, $klasse, $fach, $title, $description, $token, $context_image_path])) {
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
            $file_path = __DIR__ . '/public/' . $submission['image_path'];
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
        
    } elseif ($action === 'delete_submissions_bulk') {
        $submission_ids = $_POST['submission_ids'] ?? [];
        $assignment_id = $_POST['assignment_id'] ?? 0;

        if (!empty($submission_ids) && is_array($submission_ids)) {
            $submission_ids = array_map('intval', $submission_ids);
            $placeholders = implode(',', array_fill(0, count($submission_ids), '?'));

            try {
                $conn->beginTransaction();

                // Fetch file paths to unlink
                $stmt_files = $conn->prepare("SELECT image_path FROM homework_submissions WHERE id IN ($placeholders)");
                $stmt_files->execute($submission_ids);
                $files = $stmt_files->fetchAll(PDO::FETCH_COLUMN);

                foreach ($files as $file) {
                    if (!empty($file)) {
                        $file_path = __DIR__ . '/public/' . $file;
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                }

                // Delete records
                $stmt_del = $conn->prepare("DELETE FROM homework_submissions WHERE id IN ($placeholders)");
                $stmt_del->execute($submission_ids);

                $conn->commit();
                $_SESSION['flash_success'] = count($submission_ids) . " Einreichung(en) erfolgreich gelöscht.";
            } catch (Exception $e) {
                $conn->rollBack();
                $_SESSION['flash_error'] = "Fehler beim Löschen: " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_error'] = "Keine Einreichungen zum Löschen ausgewählt.";
        }

        if ($assignment_id) {
            header("Location: admin_homework.php?action=view&id=" . (int)$assignment_id);
        } else {
            header("Location: admin_homework.php");
        }
        exit;
    } elseif ($action === 'edit_evaluation') {
        $submission_id = (int)($_POST['submission_id'] ?? 0);
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $score = isset($_POST['score']) && $_POST['score'] !== '' ? (int)$_POST['score'] : null;
        $teacher_notes = trim($_POST['teacher_notes'] ?? '');
        $student_feedback = trim($_POST['student_feedback'] ?? '');
        
        // Verify ownership of the submission first
        $stmt_sub_verify = $conn->prepare("
            SELECT s.id, e.id as evaluation_id 
            FROM homework_submissions s
            JOIN homework_assignments a ON s.assignment_id = a.id
            LEFT JOIN homework_evaluations e ON s.id = e.submission_id
            WHERE s.id = ? AND a.teacher_id = ?
        ");
        $stmt_sub_verify->execute([$submission_id, $user_id]);
        $sub_info = $stmt_sub_verify->fetch();
        
        if ($sub_info) {
            if ($sub_info['evaluation_id']) {
                // Update existing evaluation
                $stmt_update = $conn->prepare("
                    UPDATE homework_evaluations 
                    SET score = ?, teacher_notes = ?, student_feedback = ? 
                    WHERE submission_id = ?
                ");
                if ($stmt_update->execute([$score, $teacher_notes, $student_feedback, $submission_id])) {
                    $_SESSION['flash_success'] = "Bewertung erfolgreich aktualisiert.";
                } else {
                    $_SESSION['flash_error'] = "Fehler beim Aktualisieren.";
                }
            } else {
                // Create new evaluation from scratch (manual grading)
                $stmt_insert = $conn->prepare("
                    INSERT INTO homework_evaluations (submission_id, score, teacher_notes, student_feedback) 
                    VALUES (?, ?, ?, ?)
                ");
                if ($stmt_insert->execute([$submission_id, $score, $teacher_notes, $student_feedback])) {
                    // Update submission status to 'evaluated'
                    $conn->prepare("UPDATE homework_submissions SET status = 'evaluated' WHERE id = ?")->execute([$submission_id]);
                    $_SESSION['flash_success'] = "Manuelle Bewertung erfolgreich gespeichert.";
                } else {
                    $_SESSION['flash_error'] = "Fehler beim Speichern der Bewertung.";
                }
            }
        } else {
            $_SESSION['flash_error'] = "Keine Berechtigung oder Einreichung nicht gefunden.";
        }
        
        header("Location: admin_homework.php?action=view&id=" . $assignment_id);
        exit;
    }
}

if ($action === 'list') {
    $stmt = $conn->prepare("SELECT * FROM homework_assignments WHERE teacher_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $assignments = $stmt->fetchAll();

    // Fetch submission counts and submissions details
    foreach ($assignments as &$assignment) {
        $stmt_sub = $conn->prepare("
            SELECT s.*, e.teacher_notes, e.student_feedback, e.score, e.error_markers 
            FROM homework_submissions s
            LEFT JOIN homework_evaluations e ON s.id = e.submission_id
            WHERE s.assignment_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt_sub->execute([$assignment['id']]);
        $assignment['submissions'] = $stmt_sub->fetchAll();
        $assignment['submission_count'] = count($assignment['submissions']);
    }

    // Fetch all classes and selected classes
    try {
        $stmt_classes = $conn->prepare("SELECT id, name FROM classes ORDER BY name ASC");
        $stmt_classes->execute();
        $all_classes = $stmt_classes->fetchAll();

        $stmt_selected = $conn->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
        $stmt_selected->execute([$user_id]);
        $selected_class_ids = $stmt_selected->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $all_classes = [];
        $selected_class_ids = [];
    }

    echo $twig->render('admin_homework.twig', [
        'assignments' => $assignments,
        'all_classes' => $all_classes,
        'selected_class_ids' => $selected_class_ids,
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
        SELECT s.*, e.teacher_notes, e.student_feedback, e.score, e.error_markers 
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
        'csrf_token' => get_csrf_token(),
        'is_logged_in' => true,
        'is_admin' => is_current_user_admin(),
        'current_user_name' => get_current_user_name(),
        'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]"
    ]);
}
