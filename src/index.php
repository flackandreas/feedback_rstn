<?php
/**
 * src/index.php
 * Main Dashboard
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

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

} catch (PDOException $e) {
    // If table doesn't exist yet, we catch the error gracefully
    $pending_extra = $pending_exemptions = $recent_sick = 0;
}

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('dashboard.twig', [
    'current_user_name' => $user_name,
    'current_date' => date('d.m.Y'),
    'pending_extra' => (int)$pending_extra,
    'pending_exemptions' => (int)$pending_exemptions,
    'recent_sick' => (int)$recent_sick,
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
