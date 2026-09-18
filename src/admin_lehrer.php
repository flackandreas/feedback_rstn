<?php
/**
 * src/admin_lehrer.php
 * Interface for administrators to manage teachers.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_admin();

$conn = db_connect();
$flash_success = null;
$flash_error = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $kuerzel = trim($_POST['kuerzel'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $is_admin = isset($_POST['is_admin']) ? 1 : 0;
            $password = $_POST['password'] ?? '';
            
            if (empty($kuerzel) || empty($name)) {
                $_SESSION['flash_error'] = "Name und Kürzel sind Pflichtfelder.";
            } else {
                try {
                    if ($action === 'create') {
                        if (empty($password)) {
                            $_SESSION['flash_error'] = "Für neue Lehrkräfte muss ein Passwort vergeben werden.";
                        } elseif (mb_strlen($password) < 8) {
                            $_SESSION['flash_error'] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
                        } else {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            // Wechselzwang: die Verwaltung kennt dieses Passwort,
                            // sie hat es gerade selbst eingetippt.
                            $stmt = $conn->prepare("INSERT INTO teachers (kuerzel, name, email, passwort_hash, is_admin, force_password_change) VALUES (?, ?, ?, ?, ?, 1)");
                            $stmt->execute([$kuerzel, $name, $email, $hash, $is_admin]);
                            $_SESSION['flash_success'] = "Lehrkraft angelegt. Das Passwort muss beim ersten Anmelden gewechselt werden.";
                        }
                    } elseif ($action === 'update' && $id > 0) {
                        if (!empty($password) && mb_strlen($password) < 8) {
                            $_SESSION['flash_error'] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
                        } elseif (!empty($password)) {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            // Ein zurueckgesetztes Passwort kennt die Verwaltung -
                            // ausser man setzt das eigene, dann waere der Zwang nur laestig.
                            $wechsel = $id === (int)get_current_user_id() ? 0 : 1;
                            $stmt = $conn->prepare("UPDATE teachers SET kuerzel = ?, name = ?, email = ?, passwort_hash = ?, is_admin = ?, force_password_change = ? WHERE id = ?");
                            $stmt->execute([$kuerzel, $name, $email, $hash, $is_admin, $wechsel, $id]);
                            $_SESSION['flash_success'] = "Lehrkraft erfolgreich aktualisiert.";
                        } else {
                            $stmt = $conn->prepare("UPDATE teachers SET kuerzel = ?, name = ?, email = ?, is_admin = ? WHERE id = ?");
                            $stmt->execute([$kuerzel, $name, $email, $is_admin, $id]);
                            $_SESSION['flash_success'] = "Lehrkraft erfolgreich aktualisiert.";
                        }
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate entry)
                        $_SESSION['flash_error'] = "Das Kürzel wird bereits verwendet.";
                    } else {
                        $_SESSION['flash_error'] = "Fehler beim Speichern: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === get_current_user_id()) {
                $_SESSION['flash_error'] = "Sie können sich nicht selbst löschen.";
            } elseif ($id > 0) {
                try {
                    $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['flash_success'] = "Lehrkraft erfolgreich gelöscht.";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Fehler beim Löschen. Eventuell gibt es noch verknüpfte Anträge.";
                }
            }
        } elseif ($action === 'import_csv') {
            // Am Portal gehoeren die Konten dorthin - siehe admin_system.php.
            if (sso_aktiv()) {
                $_SESSION['flash_error'] = 'Konten werden am Portal verwaltet. Dort einlesen – hier entsteht der Eintrag bei der ersten Anmeldung von selbst.';
            } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                $fileName = $_FILES['csv_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if ($fileExtension !== 'csv') {
                    $_SESSION['flash_error'] = "Bitte laden Sie nur CSV-Dateien hoch.";
                } else {
                    $handle = fopen($fileTmpPath, 'r');
                    if ($handle !== false) {
                        $imported = 0;
                        $skipped = 0;
                        $isFirstRow = true;
                        
                        $zugaenge = [];

                        // Je Konto ein eigenes Zufallspasswort mit Wechselzwang.
                        // Vorher bekam jede importierte Lehrkraft dasselbe, im
                        // Quelltext stehende "Start123!".
                        $stmtCheck = $conn->prepare("SELECT id FROM teachers WHERE kuerzel = ?");
                        $stmtInsert = $conn->prepare("INSERT INTO teachers (kuerzel, name, email, passwort_hash, is_admin, force_password_change) VALUES (?, ?, ?, ?, 0, 1)");

                        while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                            if ($isFirstRow) {
                                $isFirstRow = false;
                                continue;
                            }
                            
                            if (count($row) >= 2) {
                                $kuerzel = trim($row[0]);
                                $name = trim($row[1]);
                                $email = isset($row[2]) ? trim($row[2]) : '';
                                
                                if (!empty($kuerzel) && !empty($name)) {
                                    $stmtCheck->execute([$kuerzel]);
                                    if ($stmtCheck->rowCount() == 0) {
                                        $passwort = erstes_passwort();
                                        $stmtInsert->execute([$kuerzel, $name, $email, password_hash($passwort, PASSWORD_DEFAULT)]);
                                        $imported++;
                                        $zugaenge[] = ['kuerzel' => $kuerzel, 'name' => $name, 'passwort' => $passwort];
                                    } else {
                                        $skipped++;
                                    }
                                }
                            }
                        }
                        fclose($handle);

                        // Genau einmal anzeigen - ohne diese Liste kommt
                        // niemand in sein neues Konto.
                        $_SESSION['neue_zugaenge'] = $zugaenge;
                        $_SESSION['flash_success'] = "Import abgeschlossen: $imported neu angelegt, $skipped übersprungen (bereits vorhanden).";
                    } else {
                        $_SESSION['flash_error'] = "Fehler beim Lesen der CSV-Datei.";
                    }
                }
            } else {
                $_SESSION['flash_error'] = "Bitte wählen Sie eine gültige Datei aus.";
            }
        }
    }
    
    // Post/Redirect/Get
    header("Location: /admin_lehrer.php");
    exit;
}

// Fetch all teachers
$stmt = $conn->prepare("SELECT id, kuerzel, name, email, is_admin, created_at FROM teachers ORDER BY name ASC");
$stmt->execute();
$teachers = $stmt->fetchAll();

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
$neue_zugaenge = $_SESSION['neue_zugaenge'] ?? [];
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['neue_zugaenge']);

echo $twig->render('admin_lehrer.twig', [
    'csrf_token' => $csrf_token,
    'neue_zugaenge' => $neue_zugaenge,
    'portal_aktiv' => sso_aktiv(),
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'teachers' => $teachers,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
?>
