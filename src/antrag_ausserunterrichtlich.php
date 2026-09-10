<?php
/**
 * src/antrag_ausserunterrichtlich.php
 * Form Controller for "Antrag auf außerunterrichtliche Veranstaltung"
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/klassen.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_login();

// Run DB migration silently on first load
require_once __DIR__ . '/run_alter_extra.php';

$user_id = get_current_user_id();
$edit_id = $_GET['edit_id'] ?? $_POST['edit_id'] ?? null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } else {
        // Mehrere Klassen sind moeglich. Gespeichert wird eine kommagetrennte
        // Liste in derselben Textspalte wie bisher - genau wie bei den
        // Begleitpersonen ein paar Zeilen weiter unten.
        $class_names = array_values(array_filter(
            array_map('trim', array_map('strval', (array) ($_POST['class_name'] ?? []))),
            static fn (string $k): bool => $k !== ''
        ));
        $class_name = '';
        $event_date = $_POST['event_date'] ?? '';
        $event_date_to = $_POST['event_date_to'] ?? '';
        $destination = trim($_POST['destination'] ?? '');
        $aud_type = $_POST['aud_type'] ?? null;
        $participating_teacher_id = !empty($_POST['participating_teacher_id']) ? $_POST['participating_teacher_id'] : null;
        $role              = trim($_POST['role'] ?? '');
        $companion_select_1= trim($_POST['companion_select_1'] ?? '');
        $companion_extra   = trim($_POST['companion_extra'] ?? '');
        $companion = trim(implode(', ', array_filter([$companion_select_1, $companion_extra])));
        $event_name        = trim($_POST['event_name'] ?? '');
        $costs             = trim($_POST['costs'] ?? '');
        $transport         = trim($_POST['transport'] ?? '');
        $start_time        = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $start_location    = trim($_POST['start_location'] ?? '');
        $return_time       = !empty($_POST['return_time']) ? $_POST['return_time'] : null;
        $return_location   = trim($_POST['return_location'] ?? '');
        $return_trip_arranged = isset($_POST['return_trip_arranged']) ? 1 : 0;
        $supervisors       = trim($_POST['supervisors'] ?? '');
        $consent_form      = in_array($_POST['consent_form'] ?? '', ['ja', 'nein']) ? $_POST['consent_form'] : null;
        $schedule_notified = isset($_POST['schedule_notified']) ? 1 : 0;
        
        if ($class_names === [] || empty($event_date) || empty($event_date_to) || empty($destination)) {
            $_SESSION['flash_error'] = "Bitte füllen Sie alle Pflichtfelder aus (Klasse, Startdatum, Enddatum, Ziel).";
        } else {
            try {
                $conn = db_connect();

                // Nur Klassen, die es wirklich gibt, und in der Reihenfolge
                // der Liste statt in der des Anklickens. Fehlt die Liste -
                // sie gehoert dem Unterrichtsmodul -, wird nicht geprueft,
                // sonst waere das Formular gar nicht mehr abzuschicken.
                $bekannte = array_column(klassen_fuer_formular($conn, (int) $user_id)['alle'], 'name');
                if ($bekannte !== []) {
                    $class_names = array_values(array_intersect($bekannte, $class_names));
                }

                if ($class_names === []) {
                    $_SESSION['flash_error'] = "Bitte mindestens eine Klasse auswählen.";
                    header("Location: /antrag_ausserunterrichtlich.php");
                    exit;
                }

                // Die Spalte fasst 100 Zeichen; alle zwoelf Klassen zusammen
                // sind 48. Der Schnitt ist nur der Gurt.
                $class_name = mb_substr(implode(', ', $class_names), 0, 100);
                
                if ($edit_id) {
                    // Check ownership and current status
                    $stmt_check = $conn->prepare("SELECT status, modified_after_approval FROM extracurricular_requests WHERE id = ? AND teacher_id = ?");
                    $stmt_check->execute([$edit_id, $user_id]);
                    $ext = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($ext) {
                        $is_modified = ($ext['status'] === 'approved') ? 1 : $ext['modified_after_approval'];
                        
                        $stmt = $conn->prepare("
                            UPDATE extracurricular_requests SET
                            role=?, class_name=?, companion=?, event_date=?, event_date_to=?, event_name=?, destination=?, costs=?, transport=?, start_time=?, start_location=?, return_time=?, return_location=?, return_trip_arranged=?, supervisors=?, consent_form=?, schedule_notified=?, aud_type=?, participating_teacher_id=?, modified_after_approval=?, 
                            modified_at = CASE WHEN ? = 1 THEN NOW() ELSE modified_at END
                            WHERE id = ? AND teacher_id = ?
                        ");
                        $stmt->execute([
                            $role, $class_name, $companion, $event_date, $event_date_to, $event_name, $destination,
                            $costs, $transport, $start_time, $start_location, $return_time, $return_location,
                            $return_trip_arranged, $supervisors, $consent_form, $schedule_notified, $aud_type, $participating_teacher_id, $is_modified,
                            $is_modified, $edit_id, $user_id
                        ]);
                        
                        // Notify admin if approved request was modified
                        if ($is_modified && $ext['status'] === 'approved') {
                            require_once __DIR__ . '/includes/mailer.php';
                            $stmt_admin = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'report_email'");
                            $admin_email = $stmt_admin->fetchColumn();
                            
                            if ($admin_email) {
                                $admin_subject = "Änderung an bereits genehmigtem Antrag (ID $edit_id)";
                                $admin_body = "<h3>Achtung: Änderung!</h3>
                                    <p>Der bereits genehmigte Antrag von <strong>" . get_current_user_name() . "</strong> 
                                    für die Veranstaltung <strong>$event_name</strong> (Klasse $class_name) wurde nachträglich geändert.</p>
                                    <p>Bitte überprüfen Sie die Änderungen im Admin-Dashboard.</p>";
                                send_notification_email($admin_email, $admin_subject, $admin_body);
                            }
                        }
                        
                        $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich aktualisiert.";
                    } else {
                        $_SESSION['flash_error'] = "Antrag konnte nicht gefunden werden oder fehlende Berechtigung.";
                    }
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO extracurricular_requests 
                        (teacher_id, role, class_name, companion, event_date, event_date_to, event_name, destination, costs, transport, start_time, start_location, return_time, return_location, return_trip_arranged, supervisors, consent_form, schedule_notified, aud_type, participating_teacher_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user_id, $role, $class_name, $companion, $event_date, $event_date_to, $event_name, $destination,
                        $costs, $transport, $start_time, $start_location, $return_time, $return_location,
                        $return_trip_arranged, $supervisors, $consent_form, $schedule_notified, $aud_type, $participating_teacher_id
                    ]);
                    $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich eingereicht.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert Error (Extracurricular): " . $e->getMessage());
            }
        }
        
        // Post/Redirect/Get pattern
        header("Location: /antrag_ausserunterrichtlich.php");
        exit;
    }
}

$conn = db_connect();

$edit_request = null;
if ($edit_id) {
    $stmt_edit = $conn->prepare("SELECT * FROM extracurricular_requests WHERE id = ? AND teacher_id = ?");
    $stmt_edit->execute([$edit_id, $user_id]);
    $edit_request = $stmt_edit->fetch(PDO::FETCH_ASSOC);
    if (!$edit_request) {
        $_SESSION['flash_error'] = "Antrag konnte nicht geladen werden.";
        header("Location: /meine_antraege.php");
        exit;
    }
    
    // Die gespeicherten Klassen wieder in einzelne Namen, damit die
    // Vorlage die richtigen Kaestchen ankreuzen kann.
    $edit_request['class_names'] = klassen_aus_text($edit_request['class_name'] ?? '');

    // Parse companions to match form field names
    $comps = array_map('trim', explode(',', $edit_request['companion'] ?? ''));
    $edit_request['companion_select_1'] = $comps[0] ?? '';
    $edit_request['companion_extra'] = implode(', ', array_slice($comps, 1));
}

$stmt = $conn->prepare("SELECT class_name, event_date, event_date_to, destination, status, aud_type, participating_teacher_id FROM extracurricular_requests WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

$stmt_teachers = $conn->prepare("SELECT id, name, kuerzel FROM teachers WHERE id != ? ORDER BY name ASC");
$stmt_teachers->execute([$user_id]);
$all_teachers = $stmt_teachers->fetchAll();

// Die Klassen gehoeren dem Unterrichtsmodul. Fehlen sie, bleibt das
// Auswahlfeld leer - das Formular bleibt benutzbar.
$klassen = klassen_fuer_formular($conn, (int) $user_id);
$all_classes = $klassen['alle'];
$selected_class_ids = $klassen['eigene'];

$stmt_teachers_full = $conn->prepare("SELECT id, name, kuerzel FROM teachers ORDER BY name ASC");
$stmt_teachers_full->execute();
$all_teachers_full = $stmt_teachers_full->fetchAll();

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$prefilled_date = $_GET['date'] ?? null;

echo $twig->render('form_ausserunterrichtlich.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'requests' => $requests,
    'edit_request' => $edit_request,
    'all_teachers' => $all_teachers,
    'all_classes' => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'all_teachers_full' => $all_teachers_full,
    'prefilled_date' => $prefilled_date,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
