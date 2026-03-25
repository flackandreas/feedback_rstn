<?php
/**
 * src/index.php
 * Main Dashboard
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

$user_id = get_current_user_id();
$user_name = get_current_user_name();

// Fetch summary of current requests
try {
    $conn = db_connect();
    
    // Extracurricular
    $stmt1 = $conn->prepare("SELECT COUNT(*) FROM extracurricular_requests WHERE teacher_id = ? AND status = 'pending'");
    $stmt1->execute([$user_id]);
    $pending_extra = $stmt1->fetchColumn();

    // Exemptions
    $stmt2 = $conn->prepare("SELECT COUNT(*) FROM exemption_requests WHERE teacher_id = ? AND status = 'pending'");
    $stmt2->execute([$user_id]);
    $pending_exemptions = $stmt2->fetchColumn();

    // Sick leaves (Total recent)
    $stmt3 = $conn->prepare("SELECT COUNT(*) FROM sick_leave_reports WHERE teacher_id = ? AND date_from >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt3->execute([$user_id]);
    $recent_sick = $stmt3->fetchColumn();

    // Queries (Rückfragen)
    $stmt4 = $conn->prepare("SELECT COUNT(*) FROM extracurricular_requests WHERE teacher_id = ? AND status = 'query'");
    $stmt4->execute([$user_id]);
    $queries_extra = $stmt4->fetchColumn();

    $stmt5 = $conn->prepare("SELECT COUNT(*) FROM exemption_requests WHERE teacher_id = ? AND status = 'query'");
    $stmt5->execute([$user_id]);
    $queries_exempt = $stmt5->fetchColumn();
    
    $total_queries = (int)$queries_extra + (int)$queries_exempt;

    // Active Feedback Session
    $stmt_session = $conn->prepare("SELECT * FROM feedback_sessions WHERE teacher_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
    $stmt_session->execute([$user_id]);
    $active_session = $stmt_session->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // If table doesn't exist yet, we catch the error gracefully
    $pending_extra = $pending_exemptions = $recent_sick = 0;
    $active_session = null;
}

require_once __DIR__ . '/includes/twig_setup.php';

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('dashboard.twig', [
    'current_user_name' => $user_name,
    'current_date' => date('d.m.Y'),
    'pending_extra' => (int)$pending_extra,
    'pending_exemptions' => (int)$pending_exemptions,
    'recent_sick' => (int)$recent_sick,
    'total_queries' => $total_queries ?? 0,
    'active_session' => $active_session,
    'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]",
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true,
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error
]);
