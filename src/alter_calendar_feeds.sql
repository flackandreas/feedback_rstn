-- Kalender-Feeds, die im Schulkalender mit angezeigt werden.
--
-- Vorher stand genau eine Adresse fest verdrahtet in calendar.php. Wer einen
-- zweiten Kalender einbinden oder den Schluessel wechseln wollte, musste den
-- Quelltext aendern und neu ausrollen.
CREATE TABLE IF NOT EXISTS calendar_feeds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_fetch_at DATETIME DEFAULT NULL,
    last_status VARCHAR(200) DEFAULT NULL,
    last_event_count INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_feed_aktiv (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Den bisher fest verdrahteten IServ-Kalender uebernehmen, damit beim
-- Einspielen kein Termin verschwindet. Nur, wenn noch nichts eingetragen ist.
INSERT INTO calendar_feeds (name, url, is_active)
SELECT 'IServ-Schulkalender', 'https://rstn.de/iserv/public/calendar?key=f5c7249d68e573f308af152f75f832e8', 1
WHERE NOT EXISTS (SELECT 1 FROM calendar_feeds);
