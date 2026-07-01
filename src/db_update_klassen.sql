CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optionale Initial-Daten (Beispiel)
INSERT IGNORE INTO classes (name) VALUES ('5a'), ('5b'), ('6a'), ('6b'), ('7a'), ('7b'), ('8a'), ('8b'), ('9a'), ('9b'), ('10a'), ('10b');
