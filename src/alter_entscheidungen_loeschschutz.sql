-- Audit M3 (Mac mini, 26.09.2026)
--
-- 1. Loeschschutz. Wer ein Konto loeschte, loeschte per CASCADE alle Antraege
--    und Krankmeldungen dieser Person mit - die Atteste blieben dabei als
--    Dateien ohne Verweis liegen. Geloescht wird kuenftig nur noch ueber den
--    Jahresabschluss, der die Dateien mitnimmt. Ein Konto mit Vorgaengen
--    laesst sich deshalb nicht mehr loeschen (RESTRICT). Abgeschaltet wird
--    ein Konto am Portal.
--
-- 2. Protokoll der Entscheidungen ueber Antraege - wer hat wann was
--    entschieden. Ein Eintrag lebt so lange wie sein Antrag und verschwindet
--    mit ihm beim Jahresabschluss. Den Namen haelt er selbst fest, damit er
--    auch dann lesbar bleibt, wenn das Konto spaeter geloescht wird.
--
-- Wichtig fuer includes/migrations.php: Die Datei wird an jedem Semikolon
-- zerlegt, in Kommentaren darf deshalb keines stehen.

ALTER TABLE exemption_requests DROP FOREIGN KEY IF EXISTS exemption_requests_ibfk_1;
ALTER TABLE exemption_requests ADD CONSTRAINT fk_freistellung_lehrkraft FOREIGN KEY IF NOT EXISTS (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT;

ALTER TABLE extracurricular_requests DROP FOREIGN KEY IF EXISTS extracurricular_requests_ibfk_1;
ALTER TABLE extracurricular_requests ADD CONSTRAINT fk_veranstaltung_lehrkraft FOREIGN KEY IF NOT EXISTS (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT;

ALTER TABLE sick_leave_reports DROP FOREIGN KEY IF EXISTS sick_leave_reports_ibfk_1;
ALTER TABLE sick_leave_reports ADD CONSTRAINT fk_krankmeldung_lehrkraft FOREIGN KEY IF NOT EXISTS (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS antrag_entscheidungen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    freistellung_id INT DEFAULT NULL,
    veranstaltung_id INT DEFAULT NULL,
    status_vorher VARCHAR(20) DEFAULT NULL,
    status_neu VARCHAR(20) NOT NULL,
    entschieden_von INT DEFAULT NULL,
    entschieden_von_name VARCHAR(255) NOT NULL,
    entschieden_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entscheidung_freistellung (freistellung_id),
    INDEX idx_entscheidung_veranstaltung (veranstaltung_id),
    CONSTRAINT fk_entscheidung_freistellung FOREIGN KEY (freistellung_id) REFERENCES exemption_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_entscheidung_veranstaltung FOREIGN KEY (veranstaltung_id) REFERENCES extracurricular_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_entscheidung_von FOREIGN KEY (entschieden_von) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
