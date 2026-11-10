<?php
/**
 * src/meine_antraege.php
 * Uebersicht der eigenen Antraege und Meldungen.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/includes/entscheidungen.php';
require_once __DIR__ . '/includes/lbv1211.php';
run_all_migrations();

require_login();

$user_id = get_current_user_id();
$conn = db_connect();

/**
 * Zwei Arten von Vorgang, zwei Arten von Zustand.
 *
 * Ueber einen Ausflug und eine Freistellung wird entschieden: ausstehend,
 * genehmigt, abgelehnt. Eine Krankmeldung ist dagegen eine Mitteilung -
 * niemand genehmigt sie. Bisher stand hier fest 'approved', mit dem Kommentar,
 * das sei "fuer Konsistenz" so. Auf dem Bildschirm der Lehrkraft hiess das:
 * jede Krankmeldung war "GENEHMIGT", auch die, die noch niemand gesehen hatte.
 *
 * Deshalb haben Krankmeldungen jetzt ihre eigenen Zustaende: eingegangen und
 * gelesen. Der Filter fasst beide Welten unter offen und erledigt zusammen.
 */
$filter = (string) ($_GET['status'] ?? 'all');

// Aeltere Lesezeichen und Links weiter bedienen.
$filter = [
    'pending' => 'offen',
    'approved' => 'erledigt',
    'rejected' => 'abgelehnt',
][$filter] ?? $filter;

if (!in_array($filter, ['all', 'offen', 'erledigt', 'abgelehnt'], true)) {
    $filter = 'all';
}

// Bedingung je Welt. Krankmeldungen werden nie abgelehnt, deshalb liefert
// dieser Filter dort bewusst nichts.
//
// Eine Rueckfrage der Schulleitung gehoert unter "offen": entschieden ist
// nichts, und es liegt jetzt an der Lehrkraft. Waere sie es nicht, fiele sie
// aus jedem Filter heraus und stuende nur noch unter "Alle" - ausgerechnet
// der Vorgang, der eine Reaktion verlangt.
$beiEntscheidung = [
    'offen' => "AND status IN ('pending', 'query')",
    'erledigt' => "AND status = 'approved'",
    'abgelehnt' => "AND status = 'rejected'",
][$filter] ?? '';

$beiMeldung = [
    'offen' => 'AND is_seen = 0',
    'erledigt' => 'AND is_seen = 1',
    'abgelehnt' => 'AND 1 = 0',
][$filter] ?? '';

$sql_extra = "SELECT id, 'Ausflug' AS type, class_name AS details, event_date AS date_main,
                     event_date_to AS date_end, status, NULL AS seen_at, created_at, modified_at,
                     modified_after_approval
                FROM extracurricular_requests
               WHERE teacher_id = ? {$beiEntscheidung}";

$sql_exempt = "SELECT id, 'Freistellung' AS type, reason AS details, date_from AS date_main,
                      date_to AS date_end, status, NULL AS seen_at, created_at, NULL AS modified_at,
                      0 AS modified_after_approval
                 FROM exemption_requests
                WHERE teacher_id = ? {$beiEntscheidung}";

// "Kind krank" ist dagegen Sonderurlaub - den genehmigt die Schulleitung.
// Derselbe Vermerk heisst dort deshalb "genehmigt".
$sql_sick = "SELECT id, IF(art = 'kind', 'Kind krank', 'Krankmeldung') AS type,
                    IF(art = 'kind', CONCAT('Kind: ', COALESCE(kind, ''), IF(COALESCE(notes, '') <> '', CONCAT(' – ', notes), '')), notes) AS details,
                    date_from AS date_main, date_to AS date_end,
                    IF(is_seen = 1, IF(art = 'kind', 'genehmigt', 'gelesen'), 'eingegangen') AS status,
                    seen_at, created_at, modified_at, 0 AS modified_after_approval
               FROM sick_leave_reports
              WHERE teacher_id = ? {$beiMeldung}";

$stmt = $conn->prepare("{$sql_extra} UNION ALL {$sql_exempt} UNION ALL {$sql_sick} ORDER BY created_at DESC");
$stmt->execute([$user_id, $user_id, $user_id]);
$requests = $stmt->fetchAll();

// Rueckfragen der Schulleitung (Audit W2) und die Antworten darauf. Der
// Wortwechsel bleibt stehen, auch wenn der Antrag wieder offen oder
// entschieden ist: wer geantwortet hat, soll nachlesen koennen, was. Die IDs
// sind nur je Art eindeutig.
$tabellen = ['Ausflug' => 'extracurricular_requests', 'Freistellung' => 'exemption_requests'];
$gespraeche = [];
foreach ($tabellen as $art => $tabelle) {
    $ids = [];
    foreach ($requests as $r) {
        if ($r['type'] === $art) {
            $ids[] = $r['id'];
        }
    }
    $gespraeche[$art] = rueckfrage_gespraeche($conn, $tabelle, $ids);
}
foreach ($requests as &$r) {
    $r['gespraech'] = $gespraeche[$r['type']][(int) $r['id']] ?? [];
    $r['beantwortet'] = rueckfrage_beantwortet((string) $r['status'], $r['gespraech']);
}
unset($r);

// Ausfluege anderer, bei denen die Lehrkraft als Begleitperson eingetragen
// ist. Bisher sah sie die nirgends, auch nicht im Kalender. Bearbeiten kann
// sie nur die Leitung; hier steht der Ausflug nur zum Nachsehen.
$stmt = $conn->prepare("SELECT r.id, r.event_name, r.class_name, r.destination, r.event_date, r.event_date_to,
                               r.status, t.name AS leitung, t.kuerzel AS leitung_kuerzel
                          FROM extracurricular_requests r
                          JOIN teachers t ON t.id = r.teacher_id
                         WHERE r.teacher_id <> ? AND " . lbv_begleitet_sql() . " {$beiEntscheidung}
                         ORDER BY r.event_date DESC");
$stmt->execute([$user_id, $user_id, $user_id]);
$begleitungen = $stmt->fetchAll();

require_once __DIR__ . '/includes/twig_setup.php';

// Meldungen der Seiten, die hierher zurueckfuehren: die Antwort auf eine
// Rueckfrage und die Aenderung eines Ausflugs. Bisher las diese Seite sie
// nicht aus - die Bestaetigung fehlte, und die Meldung erschien erst spaeter
// auf irgendeiner anderen Seite.
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('meine_antraege.twig', [
    'requests' => $requests,
    'begleitungen' => $begleitungen,
    // Fuer das Antwortformular unter einer Rueckfrage.
    'csrf_token' => get_csrf_token(),
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'filter_status' => $filter,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true,
]);
