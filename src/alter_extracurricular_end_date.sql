ALTER TABLE extracurricular_requests ADD COLUMN IF NOT EXISTS event_date_to DATE DEFAULT NULL;
UPDATE extracurricular_requests SET event_date_to = event_date WHERE event_date_to IS NULL;
