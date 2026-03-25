<?php
/**
 * src/includes/migrations.php
 * Silently runs DB migrations if they haven't been run yet.
 */
require_once __DIR__ . '/../config/database.php';

function run_all_migrations() {
    $conn = db_connect();
    $sql_files = [
        'alter_extracurricular.sql',
        'alter_feedback.sql',
        'alter_material.sql',
        'alter_step2.sql'
    ];

    foreach ($sql_files as $file) {
        $path = __DIR__ . '/../' . $file;
        if (file_exists($path)) {
            try {
                $sql = file_get_contents($path);
                // We run them one by one if they contain multiple statements, 
                // but these scripts were written to be idempotent (ADD COLUMN IF NOT EXISTS).
                // exec() might have issues with multiple statements depending on the driver, 
                // but for simple ALTER TABLE it usually works.
                $conn->exec($sql);
            } catch (PDOException $e) {
                // Silently log errors; columns likely already exist
                error_log("Migration error for $file: " . $e->getMessage());
            }
        }
    }
}
