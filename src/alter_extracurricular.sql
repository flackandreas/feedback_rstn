-- Migration: Add extended columns to extracurricular_requests
-- Safe to run multiple times (IF NOT EXISTS)
ALTER TABLE extracurricular_requests ADD COLUMN IF NOT EXISTS aud_type VARCHAR(50) DEFAULT NULL;
ALTER TABLE extracurricular_requests ADD COLUMN IF NOT EXISTS participating_teacher_id INT DEFAULT NULL;
ALTER TABLE extracurricular_requests
    ADD COLUMN IF NOT EXISTS role VARCHAR(255) DEFAULT NULL AFTER teacher_id,
    ADD COLUMN IF NOT EXISTS companion VARCHAR(255) DEFAULT NULL AFTER class_name,
    ADD COLUMN IF NOT EXISTS event_name VARCHAR(255) DEFAULT NULL AFTER event_date,
    ADD COLUMN IF NOT EXISTS costs VARCHAR(100) DEFAULT NULL AFTER destination,
    ADD COLUMN IF NOT EXISTS transport TEXT DEFAULT NULL AFTER costs,
    ADD COLUMN IF NOT EXISTS start_time TIME DEFAULT NULL AFTER transport,
    ADD COLUMN IF NOT EXISTS start_location VARCHAR(255) DEFAULT NULL AFTER start_time,
    ADD COLUMN IF NOT EXISTS return_time TIME DEFAULT NULL AFTER start_location,
    ADD COLUMN IF NOT EXISTS return_location VARCHAR(255) DEFAULT NULL AFTER return_time,
    ADD COLUMN IF NOT EXISTS return_trip_arranged TINYINT(1) DEFAULT 0 AFTER return_location,
    ADD COLUMN IF NOT EXISTS supervisors TEXT DEFAULT NULL AFTER return_trip_arranged,
    ADD COLUMN IF NOT EXISTS consent_form ENUM('ja','nein') DEFAULT NULL AFTER supervisors,
    ADD COLUMN schedule_notified TINYINT(1) DEFAULT 0 AFTER consent_form,
    ADD COLUMN modified_after_approval TINYINT(1) DEFAULT 0,
    ADD COLUMN modified_at DATETIME DEFAULT NULL;

-- Try to add fk constraint, ignore if exists (requires manual care or ignore since script is simple)
ALTER TABLE extracurricular_requests ADD CONSTRAINT fk_extracurricular_participating_teacher FOREIGN KEY IF NOT EXISTS (participating_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL;

