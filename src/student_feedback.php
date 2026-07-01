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

// 2. Fetch questions for this session
$stmt_q = $conn->prepare("SELECT * FROM feedback_questions WHERE session_id = ? ORDER BY sort_order ASC");
$stmt_q->execute([$session['id']]);
$questions = $stmt_q->fetchAll(PDO::FETCH_ASSOC);

// 3. Check for "already voted" cookie for this specific session
$voted_cookie = "voted_" . $session['id'];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_COOKIE[$voted_cookie])) {
        $error = "Du hast für diese Stunde bereits abgestimmt. Vielen Dank!";
    } else {
        $scores = $_POST['scores'] ?? [];
        
        $stmt_ins = $conn->prepare("INSERT INTO feedback_responses (session_id, question_id, score) VALUES (?, ?, ?)");
        
        foreach ($questions as $q) {
            $q_id = $q['id'];
            $score = isset($scores[$q_id]) ? (int)$scores[$q_id] : 3;
            $score = max(1, min(5, $score));
            
            $stmt_ins->execute([$session['id'], $q_id, $score]);
        }

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
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .emoji-item label {
            font-size: 2rem;
            cursor: pointer;
            padding: 10px 5px;
            border-radius: 8px;
            display: block;
            transition: all 0.2s;
            filter: grayscale(100%);
            opacity: 0.7;
        }
        .emoji-item label:hover {
            filter: grayscale(30%);
            opacity: 0.9;
            background: rgba(74, 144, 226, 0.05);
        }
        .emoji-item input:checked + label {
            filter: grayscale(0%);
            opacity: 1;
            background: rgba(74, 144, 226, 0.15);
            transform: scale(1.15);
            box-shadow: 0 4px 10px rgba(74, 144, 226, 0.15);
        }
        .emoji-item input:focus-visible + label {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
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

        /* Screen reader utility */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
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
            <?php 
            $emoji_labels = [
                1 => "1 von 5 Sterne (Sehr unzufrieden)",
                2 => "2 von 5 Sterne (Unzufrieden)",
                3 => "3 von 5 Sterne (Neutral)",
                4 => "4 von 5 Sterne (Zufrieden)",
                5 => "5 von 5 Sterne (Sehr zufrieden)"
            ];
            foreach ($questions as $q): ?>
                <fieldset class="question-box" style="border: none; padding: 0; margin: 0 0 30px 0;">
                    <legend class="question-label" style="font-weight: 600; display: block; margin-bottom: 15px; padding: 0; font-size: 1rem; color: var(--text);">
                        <?php echo htmlspecialchars($q['question_text']); ?>
                    </legend>
                    <div class="emoji-group">
                        <?php 
                        $emojis = ['😫', '🙁', '😐', '🙂', '😄'];
                        foreach($emojis as $i => $emoji): $val = $i + 1; ?>
                            <div class="emoji-item">
                                <input type="radio" name="scores[<?php echo $q['id']; ?>]" id="q<?php echo $q['id']; ?>_<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $val == 3 ? 'checked' : ''; ?>>
                                <label for="q<?php echo $q['id']; ?>_<?php echo $val; ?>">
                                    <span class="sr-only"><?php echo $emoji_labels[$val]; ?></span>
                                    <span aria-hidden="true"><?php echo $emoji; ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            
            <button type="submit" class="btn-send">Feedback senden</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
