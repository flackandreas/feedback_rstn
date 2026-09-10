<?php
/**
 * src/includes/klassen.php
 * Die Klassenliste fuer die Antragsformulare.
 *
 * Die Klassen gehoeren dem Unterrichtsmodul und stehen in dessen Datenbank.
 * Dieses Modul sieht sie nur, wenn beide zusammen betrieben werden und in
 * db_feedback eine Sicht darauf liegt - so, wie umgekehrt das Unterrichtsmodul
 * die Lehrkraefte ueber eine Sicht aus db_feedback liest.
 *
 * Fehlt sie, ist das kein Grund, das ganze Formular scheitern zu lassen. Genau
 * das ist bisher passiert: "Freistellung" und "Ausserunterrichtliche
 * Veranstaltung" endeten mit einer Fehlerseite, weil eine Auswahlliste nicht
 * geladen werden konnte. Eine fehlende Nebensache darf die Hauptsache nicht
 * mitreissen - das Formular laesst sich auch ohne vorgegebene Klassenliste
 * ausfuellen.
 */

/**
 * @return array{alle: list<array<string,mixed>>, eigene: list<int>}
 */
function klassen_fuer_formular(PDO $conn, int $teacherId): array
{
    static $gemeldet = false;

    $leer = ['alle' => [], 'eigene' => []];

    try {
        $alle = $conn->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();
    } catch (PDOException $e) {
        if (!$gemeldet) {
            error_log('Antragssystem: Klassenliste nicht verfuegbar, das Auswahlfeld bleibt leer. '
                . 'Sie kommt aus dem Unterrichtsmodul; siehe LIESMICH. Meldung: ' . $e->getMessage());
            $gemeldet = true;
        }

        return $leer;
    }

    try {
        $abfrage = $conn->prepare('SELECT class_id FROM teacher_classes WHERE teacher_id = ?');
        $abfrage->execute([$teacherId]);
        $eigene = array_map('intval', $abfrage->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (PDOException $e) {
        // Die Vorauswahl ist Bequemlichkeit, keine Bedingung.
        error_log('Antragssystem: eigene Klassen nicht ermittelbar: ' . $e->getMessage());
        $eigene = [];
    }

    return ['alle' => is_array($alle) ? $alle : [], 'eigene' => $eigene];
}

/**
 * Zerlegt das Feld class_name in einzelne Klassen.
 *
 * Gespeichert wird eine kommagetrennte Liste in einer Textspalte, keine
 * Verknuepfungstabelle. Das ist Absicht: der Wert wird ausschliesslich
 * angezeigt, nie gejoint oder gefiltert, und alle zwoelf Klassen der Schule
 * zusammen ergeben 48 Zeichen bei 100 verfuegbaren.
 *
 * @return list<string>
 */
function klassen_aus_text(?string $liste): array
{
    if ($liste === null || trim($liste) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $liste)), static fn (string $k): bool => $k !== ''));
}

/**
 * Kuerzt eine Klassenliste fuer eine Ueberschrift.
 *
 * "5a, 5b, 6a, 6b, 7a, 7b" wird zu "5a, 5b +4". Gedacht fuer den Titel eines
 * Kalendereintrags: in der Monatsansicht steht dafuer eine Zeile zur
 * Verfuegung, und ein Titel, der die Zeile sprengt, verdeckt das Wesentliche.
 * Die vollstaendige Liste bleibt in den Details.
 */
function klassen_kurz(?string $liste, int $zeigen = 2): string
{
    $klassen = klassen_aus_text($liste);

    if (count($klassen) <= $zeigen + 1) {
        return implode(', ', $klassen);
    }

    return implode(', ', array_slice($klassen, 0, $zeigen)) . ' +' . (count($klassen) - $zeigen);
}
