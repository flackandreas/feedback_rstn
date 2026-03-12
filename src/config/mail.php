<?php
/**
 * src/config/mail.php
 * Configuration holding SMTP settings for PHPMailer.
 * REPLACE these place holders with your actual credentials!
 */

return [
    'host' => 'smtp.example.com',        // Specify main and backup SMTP servers
    'port' => 587,                       // TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`
    'username' => 'your_username',       // SMTP username
    'password' => 'your_password',       // SMTP password
    'from_email' => 'noreply@school.edu',// Sender email address
    'from_name' => 'Feedback System',    // Sender name
    'encryption' => 'tls',               // Enable TLS encryption, `ssl` also accepted
    'auth' => true                       // Enable SMTP authentication
];
