<?php
/**
 * src/admin_archive.php
 * Jahresabschluss: archivieren und bereinigen.
 *
 * Zwei Aenderungen gegenueber frueher:
 *
 * 1. Zeitraum ist das Schuljahr (1. August bis 31. Juli), nicht das
 *    Kalenderjahr. Ein Kalenderjahr schneidet mitten durch - ein Abschluss im
 *    Dezember nimmt die eine Haelfte mit und laesst die andere stehen, und
 *    eine Klassenfahrt im Maerz liegt im selben Schuljahr wie eine im
 *    November davor.
 *
 * 2. Das Archiv entsteht ausserhalb des DocumentRoot. Vorher wurde es in
 *    public/uploads/ gebaut - dessen .htaccess sperrt nur die Ausfuehrung von
 *    PHP, nicht den Abruf. Ein Jahresarchiv mit allen Krankmeldungen und
 *    Attesten lag damit unter einer Adresse, die aus Name und Zeitstempel
 *    besteht, und blieb dort liegen, sobald der Download abbrach.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();
$conn = db_connect();

$action = $_GET['action'] ?? '';

/**
 * Beginnjahr des Schuljahres aus der Anfrage - oder das laufende.
 *
 * Bis Juli laeuft das Schuljahr, das im Vorjahr begonnen hat.
 */
function gewaehltes_schuljahr() {
    $wunsch = (int)($_POST['schuljahr'] ?? $_GET['schuljahr'] ?? 0);

    if ($wunsch >= 2000 && $wunsch <= 2100) {
        return $wunsch;
    }

    return (int)date('n') >= 8 ? (int)date('Y') : (int)date('Y') - 1;
}

/**
 * @return array{0:string,1:string} erster und letzter Tag des Schuljahres
 */
function schuljahr_zeitraum($beginn) {
    return [sprintf('%04d-08-01', $beginn), sprintf('%04d-07-31', $beginn + 1)];
}

if ($action === 'export') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Die Seite war zu lange geöffnet. Bitte neu laden.';
        header('Location: /admin_system.php');
        exit;
    }

    $beginn = gewaehltes_schuljahr();
    [$von, $bis] = schuljahr_zeitraum($beginn);
    $year = sprintf('%d-%02d', $beginn, ($beginn + 1) % 100);

    $archive_name = "Jahresabschluss_" . $year;

    // Ausserhalb des DocumentRoot: ein Archiv mit Attesten gehoert nicht in
    // ein Verzeichnis, das der Webserver ausliefert.
    $tmp_dir = sys_get_temp_dir() . "/" . $archive_name . "_" . bin2hex(random_bytes(6));
    
    // Laesst sich das Arbeitsverzeichnis nicht anlegen, hat der Export keinen
    // Zweck - frueher lief er blind weiter und erzeugte ein leeres Archiv.
    $verzeichnisse = [$tmp_dir, "$tmp_dir/Krankmeldungen", "$tmp_dir/Veranstaltungen",
                      "$tmp_dir/Freistellungen", "$tmp_dir/Anhaenge"];
    foreach ($verzeichnisse as $verzeichnis) {
        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0775, true) && !is_dir($verzeichnis)) {
            error_log("admin_archive: $verzeichnis nicht anlegbar");
            $_SESSION['flash_error'] = "Das Archiv konnte nicht erstellt werden: Das "
                . "Ablageverzeichnis ist nicht beschreibbar. Bitte die Administration "
                . "informieren.";
            // admin_archive.php hat keine eigene Oberflaeche - die Meldung
            // erscheint in der Systemverwaltung, so wie beim Cleanup weiter unten.
            header("Location: /admin_system.php");
            exit;
        }
    }

    // --- 1. Export Sick Leave Reports ---
    $stmt = $conn->prepare("SELECT * FROM sick_leave_reports WHERE date_from BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Von;Bis;Notizen;Material;Anhang;Erstellt_am\n";
    foreach ($data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
        if (!empty($r['attachment_path']) && file_exists(__DIR__ . '/public/' . $r['attachment_path'])) {
            copy(__DIR__ . '/public/' . $r['attachment_path'], $tmp_dir . "/Anhaenge/" . basename($r['attachment_path']));
        }
    }
    file_put_contents($tmp_dir . "/Krankmeldungen/krankmeldungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 2. Export Extracurricular Events ---
    $stmt = $conn->prepare("SELECT id, teacher_id, role, class_name, companion, event_date, event_date_to, event_name, destination, aud_type, participating_teacher_id, costs, transport, start_time, start_location, return_time, return_location, return_trip_arranged, supervisors, consent_form, schedule_notified, status, created_at, modified_at, modified_after_approval FROM extracurricular_requests WHERE event_date BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $extra_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Rolle;Klasse;Begleitung;Datum;Enddatum;Event;Ziel;AUD_Typ;Begleitlehrer_ID;Kosten;Transport;Start_Zeit;Start_Ort;Rueck_Zeit;Rueck_Ort;Rueck_Arrangiert;Aufsicht;Einverstaendnis;Stundenplan;Status;Erstellt_am;Geaendert_am;Geaendert_nach_Appr\n";
    foreach ($extra_data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
    }
    file_put_contents($tmp_dir . "/Veranstaltungen/veranstaltungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 3. Export Exemption Requests ---
    $stmt = $conn->prepare("SELECT * FROM exemption_requests WHERE date_from BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $ex_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Von;Bis;Grund;Wochentage;Klassen;Stuendlich;Std_Von;Std_Bis;Grund_Typ;Status;Erstellt_am\n";
    foreach ($ex_data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
    }
    file_put_contents($tmp_dir . "/Freistellungen/freistellungen_$year.csv", "\xEF\xBB\xBF" . $csv);


    // Create Archive using tar (fallback for ZipArchive)
    $archive_file = $archive_name . ".tar.gz";
    $archive_path = $tmp_dir . ".tar.gz";
    
    $cmd = "tar -czf " . escapeshellarg($archive_path) . " -C " . escapeshellarg($tmp_dir) . " .";
    shell_exec($cmd);

    // Cleanup tmp dir
    shell_exec("rm -rf " . escapeshellarg($tmp_dir));

    if (file_exists($archive_path)) {
        header('Content-Type: application/gzip');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $archive_file . '"');
        header('Content-Length: ' . filesize($archive_path));
        readfile($archive_path);
        unlink($archive_path);
        exit;
    } else {
        die("Fehler beim Erstellen des Archivs.");
    }
}

if ($action === 'cleanup' && isset($_POST['confirm_year'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        die("CSRF Security Check failed.");
    }
    
    $beginn = (int)$_POST['confirm_year'];
    [$von, $bis] = schuljahr_zeitraum($beginn);
    $schuljahr = sprintf('%d/%02d', $beginn, ($beginn + 1) % 100);

    // Delete and log
    $stmt = $conn->prepare("DELETE FROM sick_leave_reports WHERE date_from BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $count_sick = $stmt->rowCount();

    $stmt = $conn->prepare("DELETE FROM extracurricular_requests WHERE event_date BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $count_extra = $stmt->rowCount();

    $stmt = $conn->prepare("DELETE FROM exemption_requests WHERE date_from BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $count_exempt = $stmt->rowCount();

    $_SESSION['flash_success'] = "Archiv-Cleanup für das Schuljahr $schuljahr abgeschlossen. $count_sick Krankmeldungen, $count_extra Veranstaltungen und $count_exempt Freistellungen wurden entfernt.";
    header("Location: /admin_system.php");
    exit;
}
