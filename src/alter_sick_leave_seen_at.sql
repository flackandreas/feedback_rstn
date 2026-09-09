-- Wann die Schulleitung eine Krankmeldung gelesen hat.
--
-- Bisher merkte sich die Tabelle nur ob, nicht wann. Fuer die Lehrkraft ist
-- aber genau das die Auskunft, die zaehlt: wer sich Freitagnachmittag krank
-- meldet, will wissen, ob es vor Montag angekommen ist.
--
-- Bereits gelesene Meldungen bekommen keinen Zeitpunkt nachgetragen - einen zu
-- erfinden waere schlimmer, als keinen zu zeigen.
ALTER TABLE sick_leave_reports ADD COLUMN IF NOT EXISTS seen_at DATETIME DEFAULT NULL;
