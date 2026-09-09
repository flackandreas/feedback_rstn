<?php
/**
 * src/sso_abmelden.php
 * Abmeldung ueber den Vorderkanal (OpenID Connect Front-Channel Logout 1.0).
 *
 * Das Portal laedt diese Adresse beim Abmelden in einem unsichtbaren Rahmen.
 * Wir beenden daraufhin unsere eigene Sitzung.
 *
 * Geprueft wird, dass Aussteller und Sitzungskennung zu dieser Sitzung
 * gehoeren. Ohne diese Pruefung koennte jede fremde Seite, die das Modul in
 * einen Rahmen laedt, die Lehrkraft mitten in der Arbeit abmelden.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sso.php';

// Der Rahmen kommt vom Portal - X-Frame-Options: DENY aus dem Front Controller
// wuerde ihn blockieren. Erlaubt wird ausschliesslich das Portal.
header_remove('X-Frame-Options');
$portal = sso_portal_adresse();
header("Content-Security-Policy: default-src 'none'; frame-ancestors " . ($portal !== '' ? $portal : "'none'"));
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

if (!sso_aktiv()) {
    http_response_code(204);
    exit;
}

if (sso_anmeldung()->pruefeVorderkanalAbmeldung($_GET, $_SESSION['sso_sid'] ?? null)) {
    $_SESSION = [];
    session_destroy();
}

// Immer 204: ob hier eine Sitzung bestand, geht den Aufrufer nichts an.
http_response_code(204);
exit;
