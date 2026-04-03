ALTER TABLE sick_leave_reports ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL;
ALTER TABLE sick_leave_reports ADD COLUMN modified_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('report_email', '');
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('report_time', '08:00');
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('report_interval', 'weekly');
