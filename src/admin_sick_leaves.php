<?php
/**
 * src/admin_sick_leaves.php
 * Sickness reports administration for Schulleitung.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/kind_krank.php';

require_admin();

$conn = db_connect();

// Kalenderjahr fuer die Uebersicht "Kind krank". Das Kontingent gilt je
// Kalenderjahr, nicht je Schuljahr.
$jahr = (int) ($_GET['jahr'] ?? $_POST['jahr'] ?? date('Y'));
if ($jahr < 2000 || $jahr > (int) date('Y') + 1) {
    $jahr = (int) date('Y');
}

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: CSRF Token ungültig.";
    } else {
        $action = $_POST['action'];
        if ($action === 'mark_seen') {
            $id = (int)($_POST['id'] ?? 0);
            // COALESCE: ein bereits vermerkter Zeitpunkt bleibt stehen. Sonst
            // wuerde ein zweiter Klick das erste Lesen ueberschreiben.
            //
            // Bei "Kind krank" heisst derselbe Vermerk "genehmigt": rechtlich
            // ist es Sonderurlaub, und den genehmigt die Schulleitung.
            $stmt = $conn->prepare("UPDATE sick_leave_reports SET is_seen = 1, seen_at = COALESCE(seen_at, NOW()) WHERE id = ?");
            $stmt->execute([$id]);
            $art = $conn->prepare("SELECT art FROM sick_leave_reports WHERE id = ?");
            $art->execute([$id]);
            $_SESSION['flash_success'] = $art->fetchColumn() === 'kind'
                ? "„Kind krank“ wurde genehmigt."
                : "Krankmeldung wurde als gelesen markiert.";
        } elseif ($action === 'mark_all_seen') {
            // Nur die eigenen Krankmeldungen: "Kind krank" ist eine Genehmigung
            // und wird einzeln erteilt, mit Blick auf die bisherigen Tage.
            $conn->query("UPDATE sick_leave_reports SET is_seen = 1, seen_at = COALESCE(seen_at, NOW()) WHERE is_seen = 0 AND art = 'eigene'");
            $_SESSION['flash_success'] = "Alle Krankmeldungen wurden als gelesen markiert.";
        } elseif ($action === 'arbeitstage_setzen') {
            // Das System zaehlt Montag bis Freitag ohne Feiertage. Wer in
            // Teilzeit nur an drei Tagen Dienst hat, bekommt hier die richtige
            // Zahl. Leer heisst: wieder automatisch zaehlen.
            $id = (int) ($_POST['id'] ?? 0);
            $eingabe = trim((string) ($_POST['arbeitstage'] ?? ''));
            if ($eingabe !== '' && (!ctype_digit($eingabe) || (int) $eingabe > 366)) {
                $_SESSION['flash_error'] = "Bitte eine Zahl von 0 bis 366 eintragen oder das Feld leeren.";
            } else {
                $stmt = $conn->prepare("UPDATE sick_leave_reports SET arbeitstage = ? WHERE id = ? AND art = 'kind'");
                $stmt->execute([$eingabe === '' ? null : (int) $eingabe, $id]);
                $_SESSION['flash_success'] = $eingabe === ''
                    ? "Die Arbeitstage werden wieder automatisch gezählt."
                    : "Die Arbeitstage sind auf $eingabe gesetzt.";
            }
        }
    }
    header("Location: /admin_sick_leaves.php?jahr=$jahr");
    exit;
}

$spalten = "r.id, r.teacher_id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, r.created_at, r.modified_at,
            r.seen_at, t.name as teacher_name, t.kuerzel, r.attachment_path, r.material_link, r.art, r.kind, r.arbeitstage";

// 1. Neue Krankmeldungen (is_seen = 0)
$stmt_new = $conn->query("
    SELECT {$spalten}
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.is_seen = 0
    ORDER BY r.created_at DESC
");
$sick_leaves_new = $stmt_new->fetchAll();

// 2. Laufende Krankmeldungen (is_seen = 1, date_to >= DATE_SUB(CURDATE(), INTERVAL 2 DAY))
$stmt_curr = $conn->query("
    SELECT {$spalten}
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.is_seen = 1 AND r.date_to >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from ASC
");
$sick_leaves_current = $stmt_curr->fetchAll();

// "Kind krank" in den beiden Listen oben: wie viele Arbeitstage die Meldung
// kostet und wo das Kind im Jahr damit steht - die Schulleitung genehmigt mit
// Blick darauf.
$konten = [];
foreach ([&$sick_leaves_new, &$sick_leaves_current] as &$liste) {
    foreach ($liste as &$r) {
        if ($r['art'] !== 'kind') {
            continue;
        }
        $r['date_from'] = $r['date_main'];
        $r['tage'] = kind_krank_tage_gesamt($r);
        $r_jahr = (int) substr((string) $r['date_main'], 0, 4);
        $konten[$r_jahr] ??= array_column(kind_krank_uebersicht($conn, $r_jahr), null, 'id');
        $konto = $konten[$r_jahr][(int) $r['teacher_id']] ?? null;
        $r['konto'] = null;
        if ($konto !== null) {
            foreach ($konto['kinder'] as $k) {
                if (kind_schluessel($k['name']) === kind_schluessel((string) $r['kind'])) {
                    $r['konto'] = $k + ['jahr' => $r_jahr, 'je_kind' => $konto['grenzen']['je_kind']];
                }
            }
        }
    }
    unset($r);
}
unset($liste);

// 3. Vergangene Krankmeldungen (is_seen = 1, date_to < DATE_SUB(CURDATE(), INTERVAL 2 DAY))
//
// Nur die eigenen: "Kind krank" sind keine Krankheitstage der Lehrkraft. Die
// Summe hier ist der Blick auf das Betriebliche Eingliederungsmanagement
// (sechs Wochen im Jahr), und dort haben die Tage des Kindes nichts verloren.
$stmt_hist = $conn->query("
    SELECT {$spalten}
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.is_seen = 1 AND r.art = 'eigene' AND r.date_to < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY r.date_from DESC
");
$sick_leaves_old_raw = $stmt_hist->fetchAll();

$sick_history_by_teacher = [];
foreach ($sick_leaves_old_raw as $r) {
    $tid = $r['teacher_id'];
    if (!isset($sick_history_by_teacher[$tid])) {
        $sick_history_by_teacher[$tid] = [
            'id' => $tid,
            'name' => $r['teacher_name'],
            'kuerzel' => $r['kuerzel'],
            'total_days' => 0,
            'records' => []
        ];
    }

    $d1 = new DateTime($r['date_main']);
    $d2 = new DateTime($r['date_to']);
    $days = $d2->diff($d1)->days + 1;

    $sick_history_by_teacher[$tid]['total_days'] += $days;
    $sick_history_by_teacher[$tid]['records'][] = $r;
}
usort($sick_history_by_teacher, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// 4. "Kind krank" im Kalenderjahr, je Lehrkraft und Kind
$kind_uebersicht = kind_krank_uebersicht($conn, $jahr);
$erstes_jahr = (int) ($conn->query("SELECT MIN(YEAR(date_from)) FROM sick_leave_reports WHERE art = 'kind'")->fetchColumn() ?: date('Y'));
$kind_jahre = range(max((int) date('Y'), $jahr), min($erstes_jahr, (int) date('Y')));

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/twig_setup.php';

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_sick_leaves.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'sick_leaves_new' => $sick_leaves_new,
    'sick_leaves_current' => $sick_leaves_current,
    'sick_history_by_teacher' => $sick_history_by_teacher,
    'jahr' => $jahr,
    'kind_jahre' => $kind_jahre,
    'kind_uebersicht' => $kind_uebersicht,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
