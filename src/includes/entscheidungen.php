<?php
/**
 * src/includes/entscheidungen.php
 * Protokoll der Entscheidungen ueber Antraege (Audit M3), die Rueckfragen
 * der Schulleitung (Audit W2) und die Antworten der Lehrkraefte darauf.
 *
 * Eine eigene Datei, weil nicht nur die Verwaltung sie braucht: Die Lehrkraft
 * sieht unter "Meine Antraege" die Rueckfrage zu ihrem Antrag und antwortet
 * dort.
 *
 * Antworten stehen in derselben Tabelle wie die Entscheidungen, mit
 * art = 'antwort'. So ergibt sich je Antrag ein Verlauf in der richtigen
 * Reihenfolge, der mit dem Antrag geloescht und archiviert wird.
 */

/** Spalte in antrag_entscheidungen, die auf einen Antrag dieser Tabelle zeigt. */
function entscheidung_spalte(string $tabelle): string
{
    return $tabelle === 'extracurricular_requests' ? 'veranstaltung_id' : 'freistellung_id';
}

/** Wer gerade entscheidet, so wie es im Protokoll stehen soll: "Name (Kuerzel)". */
function entscheidung_bearbeiter(): string
{
    $name = trim((string) get_current_user_name());
    $kuerzel = trim((string) get_current_user_kuerzel());

    if ($kuerzel === '') {
        return $name !== '' ? $name : 'unbekannt';
    }

    return $name !== '' ? "$name ($kuerzel)" : $kuerzel;
}

/**
 * Haelt eine Entscheidung der Schulleitung ueber einen Antrag fest (Audit M3).
 *
 * Der Name steht mit im Eintrag, damit das Protokoll lesbar bleibt, wenn das
 * Konto spaeter geloescht wird. Der Eintrag verschwindet mit seinem Antrag
 * beim Jahresabschluss (ON DELETE CASCADE). $nachricht ist der Text einer
 * Rueckfrage (Audit W2).
 */
function entscheidung_protokollieren(PDO $conn, string $tabelle, int $antrag_id, ?string $vorher, string $neu, ?string $nachricht = null): void
{
    $spalte = entscheidung_spalte($tabelle);
    $conn->prepare(
        "INSERT INTO antrag_entscheidungen ($spalte, status_vorher, status_neu, nachricht, entschieden_von, entschieden_von_name)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$antrag_id, $vorher, $neu, $nachricht, get_current_user_id(), mb_substr(entscheidung_bearbeiter(), 0, 255)]);
}

/**
 * Haelt die Antwort der Lehrkraft auf eine Rueckfrage fest.
 *
 * Der Antrag geht damit von 'query' zurueck auf 'pending' - so steht es auch
 * im Eintrag. Die Statusaenderung selbst macht der Aufrufer, in derselben
 * Transaktion.
 */
function antwort_protokollieren(PDO $conn, string $tabelle, int $antrag_id, string $antwort): void
{
    $spalte = entscheidung_spalte($tabelle);
    $conn->prepare(
        "INSERT INTO antrag_entscheidungen ($spalte, art, status_vorher, status_neu, nachricht, entschieden_von, entschieden_von_name)
         VALUES (?, 'antwort', 'query', 'pending', ?, ?, ?)"
    )->execute([$antrag_id, $antwort, get_current_user_id(), mb_substr(entscheidung_bearbeiter(), 0, 255)]);
}

/**
 * Verlauf der Entscheidungen je Antrag, fuer die Detailansicht der Schulleitung.
 *
 * @param list<int|string> $ids
 * @return array<int, list<string>> Antrags-ID => Zeilen wie "25.09.2026 10:35 · genehmigt · Name (Kuerzel)"
 */
function entscheidungen_verlauf(PDO $conn, string $tabelle, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }

    $spalte = entscheidung_spalte($tabelle);
    $stmt = $conn->prepare(
        "SELECT $spalte AS antrag_id, art, status_neu, entschieden_von_name, entschieden_am
           FROM antrag_entscheidungen
          WHERE $spalte IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
          ORDER BY entschieden_am, id"
    );
    $stmt->execute($ids);

    $woerter = ['approved' => 'genehmigt', 'rejected' => 'abgelehnt', 'query' => 'Rückfrage', 'pending' => 'offen'];
    $verlauf = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        // Eine Antwort setzt den Status zwar auf 'pending', "offen" waere hier
        // aber das falsche Wort: es war die Lehrkraft, nicht die Schulleitung.
        $wort = $e['art'] === 'antwort' ? 'beantwortet' : ($woerter[$e['status_neu']] ?? $e['status_neu']);
        $verlauf[(int) $e['antrag_id']][] = date('d.m.Y H:i', strtotime($e['entschieden_am']))
            . ' · ' . $wort
            . ' · ' . $e['entschieden_von_name'];
    }

    return $verlauf;
}

/**
 * Rueckfragen und Antworten je Antrag, in der Reihenfolge, in der sie kamen.
 *
 * Vorher gab es hier nur die juengste Rueckfrage. Seit die Lehrkraft
 * antworten kann und die Schulleitung danach erneut nachfragen darf, gehoert
 * der ganze Wortwechsel dazu - sonst steht die zweite Frage ohne die Antwort
 * da, auf die sie sich bezieht.
 *
 * Rueckfragen von vor dem 26.09.2026 haben keinen Text und fehlen hier.
 *
 * @param list<int|string> $ids
 * @return array<int, list<array{art: string, text: string, von: string, am: string}>>
 *         art ist 'rueckfrage' oder 'antwort'
 */
function rueckfrage_gespraeche(PDO $conn, string $tabelle, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }

    $spalte = entscheidung_spalte($tabelle);
    $stmt = $conn->prepare(
        "SELECT $spalte AS antrag_id, art, nachricht, entschieden_von_name, entschieden_am
           FROM antrag_entscheidungen
          WHERE $spalte IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
            AND nachricht IS NOT NULL
            AND (art = 'antwort' OR status_neu = 'query')
          ORDER BY entschieden_am, id"
    );
    $stmt->execute($ids);

    $gespraeche = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $gespraeche[(int) $e['antrag_id']][] = [
            'art' => $e['art'] === 'antwort' ? 'antwort' : 'rueckfrage',
            'text' => $e['nachricht'],
            'von' => $e['entschieden_von_name'],
            'am' => date('d.m.Y H:i', strtotime($e['entschieden_am'])),
        ];
    }

    return $gespraeche;
}

/**
 * Wartet der Antrag auf die Schulleitung, weil die Lehrkraft geantwortet hat?
 *
 * Das ist der Fall, wenn er wieder offen ist und das Letzte im Wortwechsel
 * eine Antwort war. Entscheidet die Schulleitung danach oder fragt erneut
 * nach, aendert sich der Status, und der Hinweis faellt weg.
 *
 * @param list<array{art: string}> $gespraech
 */
function rueckfrage_beantwortet(string $status, array $gespraech): bool
{
    return $status === 'pending' && $gespraech !== [] && end($gespraech)['art'] === 'antwort';
}

/**
 * Die letzte Rueckfrage mit Text zu einem Antrag - fuer die Mail, die der
 * fragenden Person die Antwort bringt.
 *
 * @return array{text: string, von_id: int|null, von: string, am: string}|null
 */
function rueckfrage_letzte(PDO $conn, string $tabelle, int $antrag_id): ?array
{
    $spalte = entscheidung_spalte($tabelle);
    $stmt = $conn->prepare(
        "SELECT nachricht, entschieden_von, entschieden_von_name, entschieden_am
           FROM antrag_entscheidungen
          WHERE $spalte = ? AND art = 'entscheidung' AND status_neu = 'query' AND nachricht IS NOT NULL
          ORDER BY entschieden_am DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute([$antrag_id]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$e) {
        return null;
    }

    return [
        'text' => $e['nachricht'],
        'von_id' => $e['entschieden_von'] !== null ? (int) $e['entschieden_von'] : null,
        'von' => $e['entschieden_von_name'],
        'am' => date('d.m.Y H:i', strtotime($e['entschieden_am'])),
    ];
}
