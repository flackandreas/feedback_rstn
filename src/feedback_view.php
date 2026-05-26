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

// 2. Fetch questions
$stmt_q = $conn->prepare("SELECT * FROM feedback_questions WHERE session_id = ? ORDER BY sort_order ASC");
$stmt_q->execute([$session_id]);
$questions = $stmt_q->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch responses
$stmt_res = $conn->prepare("SELECT question_id, score FROM feedback_responses WHERE session_id = ?");
$stmt_res->execute([$session_id]);
$responses = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

// 4. Process data for charts and calculate averages
$data = [];
foreach ($questions as $q) {
    $data[$q['id']] = [
        'text' => $q['question_text'],
        'scores' => [1=>0, 2=>0, 3=>0, 4=>0, 5=>0],
        'total_score' => 0,
        'count' => 0
    ];
}

$total_score = 0;
$response_count = count($responses);

foreach ($responses as $r) {
    if (isset($data[$r['question_id']])) {
        $data[$r['question_id']]['scores'][$r['score']]++;
        $data[$r['question_id']]['total_score'] += $r['score'];
        $data[$r['question_id']]['count']++;
    }
    $total_score += $r['score'];
}

// Calculate individual question averages
foreach ($data as $q_id => &$q_data) {
    $q_data['avg'] = ($q_data['count'] > 0) ? ($q_data['total_score'] / $q_data['count']) : 0;
}
unset($q_data);

// Calculate overall session average
$session_average = ($response_count > 0) ? ($total_score / $response_count) : 0;

$num_questions = count($questions);
$total_votes = ($num_questions > 0) ? count($responses) / $num_questions : 0;

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('feedback_view.twig', [
    'session' => $session,
    'data' => $data,
    'total_votes' => (int)$total_votes,
    'session_average' => $session_average,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
