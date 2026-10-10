<?php
/**
 * src/antrag_ausserunterrichtlich.php
 * Form Controller for "Antrag auf außerunterrichtliche Veranstaltung"
 *
 * Das Formular folgt dem amtlichen Vordruck LBV 1211 (Stand 07/20). Welche
 * Angaben der Vordruck verlangt und wie sie geprueft werden, steht in
 * includes/lbv1211.php - der PDF-Ausdruck braucht dieselben Regeln.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/klassen.php';
require_once __DIR__ . '/includes/lbv1211.php';
require_once __DIR__ . '/includes/twig_setup.php';

require_login();

// Frueher lief hier bei jedem Aufruf run_alter_extra.php und baute die
// Tabelle um. Die Spalten spielt laengst die regulaere Migration ein
// (includes/migrations.php, alter_extracurricular.sql, alter_lbv1211.sql).

$user_id = (int) get_current_user_id();
$edit_id = $_GET['edit_id'] ?? $_POST['edit_id'] ?? null;
$edit_id = ($edit_id !== null && ctype_digit((string) $edit_id)) ? (int) $edit_id : null;

// Zurueck ins Formular - bei einer Aenderung zu demselben Antrag.
$formular = '/antrag_ausserunterrichtlich.php' . ($edit_id ? '?edit_id=' . $edit_id : '');

/** Alle Konten nach ID, fuer die Begleitpersonen. */
function kollegium_nach_id(PDO $conn): array
{
    $kollegium = [];
    foreach ($conn->query('SELECT id, name, kuerzel FROM teachers ORDER BY name ASC') as $t) {
        $kollegium[(int) $t['id']] = $t;
    }

    return $kollegium;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } else {
        // Mehrere Klassen sind moeglich. Gespeichert wird eine kommagetrennte
        // Liste in derselben Textspalte wie bisher.
        $class_names = array_values(array_filter(
            array_map('trim', array_map('strval', (array) ($_POST['class_name'] ?? []))),
            static fn (string $k): bool => $k !== ''
        ));
        $eingabe = lbv_eingabe_lesen($_POST);

        try {
            $conn = db_connect();

            // Nur Klassen, die es wirklich gibt, und in der Reihenfolge
            // der Liste statt in der des Anklickens. Fehlt die Liste -
            // sie gehoert dem Unterrichtsmodul -, wird nicht geprueft,
            // sonst waere das Formular gar nicht mehr abzuschicken.
            $bekannte = array_column(klassen_fuer_formular($conn, $user_id)['alle'], 'name');
            if ($bekannte !== []) {
                $class_names = array_values(array_intersect($bekannte, $class_names));
            }

            $fehler = lbv_eingabe_pruefen($eingabe);
            if ($class_names === []) {
                array_unshift($fehler, 'Bitte mindestens eine Klasse auswählen.');
            }

            if ($fehler !== []) {
                // Die Eingaben bleiben stehen. Bei rund dreissig Feldern waere
                // ein leeres Formular nach einem Tippfehler eine Zumutung.
                $_SESSION['flash_error'] = implode(' ', $fehler);
                $_SESSION['antrag_entwurf'] = [
                    'edit_id' => $edit_id,
                    'werte' => $eingabe + ['class_names' => $class_names],
                ];
                header("Location: $formular");
                exit;
            }

            unset($_SESSION['antrag_entwurf']);

            $kollegium = kollegium_nach_id($conn);
            $begleitung = array_values(array_filter(
                lbv_begleitpersonen_abgleichen($eingabe['begleitpersonen'], $kollegium),
                static fn (array $p): bool => $p['nachname'] !== ''
            ));

            // Die Spalte class_name fasst 100 Zeichen; alle zwoelf Klassen
            // zusammen sind 48. Der Schnitt ist nur der Gurt.
            $spalten = lbv_spalten($eingabe)
                + ['class_name' => mb_substr(implode(', ', $class_names), 0, 100)]
                + lbv_begleitung_altfelder($begleitung, $kollegium);

            // Die Personalnummer wandert ins Profil, damit sie beim naechsten
            // Antrag schon dasteht.
            $profil = $conn->prepare("UPDATE teachers SET lbv_personalnummer = ? WHERE id = ?");

            if ($edit_id) {
                $conn->beginTransaction();

                // Check ownership and current status
                $stmt_check = $conn->prepare("SELECT status, modified_after_approval FROM extracurricular_requests WHERE id = ? AND teacher_id = ? FOR UPDATE");
                $stmt_check->execute([$edit_id, $user_id]);
                $ext = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if ($ext) {
                    $is_modified = ($ext['status'] === 'approved') ? 1 : (int) $ext['modified_after_approval'];
                    $spalten['modified_after_approval'] = $is_modified;

                    // Die Spaltennamen stammen aus lbv_spalten(), nicht aus der Anfrage.
                    $setzen = implode(', ', array_map(static fn (string $s): string => "$s = ?", array_keys($spalten)));
                    $stmt = $conn->prepare("
                        UPDATE extracurricular_requests SET $setzen,
                        modified_at = CASE WHEN ? = 1 THEN NOW() ELSE modified_at END
                        WHERE id = ? AND teacher_id = ?
                    ");
                    $stmt->execute([...array_values($spalten), $is_modified, $edit_id, $user_id]);
                    lbv_begleitpersonen_speichern($conn, $edit_id, $begleitung);
                    $profil->execute([$eingabe['lbv_personalnummer'], $user_id]);
                    $conn->commit();

                    // Notify admin if approved request was modified
                    if ($is_modified && $ext['status'] === 'approved') {
                        require_once __DIR__ . '/includes/mailer.php';
                        require_once __DIR__ . '/includes/mail_texte.php';
                        $stmt_admin = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'report_email'");
                        $admin_email = $stmt_admin->fetchColumn();

                        if ($admin_email) {
                            $admin_subject = "Änderung an bereits genehmigtem Antrag (ID " . $edit_id . ")";
                            $admin_body = mail_text_aenderung_nach_genehmigung((string) get_current_user_name(), (string) $spalten['event_name'], (string) $spalten['class_name']);
                            send_notification_email($admin_email, $admin_subject, $admin_body);
                        }
                    }

                    $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich aktualisiert.";
                } else {
                    $conn->rollBack();
                    $_SESSION['flash_error'] = "Antrag konnte nicht gefunden werden oder fehlende Berechtigung.";
                }
            } else {
                $spalten = ['teacher_id' => $user_id] + $spalten;

                $conn->beginTransaction();
                $stmt = $conn->prepare(
                    "INSERT INTO extracurricular_requests (" . implode(', ', array_keys($spalten)) . ")
                     VALUES (" . implode(', ', array_fill(0, count($spalten), '?')) . ")"
                );
                $stmt->execute(array_values($spalten));
                lbv_begleitpersonen_speichern($conn, (int) $conn->lastInsertId(), $begleitung);
                $profil->execute([$eingabe['lbv_personalnummer'], $user_id]);
                $conn->commit();

                $_SESSION['flash_success'] = "Ihr Antrag wurde erfolgreich eingereicht.";
            }
        } catch (PDOException $e) {
            if (isset($conn) && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['flash_error'] = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
            error_log("DB Insert Error (Extracurricular): " . $e->getMessage());
        }
    }

    // Post/Redirect/Get pattern
    header("Location: /antrag_ausserunterrichtlich.php");
    exit;
}

$conn = db_connect();
$kollegium = kollegium_nach_id($conn);

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
}

// Was das Formular zeigt: beim Bearbeiten der gespeicherte Antrag, sonst
// die Vorbelegung aus dem Profil. Name und Vorname kommen aus dem Konto -
// sie stehen dort als ein Feld und werden am letzten Leerzeichen geteilt.
$stmt_profil = $conn->prepare("SELECT lbv_personalnummer FROM teachers WHERE id = ?");
$stmt_profil->execute([$user_id]);
$vorbelegung = [
    'lbv_personalnummer' => (string) $stmt_profil->fetchColumn(),
    'lk_nachname' => '',
    'lk_vorname' => '',
];
$geteilt = lbv_name_teilen((string) ($kollegium[$user_id]['name'] ?? get_current_user_name()));
$vorbelegung['lk_nachname'] = $geteilt['nachname'];
$vorbelegung['lk_vorname'] = $geteilt['vorname'];

if ($edit_request) {
    $werte = $edit_request;
    // Die gespeicherten Klassen wieder in einzelne Namen, damit die
    // Vorlage die richtigen Eintraege auswaehlen kann.
    $werte['class_names'] = klassen_aus_text($edit_request['class_name'] ?? '');
    // Antraege von vor der Umstellung haben noch keine Begleitpersonen-Zeilen.
    $werte['begleitpersonen'] = lbv_begleitpersonen_laden($conn, [$edit_id])[$edit_id]
        ?? lbv_begleitpersonen_aus_altfeldern(
            $edit_request['participating_teacher_id'] !== null ? (int) $edit_request['participating_teacher_id'] : null,
            (string) ($edit_request['companion'] ?? ''),
            $kollegium
        );
    // Dort fehlen auch Name und Personalnummer - dann wie bei einem neuen Antrag.
    foreach ($vorbelegung as $feld => $wert) {
        if (trim((string) ($werte[$feld] ?? '')) === '') {
            $werte[$feld] = $wert;
        }
    }
} else {
    $tag = (string) ($_GET['date'] ?? '');
    $tag = lbv_datum_gueltig($tag) ? $tag : '';
    $werte = $vorbelegung + ['event_date' => $tag, 'event_date_to' => $tag, 'begleitpersonen' => []];
}

// Nach einem Fehler beim Absenden: die Eingaben von eben.
$entwurf = $_SESSION['antrag_entwurf'] ?? null;
unset($_SESSION['antrag_entwurf']);
if (is_array($entwurf) && ($entwurf['edit_id'] ?? null) === $edit_id) {
    $werte = $entwurf['werte'] + $werte;
}

foreach (['start_time', 'ankunft_zeit', 'abfahrt_zeit', 'return_time'] as $feld) {
    $werte[$feld] = lbv_zeit($werte[$feld] ?? '');
}
$leere_zeile = ['teacher_id' => null, 'nachname' => '', 'vorname' => '', 'in_ausbildung' => 0];
$begleitpersonen = array_pad(array_values($werte['begleitpersonen']), LBV_BEGLEITPERSONEN_MAX, $leere_zeile);

$stmt = $conn->prepare("SELECT id, class_name, event_date, event_date_to, destination, status FROM extracurricular_requests WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll();

// Die Klassen gehoeren dem Unterrichtsmodul. Fehlen sie, bleibt das
// Auswahlfeld leer - das Formular bleibt benutzbar.
$klassen = klassen_fuer_formular($conn, $user_id);
$all_classes = $klassen['alle'];
$selected_class_ids = $klassen['eigene'];

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('form_ausserunterrichtlich.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'requests' => $requests,
    'edit_request' => $edit_request,
    'werte' => $werte,
    'begleitpersonen' => $begleitpersonen,
    // Begleitperson kann jede Lehrkraft sein, nur nicht die antragstellende.
    'kollegium' => array_values(array_filter($kollegium, static fn (array $t): bool => (int) $t['id'] !== $user_id)),
    'veranstaltungsarten' => LBV_VERANSTALTUNGSARTEN,
    'all_classes' => $all_classes,
    'selected_class_ids' => $selected_class_ids,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
