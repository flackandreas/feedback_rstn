-- Krankmeldung "Mein Kind ist krank".
--
-- Rechtlich ist das keine Krankmeldung der Lehrkraft, sondern Sonderurlaub
-- nach Paragraf 29 Abs. 2 AzUVO, bei Angestellten eine Freistellung nach
-- Paragraf 29 TV-L und Paragraf 45 SGB V. Sie laeuft trotzdem ueber denselben
-- Meldeweg - morgens geht es in beiden Faellen um die Vertretung. Gezaehlt
-- wird aber getrennt: je Kind und Kalenderjahr, in Arbeitstagen, und nie als
-- eigene Krankheitstage der Lehrkraft. Die zaehlen fuer das Betriebliche
-- Eingliederungsmanagement.
--
-- art              eigene oder kind
-- kind             Vorname oder Kuerzel, nur um je Kind zu zaehlen
-- alleinerziehend  verdoppelt die Hoechstgrenze
-- arbeitstage      von der Schulleitung korrigierte Zahl. Leer heisst, das
--                  System zaehlt Montag bis Freitag ohne Feiertage.
--
-- Die Grenzen stehen in app_settings und lassen sich in der Systemverwaltung
-- aendern. In Baden-Wuerttemberg sind es 10 Arbeitstage je Kind und hoechstens
-- 25 im Kalenderjahr, fuer Alleinerziehende das Doppelte.
--
-- Wichtig fuer includes/migrations.php: Die Datei wird an jedem Semikolon
-- zerlegt, in Kommentaren darf deshalb keines stehen.

ALTER TABLE sick_leave_reports
    ADD COLUMN IF NOT EXISTS art VARCHAR(10) NOT NULL DEFAULT 'eigene',
    ADD COLUMN IF NOT EXISTS kind VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS alleinerziehend TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS arbeitstage SMALLINT UNSIGNED DEFAULT NULL;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('kind_krank_tage_je_kind', '10');
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('kind_krank_tage_hoechstens', '25');
