<?php
/**
 * src/meine_antraege.php
 * Uebersicht der eigenen Antraege und Meldungen.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
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
$beiEntscheidung = [
    'offen' => "AND status = 'pending'",
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

$sql_sick = "SELECT id, 'Krankmeldung' AS type, notes AS details, date_from AS date_main,
                    date_to AS date_end, IF(is_seen = 1, 'gelesen', 'eingegangen') AS status,
                    seen_at, created_at, modified_at, 0 AS modified_after_approval
               FROM sick_leave_reports
              WHERE teacher_id = ? {$beiMeldung}";

$stmt = $conn->prepare("{$sql_extra} UNION ALL {$sql_exempt} UNION ALL {$sql_sick} ORDER BY created_at DESC");
$stmt->execute([$user_id, $user_id, $user_id]);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('meine_antraege.twig', [
    'requests' => $requests,
    'filter_status' => $filter,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true,
]);
