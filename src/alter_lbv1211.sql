-- Antrag auf ausserunterrichtliche Veranstaltung nach dem amtlichen Vordruck
-- LBV 1211 (Stand 07/20), "Antrag auf Genehmigung einer Dienstreise -
-- Ausserunterrichtliche Veranstaltung -".
--
-- Der Vordruck fragt mehr ab als das bisherige Formular: Personalnummer,
-- Name und Vorname getrennt, bis zu vier Begleitpersonen mit dem Vermerk
-- "in Ausbildung", Ankunft und Abfahrt am Veranstaltungsort, die Zahl der
-- Schuelerinnen und Schueler, die Befoerderungsmittel zum Ankreuzen und die
-- Kosten fuer Lehrkraft und Begleitpersonen als Betrag in Euro.
--
-- Die Personalnummer steht zweimal: im Profil der Lehrkraft, damit sie beim
-- naechsten Antrag schon eingetragen ist, und als Kopie im Antrag, damit ein
-- spaeterer Ausdruck dieselbe Nummer zeigt wie der eingereichte.
--
-- aud_type bleibt mit allen Altwerten stehen, das Formular fragt es nur
-- nicht mehr ab.
--
-- Wichtig fuer includes/migrations.php: Die Datei wird an jedem Semikolon
-- zerlegt, in Kommentaren darf deshalb keines stehen. Jede Anweisung muss
-- sich wiederholen lassen - schlaegt eine fehl, laeuft die Datei beim
-- naechsten Sitzungsstart erneut.

ALTER TABLE teachers ADD COLUMN IF NOT EXISTS lbv_personalnummer VARCHAR(30) DEFAULT NULL;

ALTER TABLE extracurricular_requests
    ADD COLUMN IF NOT EXISTS lbv_personalnummer VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lbv_drm VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lk_nachname VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lk_vorname VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lk_in_ausbildung TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ankunft_datum DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ankunft_zeit TIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS abfahrt_datum DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS abfahrt_zeit TIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS aufenthaltstage SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS schueler_anzahl SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS bef_oepnv TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bef_reisebus TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bef_sonstiges TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bef_sonstiges_text TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lbv_kosten_eur DECIMAL(8,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lbv_kosten_erlaeuterung VARCHAR(100) DEFAULT NULL;

-- Begleitpersonen 1 bis 4 des Vordrucks. teacher_id ist gesetzt, wenn die
-- Person aus dem Kollegium gewaehlt wurde, und leer bei Eltern oder anderen
-- Begleitern. Name und Vorname stehen trotzdem immer hier: der Ausdruck soll
-- auch dann noch stimmen, wenn das Konto spaeter umbenannt oder geloescht wird.
CREATE TABLE IF NOT EXISTS extracurricular_begleitpersonen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    teacher_id INT DEFAULT NULL,
    nachname VARCHAR(100) NOT NULL,
    vorname VARCHAR(100) NOT NULL DEFAULT '',
    in_ausbildung TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_begleitperson_position (request_id, position),
    CONSTRAINT fk_begleitperson_antrag FOREIGN KEY (request_id) REFERENCES extracurricular_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_begleitperson_lehrkraft FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
