-- 8. Homework Assignments
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
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Homework Submissions
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

-- 10. Homework Evaluations
CREATE TABLE IF NOT EXISTS homework_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    student_feedback TEXT NOT NULL,
    teacher_notes TEXT NOT NULL,
    score INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES homework_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
