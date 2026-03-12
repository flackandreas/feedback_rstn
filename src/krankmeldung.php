<?php
/**
 * src/krankmeldung.php
 * Form for "Krankmeldung"
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
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($date_from) || empty($date_to)) {
            $error_msg = "Bitte füllen Sie den Zeitraum der Krankmeldung aus.";
        } else {
            try {
                $conn = db_connect();
                $stmt = $conn->prepare("INSERT INTO sick_leave_reports (teacher_id, date_from, date_to, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $date_from, $date_to, $notes]);
                
                $success_msg = "Ihre Krankmeldung wurde erfolgreich übermittelt.";
            } catch (PDOException $e) {
                $error_msg = "Fehler beim Speichern. Bitte versuchen Sie es später erneut.";
                error_log("DB Insert Error (Sick Leave): " . $e->getMessage());
            }
        }
    }
}

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Krankmeldung</h2>
    <a href="/index.php" class="button-secondary" style="text-decoration:none;">&larr; Zurück</a>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="status success"><?= htmlspecialchars($success_msg) ?></div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="status error"><?= htmlspecialchars($error_msg) ?></div>
<?php endif; ?>

<div class="content-box">
    <f<!-- Notice about AU document requirement could go here -->
    <div class="status info" style="margin-bottom: 20px;">
        <strong>Hinweis:</strong> Ab dem 3. Krankheitstag bitten wir um das Hochladen oder Nachreichen einer Arbeitsunfähigkeitsbescheinigung (AU).
    </div>

    <form method="POST" action="/krankmeldung.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="form-grid-2">
            <div class="form-group">
                <label for="date_from">Voraussichtlich Krank von *</label>
                <input type="date" id="date_from" name="date_from" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="form-group">
                <label for="date_to">Bis (geschätzt) *</label>
                <input type="date" id="date_to" name="date_to" required value="<?= date('Y-m-d', strtotime('+2 days')) ?>">
            </div>
        </div>

        <div class="form-group" style="margin-top: 15px;">
            <label for="notes">Zusätzliche Notizen (Optional)</label>
            <textarea id="notes" name="notes" rows="3" placeholder="z.B. Vertretungsmaterial liegt im Lehrerzimmer..."></textarea>
        </div>

        <div style="margin-top: 25px; text-align: right;">
            <button type="submit" class="button-danger">Meldung abgeben</button>
        </div>
    </form>
</div>

<!-- History log preview -->
<div class="content-box">
    <h3>Ihre letzten Krankmeldungen</h3>
    <?php
    $conn = db_connect();
    $stmt = $conn->prepare("SELECT date_from, date_to FROM sick_leave_reports WHERE teacher_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();

    if (empty($requests)) {
        echo "<p style='color: var(--text-muted);'>Keine Meldungen in letzter Zeit.</p>";
    } else {
        echo "<table class='data-table simple'>
                <thead>
                    <tr>
                        <th>Von</th>
                        <th>Bis</th>
                    </tr>
                </thead>
                <tbody>";
        foreach ($requests as $r) {
            echo "<tr>
                    <td>".htmlspecialchars(date('d.m.Y', strtotime($r['date_from'])))."</td>
                    <td>".htmlspecialchars(date('d.m.Y', strtotime($r['date_to'])))."</td>
                  </tr>";
        }
        echo "</tbody></table>";
    }
    ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
