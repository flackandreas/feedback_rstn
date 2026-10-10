<?php
/**
 * src/admin_aud.php
 * Management of extracurricular activities (AUD).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';
require_once __DIR__ . '/includes/admin_helpers.php';
require_once __DIR__ . '/includes/entscheidungen.php';
require_once __DIR__ . '/includes/lbv1211.php';

require_admin();

$conn = db_connect();

/**
 * Was das Detailfenster zu einem Antrag zeigt.
 *
 * Die Liste stand frueher zweimal in der Vorlage - bei den AUD-Tagen und in
 * der Tabelle - und haette mit den Feldern des Vordrucks LBV 1211 beide
 * Male wachsen muessen.
 */
function veranstaltung_info(array $r, array $begleitpersonen): array
{
    $datum = static fn (?string $d): string => $d ? date('d.m.Y', strtotime($d)) : '';
    $zeitpunkt = static fn (?string $d, ?string $z): string =>
        $datum($d) . ($d && lbv_zeit($z) !== '' ? ', ' . lbv_zeit($z) . ' Uhr' : '');
    $person = static fn (string $vorname, string $nachname, $in_ausbildung): string =>
        trim($vorname . ' ' . $nachname) . ($in_ausbildung ? ' (in Ausbildung)' : '');

    $befoerderung = array_filter([
        $r['bef_oepnv'] ? 'regelmäßig verkehrendes Beförderungsmittel' : null,
        $r['bef_reisebus'] ? 'Reisebus' : null,
        $r['bef_sonstiges'] ? 'sonstiges Verkehrsmittel: ' . $r['bef_sonstiges_text'] : null,
    ]);

    $kosten = '';
    if ($r['lbv_kosten_eur'] !== null) {
        $kosten = number_format((float) $r['lbv_kosten_eur'], 2, ',', '.') . ' €'
            . ($r['lbv_kosten_erlaeuterung'] ? ' (' . $r['lbv_kosten_erlaeuterung'] . ')' : '');
    }

    return [
        'type' => 'ausflug',
        'id' => (int) $r['id'],
        'teacher' => $r['teacher_name'] . ' (' . $r['kuerzel'] . ')',
        'event_date' => $datum($r['event_date']),
        'event_date_to' => $datum($r['event_date_to']),
        'class_name' => (string) $r['class_name'],
        'destination' => (string) $r['destination'],
        'role' => (string) $r['role'],
        'companion' => (string) $r['companion'],
        'event_name' => (string) $r['event_name'],
        // Vordruck LBV 1211
        'lbv_personalnummer' => (string) $r['lbv_personalnummer'],
        'lehrkraft' => $r['lk_nachname'] ? $person((string) $r['lk_vorname'], (string) $r['lk_nachname'], $r['lk_in_ausbildung']) : '',
        'begleitpersonen' => array_map(static fn (array $p): string => $person($p['vorname'], $p['nachname'], $p['in_ausbildung']), $begleitpersonen),
        'schueler_anzahl' => (string) $r['schueler_anzahl'],
        'beginn' => $zeitpunkt($r['event_date'], $r['start_time']),
        'ankunft' => $zeitpunkt($r['ankunft_datum'], $r['ankunft_zeit']),
        'abfahrt' => $zeitpunkt($r['abfahrt_datum'], $r['abfahrt_zeit']),
        'ende' => $zeitpunkt($r['event_date_to'], $r['return_time']),
        'aufenthaltstage' => (string) $r['aufenthaltstage'],
        'befoerderung' => implode('; ', $befoerderung),
        'lbv_kosten' => $kosten,
        // Altbestand: das fruehere Freitextfeld fuer die Verkehrsmittel
        'transport' => (string) $r['transport'],
        // Schulintern
        'costs' => (string) $r['costs'],
        'start_location' => (string) $r['start_location'],
        'return_location' => (string) $r['return_location'],
        'return_trip_arranged' => (int) $r['return_trip_arranged'],
        'supervisors' => (string) $r['supervisors'],
        'consent_form' => (string) $r['consent_form'],
        'schedule_notified' => (int) $r['schedule_notified'],
        'status' => $r['status'],
        'verlauf' => $r['verlauf'],
        'gespraech' => $r['gespraech'],
        // Ohne die Angaben des Vordrucks gibt es keinen Ausdruck (antrag_pdf.php).
        'lbv_fehlt' => lbv_fehlende_angaben($r),
    ];
}

// 1. Extracurricular requests (Ausflüge) grouped and augmented
//
// Offene Antraege zuerst, die naechste Veranstaltung oben, darunter die
// entschiedenen, die juengste zuerst. Frueher sortierte die Liste zuerst nach
// dem AUD-Tag - das Formular fragt ihn nicht mehr ab.
$stmt_extra = $conn->query("
    SELECT r.id, 'Ausflug' as type, r.class_name as class, r.class_name, r.event_date, r.event_date_to, r.destination,
           r.aud_type, r.event_date as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel,
           r.role, r.companion, r.event_name, r.costs, r.transport,
           r.start_time, r.start_location, r.return_time, r.return_location,
           r.return_trip_arranged, r.supervisors, r.consent_form, r.schedule_notified,
           r.modified_after_approval, r.modified_at,
           r.lbv_personalnummer, r.lk_nachname, r.lk_vorname, r.lk_in_ausbildung,
           r.ankunft_datum, r.ankunft_zeit, r.abfahrt_datum, r.abfahrt_zeit, r.aufenthaltstage, r.schueler_anzahl,
           r.bef_oepnv, r.bef_reisebus, r.bef_sonstiges, r.bef_sonstiges_text,
           r.lbv_kosten_eur, r.lbv_kosten_erlaeuterung
    FROM extracurricular_requests r
    JOIN teachers t ON r.teacher_id = t.id
    ORDER BY r.status IN ('pending', 'query') DESC,
             CASE WHEN r.status IN ('pending', 'query') THEN r.event_date END ASC,
             r.event_date DESC, r.created_at DESC
");
$extra_requests = $stmt_extra->fetchAll(PDO::FETCH_ASSOC);

// Wer hat wann entschieden (Audit M3), was wurde gefragt (Audit W2) und was
// hat die Lehrkraft geantwortet - fuer die Detailansicht. "beantwortet"
// markiert Antraege, die nach einer Antwort wieder bei der Schulleitung liegen.
$ids = array_column($extra_requests, 'id');
$verlauf = entscheidungen_verlauf($conn, 'extracurricular_requests', $ids);
$gespraeche = rueckfrage_gespraeche($conn, 'extracurricular_requests', $ids);
$begleitung = lbv_begleitpersonen_laden($conn, $ids);
foreach ($extra_requests as &$r) {
    $r['verlauf'] = $verlauf[(int) $r['id']] ?? [];
    $r['gespraech'] = $gespraeche[(int) $r['id']] ?? [];
    $r['beantwortet'] = rueckfrage_beantwortet((string) $r['status'], $r['gespraech']);
    $r['info'] = veranstaltung_info($r, $begleitung[(int) $r['id']] ?? []);
}
unset($r);

// Uebersicht der AUD-Tage. Abschaltbar, weil AUD 1 bis AUD 7 eine
// Besonderheit der Realschule Titisee-Neustadt sind - anderswo stuenden dort
// acht leere Kaesten. Ist sie aus, werden die neun Abfragen darunter gar
// nicht erst gestellt.
$aud_tage_sichtbar = app_schalter($conn, 'aud_tage_uebersicht', false);

$aud_list_for_ui = ['AUD 1', 'AUD 2', 'AUD 3', 'AUD 4', 'AUD 5', 'AUD 6', 'AUD 7', 'Sonstige'];
$aud_free = [];

$all_teachers = [];
if ($aud_tage_sichtbar) {
    $stmt_all = $conn->query("SELECT id, name, kuerzel FROM teachers WHERE is_admin = 0 ORDER BY name ASC");
    $all_teachers = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($aud_tage_sichtbar ? $aud_list_for_ui : [] as $aud) {
    // Collect all assigned teachers for this AUD
    $stmt = $conn->prepare("
        SELECT r.teacher_id, r.participating_teacher_id, r.companion
        FROM extracurricular_requests r
        WHERE r.aud_type = ? AND r.status != 'rejected'
    ");
    $stmt->execute([$aud]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $assigned_ids = [];
    $assigned_names = [];
    
    foreach ($requests as $req) {
        if ($req['teacher_id']) $assigned_ids[] = $req['teacher_id'];
        if ($req['participating_teacher_id']) $assigned_ids[] = $req['participating_teacher_id'];
        
        $comp_str = $req['companion'];
        if (!empty($comp_str)) {
            $assigned_names[] = $comp_str;
        }
    }
    
    $free_for_aud = [];
    foreach ($all_teachers as $t) {
        $is_assigned = false;
        if (in_array($t['id'], $assigned_ids)) {
            $is_assigned = true;
        } else {
            foreach ($assigned_names as $comp_str) {
                if (strpos($comp_str, $t['name']) !== false || strpos($comp_str, "(" . $t['kuerzel'] . ")") !== false) {
                    $is_assigned = true;
                    break;
                }
            }
        }
        
        if (!$is_assigned) {
            $free_for_aud[] = $t;
        }
    }
    
    $aud_free[$aud] = $free_for_aud;
}

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_aud.twig', [
    'aud_tage_sichtbar' => $aud_tage_sichtbar,
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'extra_requests' => $extra_requests,
    'aud_free' => $aud_free,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
