<?php
/**
 * src/admin_klassen.php
 * Interface for administrators to manage classes.
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
            $name = trim($_POST['name'] ?? '');
            
            if (empty($name)) {
                $_SESSION['flash_error'] = "Der Klassenname darf nicht leer sein.";
            } else {
                try {
                    if ($action === 'create') {
                        $stmt = $conn->prepare("INSERT INTO classes (name) VALUES (?)");
                        $stmt->execute([$name]);
                        $_SESSION['flash_success'] = "Klasse '$name' erfolgreich angelegt.";
                    } elseif ($action === 'update' && $id > 0) {
                        $stmt = $conn->prepare("UPDATE classes SET name = ? WHERE id = ?");
                        $stmt->execute([$name, $id]);
                        $_SESSION['flash_success'] = "Klasse '$name' erfolgreich aktualisiert.";
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) { // Integrity constraint violation
                        $_SESSION['flash_error'] = "Diese Klasse existiert bereits.";
                    } else {
                        $_SESSION['flash_error'] = "Fehler beim Speichern: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['flash_success'] = "Klasse erfolgreich gelöscht.";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Fehler beim Löschen der Klasse.";
                }
            }
        }
    }
    
    header("Location: /admin_klassen.php");
    exit;
}

$stmt = $conn->prepare("SELECT id, name, created_at FROM classes ORDER BY name ASC");
$stmt->execute();
$classes = $stmt->fetchAll();

$csrf_token = get_csrf_token();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo $twig->render('admin_klassen.twig', [
    'csrf_token' => $csrf_token,
    'flash_success' => $flash_success,
    'flash_error' => $flash_error,
    'classes' => $classes,
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
?>
