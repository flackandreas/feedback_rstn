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
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                // Determine delimiter by reading first line
                $first_line = fgets($handle);
                $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
                rewind($handle);
                
                // Skip header row
                fgetcsv($handle, 1000, $delimiter);
                
                $success_count = 0;
                $skip_count = 0;
                
                $stmt = $conn->prepare("INSERT IGNORE INTO teachers (kuerzel, name, email, passwort_hash) VALUES (?, ?, ?, ?)");
                $default_pw_hash = password_hash('lehrer', PASSWORD_DEFAULT);
                
                while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
                    if (count($data) >= 2) {
                        $kuerzel = trim($data[0]);
                        $name = trim($data[1]);
                        $email = isset($data[2]) ? trim($data[2]) : null;
                        
                        if (!empty($kuerzel) && !empty($name)) {
                            if ($email === '') $email = null;
                            
                            $stmt->execute([$kuerzel, $name, $email, $default_pw_hash]);
                            if ($stmt->rowCount() > 0) {
                                $success_count++;
                            } else {
                                $skip_count++;
                            }
                        }
                    }
                }
                fclose($handle);
                $_SESSION['flash_success'] = "Import abgeschlossen: $success_count hinzugefügt, $skip_count übersprungen.";
            } else {
                $_SESSION['flash_error'] = "Fehler beim Lesen der Datei.";
            }
        } else {
            $_SESSION['flash_error'] = "Nur .csv Dateien erlaubt.";
        }
    } else {
        $_SESSION['flash_error'] = "Upload-Fehler.";
    }
    header("Location: /admin_system.php");
    exit;
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
