<?php
/**
 * src/admin_dashboard.php
 * Dashboard for Schulleitung to manage incoming requests.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$conn = db_connect();

// Base queries for pending requests
$sql_extra = "
    SELECT r.id, 'Ausflug' as type, r.class_name as details, r.event_date as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'pending'";
    
$sql_exempt = "
    SELECT r.id, 'Freistellung' as type, r.reason as details, r.date_from as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'pending'";

// We also load recent sick leaves (last 7 days) just for overview, but they don't have approve/reject states
$sql_sick = "
    SELECT r.id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, 'Info' as status, r.created_at, t.name as teacher_name, t.kuerzel
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

$query = "$sql_extra UNION ALL $sql_exempt UNION ALL $sql_sick ORDER BY created_at ASC";

$stmt = $conn->query($query);
$requests = $stmt->fetchAll();

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/header.php';

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (isset($_GET['error']) && $_GET['error'] === 'csrf') {
    $flash_error = "Sicherheitsfehler: Bitte Aktion erneut ausführen.";
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Zentrale Verwaltung (Schulleitung)</h2>
</div>

<?php if ($flash_success): ?>
    <div class="status success"><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="status error"><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<div class="content-box">
    <h3>Offene Anträge & Aktuelle Meldungen</h3>
    
    <?php if (empty($requests)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3em; margin-bottom: 10px;">☕</div>
            <p>Keine offenen Anträge oder aktuellen Krankmeldungen. Alles erledigt!</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Erstellt</th>
                        <th>Lehrkraft</th>
                        <th>Typ</th>
                        <th>Für Datum</th>
                        <th>Grund/Details</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td style="font-size: 0.85em; color: var(--text-muted);">
                                <?= date('d.m. H:i', strtotime($r['created_at'])) ?>
                            </td>
                            <td style="font-weight: 600;">
                                <?= htmlspecialchars($r['teacher_name']) ?> <span style="color: var(--text-muted); font-size: 0.8em;">(<?= htmlspecialchars($r['kuerzel']) ?>)</span>
                            </td>
                            <td>
                                <?php if ($r['type'] == 'Ausflug'): ?>
                                    <span style="border-left: 3px solid var(--primary-color); padding-left: 8px;">🚌 Ausflug</span>
                                <?php elseif ($r['type'] == 'Freistellung'): ?>
                                    <span style="border-left: 3px solid var(--warning-color); padding-left: 8px;">🏖️ Freistellung</span>
                                <?php else: ?>
                                    <span style="border-left: 3px solid var(--danger-color); padding-left: 8px;">🤒 Krankmeldung</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 500;">
                                <?= date('d.m.Y', strtotime($r['date_main'])) ?>
                            </td>
                            <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($r['details']) ?>">
                                <?= htmlspecialchars($r['details']) ?: '-' ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <?php $req_type_key = ($r['type'] === 'Ausflug') ? 'ausflug' : 'freistellung'; ?>
                                    <form method="POST" action="/admin_action.php" style="display:inline-block; margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="type" value="<?= $req_type_key ?>">
                                        
                                        <button type="submit" name="action" value="approve" class="button-primary" style="background-color: var(--success-color); padding: 5px 10px; font-size: 0.85em; margin-right: 5px;" title="Genehmigen">
                                            ✓
                                        </button>
                                        <button type="submit" name="action" value="reject" class="button-danger" style="padding: 5px 10px; font-size: 0.85em;" title="Ablehnen" onclick="return confirm('Möchten Sie diesen Antrag wirklich ablehnen?');">
                                            ✗
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.9em;">- Info -</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
