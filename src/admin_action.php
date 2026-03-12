<?php
/**
 * src/admin_action.php
 * API Endpoint for the Principal to approve or reject requests.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

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

    if ($request_id > 0 && in_array($request_type, ['ausflug', 'freistellung']) && in_array($action, ['approve', 'reject'])) {
        $conn = db_connect();
        $status = ($action === 'approve') ? 'approved' : 'rejected';
        
        $table = ($request_type === 'ausflug') ? 'extracurricular_requests' : 'exemption_requests';
        
        try {
            $stmt = $conn->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
            if ($stmt->execute([$status, $request_id])) {
                $_SESSION['flash_success'] = "Antrag erfolgreich bearbeitet.";
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
