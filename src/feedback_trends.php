<?php
/**
 * src/feedback_trends.php
 * Longitudinal analysis of feedback scores.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$teacher_id = get_current_user_id();
$conn = db_connect();

// Get filter parameters
$klasse = $_GET['klasse'] ?? '';
$fach = $_GET['fach'] ?? '';

// Fetch available classes and subjects for filtering
$stmt_filters = $conn->prepare("SELECT DISTINCT klasse, fach FROM feedback_sessions WHERE teacher_id = ?");
$stmt_filters->execute([$teacher_id]);
$filters = $stmt_filters->fetchAll(PDO::FETCH_ASSOC);

// Fetch average scores per session
$query = "
    SELECT 
        s.id, s.klasse, s.fach, s.created_at,
        AVG(CASE WHEN r.category = 'lesson' THEN r.score END) as avg_lesson,
        AVG(CASE WHEN r.category = 'climate' THEN r.score END) as avg_climate
    FROM feedback_sessions s
    LEFT JOIN feedback_responses r ON s.id = r.session_id
    WHERE s.teacher_id = ?
";

$params = [$teacher_id];
if ($klasse) { $query .= " AND s.klasse = ?"; $params[] = $klasse; }
if ($fach) { $query .= " AND s.fach = ?"; $params[] = $fach; }

$query .= " GROUP BY s.id ORDER BY s.created_at ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('feedback_trends.twig', [
    'history' => $history,
    'filters' => $filters,
    'current_klasse' => $klasse,
    'current_fach' => $fach,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
