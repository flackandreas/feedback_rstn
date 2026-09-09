-- Zaehltabelle fuer Anmeldeversuche.
--
-- Bisher gab es an der Anmeldung keine Begrenzung: ein sleep(1) je Fehlversuch
-- haelt automatisiertes Durchprobieren nicht auf.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(190) NOT NULL PRIMARY KEY,
    window_start DATETIME NOT NULL,
    hits INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
