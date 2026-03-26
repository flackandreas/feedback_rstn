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
                // Simple split by semicolon (careful with strings, but usually fine for simple migrations)
                $queries = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($queries as $query) {
                    try {
                        $conn->exec($query);
                    } catch (PDOException $e) {
                        // Log but continue (column might already exist)
                        error_log("Statement failed in $file: " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                error_log("Critical error reading migration $file: " . $e->getMessage());
            }
        }
    }
}
