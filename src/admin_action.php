<?php
/**
 * src/admin_action.php
 * API Endpoint for the Principal to approve or reject requests.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/mail_texte.php';
require_once __DIR__ . '/includes/entscheidungen.php';

require_admin(); // Security block

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        header("Location: /admin_dashboard.php?error=csrf");
        exit;
    }

    $request_id = (int) ($_POST['id'] ?? 0);
    $request_type = $_POST['type'] ?? '';
    $action = $_POST['action'] ?? '';

    if ($request_id > 0 && in_array($request_type, ['ausflug', 'freistellung']) && in_array($action, ['approve', 'reject', 'query'])) {
        $conn = db_connect();
        $status = 'pending';
        if ($action === 'approve') $status = 'approved';
        elseif ($action === 'reject') $status = 'rejected';
        elseif ($action === 'query') $status = 'query';
        
        $table = ($request_type === 'ausflug') ? 'extracurricular_requests' : 'exemption_requests';

        // Eine Rueckfrage braucht ihren Text (Audit W2). Vorher bekam die
        // Lehrkraft nur "Bitte halte kurz Rücksprache" und musste raten, worum es geht.
        $rueckfrage = null;
        if ($action === 'query') {
            $rueckfrage = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['rueckfrage'] ?? '')));
            if ($rueckfrage === '' || mb_strlen($rueckfrage) > 2000) {
                $_SESSION['flash_error'] = $rueckfrage === ''
                    ? "Bitte die Rückfrage formulieren. Der Antrag ist unverändert."
                    : "Die Rückfrage ist zu lang (höchstens 2000 Zeichen). Der Antrag ist unverändert.";
                header("Location: /admin_dashboard.php");
                exit;
            }
        }

        try {
            // First, get teacher email, name, and request details
            $details_query = "";
            if ($table === 'extracurricular_requests') {
                $details_query = "SELECT r.status, t.email, t.name as teacher_name, r.class_name, r.event_date, r.event_date_to, r.destination 
                                  FROM extracurricular_requests r 
                                  JOIN teachers t ON r.teacher_id = t.id 
                                  WHERE r.id = ?";
            } else {
                $details_query = "SELECT r.status, t.email, t.name as teacher_name, r.date_from, r.date_to, r.reason 
                                  FROM exemption_requests r 
                                  JOIN teachers t ON r.teacher_id = t.id 
                                  WHERE r.id = ?";
            }
            
            $stmt_details = $conn->prepare($details_query);
            $stmt_details->execute([$request_id]);
            $request_details = $stmt_details->fetch(PDO::FETCH_ASSOC);

            if (!$request_details) {
                $_SESSION['flash_error'] = "Antrag nicht gefunden.";
                header("Location: /admin_dashboard.php");
                exit;
            }

            if ($table === 'extracurricular_requests' && $action === 'approve') {
                $stmt = $conn->prepare("UPDATE {$table} SET status = ?, modified_after_approval = 0, modified_at = NULL WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
            }

            // Entscheidung und Protokolleintrag (Audit M3) gelten nur zusammen.
            $conn->beginTransaction();
            try {
                $stmt->execute([$status, $request_id]);
                entscheidung_protokollieren($conn, $table, $request_id, $request_details['status'], $status, $rueckfrage);
                $conn->commit();
            } catch (PDOException $e) {
                $conn->rollBack();
                throw $e;
            }

            $_SESSION['flash_success'] = "Antrag erfolgreich bearbeitet.";

            // Send email notification
            if (!empty($request_details['email'])) {
                $request_type_text = ($table === 'extracurricular_requests') ? 'außerunterrichtliche Veranstaltung' : 'Freistellung';

                $subject = $status === 'query'
                    ? "Rückfrage zu deinem Antrag auf $request_type_text"
                    : "Update zu deinem Antrag auf $request_type_text";
                $body = mail_text_entscheidung($status, $table, $request_details, $rueckfrage, entscheidung_bearbeiter());

                if (!send_notification_email($request_details['email'], $subject, $body)) {
                    $_SESSION['flash_error'] = "Status gespeichert, aber E-Mail konnte nicht gesendet werden.";
                }
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Datenbankfehler bei der Bearbeitung.";
            error_log("Admin Action DB Error: " . $e->getMessage());
        }
    } else {
        $_SESSION['flash_error'] = "Ungültige Parameter.";
    }
}

header("Location: /admin_dashboard.php");
exit;
?>
