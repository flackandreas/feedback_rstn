<?php
/**
 * src/login.php
 * Anmeldung.
 *
 * Laeuft das Modul am SchulOS-Portal, ist dies nur noch eine Weiche: die
 * Anmeldung selbst passiert dort. Die oertliche Maske bleibt als Rueckfall
 * unter /login.php?lokal=1 erreichbar - fuer den Fall, dass das Portal steht
 * und jemand trotzdem an die Anwendung muss.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/sso.php';
require_once __DIR__ . '/includes/twig_setup.php';

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

$portalAktiv = sso_aktiv();

// Am Portal ist die oertliche Maske nicht der Regelweg.
if ($portalAktiv && !isset($_GET['lokal']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /sso_start.php');
    exit;
}

/**
 * Anmeldung ueber einen Token in der Adresszeile.
 *
 * Der Weg stammt aus der Zeit, als sich zwei Module gegenseitig verlinkt
 * haben. Zwei Dinge haben sich geaendert:
 *
 *  - Das Geheimnis stand frueher im Quelltext (SchulHub_SSO_Secret_Key_2026)
 *    und das Repository ist oeffentlich. Wer ein Kuerzel kannte, kam ohne
 *    Passwort herein. Es kommt jetzt aus der Umgebung; fehlt es, ist der Weg
 *    zu.
 *  - Am Portal wird er gar nicht mehr gebraucht und ist deshalb dort aus.
 */
if (!$portalAktiv && isset($_GET['autologin']) && $_GET['autologin'] === '1') {
    $kuerzel = trim((string) ($_GET['kuerzel'] ?? ''));
    $token = (string) ($_GET['token'] ?? '');
    $sso_secret = sso_umgebung('SSO_SECRET');

    if ($sso_secret !== '' && strlen($sso_secret) < 32) {
        error_log('Antragssystem: WARNUNG - SSO_SECRET ist kuerzer als 32 Zeichen.');
    }

    if ($kuerzel !== '' && $token !== '' && $sso_secret !== '') {
        $conn = db_connect();

        if (!rate_limit_allow($conn, 'autologin', rate_limit_client_ip(), 20, 900)) {
            http_response_code(429);
            $_SESSION['flash_error'] = 'Zu viele Anmeldeversuche. Bitte einige Minuten warten.';
        } else {
            $stmt = $conn->prepare('SELECT * FROM teachers WHERE kuerzel = ? LIMIT 1');
            $stmt->execute([$kuerzel]);
            $user = $stmt->fetch();

            if ($user) {
                $time_bucket = floor(time() / 300);
                $token_valid = false;

                for ($i = 0; $i <= 1; $i++) {
                    $erwartet = hash('sha256', $user['kuerzel'] . $sso_secret . ($time_bucket - $i));
                    if (hash_equals($erwartet, $token)) {
                        $token_valid = true;
                        break;
                    }
                }

                if ($token_valid) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_kuerzel'] = $user['kuerzel'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['is_admin'] = $user['is_admin'];
                    $_SESSION['force_password_change'] = $user['force_password_change'];

                    header('Location: /index.php');
                    exit;
                }
            }
        }
    } elseif ($sso_secret === '') {
        error_log('Antragssystem: Autologin abgelehnt - SSO_SECRET ist nicht gesetzt.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kuerzel = trim((string) ($_POST['kuerzel'] ?? ''));
    $password = (string) ($_POST['passwort'] ?? '');
    $csrf_token = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = 'Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.';
    } elseif ($kuerzel === '' || $password === '') {
        $_SESSION['flash_error'] = 'Bitte Kürzel und Passwort eingeben.';
    } else {
        $conn = db_connect();
        $ip = rate_limit_client_ip();

        // Zwei Toepfe: nur die Adresse zu zaehlen sperrt bei Bedarf eine ganze
        // Schule aus, nur das Kuerzel zu zaehlen macht es leicht, gezielt
        // fremde Konten zu blockieren.
        if (!rate_limit_allow($conn, 'login_ip', $ip, 15, 900)
            || !rate_limit_allow($conn, 'login_user', mb_strtolower($kuerzel), 8, 900)) {
            http_response_code(429);
            $_SESSION['flash_error'] = 'Zu viele Anmeldeversuche. Bitte warten Sie einige Minuten.';
            $user = false;
        } else {
            $user = authenticate_user($conn, $kuerzel, $password);
        }

        if ($user) {
            rate_limit_reset($conn, 'login_ip', $ip);
            rate_limit_reset($conn, 'login_user', mb_strtolower($kuerzel));

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_kuerzel'] = $user['kuerzel'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['force_password_change'] = $user['force_password_change'];

            header('Location: /index.php');
            exit;
        }

        if (empty($_SESSION['flash_error'])) {
            usleep(300000);
            $_SESSION['flash_error'] = 'Falsches Kürzel oder Passwort.';
        }
    }
}

$csrf_token = get_csrf_token();
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

echo $twig->render('login.twig', [
    'csrf_token' => $csrf_token,
    'flash_error' => $flash_error,
    'is_logged_in' => false,
    'portal_aktiv' => $portalAktiv,
    'portal_adresse' => sso_portal_adresse(),
    // Am Portal ist die oertliche Maske der Ausnahmeweg, nicht der Regelweg.
    'lokal_erzwungen' => isset($_GET['lokal']),
    'iserv_aktiv' => sso_umgebung('ISERV_HOST') !== '' && sso_umgebung('ISERV_CLIENT_ID') !== '',
]);
