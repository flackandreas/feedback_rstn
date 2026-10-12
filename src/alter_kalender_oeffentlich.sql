-- Ausserunterrichtliche Veranstaltungen: im Schulkalender fuer alle sichtbar?
--
-- Der Schulkalender zeigt einer Lehrkraft bisher nur die eigenen Eintraege,
-- die Schulleitung sieht alle. Mit diesem Haken macht die antragstellende
-- Lehrkraft eine genehmigte Veranstaltung fuer alle angemeldeten Lehrkraefte
-- sichtbar. Ohne Haken bleibt es wie bisher.
--
-- Die Vorgabe ist 0. Auch bestehende Antraege bleiben damit so privat, wie
-- sie es schon waren.
--
-- Wichtig fuer includes/migrations.php: Die Datei wird an jedem Semikolon
-- zerlegt, in Kommentaren darf deshalb keines stehen.

ALTER TABLE extracurricular_requests ADD COLUMN IF NOT EXISTS kalender_oeffentlich TINYINT(1) NOT NULL DEFAULT 0;
