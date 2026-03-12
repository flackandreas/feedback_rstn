<?php
/**
 * src/index.php
 * Main Dashboard
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$user_id = get_current_user_id();
$user_name = get_current_user_name();

// Fetch summary of current requests
try {
    $conn = db_connect();
    
    // Extracurricular
    $stmt1 = $conn->prepare("SELECT COUNT(*) FROM extracurricular_requests WHERE teacher_id = ? AND status = 'pending'");
    $stmt1->execute([$user_id]);
    $pending_extra = $stmt1->fetchColumn();

    // Exemptions
    $stmt2 = $conn->prepare("SELECT COUNT(*) FROM exemption_requests WHERE teacher_id = ? AND status = 'pending'");
    $stmt2->execute([$user_id]);
    $pending_exemptions = $stmt2->fetchColumn();

    // Sick leaves (Total recent)
    $stmt3 = $conn->prepare("SELECT COUNT(*) FROM sick_leave_reports WHERE teacher_id = ? AND date_from >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt3->execute([$user_id]);
    $recent_sick = $stmt3->fetchColumn();

} catch (PDOException $e) {
    // If table doesn't exist yet, we catch the error gracefully
    $pending_extra = $pending_exemptions = $recent_sick = 0;
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center;">
    <h1>Willkommen, <?= htmlspecialchars($user_name) ?></h1>
    <span style="color: var(--text-muted); font-size: 0.9em;"><?= date('d.m.Y') ?></span>
</div>

<p style="color: var(--text-muted); margin-bottom: 30px;">Hier verwalten Sie Ihre Anträge und Meldungen.</p>

<div class="kpi-grid">
    <div class="kpi-box">
        <span>Austehende außerunterr. Anträge</span>
        <strong><?= (int)$pending_extra ?></strong>
    </div>
    <div class="kpi-box">
        <span>Ausstehende Freistellungen</span>
        <strong><?= (int)$pending_exemptions ?></strong>
    </div>
    <div class="kpi-box">
        <span>Krankmeldungen (Letzte 30 Tage)</span>
        <strong><?= (int)$recent_sick ?></strong>
    </div>
</div>

<div class="content-box" style="margin-top: 30px;">
    <h2>Schnellzugriff</h2>
    
    <div class="form-grid-3">
        <a href="/antrag_ausserunterrichtlich.php" style="text-decoration: none;">
            <div class="kpi-box" style="cursor: pointer; border-left: 5px solid var(--primary-color);">
                <div style="font-size: 2em; margin-bottom: 15px;">🚌</div>
                <span style="color: var(--text-dark); margin: 0;">Neuer Antrag:<br>Außerunterrichtliche Veranstaltung</span>
            </div>
        </a>

        <a href="/antrag_freistellung.php" style="text-decoration: none;">
            <div class="kpi-box" style="cursor: pointer; border-left: 5px solid var(--warning-color);">
                <div style="font-size: 2em; margin-bottom: 15px;">🏖️</div>
                <span style="color: var(--text-dark); margin: 0;">Neuer Antrag:<br>Freistellung</span>
            </div>
        </a>

        <a href="/krankmeldung.php" style="text-decoration: none;">
            <div class="kpi-box" style="cursor: pointer; border-left: 5px solid var(--danger-color);">
                <div style="font-size: 2em; margin-bottom: 15px;">🤒</div>
                <span style="color: var(--text-dark); margin: 0;">Neue Meldung:<br>Krankmeldung</span>
            </div>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
