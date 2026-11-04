<?php
/**
 * src/admin_dashboard.php
 * Dashboard for Schulleitung to manage incoming requests separately.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entscheidungen.php';

require_admin();

$conn = db_connect();

// (CSV Import moved to admin_system.php)
// (Mail-Adresse der Schulleitung ebenfalls: admin_system.php. Das Formular
// dafuer war hier schon seit April 2026 verschwunden.)

// 2. Exemption requests (Freistellungen)
$stmt_exempt = $conn->query("
    SELECT r.id, r.reason_type, r.days_of_week, r.classes, r.hourly_exemption, r.hour_from, r.hour_to, r.reason,
           r.date_from, r.date_to, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$exempt_requests = $stmt_exempt->fetchAll(PDO::FETCH_ASSOC);

// Wer hat wann entschieden (Audit M3), was wurde gefragt (Audit W2) und was
// hat die Lehrkraft geantwortet - fuer die Detailansicht. "beantwortet"
// markiert Antraege, die nach einer Antwort wieder bei der Schulleitung liegen.
$ids = array_column($exempt_requests, 'id');
$verlauf = entscheidungen_verlauf($conn, 'exemption_requests', $ids);
$gespraeche = rueckfrage_gespraeche($conn, 'exemption_requests', $ids);
foreach ($exempt_requests as &$r) {
    $r['verlauf'] = $verlauf[(int) $r['id']] ?? [];
    $r['gespraech'] = $gespraeche[(int) $r['id']] ?? [];
    $r['beantwortet'] = rueckfrage_beantwortet((string) $r['status'], $r['gespraech']);
}
unset($r);

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/twig_setup.php';

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (isset($_GET['error']) && $_GET['error'] === 'csrf') {
    $flash_error = "Sicherheitsfehler: Bitte Aktion erneut ausführen.";
}

echo $twig->render('admin_dashboard.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'exempt_requests' => $exempt_requests,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
