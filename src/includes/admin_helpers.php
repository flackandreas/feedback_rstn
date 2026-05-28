<?php
/**
 * src/includes/admin_helpers.php
 * Helpers for admin-related notifications and data.
 */

function get_pending_counts($conn) {
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        return ['total' => 0, 'exemption' => 0, 'aud' => 0, 'sick_leaves' => 0];
    }

    // Count pending exemptions
    $stmt1 = $conn->query("SELECT COUNT(*) FROM exemption_requests WHERE status = 'pending' OR status = 'query'");
    $exemption_count = (int)$stmt1->fetchColumn();

    // Count pending AUDs
    $stmt2 = $conn->query("SELECT COUNT(*) FROM extracurricular_requests WHERE status = 'pending' OR status = 'query'");
    $aud_count = (int)$stmt2->fetchColumn();

    // Count unseen sick leaves (safely catch if table/column does not exist yet)
    $sick_leaves_count = 0;
    try {
        $stmt3 = $conn->query("SELECT COUNT(*) FROM sick_leave_reports WHERE is_seen = 0");
        $sick_leaves_count = (int)$stmt3->fetchColumn();
    } catch (PDOException $e) {
        // Migration has not run yet
    }

    return [
        'total' => $exemption_count + $aud_count + $sick_leaves_count,
        'exemption' => $exemption_count,
        'aud' => $aud_count,
        'sick_leaves' => $sick_leaves_count
    ];
}
