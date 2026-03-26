<?php
/**
 * src/admin_archive.php
 * Handles annual archiving and cleanup.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();
$conn = db_connect();

$action = $_GET['action'] ?? '';
$year = (int)($_GET['year'] ?? date('Y', strtotime('-1 month'))); // Default to last year if in Jan

if ($action === 'export') {
    $zip = new ZipArchive();
    $zip_filename = "Jahresabschluss_" . $year . "_" . date('Ymd_His') . ".zip";
    $zip_path = __DIR__ . "/uploads/" . $zip_filename;

    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        die("Fehler beim Erstellen des ZIP-Archivs.");
    }

    // --- 1. Export Sick Leave Reports ---
    $stmt = $conn->prepare("SELECT * FROM sick_leave_reports WHERE YEAR(date_from) = ?");
    $stmt->execute([$year]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Von;Bis;Notizen;Material;Anhang;Erstellt_am\n";
    foreach ($data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
        if (!empty($r['attachment_path']) && file_exists(__DIR__ . '/' . $r['attachment_path'])) {
            $zip->addFile(__DIR__ . '/' . $r['attachment_path'], "Anhaenge/" . basename($r['attachment_path']));
        }
    }
    $zip->addFromString("Krankmeldungen/krankmeldungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 2. Export Extracurricular Requests ---
    $stmt = $conn->prepare("SELECT * FROM extracurricular_requests WHERE YEAR(event_date) = ?");
    $stmt->execute([$year]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Rolle;Klasse;Begleitung;Datum;Event;Ziel;AUD_Typ;Begleitlehrer_ID;Kosten;Transport;Start_Zeit;Start_Ort;Rueck_Zeit;Rueck_Ort;Rueck_Arrangiert;Aufsicht;Einverstaendnis;Stundenplan;Status;Erstellt_am;Geaendert_am;Geaendert_nach_Appr\n";
    foreach ($data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
    }
    $zip->addFromString("Veranstaltungen/veranstaltungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 3. Export Exemption Requests ---
    $stmt = $conn->prepare("SELECT * FROM exemption_requests WHERE YEAR(date_from) = ?");
    $stmt->execute([$year]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Von;Bis;Grund;Wochentage;Klassen;Stuendlich;Std_Von;Std_Bis;Grund_Typ;Status;Erstellt_am\n";
    foreach ($data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
    }
    $zip->addFromString("Freistellungen/freistellungen_$year.csv", "\xEF\xBB\xBF" . $csv);

    // --- 4. Export Feedback ---
    $stmt = $conn->prepare("SELECT * FROM feedback_sessions WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $csv = "ID;Lehrer_ID;Klasse;Fach;Token;Aktiv;Ablauf;Erstellt_am\n";
    foreach ($data as $r) {
        $csv .= implode(';', array_values($r)) . "\n";
    }
    $zip->addFromString("Feedback/sessions_$year.csv", "\xEF\xBB\xBF" . $csv);

    $zip->close();

    // Send ZIP to browser
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    unlink($zip_path); // Delete temp file from server
    exit;
}

if ($action === 'cleanup' && isset($_POST['confirm_year'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        die("CSRF Security Check failed.");
    }
    
    $cleanup_year = (int)$_POST['confirm_year'];
    
    // Delete and log
    $stmt = $conn->prepare("DELETE FROM sick_leave_reports WHERE YEAR(date_from) = ?");
    $stmt->execute([$cleanup_year]);
    $count_sick = $stmt->rowCount();

    $stmt = $conn->prepare("DELETE FROM extracurricular_requests WHERE YEAR(event_date) = ?");
    $stmt->execute([$cleanup_year]);
    $count_extra = $stmt->rowCount();

    $stmt = $conn->prepare("DELETE FROM exemption_requests WHERE YEAR(date_from) = ?");
    $stmt->execute([$cleanup_year]);
    $count_exempt = $stmt->rowCount();
    
    // Feedback is slightly more complex due to cascading? Assume ON DELETE CASCADE exists.
    $stmt = $conn->prepare("DELETE FROM feedback_sessions WHERE YEAR(created_at) = ?");
    $stmt->execute([$cleanup_year]);
    $count_feedback = $stmt->rowCount();

    $_SESSION['flash_success'] = "Archiv-Cleanup für $cleanup_year abgeschlossen. $count_sick Krankmeldungen, $count_extra Veranstaltungen, $count_exempt Freistellungen und $count_feedback Feedback-Sitzungen wurden entfernt.";
    header("Location: /admin_dashboard.php");
    exit;
}
