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

/**
 * Eine Einstellung aus app_settings, mit Vorgabe.
 *
 * Bisher las jede Seite die Tabelle selbst aus. Das ging, solange es drei
 * Werte fuer den Bericht waren; mit Schaltern, die einzelne Bloecke der
 * Oberflaeche ein- und ausblenden, lohnt sich die eine Stelle.
 */
function app_einstellung(PDO $conn, string $schluessel, string $vorgabe = ''): string
{
    static $zwischenspeicher = null;

    if ($zwischenspeicher === null) {
        $zwischenspeicher = [];
        try {
            foreach ($conn->query('SELECT setting_key, setting_value FROM app_settings') as $zeile) {
                $zwischenspeicher[$zeile['setting_key']] = (string) ($zeile['setting_value'] ?? '');
            }
        } catch (PDOException $e) {
            error_log('Antragssystem: app_settings nicht lesbar: ' . $e->getMessage());
        }
    }

    $wert = $zwischenspeicher[$schluessel] ?? null;

    return ($wert === null || $wert === '') ? $vorgabe : $wert;
}

/** Wie app_einstellung(), liefert aber true/false fuer 1/0. */
function app_schalter(PDO $conn, string $schluessel, bool $vorgabe = false): bool
{
    return app_einstellung($conn, $schluessel, $vorgabe ? '1' : '0') === '1';
}

/** Schreibt eine Einstellung; legt sie an, falls es sie noch nicht gibt. */
function app_einstellung_setzen(PDO $conn, string $schluessel, string $wert): void
{
    $conn->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$schluessel, $wert]);
}
