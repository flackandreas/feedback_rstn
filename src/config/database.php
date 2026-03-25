<?php
/**
 * src/config/database.php
 * Stellt die PDO-Verbindung zur MariaDB her.
 */

// Datenbank-Konfiguration
define('DB_SERVER', 'db'); // Docker service name
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'db_user');
define('DB_NAME', 'db_feedback');
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
        } catch (PDOException $e) {
            // Ignore if column exists
        }
        
        return $conn;
    } catch (PDOException $e) {
        error_log("Datenbankfehler: " . $e->getMessage());
        die("Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.");
    }
}
?>
