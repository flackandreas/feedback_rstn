<?php
/**
 * src/logout.php
 * Abmeldung.
 *
 * Kam die Sitzung vom Portal, wird auch dort abgemeldet - und das Portal
 * meldet anschliessend die uebrigen Module ab. Sonst bliebe man auf einem
 * geteilten Rechner im Lehrerzimmer in den anderen Modulen angemeldet.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sso.php';

$abmeldeAdresse = null;

if (sso_aktiv() && sso_sitzung_vom_portal()) {
    $idToken = $_SESSION['sso_id_token'] ?? null;

    try {
        $abmeldeAdresse = sso_anmeldung()->abmeldeAdresse(
            is_string($idToken) ? $idToken : null,
            rtrim(sso_umgebung('PORTAL_ISSUER'), '/') . '/anmelden'
        );
    } catch (Throwable $e) {
        // Das Portal ist nicht erreichbar. Oertlich abmelden ist dann alles,
        // was geht - und immer noch besser als eine Fehlerseite.
        error_log('Antragssystem: Abmeldeadresse des Portals nicht ermittelbar: ' . $e->getMessage());
    }
}

session_unset();
session_destroy();

header('Location: ' . ($abmeldeAdresse ?? '/login.php'));
exit;
