<?php
/**
 * src/antrag_ausserunterrichtlich.php
 * Form for "Antrag auf außerunterrichtliche Veranstaltung"
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$user_id = get_current_user_id();
$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $error_msg = "Sicherheitsfehler: Ungültiger Token. Bitte laden Sie die Seite neu.";
    } else {
        $class_name = trim($_POST['class_name'] ?? '');
        $event_date = $_POST['event_date'] ?? '';
        $destination = trim($_POST['destination'] ?? '');
        
        if (empty($class_name) || empty($event_date) || empty($destination)) {
            $error_msg = "Bitte füllen Sie alle Pflichtfelder aus.";
        } else {
            try {
                $conn = db_connect();
                $stmt = $conn->prepare("INSERT INTO extracurricular_requests (teacher_id, class_name, event_date, destination) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $class_name, $event_date, $destination]);
                
                $success_msg = "Ihr Antrag wurde erfolgreich eingereicht.";
            } catch (PDOException $e) {
                $error_msg = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert Error (Extracurricular): " . $e->getMessage());
            }
        }
    }
}

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Antrag auf außerunterrichtliche Veranstaltung</h2>
    <a href="/index.php" class="button-secondary" style="text-decoration:none;">&larr; Zurück</a>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="status success"><?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="status error"><?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<div class="content-box">
    <form method="POST" action="/antrag_ausserunterrichtlich.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="form-grid-2">
            <div class="form-group">
                <label for="class_name">Klasse(n) *</label>
                <input type="text" id="class_name" name="class_name" required placeholder="z.B. 8a, 8b">
            </div>

            <div class="form-group">
                <label for="event_date">Datum der Veranstaltung *</label>
                <input type="date" id="event_date" name="event_date" required>
            </div>
        </div>

        <div class="form-group" style="margin-top: 15px;">
            <label for="destination">Reiseziel / Ort *</label>
            <input type="text" id="destination" name="destination" required placeholder="Wohin geht der Ausflug?">
        </div>

        <div style="margin-top: 25px; text-align: right;">
            <button type="submit" class="button-primary">Antrag einreichen</button>
        </div>
    </form>
</div>

<!-- History log preview -->
<div class="content-box">
    <h3>Ihre letzten Anträge</h3>
    <?php
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT class_name, event_date, destination, status FROM extracurricular_requests WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();

    if (empty($requests)) {
        echo "<p style='color: var(--text-muted);'>Noch keine Anträge gestellt.</p>";
    } else {
        echo "<table class='data-table simple'>
                <thead>
                    <tr>
                        <th>Klasse</th>
                        <th>Ziel</th>
                        <th>Datum</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>";
        foreach ($requests as $r) {
            $statusColor = $r['status'] == 'pending' ? 'var(--warning-color)' : ($r['status'] == 'approved' ? 'var(--success-color)' : 'var(--danger-color)');
            echo "<tr>
                    <td>".htmlspecialchars($r['class_name'])."</td>
                    <td>".htmlspecialchars($r['destination'])."</td>
                    <td>".htmlspecialchars(date('d.m.Y', strtotime($r['event_date'])))."</td>
                    <td><span style='color: {$statusColor}; font-weight: 600;'>".strtoupper($r['status'])."</span></td>
                  </tr>";
        }
        echo "</tbody></table>";
    }
    ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
