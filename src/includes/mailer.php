<?php
/**
 * src/includes/mailer.php
 * Wrapper function for sending emails using PHPMailer.
 */

// Load PHPMailer classes from our manually downloaded folder
require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends a notification email to a teacher.
 *
 * @param string $to Email address of the recipient
 * @param string $subject The subject of the email
 * @param string $body The HTML body of the email
 * @return bool True if successful, false otherwise
 */
function send_notification_email($to, $subject, $body) {
    if (empty($to)) {
        error_log("Mailer Error: Not sending email because recipient address is empty.");
        return false;
    }

    $configPath = __DIR__ . '/../config/mail.php';
    if (!file_exists($configPath)) {
        error_log("Mailer Error: Configuration file mail.php not found.");
        return false;
    }
    
    $config = require $configPath;
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = $config['auth'];
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = $config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';

        // Disable verbose debug output by default
        $mail->SMTPDebug = 0;

        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        // Strip HTML for plain text alternative
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error but don't crash the application
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
