<?php
/**
 * src/feedback_action.php
 * Handles starting and stopping feedback sessions
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
        header("Location: /teacher_feedback.php");
        exit;
    }

    $conn = db_connect();
    $teacher_id = get_current_user_id();
    
    if ($_POST['action'] === 'start') {
        $klasse = trim($_POST['klasse'] ?? '');
        $fach = trim($_POST['fach'] ?? '');
        
        if (empty($klasse) || empty($fach)) {
            $_SESSION['flash_error'] = "Bitte Klasse und Fach angeben.";
            header("Location: /teacher_feedback.php");
            exit;
        }
        
        // Generate Token
        $token = bin2hex(random_bytes(16));
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        // Deactivate old sessions for this teacher? Or allow multiple? Usually one at a time.
        $stmt_deactivate = $conn->prepare("UPDATE feedback_sessions SET is_active = 0 WHERE teacher_id = ?");
        $stmt_deactivate->execute([$teacher_id]);
        
        $stmt = $conn->prepare("INSERT INTO feedback_sessions (teacher_id, klasse, fach, token, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$teacher_id, $klasse, $fach, $token, $expires_at]);
        $session_id = $conn->lastInsertId();
        
        // Save Questions
        $questions = $_POST['questions'] ?? [];
        if (empty($questions)) {
            $questions = ["Wie war die heutige Stunde?", "Wie ist aktuell das Klassenklima?"];
        }
        
        $stmt_q = $conn->prepare("INSERT INTO feedback_questions (session_id, question_text, sort_order) VALUES (?, ?, ?)");
        foreach ($questions as $index => $q_text) {
            $q_text = trim($q_text);
            if (!empty($q_text)) {
                $stmt_q->execute([$session_id, $q_text, $index]);
            }
        }
        
        // Check if user wants to save this as a template
        $save_template = !empty($_POST['save_template']);
        $template_title = trim($_POST['template_title'] ?? '');

        if ($save_template && !empty($template_title)) {
            try {
                // Insert template
                $stmt_t = $conn->prepare("INSERT INTO feedback_templates (teacher_id, title, klasse, fach) VALUES (?, ?, ?, ?)");
                $stmt_t->execute([$teacher_id, $template_title, $klasse, $fach]);
                $new_template_id = $conn->lastInsertId();
                
                // Insert template questions
                $stmt_tq = $conn->prepare("INSERT INTO feedback_template_questions (template_id, question_text, sort_order) VALUES (?, ?, ?)");
                foreach ($questions as $index => $q_text) {
                    $q_text = trim($q_text);
                    if (!empty($q_text)) {
                        $stmt_tq->execute([$new_template_id, $q_text, $index]);
                    }
                }
                $_SESSION['flash_success'] = "Feedback-Sitzung gestartet und Vorlage \"" . htmlspecialchars($template_title) . "\" gespeichert.";
            } catch (PDOException $e) {
                error_log("Failed to save template: " . $e->getMessage());
                $_SESSION['flash_success'] = "Feedback-Sitzung gestartet (Vorlage speichern fehlgeschlagen).";
            }
        } else {
            $_SESSION['flash_success'] = "Feedback-Sitzung für $klasse ($fach) gestartet.";
        }
        
        $_SESSION['active_session_token'] = $token;
        
    } elseif ($_POST['action'] === 'stop') {
        $stmt = $conn->prepare("UPDATE feedback_sessions SET is_active = 0 WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);
        unset($_SESSION['active_session_token']);
        $_SESSION['flash_success'] = "Feedback-Sitzung beendet.";
    } elseif ($_POST['action'] === 'delete_template') {
        $template_id = (int)($_POST['template_id'] ?? 0);
        if ($template_id > 0) {
            try {
                // Ensure the template belongs to the current user
                $stmt_del = $conn->prepare("DELETE FROM feedback_templates WHERE id = ? AND teacher_id = ?");
                $stmt_del->execute([$template_id, $teacher_id]);
                $_SESSION['flash_success'] = "Vorlage erfolgreich gelöscht.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Fehler beim Löschen der Vorlage.";
            }
        }
    }
    
    header("Location: /teacher_feedback.php");
    exit;
}
