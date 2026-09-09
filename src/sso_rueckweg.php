<?php
/**
 * src/sso_rueckweg.php
 * Rueckweg vom SchulOS-Portal: Code einloesen, Konto zuordnen, anmelden.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/migrations.php';

use SchulOS\Sso\SsoFehler;

if (!sso_aktiv()) {
    header('Location: /login.php');
    exit;
}

/** Zeigt eine schlichte Fehlerseite und beendet die Anfrage. */
function sso_fehlerseite(string $titel, string $text, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($titel, ENT_QUOTES) . '</title>'
        . '<link rel="stylesheet" href="/css/app_styles.css"></head>'
        . '<body><div class="content-box" style="max-width:460px;margin:80px auto;text-align:center">'
        . '<h1>' . htmlspecialchars($titel, ENT_QUOTES) . '</h1>'
        . '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>'
        . '<p><a href="' . htmlspecialchars(sso_portal_adresse() ?: '/', ENT_QUOTES) . '">Zum Portal</a></p>'
        . '</div></body></html>';
    exit;
}

$anmeldung = sso_anmeldung();

try {
    $identitaet = $anmeldung->abschliessen($_GET);
} catch (SsoFehler $e) {
    error_log('Antragssystem: Anmeldung ueber das Portal gescheitert: ' . $e->getMessage());
    sso_fehlerseite(
        'Anmeldung nicht abgeschlossen',
        'Die Anmeldung über das Portal konnte nicht abgeschlossen werden. Bitte erneut versuchen.'
    );
}

// Die Migrationen muessen durch sein, bevor auf sso_sub zugegriffen wird.
run_all_migrations();

$conn = db_connect();

if (!sso_zuordnung_bereit($conn)) {
    error_log('Antragssystem: Tabelle portal_konten fehlt - die Migration ist nicht gelaufen.');
    sso_fehlerseite(
        'Modul noch nicht bereit',
        'Die Datenbank dieses Moduls ist noch nicht auf dem Stand für die Portal-Anmeldung. Bitte an die Administration wenden.',
        503
    );
}

$konto = sso_konto($conn, $identitaet);

if ($konto === null) {
    sso_fehlerseite(
        'Kein Zugang',
        'Für Ihr Konto ist dieses Modul nicht freigegeben. Die Schulleitung kann das im Portal ändern.',
        403
    );
}

sso_sitzung_starten($konto, $identitaet, $anmeldung->letztesIdToken());

header('Location: ' . $anmeldung->zielNachAnmeldung());
exit;
