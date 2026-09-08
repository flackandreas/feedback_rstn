<?php
/**
 * src/admin_system.php
 * System management: CSV Import, Archiving, and Cleanup.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/calendar_helper.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_admin();

$conn = db_connect();

/**
 * Leitet mit Meldung auf die Systemverwaltung zurueck.
 */
function zurueck_zur_verwaltung($erfolg = null, $fehler = null) {
    if ($erfolg !== null) {
        $_SESSION['flash_success'] = $erfolg;
    }
    if ($fehler !== null) {
        $_SESSION['flash_error'] = $fehler;
    }

    header('Location: /admin_system.php', true, 303);
    exit;
}

// Kalender-Feeds pflegen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        zurueck_zur_verwaltung(null, 'Sicherheitsfehler: Die Seite war zu lange geöffnet. Bitte neu laden.');
    }

    if ($_POST['action'] === 'add_calendar_feed') {
        $name = trim((string)($_POST['feed_name'] ?? ''));
        $url  = trim((string)($_POST['feed_url'] ?? ''));

        if ($name === '') {
            zurueck_zur_verwaltung(null, 'Bitte einen Namen für den Kalender angeben.');
        }

        $pruefung = validate_calendar_url($url);
        if (!$pruefung['ok']) {
            zurueck_zur_verwaltung(null, 'Adresse abgelehnt: ' . $pruefung['fehler']);
        }

        $vorhanden = $conn->prepare('SELECT id FROM calendar_feeds WHERE url = ? LIMIT 1');
        $vorhanden->execute([$url]);
        if ($vorhanden->fetch()) {
            zurueck_zur_verwaltung(null, 'Diese Adresse ist bereits eingetragen.');
        }

        // Einmal abrufen, damit sich nicht erst im Kalender zeigt, ob es geht.
        $probe = fetch_calendar_feed($url, 0);

        $conn->prepare('INSERT INTO calendar_feeds (name, url, is_active, last_fetch_at, last_status, last_event_count) VALUES (?, ?, 1, NOW(), ?, ?)')
            ->execute([
                mb_substr($name, 0, 120),
                $url,
                mb_substr($probe['status'], 0, 200),
                count($probe['events']),
            ]);

        if ($probe['status'] === 'OK') {
            zurueck_zur_verwaltung(sprintf('Kalender „%s" hinzugefügt – %d Termine gefunden.', $name, count($probe['events'])));
        }

        zurueck_zur_verwaltung(null, sprintf('Kalender „%s" wurde gespeichert, ist aber nicht abrufbar: %s', $name, $probe['status']));
    }

    if ($_POST['action'] === 'delete_calendar_feed') {
        $stmt = $conn->prepare('DELETE FROM calendar_feeds WHERE id = ?');
        $stmt->execute([(int)($_POST['feed_id'] ?? 0)]);

        zurueck_zur_verwaltung($stmt->rowCount() > 0 ? 'Kalender entfernt.' : null,
                               $stmt->rowCount() > 0 ? null : 'Kalender nicht gefunden.');
    }

    if ($_POST['action'] === 'toggle_calendar_feed') {
        $conn->prepare('UPDATE calendar_feeds SET is_active = 1 - is_active WHERE id = ?')
            ->execute([(int)($_POST['feed_id'] ?? 0)]);

        zurueck_zur_verwaltung('Sichtbarkeit des Kalenders geändert.');
    }

    if ($_POST['action'] === 'test_calendar_feed') {
        $stmt = $conn->prepare('SELECT name, url FROM calendar_feeds WHERE id = ?');
        $stmt->execute([(int)($_POST['feed_id'] ?? 0)]);
        $feed = $stmt->fetch();

        if (!$feed) {
            zurueck_zur_verwaltung(null, 'Kalender nicht gefunden.');
        }

        // cache_time 0 erzwingt einen echten Abruf.
        $probe = fetch_calendar_feed($feed['url'], 0);
        $conn->prepare('UPDATE calendar_feeds SET last_fetch_at = NOW(), last_status = ?, last_event_count = ? WHERE id = ?')
            ->execute([mb_substr($probe['status'], 0, 200), count($probe['events']), (int)$_POST['feed_id']]);

        if ($probe['status'] === 'OK') {
            zurueck_zur_verwaltung(sprintf('„%s" antwortet – %d Termine.', $feed['name'], count($probe['events'])));
        }

        zurueck_zur_verwaltung(null, sprintf('„%s": %s', $feed['name'], $probe['status']));
    }

    zurueck_zur_verwaltung(null, 'Unbekannte Aktion.');
}

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['teacher_csv'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: CSRF Token ungültig.";
        header("Location: /admin_system.php");
        exit;
    }
    
    $file = $_FILES['teacher_csv'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        zurueck_zur_verwaltung(null, 'Die Datei konnte nicht hochgeladen werden.');
    }

    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        zurueck_zur_verwaltung(null, 'Nur .csv-Dateien sind zulässig.');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        zurueck_zur_verwaltung(null, 'Die Datei konnte nicht gelesen werden.');
    }

    $erste_zeile = (string)fgets($handle);
    $trennzeichen = substr_count($erste_zeile, ';') >= substr_count($erste_zeile, ',') ? ';' : ',';
    rewind($handle);

    $kopf = fgetcsv($handle, 4000, $trennzeichen);
    $spalten = csv_spalten_zuordnen(is_array($kopf) ? $kopf : []);

    // Wird die Kopfzeile nicht erkannt, ist sie vermutlich schon die erste
    // Datenzeile - dann von vorn lesen und nach Position zuordnen.
    if ($spalten === null) {
        rewind($handle);
    }

    $hinzugefuegt = 0;
    $uebersprungen = 0;
    $unbrauchbar = 0;

    $stmt = $conn->prepare('INSERT IGNORE INTO teachers (kuerzel, name, email, passwort_hash) VALUES (?, ?, ?, ?)');
    $standard_passwort = password_hash('lehrer', PASSWORD_DEFAULT);

    while (($zeile = fgetcsv($handle, 4000, $trennzeichen)) !== false) {
        if ($zeile === [null] || $zeile === []) {
            continue;   // Leerzeile
        }

        $felder = csv_zeile_lesen($zeile, $spalten);

        if ($felder['kuerzel'] === '' || $felder['name'] === '') {
            $unbrauchbar++;
            continue;
        }

        $stmt->execute([
            mb_substr($felder['kuerzel'], 0, 50),
            mb_substr($felder['name'], 0, 100),
            $felder['email'] !== '' ? mb_substr($felder['email'], 0, 255) : null,
            $standard_passwort,
        ]);

        if ($stmt->rowCount() > 0) {
            $hinzugefuegt++;
        } else {
            $uebersprungen++;
        }
    }
    fclose($handle);

    $meldung = sprintf('Import abgeschlossen: %d hinzugefügt, %d übersprungen (Kürzel schon vorhanden)',
        $hinzugefuegt, $uebersprungen);
    if ($unbrauchbar > 0) {
        $meldung .= sprintf(', %d Zeile(n) ohne Kürzel oder Name', $unbrauchbar);
    }

    zurueck_zur_verwaltung($meldung . '.');
}

/**
 * Ordnet die Spalten der Kopfzeile den Feldern zu.
 *
 * Damit ist die Reihenfolge in der Datei egal - Schulverwaltungen exportieren
 * sie unterschiedlich. Wird keine brauchbare Kopfzeile erkannt, liefert die
 * Funktion null und der Aufrufer geht nach Position vor.
 *
 * @param list<string> $kopf
 * @return array<string,int>|null
 */
function csv_spalten_zuordnen(array $kopf) {
    $bekannt = [
        'kuerzel'  => ['kuerzel', 'kurzel', 'kurzel', 'kz', 'kuerzel/kz', 'lehrerkuerzel', 'krzl'],
        'nachname' => ['nachname', 'familienname', 'name2', 'surname', 'lastname'],
        'vorname'  => ['vorname', 'rufname', 'firstname'],
        'name'     => ['name', 'vollname', 'anzeigename', 'fullname'],
        'email'    => ['email', 'e-mail', 'mail', 'emailadresse', 'e-mailadresse'],
    ];

    $zuordnung = [];
    foreach ($kopf as $i => $bezeichnung) {
        $schluessel = csv_bezeichnung_normalisieren((string)$bezeichnung);
        if ($schluessel === '') {
            continue;
        }

        foreach ($bekannt as $feld => $varianten) {
            if (in_array($schluessel, $varianten, true) && !isset($zuordnung[$feld])) {
                $zuordnung[$feld] = $i;
                break;
            }
        }
    }

    // Ohne Kürzel und ohne irgendeine Namensspalte war das keine Kopfzeile.
    if (!isset($zuordnung['kuerzel'])) {
        return null;
    }
    if (!isset($zuordnung['name']) && !isset($zuordnung['nachname']) && !isset($zuordnung['vorname'])) {
        return null;
    }

    return $zuordnung;
}

/**
 * Vereinheitlicht eine Spaltenbezeichnung: Kleinschreibung, ohne BOM,
 * Umlaute aufgeloest, ohne Leer- und Sonderzeichen ausser Bindestrich.
 */
function csv_bezeichnung_normalisieren($text) {
    $text = str_replace("\xEF\xBB\xBF", '', (string)$text);   // BOM aus Excel
    $text = csv_nach_utf8($text);
    $text = mb_strtolower(trim($text));
    $text = strtr($text, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);

    return (string)preg_replace('/[^a-z0-9\-]/', '', $text);
}

/**
 * Liest eine Datenzeile anhand der Zuordnung - oder nach Position.
 *
 * Nach Position gilt: vier Spalten sind Kürzel, Nachname, Vorname, E-Mail;
 * drei Spalten das aeltere Kürzel, Name, E-Mail.
 *
 * @param list<string|null>       $zeile
 * @param array<string,int>|null  $spalten
 * @return array{kuerzel:string,name:string,email:string}
 */
function csv_zeile_lesen(array $zeile, $spalten) {
    $hole = static function ($index) use ($zeile) {
        return $index === null || !isset($zeile[$index]) ? '' : trim(csv_nach_utf8((string)$zeile[$index]));
    };

    if ($spalten !== null) {
        $kuerzel = $hole($spalten['kuerzel'] ?? null);
        $nachname = $hole($spalten['nachname'] ?? null);
        $vorname = $hole($spalten['vorname'] ?? null);
        $name = $hole($spalten['name'] ?? null);
        $email = $hole($spalten['email'] ?? null);
    } elseif (count($zeile) >= 4) {
        [$kuerzel, $nachname, $vorname, $email] = [$hole(0), $hole(1), $hole(2), $hole(3)];
        $name = '';
    } else {
        [$kuerzel, $name, $email] = [$hole(0), $hole(1), $hole(2)];
        $nachname = $vorname = '';
    }

    // Angezeigt wird "Vorname Nachname" - so steht der Name überall sonst.
    if ($name === '') {
        $name = trim($vorname . ' ' . $nachname);
    }

    $kuerzel = str_replace("\xEF\xBB\xBF", '', $kuerzel);

    return ['kuerzel' => $kuerzel, 'name' => $name, 'email' => $email];
}

/**
 * Wandelt eine Zeichenkette nach UTF-8, falls sie es noch nicht ist.
 *
 * Excel unter Windows speichert CSV-Dateien haeufig als CP1252; ohne diese
 * Umwandlung wuerde aus "Müller" ein "M?ller".
 */
function csv_nach_utf8($text) {
    if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }

    return (string)mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_system.twig', [
    'calendar_feeds' => $conn->query('SELECT * FROM calendar_feeds ORDER BY name ASC')->fetchAll(),
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
