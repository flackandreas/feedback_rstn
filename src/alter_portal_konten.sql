-- Zuordnung Portalkonto -> Lehrkraft.
--
-- Warum eine eigene Tabelle und keine Spalte in teachers?
--
-- Weil teachers im Unterrichtsmodul nur eine Sicht auf diese Tabelle ist -
-- und eine mit "SELECT *" angelegte Sicht uebernimmt spaeter hinzugefuegte
-- Spalten NICHT. Eine Spalte hier waere drueben unsichtbar geblieben, und die
-- Anmeldung dort waere daran gescheitert. Jedes Modul fuehrt seine Zuordnung
-- deshalb selbst, in seiner eigenen Datenbank.
--
-- Warum nicht ueber das Kuerzel verknuepfen? Weil Kuerzel an Schulen nach
-- Jahren neu vergeben werden. Wer Konten am Kuerzel festmacht, vererbt die
-- Antraege und Krankmeldungen der Vorgaengerin an die Nachfolgerin.
CREATE TABLE IF NOT EXISTS portal_konten (
    sso_sub VARCHAR(64) NOT NULL PRIMARY KEY,
    teacher_id INT NOT NULL,
    verknuepft_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ein_portalkonto_je_lehrkraft (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
