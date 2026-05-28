<?php
/**
 * src/admin_action.php
 * API Endpoint for the Principal to approve or reject requests.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

require_admin(); // Security block

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        header("Location: /admin_dashboard.php?error=csrf");
        exit;
    }

    $request_id = (int)$_POST['id'] ?? 0;
    $request_type = $_POST['type'] ?? '';
    $action = $_POST['action'] ?? '';

    if ($request_id > 0 && in_array($request_type, ['ausflug', 'freistellung']) && in_array($action, ['approve', 'reject', 'query'])) {
        $conn = db_connect();
        $status = 'pending';
        if ($action === 'approve') $status = 'approved';
        elseif ($action === 'reject') $status = 'rejected';
        elseif ($action === 'query') $status = 'query';
        
        $table = ($request_type === 'ausflug') ? 'extracurricular_requests' : 'exemption_requests';
        
        try {
            // First, get teacher email, name, and request details
            $details_query = "";
            if ($table === 'extracurricular_requests') {
                $details_query = "SELECT t.email, t.name as teacher_name, r.class_name, r.event_date, r.event_date_to, r.destination 
                                  FROM extracurricular_requests r 
                                  JOIN teachers t ON r.teacher_id = t.id 
                                  WHERE r.id = ?";
            } else {
                $details_query = "SELECT t.email, t.name as teacher_name, r.date_from, r.date_to, r.reason 
                                  FROM exemption_requests r 
                                  JOIN teachers t ON r.teacher_id = t.id 
                                  WHERE r.id = ?";
            }
            
            $stmt_details = $conn->prepare($details_query);
            $stmt_details->execute([$request_id]);
            $request_details = $stmt_details->fetch(PDO::FETCH_ASSOC);

            if ($table === 'extracurricular_requests' && $action === 'approve') {
                $stmt = $conn->prepare("UPDATE {$table} SET status = ?, modified_after_approval = 0, modified_at = NULL WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
            }
            if ($stmt->execute([$status, $request_id])) {
                $_SESSION['flash_success'] = "Antrag erfolgreich bearbeitet.";
                
                // Send email notification
                if ($request_details && !empty($request_details['email'])) {
                    $status_text = 'unbekannt';
                    if ($status === 'approved') $status_text = 'genehmigt';
                    elseif ($status === 'rejected') $status_text = 'abgelehnt';
                    elseif ($status === 'query') $status_text = 'mit Rückfrage versehen';

                    $request_type_text = ($table === 'extracurricular_requests') ? 'außerunterrichtliche Veranstaltung' : 'Freistellung';
                    
                    $subject = "Update zu deinem Antrag auf $request_type_text";
                    $body = "<p>Hallo {$request_details['teacher_name']},</p>";
                    
                    if ($status === 'query') {
                        $body .= "<p>zu deinem Antrag auf $request_type_text gibt es eine <strong>Rückfrage der Schulleitung</strong>.</p>";
                        $body .= "<p>Bitte halte kurz Rücksprache mit der Schulleitung.</p>";
                    } else {
                        $body .= "<p>dein Antrag auf $request_type_text wurde soeben <strong>{$status_text}</strong>.</p>";
                    }
                    
                    if ($table === 'extracurricular_requests') {
                        $start = date('d.m.Y', strtotime($request_details['event_date']));
                        $end = (!empty($request_details['event_date_to']) && $request_details['event_date_to'] !== $request_details['event_date']) 
                            ? date('d.m.Y', strtotime($request_details['event_date_to'])) 
                            : null;
                        
                        $date_str = $end ? "vom $start bis $end" : "am $start";
                        $body .= "<p>Details: {$request_details['class_name']} nach {$request_details['destination']} $date_str</p>";
                    } else {
                        $body .= "<p>Details: Zeitraum vom " . date('d.m.Y', strtotime($request_details['date_from'])) . " bis " . date('d.m.Y', strtotime($request_details['date_to'])) . "</p>";
                    }
                    
                    $body .= "<p>Viele Grüße,<br>Dein Feedback-System Team</p>";

                    if (!send_notification_email($request_details['email'], $subject, $body)) {
                        $_SESSION['flash_error'] = "Status gespeichert, aber E-Mail konnte nicht gesendet werden.";
                    }
                }
            } else {
                $_SESSION['flash_error'] = "Verarbeitung fehlgeschlagen.";
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
