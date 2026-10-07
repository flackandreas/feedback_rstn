-- Antworten der Lehrkraft auf eine Rueckfrage der Schulleitung.
--
-- Sie stehen in derselben Tabelle wie die Entscheidungen, als eigene Art von
-- Eintrag. So gibt es je Antrag einen einzigen Verlauf in der richtigen
-- Reihenfolge - Rueckfrage, Antwort, Entscheidung -, der mit dem Antrag
-- geloescht und vorher mit ihm archiviert wird. Eine eigene Tabelle haette
-- die beiden Verweise auf Freistellung und Veranstaltung verdoppelt und im
-- Jahresabschluss eigenen Code gebraucht.
--
-- Alles, was schon in der Tabelle steht, sind Entscheidungen der
-- Schulleitung - daher die Vorgabe.
--
-- Wichtig fuer includes/migrations.php: Die Datei wird an jedem Semikolon
-- zerlegt, in Kommentaren darf deshalb keines stehen.

ALTER TABLE antrag_entscheidungen ADD COLUMN IF NOT EXISTS art VARCHAR(20) NOT NULL DEFAULT 'entscheidung' AFTER veranstaltung_id;
