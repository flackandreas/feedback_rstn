<?php
/**
 * src/profile_action.php
 * Handles user profile updates (like selected classes).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
$user_id = get_current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['flash_error'] = "Sicherheitsfehler: Ungültiger Token.";
        header("Location: /index.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_classes') {
        $selected_classes = $_POST['classes'] ?? [];
        $conn = db_connect();

        try {
            $conn->beginTransaction();

            // Clear old selections
            $stmt_clear = $conn->prepare("DELETE FROM teacher_classes WHERE teacher_id = ?");
            $stmt_clear->execute([$user_id]);

            // Insert new selections
            if (!empty($selected_classes)) {
                $stmt_insert = $conn->prepare("INSERT INTO teacher_classes (teacher_id, class_id) VALUES (?, ?)");
                foreach ($selected_classes as $class_id) {
                    $stmt_insert->execute([$user_id, (int)$class_id]);
                }
            }

            $conn->commit();
            $_SESSION['flash_success'] = "Ihre Klassenauswahl wurde gespeichert.";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['flash_error'] = "Fehler beim Speichern: " . $e->getMessage();
        }
    }

    header("Location: /index.php");
    exit;
}
