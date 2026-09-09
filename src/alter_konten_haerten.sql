-- Die beiden mitgelieferten Konten aus init.sql.
--
-- "admin" hat dort das Passwort "admin" und force_password_change = 0. Auf
-- einer frisch aufgesetzten Installation steht dieses Konto damit offen.
-- Verglichen wird der genaue Hash: ein bereits geaendertes Passwort bleibt
-- unangetastet.
UPDATE teachers
   SET force_password_change = 1
 WHERE kuerzel = 'admin'
   AND passwort_hash = '$2y$10$4aI/.pBlZUV.ltYBK1wJ..fwPLdyzvNHsotVWcZ8HcVdoOOprSOH.';

-- "test" hat einen Hash, der gegen kein Passwort verifiziert - das Konto ist
-- tot und dient nur dazu, ein gueltiges Kuerzel zu verraten. Nur loeschen,
-- wenn nie etwas damit gemacht wurde.
DELETE FROM teachers
 WHERE kuerzel = 'test'
   AND passwort_hash = '$2y$10$K7M3J4s/n/U.t.G9W636r.P/bZq3w/I.w9I.K.2/J7.3K.2.h.QyC'
   AND id NOT IN (SELECT teacher_id FROM sick_leave_reports)
   AND id NOT IN (SELECT teacher_id FROM exemption_requests)
   AND id NOT IN (SELECT teacher_id FROM extracurricular_requests);
