<?php
/**
 * src/includes/rate_limit.php
 * Einfaches, datenbankgestuetztes Rate-Limiting.
 *
 * Gleiche Bauart wie im Unterrichtsmodul, damit sich beide gleich verhalten.
 */

/**
 * Zaehlt einen Zugriff und meldet, ob das Limit noch eingehalten wird.
 *
 * @param string $action  Bezeichner des Vorgangs, z.B. "login_ip"
 * @param string $subject Wer/was begrenzt wird, z.B. eine IP oder ein Kuerzel
 * @param int    $limit   Erlaubte Zugriffe im Zeitfenster
 * @param int    $window  Laenge des Zeitfensters in Sekunden
 */
function rate_limit_allow(PDO $conn, string $action, string $subject, int $limit, int $window): bool
{
    $bucket = $action . ':' . hash('sha256', $subject);
    $window = max(1, $window);

    try {
        $stmt = $conn->prepare("
            INSERT INTO rate_limits (bucket, window_start, hits)
            VALUES (:bucket, NOW(), 1)
            ON DUPLICATE KEY UPDATE
                hits = IF(window_start < NOW() - INTERVAL {$window} SECOND, 1, hits + 1),
                window_start = IF(window_start < NOW() - INTERVAL {$window} SECOND, NOW(), window_start)
        ");
        $stmt->execute([':bucket' => $bucket]);

        $stmt = $conn->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $hits = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Ein Fehler in der Zaehltabelle darf die Anmeldung nicht blockieren.
        error_log('Antragssystem: Rate-Limit-Pruefung fehlgeschlagen: ' . $e->getMessage());

        return true;
    }

    if ($hits > $limit) {
        error_log(sprintf('Antragssystem: Rate limit erreicht: action=%s hits=%d limit=%d', $action, $hits, $limit));

        return false;
    }

    return true;
}

function rate_limit_reset(PDO $conn, string $action, string $subject): void
{
    try {
        $conn->prepare('DELETE FROM rate_limits WHERE bucket = ?')
            ->execute([$action . ':' . hash('sha256', $subject)]);
    } catch (PDOException $e) {
        error_log('Antragssystem: Rate-Limit-Reset fehlgeschlagen: ' . $e->getMessage());
    }
}

/** Adresse des Anfragenden, hinter dem Reverse-Proxy aus X-Forwarded-For. */
function rate_limit_client_ip(): string
{
    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $erste = trim(explode(',', $forwarded)[0]);
        if (filter_var($erste, FILTER_VALIDATE_IP)) {
            return $erste;
        }
    }

    $entfernt = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $entfernt !== '' ? $entfernt : 'unbekannt';
}
