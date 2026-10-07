<?php
/**
 * src/includes/auth.php
 * Session management and authentication checks.
 */

require_once __DIR__ . '/request.php';
require_once __DIR__ . '/../config/database.php';

/*
 * Grenzen einer Sitzung, in Minuten, per Umgebung einstellbar (Audit M4).
 *
 * Vorher lief eine Sitzung, bis der Browser geschlossen wurde - an einem
 * Rechner im Lehrerzimmer unter Umstaenden tagelang, mit Einblick in
 * Krankmeldungen und Atteste. Am Portal ist der Ablauf kaum zu spueren:
 * besteht dort die Anmeldung noch, fuehrt der naechste Klick ohne Eingabe
 * zurueck.
 */
define('SITZUNG_LEERLAUF', 60 * max(5, (int) ($_ENV['SITZUNG_LEERLAUF_MINUTEN'] ?? getenv('SITZUNG_LEERLAUF_MINUTEN') ?: 120)));
define('SITZUNG_HOECHSTDAUER', 60 * max(30, (int) ($_ENV['SITZUNG_HOECHSTDAUER_MINUTEN'] ?? getenv('SITZUNG_HOECHSTDAUER_MINUTEN') ?: 720)));

// Sonst raeumt PHP die Sitzungsdatei nach 24 Minuten ohne Aufruf ab - aber
// nur gelegentlich, sodass die tatsaechliche Dauer vom Zufall abhing.
ini_set('session.gc_maxlifetime', (string) SITZUNG_LEERLAUF);

session_name('feedback_session');
session_set_cookie_params([
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    // request_is_https() beruecksichtigt X-Forwarded-Proto. Hinter dem
    // Reverse Proxy ist $_SERVER['HTTPS'] nicht gesetzt - das
    // Sitzungscookie ging deshalb ohne Secure-Flag hinaus und war damit
    // auch ueber eine unverschluesselte Verbindung zu bekommen.
    'secure'   => request_is_https(),
]);
session_start();
sitzung_ablauf_pruefen();

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Beendet die Anmeldung, laesst aber eine Meldung fuer die naechste Seite stehen.
 */
function sitzung_beenden(string $meldung): void {
    $_SESSION = [];
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['flash_error'] = $meldung;
}

/**
 * Beendet Sitzungen nach Leerlauf oder Hoechstdauer (Audit M4).
 *
 * Der Beginn wird beim ersten Aufruf nach der Anmeldung festgehalten; so
 * muessen die verschiedenen Anmeldewege (Portal, IServ, lokal) nichts davon
 * wissen. Wechselt die angemeldete Person, beginnt die Zaehlung neu.
 */
function sitzung_ablauf_pruefen(): void {
    if (!is_logged_in()) {
        return;
    }

    $jetzt = time();
    if ((string) ($_SESSION['angemeldet_als'] ?? '') !== (string) $_SESSION['user_id']) {
        $_SESSION['angemeldet_als'] = (string) $_SESSION['user_id'];
        $_SESSION['angemeldet_um'] = $jetzt;
        $_SESSION['zuletzt_aktiv'] = $jetzt;
    }

    if ($jetzt - (int) $_SESSION['zuletzt_aktiv'] > SITZUNG_LEERLAUF
        || $jetzt - (int) $_SESSION['angemeldet_um'] > SITZUNG_HOECHSTDAUER) {
        sitzung_beenden('Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.');
        return;
    }

    $_SESSION['zuletzt_aktiv'] = $jetzt;
}

/**
 * Gleicht die Sitzung mit dem Konto in der Datenbank ab (Audit M4).
 *
 * Vorher galt bis zum Abmelden, was beim Anmelden in der Sitzung stand: wem
 * die Verwaltung entzogen wurde, behielt sie, und ein geloeschtes Konto blieb
 * angemeldet. Jetzt zaehlt bei jeder geschuetzten Seite der Stand in der
 * Datenbank. Bei der Anmeldung ueber das Portal schreibt includes/sso.php das
 * Verwaltungsrecht aus den Portalgruppen dorthin.
 */
function sitzung_mit_konto_abgleichen(): void {
    if (!is_logged_in()) {
        return;
    }

    $stmt = db_connect()->prepare('SELECT name, is_admin, force_password_change FROM teachers WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $konto = $stmt->fetch();

    if (!$konto) {
        sitzung_beenden('Ihr Konto besteht nicht mehr. Bitte wenden Sie sich an die Schulleitung.');
        return;
    }

    $_SESSION['user_name'] = $konto['name'];
    $_SESSION['is_admin'] = (int) $konto['is_admin'];
    $_SESSION['force_password_change'] = (int) $konto['force_password_change'];
}

function require_login() {
    sitzung_mit_konto_abgleichen();

    if (!is_logged_in()) {
        header("Location: /login.php");
        exit;
    }
    // Check if password change is forced
    if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] == 1) {
        $current_script = basename($_SERVER['SCRIPT_NAME']);
        if ($current_script !== 'change_password.php' && $current_script !== 'logout.php') {
            header("Location: /change_password.php");
            exit;
        }
    }
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_current_user_name() {
    return $_SESSION['user_name'] ?? null;
}

function get_current_user_kuerzel() {
    return $_SESSION['user_kuerzel'] ?? null;
}

function is_current_user_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function require_admin() {
    require_login();
    if (!is_current_user_admin()) {
        header("Location: /index.php");
        exit;
    }
}

/**
 * Validates a Kürzel and Password against the database.
 * Returns user data on success, false on failure.
 */
function authenticate_user($conn, $kuerzel, $password) {
    if (empty($kuerzel) || empty($password)) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id, kuerzel, is_admin, passwort_hash, name, force_password_change FROM teachers WHERE kuerzel = :kuerzel LIMIT 1");
    $stmt->execute([':kuerzel' => $kuerzel]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['passwort_hash'])) {
        return $user;
    }

    return false;
}

/**
 * Erzeugt ein Erstpasswort fuer ein neu angelegtes Konto.
 *
 * Vorher vergaben beide Importe ein festes, im Quelltext lesbares Passwort
 * ("lehrer" bzw. "Start123!") - fuer jede importierte Lehrkraft dasselbe. Der
 * Wechselzwang aus der Spaltenvorgabe half wenig: wer das Passwort kannte,
 * konnte den Wechsel selbst vollziehen und das Konto uebernehmen, bevor die
 * Lehrkraft sich zum ersten Mal anmeldete.
 *
 * Das Alphabet laesst 0, 1 und l weg: das Passwort wird auf Papier
 * weitergegeben und abgetippt, und eine Verwechslung kostet einen Anruf.
 */
function erstes_passwort($laenge = 12) {
    $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
    $grenze = strlen($alphabet) - 1;
    $laenge = (int)(ceil(max(8, (int)$laenge) / 4) * 4);

    $zeichen = '';
    for ($i = 0; $i < $laenge; $i++) {
        $zeichen .= $alphabet[random_int(0, $grenze)];
    }

    // In Vierergruppen: "k7fp-r3mq-x9tz" liest sich vom Zettel besser.
    return implode('-', str_split($zeichen, 4));
}

/**
 * Simple CSRF token generation
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Simple CSRF token validation
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
?>
