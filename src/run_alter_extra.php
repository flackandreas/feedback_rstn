<?php
// One-time migration: add new columns to extracurricular_requests
require_once __DIR__ . '/config/database.php';
try {
    $conn = db_connect();
    $sql = file_get_contents(__DIR__ . '/alter_extracurricular.sql');
    $conn->exec($sql);
} catch (PDOException $e) {
    error_log("Migration error (extracurricular): " . $e->getMessage());
}
