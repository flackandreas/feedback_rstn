-- Initial Database Schema for School Feedback Tool

CREATE DATABASE IF NOT EXISTS db_feedback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_feedback;

-- 1. Teachers Table
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kuerzel VARCHAR(50) NOT NULL UNIQUE,
    passwort_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Extracurricular Requests (Antrag auf außerunterrichtliche Veranstaltung)
CREATE TABLE IF NOT EXISTS extracurricular_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    event_date DATE NOT NULL,
    destination VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Exemption Requests (Antrag auf Freistellung)
CREATE TABLE IF NOT EXISTS exemption_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Sick Leave Reports (Krankmeldung)
CREATE TABLE IF NOT EXISTS sick_leave_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert a test admin user (Password: admin)
-- The password_hash is generated using password_hash('admin', PASSWORD_DEFAULT);
INSERT IGNORE INTO teachers (kuerzel, passwort_hash, name) 
VALUES ('admin', '$2y$10$w0f./h3bVOh4y/d/LzXN0e2OEXlqY4ZlZ7l3k4h.Kx6Y7eZbS.51q', 'Administrator');

-- Insert a normal test teacher (Password: lehrer)
INSERT IGNORE INTO teachers (kuerzel, passwort_hash, name) 
VALUES ('test', '$2y$10$K7M3J4s/n/U.t.G9W636r.P/bZq3w/I.w9I.K.2/J7.3K.2.h.QyC', 'Test Lehrer');
