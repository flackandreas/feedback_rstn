<?php
/**
 * src/rueckfrage_antwort.php
 * Die Lehrkraft beantwortet eine Rueckfrage der Schulleitung.
 *
 * Danach steht der Antrag wieder auf "ausstehend": entschieden ist noch
 * nichts, und es liegt wieder bei der Schulleitung. Sie genehmigt, lehnt ab
 * oder fragt erneut nach - mit den Knoepfen, die ein offener Antrag ohnehin
 * hat. Eine zweite Rueckfrage ging vorher nicht: der Knopf fehlt, solange ein
 * Antrag auf Rueckfrage steht.
 *
 * Eine Antwort ist freiwillig. Wer die Frage lieber im Gespraech klaert,
 * schreibt nichts, und die Schulleitung entscheidet direkt aus der Rueckfrage
 * heraus.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entscheidungen.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/mail_texte.php';
require_once __DIR__ . '/includes/rate_limit.php';

require_login();

/** Zurueck zur Liste, mit einer Meldung. */
function antwort_zurueck(bool $ok, string $meldung): void
{
    $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $meldung;
    header('Location: /meine_antraege.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /meine_antraege.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    antwort_zurueck(false, 'Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.');
}

// is_string(), weil ein Feld auch als Array ankommen kann (antwort[]=x) - der
// (string)-Cast machte daraus "Array" samt Warnung.
$tabellen = ['ausflug' => 'extracurricular_requests', 'freistellung' => 'exemption_requests'];
$typ = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
$antrag_id = is_string($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;
$roh = is_string($_POST['antwort'] ?? null) ? $_POST['antwort'] : '';
$antwort = trim(str_replace(["\r\n", "\r"], "\n", $roh));

if (!isset($tabellen[$typ]) || $antrag_id <= 0) {
    antwort_zurueck(false, 'Ungültige Anfrage.');
}

if ($antwort === '') {
    antwort_zurueck(false, 'Bitte schreiben Sie eine Antwort. Der Antrag ist unverändert.');
}

// Dieselbe Grenze wie fuer die Rueckfrage selbst (admin_action.php).
if (mb_strlen($antwort) > 2000) {
    antwort_zurueck(false, 'Die Antwort ist zu lang (höchstens 2000 Zeichen). Der Antrag ist unverändert.');
}

$conn = db_connect();
$user_id = (int) get_current_user_id();

// Jede Antwort verschickt eine Mail an die Schulleitung.
if (!rate_limit_allow($conn, 'rueckfrage_antwort', (string) $user_id, 20, 3600)) {
    antwort_zurueck(false, 'Zu viele Antworten in kurzer Zeit. Bitte versuchen Sie es später erneut.');
}

$tabelle = $tabellen[$typ];

// Nur der eigene Antrag. Ein fremder und ein nicht vorhandener bekommen
// dieselbe Meldung - sonst liesse sich ausprobieren, welche Antraege es gibt.
$sql = $tabelle === 'extracurricular_requests'
    ? "SELECT r.status, r.class_name, r.event_date, r.event_date_to, r.destination
         FROM extracurricular_requests r
        WHERE r.id = ? AND r.teacher_id = ?"
    : "SELECT r.status, r.date_from, r.date_to
         FROM exemption_requests r
        WHERE r.id = ? AND r.teacher_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$antrag_id, $user_id]);
$antrag = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$antrag) {
    antwort_zurueck(false, 'Der Antrag wurde nicht gefunden.');
}

if ($antrag['status'] !== 'query') {
    antwort_zurueck(false, $antrag['status'] === 'pending'
        ? 'Ihre Antwort liegt der Schulleitung bereits vor.'
        : 'Die Schulleitung hat über diesen Antrag bereits entschieden.');
}

try {
    $conn->beginTransaction();

    // Der Status wechselt nur, solange er noch 'query' ist. Zwischen dem Laden
    // oben und hier kann die Schulleitung entschieden haben, oder das Formular
    // wurde doppelt abgeschickt - dann trifft diese Zeile nichts, und es
    // entsteht auch kein zweiter Eintrag.
    $wechsel = $conn->prepare("UPDATE {$tabelle} SET status = 'pending' WHERE id = ? AND teacher_id = ? AND status = 'query'");
    $wechsel->execute([$antrag_id, $user_id]);

    if ($wechsel->rowCount() !== 1) {
        $conn->rollBack();
        antwort_zurueck(false, 'Zu diesem Antrag ist keine Rückfrage mehr offen.');
    }

    antwort_protokollieren($conn, $tabelle, $antrag_id, $antwort);
    $conn->commit();
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('rueckfrage_antwort: Speichern fehlgeschlagen: ' . $e->getMessage());
    antwort_zurueck(false, 'Die Antwort konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.');
}

$meldung = 'Ihre Antwort ist bei der Schulleitung. Der Antrag steht wieder auf „Ausstehend“.';

// Die Mail geht an die Person, die gefragt hat - sie wartet auf die Antwort.
// Hat ihr Konto keine Adresse oder gibt es das Konto nicht mehr, an die
// Adresse fuer Berichte aus der Systemverwaltung. Die Antwort ist an dieser
// Stelle schon gespeichert; scheitert die Mail, wird das nur gemeldet.
try {
    $frage = rueckfrage_letzte($conn, $tabelle, $antrag_id);

    $empfaenger = '';
    if ($frage !== null && $frage['von_id'] !== null) {
        $stmt = $conn->prepare('SELECT email FROM teachers WHERE id = ?');
        $stmt->execute([$frage['von_id']]);
        $empfaenger = trim((string) $stmt->fetchColumn());
    }
    if ($empfaenger === '') {
        $empfaenger = trim((string) $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'report_email'")->fetchColumn());
    }

    if ($empfaenger !== '') {
        $lehrkraft = entscheidung_bearbeiter();
        $art = $tabelle === 'extracurricular_requests' ? 'außerunterrichtliche Veranstaltung' : 'Freistellung';
        $gesendet = send_notification_email(
            $empfaenger,
            "Antwort auf Rückfrage ($art): $lehrkraft",
            mail_text_rueckfrage_antwort($tabelle, $antrag, $lehrkraft, $frage, $antwort)
        );
        if (!$gesendet) {
            $meldung .= ' Die Benachrichtigung per Mail konnte allerdings nicht versendet werden.';
        }
    }
} catch (Throwable $e) {
    error_log('rueckfrage_antwort: Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
    $meldung .= ' Die Benachrichtigung per Mail konnte allerdings nicht versendet werden.';
}

antwort_zurueck(true, $meldung);
