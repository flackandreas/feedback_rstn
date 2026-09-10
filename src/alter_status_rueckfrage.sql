-- Rueckfrage als Status, damit der Knopf der Schulleitung funktioniert.
--
-- Die Oberflaeche kennt die Rueckfrage seit jeher: das Dashboard faerbt sie
-- orange und beschriftet sie mit RUECKFRAGE, die Zaehler der offenen
-- Antraege rechnen sie mit, und die Benachrichtigung an die Lehrkraft ist
-- ausformuliert. Nur die Spalte kannte den Wert nicht. Jeder Klick auf
-- "Rueckfrage" endete deshalb mit "Datenbankfehler bei der Bearbeitung" -
-- der Status blieb stehen, die Lehrkraft erfuhr nichts.
--
-- Der neue Wert wird ANGEHAENGT, nicht eingefuegt: MariaDB speichert die
-- Position, nicht den Text. Ein Einfuegen in der Mitte wuerde jede
-- vorhandene Zeile stillschweigend auf einen anderen Status setzen.

ALTER TABLE extracurricular_requests
  MODIFY status ENUM('pending','approved','rejected','query') DEFAULT 'pending';

ALTER TABLE exemption_requests
  MODIFY status ENUM('pending','approved','rejected','query') DEFAULT 'pending';
