<?php
/**
 * src/healthz.php
 * Lebenszeichen fuer das Portal.
 *
 * Bewusst ohne Versionsangaben: der Endpunkt ist unangemeldet erreichbar und
 * soll nichts verraten, was bei der Suche nach Luecken hilft.
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    db_connect()->query('SELECT 1');
} catch (Throwable $e) {
    error_log('Antragssystem: healthz meldet Datenbankproblem: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'datenbank']);
    exit;
}

echo json_encode(['status' => 'ok', 'modul' => 'antrag']);
