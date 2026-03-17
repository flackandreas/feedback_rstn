<?php
/**
 * src/feedback_view.php
 * Evaluation view for a specific feedback session.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$session_id = (int)($_GET['id'] ?? 0);
$conn = db_connect();

// 1. Fetch session details
$stmt = $conn->prepare("SELECT * FROM feedback_sessions WHERE id = ? AND teacher_id = ?");
$stmt->execute([$session_id, get_current_user_id()]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Sitzung nicht gefunden oder keine Berechtigung.");
}

// 2. Fetch responses
$stmt_res = $conn->prepare("SELECT category, score FROM feedback_responses WHERE session_id = ?");
$stmt_res->execute([$session_id]);
$responses = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

// 3. Process data for charts
$data = [
    'lesson' => [1=>0, 2=>0, 3=>0, 4=>0, 5=>0],
    'climate' => [1=>0, 2=>0, 3=>0, 4=>0, 5=>0]
];

foreach ($responses as $r) {
    $data[$r['category']][$r['score']]++;
}

$total_votes = count($responses) / 2; // Assuming 2 questions per student

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('feedback_view.twig', [
    'session' => $session,
    'data' => $data,
    'total_votes' => (int)$total_votes,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
