<?php
/**
 * src/feedback_action.php
 * Handles starting and stopping feedback sessions
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $conn = db_connect();
    $teacher_id = get_current_user_id();
    
    if ($_POST['action'] === 'start') {
        $klasse = trim($_POST['klasse'] ?? '');
        $fach = trim($_POST['fach'] ?? '');
        
        if (empty($klasse) || empty($fach)) {
            $_SESSION['flash_error'] = "Bitte Klasse und Fach angeben.";
            header("Location: /index.php");
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
        
        $_SESSION['flash_success'] = "Feedback-Sitzung für $klasse ($fach) gestartet.";
        $_SESSION['active_session_token'] = $token;
        
    } elseif ($_POST['action'] === 'stop') {
        $stmt = $conn->prepare("UPDATE feedback_sessions SET is_active = 0 WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);
        unset($_SESSION['active_session_token']);
        $_SESSION['flash_success'] = "Feedback-Sitzung beendet.";
    }
    
    header("Location: /index.php");
    exit;
}
