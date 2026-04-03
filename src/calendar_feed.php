<?php
/**
 * src/calendar_feed.php
 * Liefert einen iCal (.ics) Feed mit allen relevanten Terminen
 * (Krankmeldungen, genehmigte Freistellungen, genehmigte Ausflüge).
 */

require_once __DIR__ . '/config/database.php';

// Einfacher Schutz: Ein statischer Token für die Schulleitung (Abonnement)
$secret_token = 'A1b2C3d4E5f6G7h8';

if (!isset($_GET['token']) || $_GET['token'] !== $secret_token) {
    http_response_code(403);
    die('Zugriff verweigert. Ungültiger Token.');
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="schul_termine.ics"');

$conn = db_connect();

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Schul-Verwaltung//DE\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:Schulabwesenheiten\r\n";
echo "X-WR-TIMEZONE:Europe/Berlin\r\n";

// Helper function
function create_ics_event($uid, $dtstart, $dtend, $summary, $description) {
    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}@schulapp.local\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($dtstart)) . "\r\n";
    // End date in ICS is exclusive, add 1 day for all-day events
    $end_date = date('Ymd', strtotime($dtend . ' +1 day'));
    echo "DTEND;VALUE=DATE:" . $end_date . "\r\n";
    echo "SUMMARY:" . str_replace(["\r\n", "\n", "\r"], "\\n", $summary) . "\r\n";
    echo "DESCRIPTION:" . str_replace(["\r\n", "\n", "\r"], "\\n", $description) . "\r\n";
    echo "END:VEVENT\r\n";
}

// 1. Krankmeldungen (Aktuell & Letzte 30 Tage)
$stmt_sick = $conn->query("
    SELECT r.id, r.date_from, r.date_to, t.name as teacher_name 
    FROM sick_leave_reports r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.date_from >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
while ($r = $stmt_sick->fetch()) {
    create_ics_event(
        'sick_'.$r['id'],
        $r['date_from'],
        $r['date_to'],
        'Krankmeldung: ' . $r['teacher_name'],
        'Krankmeldung für ' . $r['teacher_name']
    );
}

// 2. Freistellungen (Gelöst/Approved)
$stmt_exempt = $conn->query("
    SELECT r.id, r.date_from, r.date_to, r.reason, t.name as teacher_name 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'approved' AND r.date_from >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
while ($r = $stmt_exempt->fetch()) {
    create_ics_event(
        'exempt_'.$r['id'],
        $r['date_from'],
        $r['date_to'],
        'Freistellung: ' . $r['teacher_name'],
        'Genehmigte Freistellung. Grund: ' . $r['reason']
    );
}

// 3. Ausflüge (Gelöst/Approved)
$stmt_extra = $conn->query("
    SELECT r.id, r.event_date, r.event_name, r.class_name, t.name as teacher_name 
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'approved' AND r.event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
while ($r = $stmt_extra->fetch()) {
    create_ics_event(
        'extra_'.$r['id'],
        $r['event_date'],
        $r['event_date'],
        'Ausflug (' . $r['class_name'] . '): ' . $r['event_name'],
        'Leitung: ' . $r['teacher_name']
    );
}

echo "END:VCALENDAR\r\n";
?>
