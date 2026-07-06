<?php
/**
 * src/config/database.php
 * Stellt die PDO-Verbindung zur MariaDB her.
 */

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Load .env from project root
try {
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    }
} catch (Exception $e) {
    // Silence error if .env is missing in production (env vars should be set in host/docker)
}

// Datenbank-Konfiguration
define('DB_SERVER', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'db');
define('DB_USERNAME', $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root');
define('DB_PASSWORD', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: 'db_user');
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'db_feedback');
define('CHARSET', 'utf8mb4');

function db_connect() {
    try {
        $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=" . CHARSET;
        $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Ensure data is returned as associative arrays by default
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Auto-migrate column for modifications
        try {
            $conn->exec("ALTER TABLE extracurricular_requests ADD COLUMN modified_after_approval TINYINT(1) DEFAULT 0");
        } catch (PDOException $e) {}
        
        return $conn;
    } catch (PDOException $e) {
        error_log("Datenbankfehler: " . $e->getMessage());
        die("Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.");
    }
}
?>
