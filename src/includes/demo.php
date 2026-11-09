<?php
/**
 * src/includes/demo.php
 * Schalter fuer die oeffentliche SchulOS-Demo (demo.schulos.de).
 *
 * Mit SCHULOS_DEMO=1 laeuft das Antragssystem als Teil der offenen Demo:
 * erfundene Daten, Anmeldung nur ueber die Rollenwahl des Portals, Mails
 * landen im Demo-Postfach (Mailpit). Ohne den Schalter aendert sich nichts.
 */

/** Hoechstgroesse fuer Uploads in der Demo: 5 MB. */
const DEMO_UPLOAD_GRENZE = 5 * 1024 * 1024;

function demo_aktiv(): bool
{
    $wert = $_ENV['SCHULOS_DEMO'] ?? $_SERVER['SCHULOS_DEMO'] ?? getenv('SCHULOS_DEMO');

    return is_string($wert) && trim($wert) === '1';
}
