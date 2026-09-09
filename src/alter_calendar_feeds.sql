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

-- Hier stand die bisher fest verdrahtete IServ-Adresse, damit sie beim
-- Einspielen automatisch uebernommen wird. Sie enthielt den Zugangsschluessel
-- des Schulkalenders - in einem oeffentlichen Repository.
--
-- Die Uebernahme hat auf den bestehenden Installationen stattgefunden, die
-- Zeile hat ihren Zweck also erfuellt. Eine neue Installation traegt ihren
-- Kalender unter Systemverwaltung ein; dort gehoert er hin.
--
-- Der Schluessel steht weiterhin in der Versionsgeschichte. Ihn zu entfernen
-- reicht deshalb nicht: er gehoert in IServ gewechselt und danach ueber die
-- Oberflaeche neu eingetragen.
