<?php
/**
 * src/calendar.php
 * Controller for the Schulkalender view, including database and IServ external feeds.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/calendar_helper.php';

require_login();

$conn = db_connect();

// Fetch local events:
// 1. Sick leaves (all)
$stmt_sick = $conn->query("
    SELECT r.id, r.date_from, r.date_to, r.notes, t.name as teacher_name 
    FROM sick_leave_reports r 
    JOIN teachers t ON r.teacher_id = t.id
");
$sick_leaves = $stmt_sick->fetchAll();

// 2. Approved exemptions
$stmt_exempt = $conn->query("
    SELECT r.id, r.date_from, r.date_to, r.reason, r.reason_type, t.name as teacher_name 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'approved'
");
$exemptions = $stmt_exempt->fetchAll();

// 3. Approved extracurricular events
$stmt_extra = $conn->query("
    SELECT r.id, r.event_date, r.event_date_to, r.event_name, r.class_name, t.name as teacher_name, r.destination
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'approved'
");
$extracurriculars = $stmt_extra->fetchAll();

$events = [];

// Map Sick Leaves
foreach ($sick_leaves as $s) {
    $events[] = [
        'id' => 'sick_' . $s['id'],
        'type' => 'sick',
        'start' => $s['date_from'],
        'end' => $s['date_to'],
        'title' => '🤒 Krankmeldung: ' . $s['teacher_name'],
        'details' => 'Krankmeldung für ' . $s['teacher_name'] . ($s['notes'] ? "\nNotizen: " . $s['notes'] : '')
    ];
}

// Map Exemptions
foreach ($exemptions as $e) {
    $events[] = [
        'id' => 'exempt_' . $e['id'],
        'type' => 'exempt',
        'start' => $e['date_from'],
        'end' => $e['date_to'],
        'title' => '🏖️ Freistellung: ' . $e['teacher_name'],
        'details' => 'Genehmigte Freistellung für ' . $e['teacher_name'] . "\nArt: " . $e['reason_type'] . "\nGrund: " . $e['reason']
    ];
}

// Map Extracurriculars
foreach ($extracurriculars as $ex) {
    $events[] = [
        'id' => 'extra_' . $ex['id'],
        'type' => 'extra',
        'start' => $ex['event_date'],
        'end' => $ex['event_date_to'] ?: $ex['event_date'],
        'title' => '🚌 Ausflug (' . $ex['class_name'] . '): ' . $ex['event_name'],
        'details' => 'Veranstaltung: ' . $ex['event_name'] . "\nKlasse(n): " . $ex['class_name'] . "\nZiel: " . $ex['destination'] . "\nLeitung: " . $ex['teacher_name']
    ];
}

// 4. Fetch and parse external IServ calendar events
$iserv_url = 'https://rstn.de/iserv/public/calendar?key=f5c7249d68e573f308af152f75f832e8';
$iserv_events = get_iserv_events($iserv_url);
$events = array_merge($events, $iserv_events);
require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('calendar.twig', [
    'events_json' => json_encode($events),
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
