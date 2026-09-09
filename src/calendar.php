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
$user_id = get_current_user_id();

/**
 * Wer sieht wessen Eintraege?
 *
 * Eine Krankmeldung ist eine Gesundheitsangabe. Dass jede Lehrkraft im
 * Schulkalender lesen konnte, wer wann krank war, war weder noetig noch
 * zulaessig - der Kalender zeigt deshalb nur noch die eigenen Vorgaenge.
 *
 * Die Schulleitung behaelt den Gesamtblick: sie plant die Vertretung, und die
 * Angaben liegen ihr ohnehin vor. Sie sieht dieselben Daten schon unter
 * Krankmeldungen, Freistellungen und AUD-Terminen.
 *
 * Von der Einschraenkung ausgenommen sind die eingetragenen Kalender weiter
 * unten. Die kommen von aussen, gehoeren der ganzen Schule und werden jedem
 * angezeigt.
 */
$istVerwaltung = is_current_user_admin();
$nurEigene = $istVerwaltung ? '' : ' AND r.teacher_id = :teacher_id';
$werte = $istVerwaltung ? [] : [':teacher_id' => $user_id];

// 1. Krankmeldungen
$stmt_sick = $conn->prepare("
    SELECT r.id, r.date_from, r.date_to, r.notes, t.name as teacher_name
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE 1 = 1 {$nurEigene}
");
$stmt_sick->execute($werte);
$sick_leaves = $stmt_sick->fetchAll();

// 2. Genehmigte Freistellungen
$stmt_exempt = $conn->prepare("
    SELECT r.id, r.date_from, r.date_to, r.reason, r.reason_type, t.name as teacher_name
    FROM exemption_requests r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.status = 'approved' {$nurEigene}
");
$stmt_exempt->execute($werte);
$exemptions = $stmt_exempt->fetchAll();

// 3. Genehmigte ausserunterrichtliche Veranstaltungen
$stmt_extra = $conn->prepare("
    SELECT r.id, r.event_date, r.event_date_to, r.event_name, r.class_name, t.name as teacher_name, r.destination
    FROM extracurricular_requests r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.status = 'approved' {$nurEigene}
");
$stmt_extra->execute($werte);
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

// 4. Externe Kalender aus der Verwaltung einlesen
//
// Vorher stand hier genau eine Adresse fest im Quelltext. Gepflegt werden die
// Feeds jetzt unter Systemverwaltung; jeder hat seinen eigenen
// Zwischenspeicher, ein langsamer Feed haelt die uebrigen also nicht auf.
$stmt_feeds = $conn->query("SELECT id, name, url FROM calendar_feeds WHERE is_active = 1 ORDER BY id ASC");
$merken = $conn->prepare("
    UPDATE calendar_feeds
    SET last_fetch_at = NOW(), last_status = ?, last_event_count = ?
    WHERE id = ?
");

foreach ($stmt_feeds->fetchAll() as $feed) {
    $ergebnis = fetch_calendar_feed($feed['url']);
    $events = array_merge($events, $ergebnis['events']);

    // Nur bei einem echten Abruf vermerken - sonst ueberschriebe jeder
    // Seitenaufruf den Zeitstempel mit einem Treffer aus dem Zwischenspeicher.
    if (!$ergebnis['aus_cache']) {
        $merken->execute([
            mb_substr($ergebnis['status'], 0, 200),
            count($ergebnis['events']),
            (int)$feed['id'],
        ]);
    }
}
require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('calendar.twig', [
    'events_json' => json_encode($events),
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
