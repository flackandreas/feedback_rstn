ALTER TABLE exemption_requests 
ADD COLUMN days_of_week VARCHAR(255) DEFAULT NULL AFTER reason,
ADD COLUMN classes VARCHAR(255) DEFAULT NULL AFTER days_of_week,
ADD COLUMN hourly_exemption TINYINT(1) DEFAULT 0 AFTER classes,
ADD COLUMN hour_from INT DEFAULT NULL AFTER hourly_exemption,
ADD COLUMN hour_to INT DEFAULT NULL AFTER hour_from,
ADD COLUMN reason_type VARCHAR(100) DEFAULT NULL AFTER hour_to;
