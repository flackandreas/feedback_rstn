<?php
/**
 * src/teacher_feedback.php
 * Management of live student feedback sessions.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_login();

$user_id = get_current_user_id();
$conn = db_connect();

// Active Feedback Session
$stmt_session = $conn->prepare("SELECT * FROM feedback_sessions WHERE teacher_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
$stmt_session->execute([$user_id]);
$active_session = $stmt_session->fetch(PDO::FETCH_ASSOC);

// Classes for the form
try {
    $stmt_classes = $conn->prepare("SELECT id, name FROM classes ORDER BY name ASC");
    $stmt_classes->execute();
    $all_classes = $stmt_classes->fetchAll();

    // Fetch selected classes for this teacher
    $stmt_selected = $conn->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
    $stmt_selected->execute([$user_id]);
    $selected_class_ids = $stmt_selected->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $all_classes = [];
    $selected_class_ids = [];
}

// Fetch saved templates
$templates = [];
try {
    $stmt_templates = $conn->prepare("
        SELECT t.id, t.title, t.klasse, t.fach, COUNT(q.id) as question_count 
        FROM feedback_templates t
        LEFT JOIN feedback_template_questions q ON t.id = q.template_id
        WHERE t.teacher_id = ?
        GROUP BY t.id, t.klasse, t.fach
        ORDER BY t.title ASC
    ");
    $stmt_templates->execute([$user_id]);
    $templates = $stmt_templates->fetchAll(PDO::FETCH_ASSOC);

    // Fetch actual questions for templates
    $stmt_questions = $conn->prepare("
        SELECT template_id, question_text 
        FROM feedback_template_questions 
        ORDER BY template_id, sort_order ASC
    ");
    $stmt_questions->execute();
    $template_questions_raw = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    
    // Group questions by template ID
    $template_questions = [];
    foreach ($template_questions_raw as $q) {
        $template_questions[$q['template_id']][] = $q['question_text'];
    }
    
    // Add questions list directly into the templates array
    foreach ($templates as &$t) {
        $t['questions'] = $template_questions[$t['id']] ?? [];
    }
    unset($t);
} catch (PDOException $e) {
    $templates = [];
}

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('teacher_feedback.twig', [
    'active_session' => $active_session,
    'all_classes' => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'templates' => $templates,
    'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]",
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true,
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
