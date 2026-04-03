<?php
/**
 * src/cron_report.php
 * Automatisiertes Reporting (wird z.B. per Cronjob um 7:00 Uhr aufgerufen)
 * Sendet Krankmeldungen des aktuellen Tages per SMTP.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Nur für CLI oder mit Token ausführen
if (php_sapi_name() !== 'cli' && (!isset($_GET['token']) || $_GET['token'] !== 'cron_secret_77')) {
    die("Access denied.");
}

$conn = db_connect();
$today = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT r.date_from, r.date_to, t.name as teacher_name, r.notes 
    FROM sick_leave_reports r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE ? BETWEEN r.date_from AND r.date_to
");
$stmt->execute([$today]);
$sick_leaves = $stmt->fetchAll();

$htmlBody = "<h2>Krankenstand am " . date('d.m.Y') . "</h2>";
if (empty($sick_leaves)) {
    $htmlBody .= "<p>Heute liegen keine Krankmeldungen vor.</p>";
} else {
    $htmlBody .= "<ul>";
    foreach ($sick_leaves as $leave) {
        $htmlBody .= "<li><strong>{$leave['teacher_name']}</strong> (bis " . date('d.m.Y', strtotime($leave['date_to'])) . ")<br>";
        if (!empty($leave['notes'])) {
            $htmlBody .= "<em>Notiz: {$leave['notes']}</em>";
        }
        $htmlBody .= "</li>";
    }
    $htmlBody .= "</ul>";
}

// Config für Mail (Placeholder - muss von Admin gesetzt werden)
$mail = new PHPMailer(true);

try {
    // Servereinstellungen (In Realität kommen diese aus der `app_settings` Tabelle)
    $mail->isSMTP();
    $mail->Host       = 'smtp.example.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'sekretariat@schule.de';
    $mail->Password   = 'secret';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('sekretariat@schule.de', 'Schulsystem Automatik');
    $mail->addAddress('schulleitung@schule.de', 'Schulleitung');

    $mail->isHTML(true);
    $mail->Subject = 'Tagesbericht Krankmeldungen - ' . date('d.m.Y');
    $mail->Body    = $htmlBody;
    $mail->AltBody = strip_tags($htmlBody);

    // Auskommentiert, damit es im Demo-Docker nicht abstürzt
    // $mail->send();
    echo "Cronjob erfolgreich ausgeführt. Mail (Simulation) vorbereitet:\n";
    echo strip_tags($htmlBody);
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
?>
