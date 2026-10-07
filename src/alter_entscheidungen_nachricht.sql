-- Audit W2 (Mac mini, 26.09.2026)
--
-- Eine Rueckfrage der Schulleitung bekommt einen Text. Er steht beim
-- Protokolleintrag der Entscheidung, geht per Mail an die Lehrkraft und
-- erscheint bei ihr unter Meine Antraege. Spaeter kann hier auch die
-- Begruendung einer Ablehnung stehen (Audit W3).
--
-- Wichtig fuer includes/migrations.php: Die Datei wird an jedem Semikolon
-- zerlegt, in Kommentaren darf deshalb keines stehen.

ALTER TABLE antrag_entscheidungen ADD COLUMN IF NOT EXISTS nachricht TEXT DEFAULT NULL AFTER status_neu;
