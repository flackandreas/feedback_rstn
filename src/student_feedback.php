<?php
/**
 * src/student_feedback.php
 * Student interface for giving anonymous emoji feedback.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php'; // For CSRF protection if needed, though we'll keep it simple for students

$token = $_GET['t'] ?? '';
$conn = db_connect();

// 1. Verify token
$stmt = $conn->prepare("SELECT * FROM feedback_sessions WHERE token = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
$stmt->execute([$token]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    die("Ungültiger oder abgelaufener Feedback-Link. Bitte fragen Sie Ihre Lehrkraft.");
}

// 2. Check for "already voted" cookie for this specific session
$voted_cookie = "voted_" . $session['id'];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_COOKIE[$voted_cookie])) {
        $error = "Du hast für diese Stunde bereits abgestimmt. Vielen Dank!";
    } else {
        $lesson_score = (int)($_POST['lesson_score'] ?? 3);
        $climate_score = (int)($_POST['climate_score'] ?? 3);

        // Sanitize scores
        $lesson_score = max(1, min(5, $lesson_score));
        $climate_score = max(1, min(5, $climate_score));

        // Insert responses
        $stmt_ins = $conn->prepare("INSERT INTO feedback_responses (session_id, category, score) VALUES (?, 'lesson', ?), (?, 'climate', ?)");
        $stmt_ins->execute([$session['id'], $lesson_score, $session['id'], $climate_score]);

        // Set cookie to prevent double voting (expires in 1 hour)
        setcookie($voted_cookie, "1", time() + 3600, "/");
        $success = true;
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schüler-Feedback</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4a90e2;
            --bg: #f5f7fa;
            --card: #ffffff;
            --text: #333;
            --success: #27ae60;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .feedback-card {
            background: var(--card);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        h1 { font-size: 1.5rem; margin-bottom: 5px; }
        .subtitle { color: #888; margin-bottom: 25px; font-size: 0.9rem; }
        
        .question-box {
            margin-bottom: 30px;
            text-align: left;
        }
        .question-label {
            font-weight: 600;
            display: block;
            margin-bottom: 15px;
        }
        .emoji-group {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
        .emoji-item {
            flex: 1;
            text-align: center;
        }
        .emoji-item input {
            display: none;
        }
        .emoji-item label {
            font-size: 1.8rem;
            cursor: pointer;
            padding: 10px 5px;
            border-radius: 8px;
            display: block;
            transition: all 0.2s;
            filter: grayscale(100%);
            opacity: 0.4;
        }
        .emoji-item input:checked + label {
            filter: grayscale(0%);
            opacity: 1;
            background: rgba(74,144,226, 0.1);
            transform: scale(1.1);
        }
        
        .btn-send {
            background: var(--primary);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            transition: background 0.2s;
        }
        .btn-send:hover { background: #357abd; }
        
        .success-msg { color: var(--success); }
    </style>
</head>
<body>

<div class="feedback-card">
    <?php if ($success): ?>
        <div style="font-size: 4rem; margin-bottom: 20px;">🎉</div>
        <h2 class="success-msg">Vielen Dank!</h2>
        <p>Dein Feedback wurde anonym gespeichert.</p>
        <p style="font-size: 0.8rem; color: #888; margin-top: 20px;">Du kannst dieses Fenster jetzt schließen.</p>
    <?php elseif (isset($error)): ?>
         <div style="font-size: 4rem; margin-bottom: 20px;">🙌</div>
         <h2>Schon erledigt!</h2>
         <p><?php echo $error; ?></p>
    <?php else: ?>
        <h1>Schüler-Feedback</h1>
        <p class="subtitle"><?php echo htmlspecialchars($session['klasse'] . " - " . $session['fach']); ?></p>
        
        <form method="POST">
            <div class="question-box">
                <span class="question-label">Wie war die heutige Stunde?</span>
                <div class="emoji-group">
                    <?php 
                    $emojis = ['😫', '🙁', '😐', '🙂', '😄'];
                    foreach($emojis as $i => $emoji): $val = $i + 1; ?>
                        <div class="emoji-item">
                            <input type="radio" name="lesson_score" id="l<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $val == 3 ? 'checked' : ''; ?>>
                            <label for="l<?php echo $val; ?>"><?php echo $emoji; ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="question-box">
                <span class="question-label">Wie ist aktuell das Klassenklima?</span>
                <div class="emoji-group">
                    <?php 
                    foreach($emojis as $i => $emoji): $val = $i + 1; ?>
                        <div class="emoji-item">
                            <input type="radio" name="climate_score" id="c<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $val == 3 ? 'checked' : ''; ?>>
                            <label for="c<?php echo $val; ?>"><?php echo $emoji; ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <button type="submit" class="btn-send">Feedback senden</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
