<?php
/**
 * src/index.php
 * Einstieg.
 *
 * Am SchulOS-Portal ist die Modulauswahl dort zuhause - hier wird nur noch
 * dorthin weitergeleitet. Ohne Portal bleibt die alte Auswahlseite bestehen,
 * damit eine Schule dieses Modul auch allein betreiben kann.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/migrations.php';
run_all_migrations();

require_login();

// Am Portal ist die alte Modulauswahl gegenstandslos - der Weg fuehrt auf das
// eigene Dashboard.
//
// NICHT zurueck zum Portal: die Kacheln dort zeigen auf die Wurzel des Moduls,
// und die landet hier. Eine Weiterleitung ans Portal schickt damit jeden, der
// eine Kachel antippt, sofort wieder dorthin zurueck. Der Weg zum Portal
// steht in der Navigation.
if (sso_aktiv()) {
    header('Location: /dashboard');
    exit;
}

$user_name = get_current_user_name();

require_once __DIR__ . '/includes/twig_setup.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/**
 * Verweis auf das Unterrichtsmodul.
 *
 * Das Geheimnis stand hier frueher im Quelltext, und das Repository ist
 * oeffentlich - damit konnte jeder mit einem bekannten Kuerzel ein gueltiges
 * Token bauen und sich ohne Passwort anmelden. Ohne SSO_SECRET in der
 * Umgebung gibt es die Verknuepfung jetzt schlicht nicht.
 */
$sso_secret = sso_umgebung('SSO_SECRET');
$url_unterricht = '#';

if ($sso_secret !== '') {
    $kuerzel = (string) get_current_user_kuerzel();
    $token = hash('sha256', $kuerzel . $sso_secret . floor(time() / 300));
    $ziel = sso_umgebung('UNTERRICHT_URL');

    if ($ziel === '') {
        // Rueckfall auf den Nachbarport am selben Host, wie bisher.
        $host = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'))[0];
        $ziel = '//' . $host . ':8889';
    }

    $url_unterricht = rtrim($ziel, '/') . '/login.php?autologin=1&kuerzel='
        . urlencode($kuerzel) . '&token=' . $token;
}

echo $twig->render('portal_choice.twig', [
    'current_user_name' => $user_name,
    'is_logged_in' => false,
    'url_antraege' => '/dashboard',
    'url_unterricht' => $url_unterricht,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
]);
