-- Consolidated Database Schema for School Feedback & Unterricht Tools

-- =========================================================================
-- DATABASE 1: db_feedback (Main Database with User Base)
-- =========================================================================
CREATE DATABASE IF NOT EXISTS db_feedback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_feedback;

-- 1. Teachers Table (Single Source of Truth for accounts)
CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kuerzel VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) DEFAULT NULL,
    passwort_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    force_password_change TINYINT(1) DEFAULT 1,
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

-- Seed initial data for db_feedback
-- Insert a test admin user (Password: admin)
INSERT IGNORE INTO teachers (kuerzel, email, passwort_hash, is_admin, name, force_password_change) 
VALUES ('admin', 'admin@example.local', '$2y$10$4aI/.pBlZUV.ltYBK1wJ..fwPLdyzvNHsotVWcZ8HcVdoOOprSOH.', 1, 'Administrator', 0);

-- Insert a normal test teacher (Password: lehrer)
INSERT IGNORE INTO teachers (kuerzel, email, passwort_hash, name, force_password_change) 
VALUES ('test', 'lehrer@example.local', '$2y$10$K7M3J4s/n/U.t.G9W636r.P/bZq3w/I.w9I.K.2/J7.3K.2.h.QyC', 'Test Lehrer', 0);


-- =========================================================================
-- DATABASE 2: db_unterricht (Teaching & Feedback)
-- =========================================================================
CREATE DATABASE IF NOT EXISTS db_unterricht CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_unterricht;

-- 1. Create View for Teachers (Points directly to db_feedback.teachers)
CREATE OR REPLACE VIEW teachers AS 
SELECT * FROM db_feedback.teachers;

-- 2. Classes Table
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Teacher Classes (Mapping)
CREATE TABLE IF NOT EXISTS teacher_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    class_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_teacher_class (teacher_id, class_id),
    FOREIGN KEY (teacher_id) REFERENCES db_feedback.teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Feedback Sessions
CREATE TABLE IF NOT EXISTS feedback_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    klasse VARCHAR(100) NOT NULL,
    fach VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES db_feedback.teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Feedback Questions
CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_text TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES feedback_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Feedback Responses
CREATE TABLE IF NOT EXISTS feedback_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    score INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES feedback_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Feedback Templates
CREATE TABLE IF NOT EXISTS feedback_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    klasse VARCHAR(100) DEFAULT NULL,
    fach VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES db_feedback.teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Feedback Template Questions
CREATE TABLE IF NOT EXISTS feedback_template_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    question_text TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES feedback_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Homework Assignments
CREATE TABLE IF NOT EXISTS homework_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    klasse VARCHAR(100) NOT NULL,
    fach VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    due_date DATETIME DEFAULT NULL,
    context_image_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES db_feedback.teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Homework Submissions
CREATE TABLE IF NOT EXISTS homework_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    student_pseudonym VARCHAR(64) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    token VARCHAR(64) DEFAULT NULL,
    status ENUM('pending', 'evaluated') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES homework_assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Homework Evaluations
CREATE TABLE IF NOT EXISTS homework_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    student_feedback TEXT NOT NULL,
    teacher_notes TEXT NOT NULL,
    score INT DEFAULT NULL,
    error_markers TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES homework_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed sample classes
INSERT IGNORE INTO classes (name) VALUES 
('5a'), ('5b'), ('6a'), ('6b'), ('7a'), ('7b'), ('8a'), ('8b'), ('9a'), ('9b'), ('10a'), ('10b');
