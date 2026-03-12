<?php
/**
 * src/admin_dashboard.php
 * Dashboard for Schulleitung to manage incoming requests separately.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$conn = db_connect();

// 1. Sick leaves (Krankmeldungen)
$stmt_sick = $conn->query("
    SELECT r.id, 'Krankmeldung' as type, r.notes as details, r.date_from as date_main, r.date_to, 'Info' as status, r.created_at, t.name as teacher_name, t.kuerzel
    FROM sick_leave_reports r
    JOIN teachers t ON r.teacher_id = t.id
    ORDER BY r.created_at DESC
");
$sick_leaves = $stmt_sick->fetchAll();

// 2. Exemption requests (Freistellungen)
$stmt_exempt = $conn->query("
    SELECT r.id, 'Freistellung' as type, r.reason as details, r.date_from as date_main, r.date_to, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$exempt_requests = $stmt_exempt->fetchAll();

// 3. Extracurricular requests (Ausflüge)
$stmt_extra = $conn->query("
    SELECT r.id, 'Ausflug' as type, r.class_name as class, r.destination as details, r.event_date as date_main, r.status, r.created_at, t.name as teacher_name, t.kuerzel 
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    ORDER BY FIELD(r.status, 'pending') DESC, r.created_at DESC
");
$extra_requests = $stmt_extra->fetchAll();

$csrf_token = get_csrf_token();
require_once __DIR__ . '/includes/header.php';

// Flash Messages
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if (isset($_GET['error']) && $_GET['error'] === 'csrf') {
    $flash_error = "Sicherheitsfehler: Bitte Aktion erneut ausführen.";
}

// Helper function to render action buttons
function render_action_buttons($req_id, $req_type, $status, $csrf_token) {
    if ($status === 'pending') {
        return '
        <form method="POST" action="/admin_action.php" style="display:inline-block; margin:0;">
            <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">
            <input type="hidden" name="id" value="' . $req_id . '">
            <input type="hidden" name="type" value="' . $req_type . '">
            <button type="submit" name="action" value="approve" class="button-primary" style="background-color: var(--success-color); padding: 5px 10px; font-size: 0.85em; margin-right: 5px;" title="Genehmigen">✓</button>
            <button type="submit" name="action" value="reject" class="button-danger" style="padding: 5px 10px; font-size: 0.85em;" title="Ablehnen" onclick="return confirm(\'Möchten Sie diesen Antrag wirklich ablehnen?\');">✗</button>
        </form>';
    } else {
        $statusColor = $status == 'approved' ? 'var(--success-color)' : 'var(--danger-color)';
        $statusText = $status == 'approved' ? 'GENEHMIGT' : 'ABGELEHNT';
        return '<span style="color: ' . $statusColor . '; font-weight: 600; font-size: 0.85em;">' . $statusText . '</span>';
    }
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

<!-- Section 1: Krankmeldungen -->
<div class="content-box">
    <h3 style="border-left: 4px solid var(--danger-color); padding-left: 10px;">Krankmeldungen</h3>
    <?php if (empty($sick_leaves)): ?>
        <p style="color: var(--text-muted);">Keine Krankmeldungen vorhanden.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table simple">
                <thead>
                    <tr>
                        <th>Eingang</th>
                        <th>Lehrkraft</th>
                        <th>Von</th>
                        <th>Bis</th>
                        <th>Notizen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sick_leaves as $r): ?>
                        <tr>
                            <td style="font-size: 0.85em; color: var(--text-muted);"><?= date('d.m.y H:i', strtotime($r['created_at'])) ?></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($r['teacher_name']) ?> <span style="font-size: 0.8em; color: var(--text-muted);">(<?= htmlspecialchars($r['kuerzel']) ?>)</span></td>
                            <td><?= date('d.m.Y', strtotime($r['date_main'])) ?></td>
                            <td><?= date('d.m.Y', strtotime($r['date_to'])) ?></td>
                            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($r['details']) ?>"><?= htmlspecialchars($r['details']) ?: '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Section 2: Freistellungen -->
<div class="content-box">
    <h3 style="border-left: 4px solid var(--warning-color); padding-left: 10px;">Anträge auf Freistellung</h3>
    <?php if (empty($exempt_requests)): ?>
        <p style="color: var(--text-muted);">Keine Anträge auf Freistellung vorhanden.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table simple">
                <thead>
                    <tr>
                        <th>Eingang</th>
                        <th>Lehrkraft</th>
                        <th>Von</th>
                        <th>Bis</th>
                        <th>Grund</th>
                        <th>Aktion/Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exempt_requests as $r): ?>
                        <tr style="<?= $r['status'] !== 'pending' ? 'opacity: 0.7;' : '' ?>">
                            <td style="font-size: 0.85em; color: var(--text-muted);"><?= date('d.m.y H:i', strtotime($r['created_at'])) ?></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($r['teacher_name']) ?> <span style="font-size: 0.8em; color: var(--text-muted);">(<?= htmlspecialchars($r['kuerzel']) ?>)</span></td>
                            <td><?= date('d.m.Y', strtotime($r['date_main'])) ?></td>
                            <td><?= date('d.m.Y', strtotime($r['date_to'])) ?></td>
                            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($r['details']) ?>"><?= htmlspecialchars($r['details']) ?></td>
                            <td><?= render_action_buttons($r['id'], 'freistellung', $r['status'], $csrf_token) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Section 3: Ausflüge (Außerunterrichtlich) -->
<div class="content-box">
    <h3 style="border-left: 4px solid var(--primary-color); padding-left: 10px;">Außerunterrichtliche Veranstaltungen</h3>
    <?php if (empty($extra_requests)): ?>
        <p style="color: var(--text-muted);">Keine Anträge auf außerunterrichtliche Veranstaltungen vorhanden.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="data-table simple">
                <thead>
                    <tr>
                        <th>Eingang</th>
                        <th>Lehrkraft</th>
                        <th>Datum</th>
                        <th>Klasse</th>
                        <th>Ziel</th>
                        <th>Aktion/Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($extra_requests as $r): ?>
                        <tr style="<?= $r['status'] !== 'pending' ? 'opacity: 0.7;' : '' ?>">
                            <td style="font-size: 0.85em; color: var(--text-muted);"><?= date('d.m.y H:i', strtotime($r['created_at'])) ?></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($r['teacher_name']) ?> <span style="font-size: 0.8em; color: var(--text-muted);">(<?= htmlspecialchars($r['kuerzel']) ?>)</span></td>
                            <td><?= date('d.m.Y', strtotime($r['date_main'])) ?></td>
                            <td><?= htmlspecialchars($r['class']) ?></td>
                            <td><?= htmlspecialchars($r['details']) ?></td>
                            <td><?= render_action_buttons($r['id'], 'ausflug', $r['status'], $csrf_token) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
