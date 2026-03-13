<?php
require_once __DIR__ . '/config/database.php';

try {
    $conn = db_connect();
    $sql = file_get_contents(__DIR__ . '/alter_exemption.sql');
    $conn->exec($sql);
    echo "Table exemption_requests updated successfully.\n";
} catch (PDOException $e) {
    echo "Error updating table: " . $e->getMessage() . "\n";
}
