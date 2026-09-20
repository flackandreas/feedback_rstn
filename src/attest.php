<?php
/**
 * src/attest.php
 * Liefert das Attest einer Krankmeldung aus - mit Berechtigungspruefung.
 *
 * Ersetzt den direkten Zugriff auf public/uploads/. Vorher war die Datei
 * ueber ihren Pfad fuer jeden abrufbar, auch unangemeldet; geschuetzt war sie
 * allein durch einen Dateinamen aus uniqid(), also aus einem Zeitstempel.
 *
 * Sehen darf ein Attest:
 *   - die Lehrkraft, die es hochgeladen hat
 *   - die Schulleitung, die die Krankmeldung bearbeitet
 *
 * Mehr nicht. Es sind Gesundheitsdaten.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ablage.php';

require_login();

/**
 * Bricht ab, ohne zu verraten, ob es die Krankmeldung ueberhaupt gibt.
 *
 * Ein unterschiedlicher Fehler fuer "gibt es nicht" und "gehoert jemand
 * anderem" waere eine Auskunft darueber, wer wann krank war.
 */
function attest_verweigern() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nicht gefunden.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    attest_verweigern();
}

$conn = db_connect();

$stmt = $conn->prepare('SELECT teacher_id, attachment_path FROM sick_leave_reports WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$meldung = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meldung || empty($meldung['attachment_path'])) {
    attest_verweigern();
}

$eigene = (int)$meldung['teacher_id'] === (int)get_current_user_id();

if (!$eigene && !is_current_user_admin()) {
    attest_verweigern();
}

$pfad = ablage_aufloesen($meldung['attachment_path']);
if ($pfad === null) {
    attest_verweigern();
}

$mime = ablage_mimetyp($pfad);
$endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
$endung = preg_match('/^[a-z0-9]{1,5}$/', $endung) === 1 ? $endung : 'bin';

// Ein neutraler Name: der Originalname trug oft den Namen der Person, und
// er landet beim Herunterladen im Dateisystem und in jedem Verlauf.
$name = 'Attest_' . $id . '.' . $endung;

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($pfad));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
// Gesundheitsdaten gehoeren in keinen Zwischenspeicher, auch nicht in den
// des Browsers auf einem geteilten Rechner im Lehrerzimmer.
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

readfile($pfad);
