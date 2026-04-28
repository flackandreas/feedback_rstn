<?php
/**
 * src/includes/admin_helpers.php
 * Helpers for admin-related notifications and data.
 */

function get_pending_counts($conn) {
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        return ['total' => 0, 'exemption' => 0, 'aud' => 0];
    }

    // Count pending exemptions
    $stmt1 = $conn->query("SELECT COUNT(*) FROM exemption_requests WHERE status = 'pending' OR status = 'query'");
    $exemption_count = $stmt1->fetchColumn();

    // Count pending AUDs
    $stmt2 = $conn->query("SELECT COUNT(*) FROM extracurricular_requests WHERE status = 'pending' OR status = 'query'");
    $aud_count = $stmt2->fetchColumn();

    return [
        'total' => $exemption_count + $aud_count,
        'exemption' => (int)$exemption_count,
        'aud' => (int)$aud_count
    ];
}
