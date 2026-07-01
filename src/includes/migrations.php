<?php
/**
 * src/includes/migrations.php
 * Silently runs DB migrations if they haven't been run yet.
 */
require_once __DIR__ . '/../config/database.php';

function run_all_migrations() {
    // Session caching to prevent running migrations query on every request
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['migrations_run'])) {
        return;
    }

    $conn = db_connect();
    
    // Create migration log table if not exists
    $conn->exec("CREATE TABLE IF NOT EXISTS migration_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) UNIQUE NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Self-healing: if teacher_classes table doesn't exist but alter_teacher_classes.sql
    // is logged as executed, delete that entry from migration_log so it runs again after db_update_klassen.sql.
    try {
        $check = $conn->query("SHOW TABLES LIKE 'teacher_classes'")->fetch();
        if (!$check) {
            $conn->exec("DELETE FROM migration_log WHERE filename = 'alter_teacher_classes.sql'");
        }
    } catch (PDOException $e) {
        error_log("Self-healing check failed: " . $e->getMessage());
    }

    $sql_files = [
        'alter_homework.sql',
        'alter_extracurricular.sql',
        'alter_feedback.sql',
        'alter_material.sql',
        'alter_step2.sql',
        'db_update_klassen.sql',
        'alter_teacher_classes.sql',
        'alter_homework_context.sql',
        'alter_force_password_change.sql',
        'alter_feedback_templates.sql',
        'alter_feedback_templates_klasse_fach.sql',
        'alter_homework_submission_token.sql',
        'alter_sick_leave_is_seen.sql',
        'alter_extracurricular_end_date.sql'
    ];

    foreach ($sql_files as $file) {
        // Check if already executed
        $stmt = $conn->prepare("SELECT id FROM migration_log WHERE filename = ?");
        $stmt->execute([$file]);
        if ($stmt->fetch()) {
            continue; // Skip
        }

        $path = __DIR__ . '/../' . $file;
        if (file_exists($path)) {
            try {
                $sql = file_get_contents($path);
                $queries = array_filter(array_map('trim', explode(';', $sql)));
                $success = true;
                foreach ($queries as $query) {
                    if (empty($query)) continue;
                    try {
                        $conn->exec($query);
                    } catch (PDOException $e) {
                        error_log("Statement failed in $file: " . $e->getMessage());
                        $success = false;
                    }
                }
                
                // Log successful execution
                if ($success) {
                    $stmt_log = $conn->prepare("INSERT INTO migration_log (filename) VALUES (?)");
                    $stmt_log->execute([$file]);
                }
                
            } catch (Exception $e) {
                error_log("Critical error reading migration $file: " . $e->getMessage());
            }
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['migrations_run'] = true;
    }
}
