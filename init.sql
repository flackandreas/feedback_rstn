-- Initial Database Schema for School Feedback Tool

CREATE DATABASE IF NOT EXISTS db_feedback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_feedback;

-- 1. Teachers Table
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kuerzel VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) DEFAULT NULL,
    passwort_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Extracurricular Requests (Antrag auf außerunterrichtliche Veranstaltung)
CREATE TABLE IF NOT EXISTS extracurricular_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    role VARCHAR(255) DEFAULT NULL,
    class_name VARCHAR(100) NOT NULL,
    companion VARCHAR(255) DEFAULT NULL,
    event_date DATE NOT NULL,
    event_name VARCHAR(255) DEFAULT NULL,
    destination VARCHAR(255) NOT NULL,
    aud_type VARCHAR(50) DEFAULT NULL,
    participating_teacher_id INT DEFAULT NULL,
    costs VARCHAR(100) DEFAULT NULL,
    transport TEXT DEFAULT NULL,
    start_time TIME DEFAULT NULL,
    start_location VARCHAR(255) DEFAULT NULL,
    return_time TIME DEFAULT NULL,
    return_location VARCHAR(255) DEFAULT NULL,
    return_trip_arranged TINYINT(1) DEFAULT 0,
    supervisors TEXT DEFAULT NULL,
    consent_form ENUM('ja', 'nein') DEFAULT NULL,
    schedule_notified TINYINT(1) DEFAULT 0,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (participating_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Exemption Requests (Antrag auf Freistellung)
CREATE TABLE IF NOT EXISTS exemption_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    reason TEXT NOT NULL,
    days_of_week VARCHAR(255) DEFAULT NULL,
    classes VARCHAR(255) DEFAULT NULL,
    hourly_exemption TINYINT(1) DEFAULT 0,
    hour_from INT DEFAULT NULL,
    hour_to INT DEFAULT NULL,
    reason_type VARCHAR(100) DEFAULT NULL,
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
INSERT IGNORE INTO teachers (kuerzel, email, passwort_hash, is_admin, name) 
VALUES ('admin', 'admin@example.local', '$2y$10$4aI/.pBlZUV.ltYBK1wJ..fwPLdyzvNHsotVWcZ8HcVdoOOprSOH.', 1, 'Administrator');

-- Insert a normal test teacher (Password: lehrer)
INSERT IGNORE INTO teachers (kuerzel, email, passwort_hash, name) 
VALUES ('test', 'lehrer@example.local', '$2y$10$K7M3J4s/n/U.t.G9W636r.P/bZq3w/I.w9I.K.2/J7.3K.2.h.QyC', 'Test Lehrer');
