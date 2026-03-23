-- Zusammenfassendes Script für alle Datenbank-Änderungen von Berta

-- 1. Anpassungen für Krankmeldungen (AUD & Participating Teacher)
ALTER TABLE extracurricular_requests ADD COLUMN IF NOT EXISTS aud_type VARCHAR(50) DEFAULT NULL;
ALTER TABLE extracurricular_requests ADD COLUMN IF NOT EXISTS participating_teacher_id INT DEFAULT NULL;

-- Ignoriere Fehler, falls der Constraint in MySQL < 8.0.16 oder MariaDB < 10.0 nicht mit IF NOT EXISTS funktioniert
-- Wenn es fehlschlägt, ist er wahrscheinlich schon da.
ALTER TABLE extracurricular_requests ADD CONSTRAINT fk_extracurricular_participating_teacher FOREIGN KEY IF NOT EXISTS (participating_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL;

-- 2. Neues Feedback-System (Tabellen erstellen)
CREATE TABLE IF NOT EXISTS feedback_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    klasse VARCHAR(100) NOT NULL,
    fach VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_text TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES feedback_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    score INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES feedback_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PDF Export / Dateianhänge für Krankmeldungen und App Settings Update
ALTER TABLE sick_leave_reports ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('report_email', '');
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('report_time', '08:00');
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('report_interval', 'weekly');
