<?php
/**
 * src/student_homework.php
 * Endpoint for students to submit homework and get AI evaluation.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/twig_setup.php';
require_once __DIR__ . '/includes/AIService.php';

$conn = db_connect();

// Handle secure submission viewing
$view_token = $_GET['view'] ?? '';
if (!empty($view_token)) {
    $stmt = $conn->prepare("
        SELECT s.*, a.title as assignment_title, a.klasse, a.fach, a.description,
               e.student_feedback, e.score, e.error_markers
        FROM homework_submissions s
        JOIN homework_assignments a ON s.assignment_id = a.id
        LEFT JOIN homework_evaluations e ON s.id = e.submission_id
        WHERE s.token = ?
    ");
    $stmt->execute([$view_token]);
    $submission = $stmt->fetch();
    
    if (!$submission) {
        die("Einreichung nicht gefunden oder ungültiger Link.");
    }
    
    require_once __DIR__ . '/includes/twig_setup.php';
    echo $twig->render('student_homework_view.twig', [
        'sub' => $submission,
        'host_url' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]"
    ]);
    exit;
}

$token = $_GET['t'] ?? '';

if (empty($token)) {
    die("Ungültiger Link.");
}

$stmt = $conn->prepare("SELECT * FROM homework_assignments WHERE token = ?");
$stmt->execute([$token]);
$assignment = $stmt->fetch();

if (!$assignment) {
    die("Hausaufgabe nicht gefunden oder Link abgelaufen.");
}

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_name = trim($_POST['student_name'] ?? '');
    
    if (empty($student_name)) {
        $error = "Bitte gib deinen Namen ein.";
    } elseif (!isset($_FILES['homework_image']) || $_FILES['homework_image']['error'] !== UPLOAD_ERR_OK) {
        $error = "Bitte lade ein Bild deiner Hausaufgabe hoch.";
    } else {
        $file = $_FILES['homework_image'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        
        if (!in_array($mimeType, $allowedTypes)) {
            $error = "Nur JPG, PNG oder WEBP Bilder sind erlaubt. Erkannt: " . htmlspecialchars($mimeType);
        } else {
            // Pseudonym generieren
            $pseudonym = 'Student_' . bin2hex(random_bytes(4));
            
            // Upload Verzeichnis sichern
            $uploadDir = __DIR__ . '/public/uploads/homework/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('hw_') . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $relativePath = 'uploads/homework/' . $filename;
                
                // Submission speichern
                $sub_token = bin2hex(random_bytes(16));
                $stmt_sub = $conn->prepare("INSERT INTO homework_submissions (assignment_id, student_name, student_pseudonym, image_path, token) VALUES (?, ?, ?, ?, ?)");
                $stmt_sub->execute([$assignment['id'], $student_name, $pseudonym, $relativePath, $sub_token]);
                $submission_id = $conn->lastInsertId();
                
                // KI Auswertung
                try {
                    $aiService = new \App\Includes\AIService();
                    $contextPath = !empty($assignment['context_image_path']) ? __DIR__ . '/public/' . $assignment['context_image_path'] : null;
                    $eval = $aiService->evaluateHomeworkImage($assignment['description'], $destination, $pseudonym, $contextPath);
                    
                    // Evaluation speichern
                    $error_markers_json = isset($eval['errors']) ? json_encode($eval['errors']) : null;
                    $stmt_eval = $conn->prepare("INSERT INTO homework_evaluations (submission_id, student_feedback, teacher_notes, score, error_markers) VALUES (?, ?, ?, ?, ?)");
                    $stmt_eval->execute([
                        $submission_id, 
                        $eval['student_feedback'] ?? 'Kein Feedback generiert.', 
                        $eval['teacher_notes'] ?? 'Keine Notizen.', 
                        $eval['score'] ?? null,
                        $error_markers_json
                    ]);
                    
                    // Status updaten
                    $conn->prepare("UPDATE homework_submissions SET status = 'evaluated' WHERE id = ?")->execute([$submission_id]);
                    
                    $result = [
                        'student_feedback' => $eval['student_feedback'] ?? 'Kein Feedback generiert.',
                        'score' => $eval['score'] ?? null,
                        'errors' => $eval['errors'] ?? [],
                        'image_path' => $relativePath,
                        'token' => $sub_token
                    ];
                } catch (\Exception $e) {
                    $error = "Fehler bei der KI-Auswertung: " . $e->getMessage();
                }
            } else {
                $error = "Fehler beim Hochladen der Datei.";
            }
        }
    }
}

echo $twig->render('student_homework.twig', [
    'assignment' => $assignment,
    'error' => $error,
    'result' => $result
]);
