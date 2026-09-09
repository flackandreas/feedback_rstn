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
