<?php
/**
 * src/config/mail.php
 * SMTP-Einstellungen fuer den Benachrichtigungsversand.
 *
 * Hier standen bis zuletzt Platzhalter mit der Aufschrift "REPLACE these
 * place holders with your actual credentials" - und diese Datei liegt im
 * Repository. Wer der Aufforderung folgte, schrieb das Passwort des
 * Schulpostfachs hinein und committete es beim naechsten Mal mit. Genau so
 * ist in diesem Repository schon einmal ein Zugangsschluessel gelandet.
 *
 * Die Werte kommen jetzt aus der Umgebung, wie die Datenbankzugaenge auch.
 * Die Datei bleibt bestehen, weil includes/mailer.php sie einbindet.
 */

/** Liest eine Umgebungsvariable, leer gilt als nicht gesetzt. */
$lesen = static function (string $name, string $vorgabe = ''): string {
    $wert = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return (is_string($wert) && trim($wert) !== '') ? trim($wert) : $vorgabe;
};

$benutzer = $lesen('SMTP_USER');

return [
    'host'       => $lesen('SMTP_HOST'),
    'port'       => (int) $lesen('SMTP_PORT', '587'),
    'username'   => $benutzer,
    'password'   => $lesen('SMTP_PASS'),
    'from_email' => $lesen('SMTP_ABSENDER', 'noreply@localhost'),
    'from_name'  => $lesen('SMTP_ABSENDERNAME', $lesen('SCHULNAME', 'Schule')),
    'encryption' => $lesen('SMTP_VERSCHLUESSELUNG', 'tls'),

    // Ohne Benutzernamen kein AUTH: ein Relais im Schulnetz verlangt haeufig
    // keines, und ein leeres AUTH weist es ab.
    'auth'       => $benutzer !== '',
];
