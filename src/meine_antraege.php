<?php
/**
 * src/meine_antraege.php
 * View for teachers to see all their past and current requests
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$user_id = get_current_user_id();
$filter_status = $_GET['status'] ?? 'all';

$conn = db_connect();

// Base queries
$sql_extra = "SELECT id, 'Ausflug' as type, class_name as details, event_date as date_main, status, created_at FROM extracurricular_requests WHERE teacher_id = ?";
$sql_exempt = "SELECT id, 'Freistellung' as type, reason as details, date_from as date_main, status, created_at FROM exemption_requests WHERE teacher_id = ?";
$sql_sick = "SELECT id, 'Krankmeldung' as type, notes as details, date_from as date_main, 'approved' as status, created_at FROM sick_leave_reports WHERE teacher_id = ?";

$params = [$user_id, $user_id, $user_id];

if ($filter_status !== 'all') {
    $sql_extra .= " AND status = ?";
    $sql_exempt .= " AND status = ?";
    // Sick leaves don't have a status in DB, we mock 'approved' for consistency so filter appropriately.
    if ($filter_status !== 'approved') {
        $sql_sick .= " AND 1=0"; // Don't show sick leaves if we are searching for pending/rejected
    }
    
    $params = [$user_id, $filter_status, $user_id, $filter_status, $user_id];
}

$query = "$sql_extra UNION ALL $sql_exempt UNION ALL $sql_sick ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2>Meine Anträge & Meldungen</h2>
    
    <!-- Filter Dropdown -->
    <form method="GET" action="/meine_antraege.php" style="display: flex; gap: 10px; align-items: center;">
        <label for="statusFilter" style="font-weight: 500; font-size: 0.9em;">Status:</label>
        <select name="status" id="statusFilter" onchange="this.form.submit()" style="padding: 5px 10px; width: auto;">
            <option value="all" <?= $filter_status == 'all' ? 'selected' : '' ?>>Alle</option>
            <option value="pending" <?= $filter_status == 'pending' ? 'selected' : '' ?>>Ausstehend</option>
            <option value="approved" <?= $filter_status == 'approved' ? 'selected' : '' ?>>Genehmigt / Bestätigt</option>
            <option value="rejected" <?= $filter_status == 'rejected' ? 'selected' : '' ?>>Abgelehnt</option>
        </select>
    </form>
</div>

<div class="content-box">
    <?php if (empty($requests)): ?>
        <p style="text-align: center; color: var(--text-muted); padding: 40px 0;">
            Sie haben bisher keine Anträge gestellt, die diesem Filter entsprechen.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Typ</th>
                        <th>Details/Grund</th>
                        <th>Gesendet am</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <?php 
                        $statusColor = $r['status'] == 'pending' ? 'var(--warning-color-dark)' : ($r['status'] == 'approved' ? 'var(--success-color)' : 'var(--danger-color)');
                        
                        $statusText = match($r['status']) {
                            'pending' => 'AUSSTEHEND',
                            'approved' => 'GENEHMIGT',
                            'rejected' => 'ABGELEHNT',
                            default => strtoupper($r['status'])
                        };
                        ?>
                        <tr>
                            <td style="font-weight: 500;"><?= date('d.m.Y', strtotime($r['date_main'])) ?></td>
                            <td>
                                <?php if ($r['type'] == 'Ausflug'): ?>
                                    🚌 <?= htmlspecialchars($r['type']) ?>
                                <?php elseif ($r['type'] == 'Freistellung'): ?>
                                    🏖️ <?= htmlspecialchars($r['type']) ?>
                                <?php else: ?>
                                    🤒 <?= htmlspecialchars($r['type']) ?>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted);">
                                <?= htmlspecialchars(mb_strimwidth($r['details'], 0, 50, '...')) ?>
                            </td>
                            <td style="font-size: 0.85em; color: var(--text-muted);">
                                <?= date('d.m.y H:i', strtotime($r['created_at'])) ?>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 0.8em; font-weight: 600; color: white; background-color: <?= $statusColor ?>;">
                                    <?= $statusText ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
