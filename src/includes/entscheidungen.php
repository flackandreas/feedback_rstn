<?php
/**
 * src/includes/entscheidungen.php
 * Protokoll der Entscheidungen ueber Antraege (Audit M3) und die Rueckfragen
 * der Schulleitung (Audit W2).
 *
 * Eine eigene Datei, weil nicht nur die Verwaltung sie braucht: Die Lehrkraft
 * sieht unter "Meine Antraege" die Rueckfrage zu ihrem Antrag.
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
        "SELECT $spalte AS antrag_id, status_neu, entschieden_von_name, entschieden_am
           FROM antrag_entscheidungen
          WHERE $spalte IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
          ORDER BY entschieden_am, id"
    );
    $stmt->execute($ids);

    $woerter = ['approved' => 'genehmigt', 'rejected' => 'abgelehnt', 'query' => 'Rückfrage', 'pending' => 'offen'];
    $verlauf = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $verlauf[(int) $e['antrag_id']][] = date('d.m.Y H:i', strtotime($e['entschieden_am']))
            . ' · ' . ($woerter[$e['status_neu']] ?? $e['status_neu'])
            . ' · ' . $e['entschieden_von_name'];
    }

    return $verlauf;
}

/**
 * Die juengste Rueckfrage mit Text je Antrag (Audit W2).
 *
 * Rueckfragen von vor dem 26.09.2026 haben keinen Text und fehlen hier.
 *
 * @param list<int|string> $ids
 * @return array<int, array{text: string, von: string, am: string}>
 */
function rueckfragen(PDO $conn, string $tabelle, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }

    $spalte = entscheidung_spalte($tabelle);
    $stmt = $conn->prepare(
        "SELECT $spalte AS antrag_id, nachricht, entschieden_von_name, entschieden_am
           FROM antrag_entscheidungen
          WHERE $spalte IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
            AND status_neu = 'query' AND nachricht IS NOT NULL
          ORDER BY entschieden_am, id"
    );
    $stmt->execute($ids);

    $rueckfragen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        // Aufsteigend sortiert: eine spaetere Rueckfrage ersetzt die fruehere.
        $rueckfragen[(int) $e['antrag_id']] = [
            'text' => $e['nachricht'],
            'von' => $e['entschieden_von_name'],
            'am' => date('d.m.Y H:i', strtotime($e['entschieden_am'])),
        ];
    }

    return $rueckfragen;
}
