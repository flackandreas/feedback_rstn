<?php
/**
 * src/includes/lbv1211.php
 * Felder und Pruefungen des Vordrucks LBV 1211 (Stand 07/20), "Antrag auf
 * Genehmigung einer Dienstreise - Ausserunterrichtliche Veranstaltung -".
 *
 * Das Formular in antrag_ausserunterrichtlich.php fragt ab, was der Vordruck
 * verlangt, und antrag_pdf.php druckt es in den Vordruck ein. Beide brauchen
 * dieselbe Vorstellung davon, wann ein Antrag vollstaendig ist - deshalb
 * steht sie hier und nicht zweimal.
 */

const LBV_BEGLEITPERSONEN_MAX = 4;

/** Vorschlaege fuer "Art der Veranstaltung", wie sie der Vordruck nennt. */
const LBV_VERANSTALTUNGSARTEN = [
    'Wandertag',
    'Schullandheim',
    'Studien-/Lehrfahrt',
    'Jahresausflug',
    'Betriebsbesichtigung',
    'Projekttage',
];

/**
 * Pflichtangaben des Vordrucks mit ihrer Bezeichnung fuer Fehlermeldungen.
 * Die Schluessel sind zugleich Formularfelder und Spalten.
 */
function lbv_pflichtfelder(): array
{
    return [
        'lbv_personalnummer' => 'Personalnummer',
        'lk_nachname' => 'Name der verantwortlichen Lehrkraft',
        'lk_vorname' => 'Vorname der verantwortlichen Lehrkraft',
        'role' => 'Art der Veranstaltung',
        'destination' => 'Ziel',
        'class_name' => 'Klasse',
        'schueler_anzahl' => 'Zahl der Schülerinnen und Schüler',
        'event_date' => 'Beginn der Reise (Datum)',
        'start_time' => 'Beginn der Reise (Uhrzeit)',
        'ankunft_datum' => 'Ankunft am Veranstaltungsort (Datum)',
        'ankunft_zeit' => 'Ankunft am Veranstaltungsort (Uhrzeit)',
        'abfahrt_datum' => 'Abfahrt am Veranstaltungsort (Datum)',
        'abfahrt_zeit' => 'Abfahrt am Veranstaltungsort (Uhrzeit)',
        'event_date_to' => 'Ende der Reise (Datum)',
        'return_time' => 'Ende der Reise (Uhrzeit)',
        'lbv_kosten_eur' => 'Kosten insgesamt',
    ];
}

/**
 * Was dem Antrag fuer den Vordruck noch fehlt.
 *
 * Nimmt die Eingabe des Formulars ebenso wie eine Zeile der Datenbank. Bei
 * Antraegen von vor der Umstellung fehlt fast alles - dann gibt es kein PDF,
 * sondern die Bitte, den Antrag zu ergaenzen.
 *
 * @return list<string> Bezeichnungen der fehlenden Angaben
 */
function lbv_fehlende_angaben(array $antrag): array
{
    $fehlend = [];
    foreach (lbv_pflichtfelder() as $feld => $bezeichnung) {
        if (trim((string) ($antrag[$feld] ?? '')) === '') {
            $fehlend[] = $bezeichnung;
        }
    }

    if (empty($antrag['bef_oepnv']) && empty($antrag['bef_reisebus']) && empty($antrag['bef_sonstiges'])) {
        $fehlend[] = 'Beförderungsmittel';
    } elseif (!empty($antrag['bef_sonstiges']) && trim((string) ($antrag['bef_sonstiges_text'] ?? '')) === '') {
        $fehlend[] = 'Art und Gründe des sonstigen Beförderungsmittels';
    }

    return $fehlend;
}

/**
 * Teilt "Vorname Nachname" am letzten Leerzeichen.
 *
 * So kommen die Namen aus dem Portal (given_name, family_name). Ein
 * Doppelname wie "Anna Maria Muster" geht damit richtig auf, ein
 * zusammengesetzter Nachname wie "van der Berg" nicht - die Felder bleiben
 * deshalb im Formular aenderbar.
 *
 * @return array{vorname: string, nachname: string}
 */
function lbv_name_teilen(string $name): array
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    $stelle = mb_strrpos($name, ' ');

    if ($stelle === false) {
        return ['vorname' => '', 'nachname' => $name];
    }

    return ['vorname' => mb_substr($name, 0, $stelle), 'nachname' => mb_substr($name, $stelle + 1)];
}

/** Betrag aus "180", "180,50" oder "180.50"; null, wenn es keiner ist. */
function lbv_betrag(string $text): ?float
{
    $text = str_replace([' ', '€'], '', $text);
    if (!preg_match('/^\d{1,6}([.,]\d{1,2})?$/', $text)) {
        return null;
    }

    return (float) str_replace(',', '.', $text);
}

/** Aufenthaltstage von Beginn bis Ende der Reise, beide Tage mitgezaehlt. */
function lbv_tage(string $von, string $bis): ?int
{
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $von);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $bis);
    if (!$a || !$b || $b < $a) {
        return null;
    }

    return $a->diff($b)->days + 1;
}

function lbv_datum_gueltig(string $wert): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

    return $d !== false && $d->format('Y-m-d') === $wert;
}

/** Uhrzeit als HH:MM; Sekunden aus der Datenbank fallen weg. */
function lbv_zeit(?string $wert): string
{
    $wert = (string) $wert;

    return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $wert) ? substr($wert, 0, 5) : '';
}

/**
 * Liest die Eingaben des Formulars, gekuerzt auf die Breite der Spalten.
 *
 * Die Klassen stehen nicht darin - sie prueft der Controller gegen die
 * Klassenliste des Unterrichtsmoduls.
 */
function lbv_eingabe_lesen(array $post): array
{
    $text = static fn (string $feld, int $laenge): string =>
        mb_substr(trim((string) (is_scalar($post[$feld] ?? null) ? $post[$feld] : '')), 0, $laenge);
    $haken = static fn (string $feld): int => isset($post[$feld]) ? 1 : 0;

    $begleitung = lbv_begleitpersonen_lesen($post['begleit'] ?? []);

    return [
        // 1. Persoenliche Angaben
        'lbv_personalnummer' => $text('lbv_personalnummer', 30),
        'lbv_drm' => $text('lbv_drm', 30),
        'lk_nachname' => $text('lk_nachname', 100),
        'lk_vorname' => $text('lk_vorname', 100),
        'lk_in_ausbildung' => $haken('lk_in_ausbildung'),
        'begleitpersonen' => $begleitung['personen'],
        'begleitpersonen_zu_viele' => $begleitung['zu_viele'],
        // 2. Angaben zur Dienstreise
        'role' => $text('role', 255),
        'event_name' => $text('event_name', 255),
        'destination' => $text('destination', 255),
        'schueler_anzahl' => $text('schueler_anzahl', 5),
        'event_date' => $text('event_date', 10),
        'start_time' => lbv_zeit($text('start_time', 8)),
        'ankunft_datum' => $text('ankunft_datum', 10),
        'ankunft_zeit' => lbv_zeit($text('ankunft_zeit', 8)),
        'abfahrt_datum' => $text('abfahrt_datum', 10),
        'abfahrt_zeit' => lbv_zeit($text('abfahrt_zeit', 8)),
        'event_date_to' => $text('event_date_to', 10),
        'return_time' => lbv_zeit($text('return_time', 8)),
        'aufenthaltstage' => $text('aufenthaltstage', 3),
        // 3. Befoerderungsmittel
        'bef_oepnv' => $haken('bef_oepnv'),
        'bef_reisebus' => $haken('bef_reisebus'),
        'bef_sonstiges' => $haken('bef_sonstiges'),
        'bef_sonstiges_text' => $text('bef_sonstiges_text', 1000),
        // 4. Voraussichtliche Kosten
        'lbv_kosten_eur' => $text('lbv_kosten_eur', 12),
        'lbv_kosten_erlaeuterung' => $text('lbv_kosten_erlaeuterung', 100),
        // Schulintern, nicht auf dem Vordruck
        'costs' => $text('costs', 100),
        'start_location' => $text('start_location', 255),
        'return_location' => $text('return_location', 255),
        'return_trip_arranged' => $haken('return_trip_arranged'),
        'consent_form' => in_array($post['consent_form'] ?? '', ['ja', 'nein'], true) ? $post['consent_form'] : '',
    ];
}

/**
 * Die Zeilen "Begleitperson 1 bis 4". Leere Zeilen fallen weg, die
 * uebrigen ruecken auf.
 *
 * @return array{personen: list<array{teacher_id: ?int, nachname: string, vorname: string, in_ausbildung: int}>, zu_viele: bool}
 */
function lbv_begleitpersonen_lesen(mixed $roh): array
{
    $personen = [];
    foreach (is_array($roh) ? $roh : [] as $zeile) {
        if (!is_array($zeile)) {
            continue;
        }
        $id = (string) ($zeile['teacher_id'] ?? '');
        $person = [
            'teacher_id' => ctype_digit($id) ? (int) $id : null,
            'nachname' => mb_substr(trim((string) ($zeile['nachname'] ?? '')), 0, 100),
            'vorname' => mb_substr(trim((string) ($zeile['vorname'] ?? '')), 0, 100),
            'in_ausbildung' => isset($zeile['in_ausbildung']) ? 1 : 0,
        ];
        if ($person['teacher_id'] === null && $person['nachname'] === '' && $person['vorname'] === '') {
            continue;
        }
        $personen[] = $person;
    }

    return [
        'personen' => array_slice($personen, 0, LBV_BEGLEITPERSONEN_MAX),
        'zu_viele' => count($personen) > LBV_BEGLEITPERSONEN_MAX,
    ];
}

/**
 * Prueft die Eingabe gegen den Vordruck.
 *
 * @return list<string> Fehlermeldungen, leer wenn alles stimmt
 */
function lbv_eingabe_pruefen(array $e): array
{
    $fehler = [];

    // Die Klassen prueft der Controller - hier zaehlt nur der Rest.
    $fehlend = lbv_fehlende_angaben($e + ['class_name' => '-']);
    if ($fehlend !== []) {
        $fehler[] = 'Es fehlen: ' . implode(', ', $fehlend) . '.';
    }

    if ($e['lbv_personalnummer'] !== '' && !preg_match('~^[0-9A-Za-z ./-]+$~', $e['lbv_personalnummer'])) {
        $fehler[] = 'Die Personalnummer darf nur Ziffern, Buchstaben, Leerzeichen und / . - enthalten.';
    }

    $daten = ['event_date' => 'Beginn', 'ankunft_datum' => 'Ankunft', 'abfahrt_datum' => 'Abfahrt', 'event_date_to' => 'Ende'];
    foreach ($daten as $feld => $bezeichnung) {
        if ($e[$feld] !== '' && !lbv_datum_gueltig($e[$feld])) {
            $fehler[] = "Das Datum bei „{$bezeichnung}“ ist ungültig.";
        }
    }

    // Beginn <= Ankunft <= Abfahrt <= Ende. Verglichen wird nur, was
    // vollstaendig und gueltig ist - fehlende Angaben meldet schon die Liste oben.
    $zeitpunkt = static function (string $datum, string $zeit): ?string {
        return (lbv_datum_gueltig($datum) && $zeit !== '') ? "$datum $zeit" : null;
    };
    $beginn = $zeitpunkt($e['event_date'], $e['start_time']);
    $ankunft = $zeitpunkt($e['ankunft_datum'], $e['ankunft_zeit']);
    $abfahrt = $zeitpunkt($e['abfahrt_datum'], $e['abfahrt_zeit']);
    $ende = $zeitpunkt($e['event_date_to'], $e['return_time']);

    if ($beginn !== null && $ende !== null && $ende < $beginn) {
        $fehler[] = 'Das Ende der Reise liegt vor ihrem Beginn.';
    }
    if ($ankunft !== null && (($beginn !== null && $ankunft < $beginn) || ($ende !== null && $ankunft > $ende))) {
        $fehler[] = 'Die Ankunft am Veranstaltungsort muss zwischen Beginn und Ende der Reise liegen.';
    }
    if ($abfahrt !== null && (($beginn !== null && $abfahrt < $beginn) || ($ende !== null && $abfahrt > $ende))) {
        $fehler[] = 'Die Abfahrt am Veranstaltungsort muss zwischen Beginn und Ende der Reise liegen.';
    }
    if ($ankunft !== null && $abfahrt !== null && $abfahrt < $ankunft) {
        $fehler[] = 'Die Abfahrt am Veranstaltungsort liegt vor der Ankunft.';
    }

    if ($e['schueler_anzahl'] !== '' && (!ctype_digit($e['schueler_anzahl']) || (int) $e['schueler_anzahl'] < 1)) {
        $fehler[] = 'Die Zahl der Schülerinnen und Schüler muss größer als 0 sein.';
    }
    if ($e['aufenthaltstage'] !== '' && (!ctype_digit($e['aufenthaltstage']) || (int) $e['aufenthaltstage'] < 1 || (int) $e['aufenthaltstage'] > 366)) {
        $fehler[] = 'Die Zahl der Aufenthaltstage ist ungültig.';
    }
    if ($e['lbv_kosten_eur'] !== '' && lbv_betrag($e['lbv_kosten_eur']) === null) {
        $fehler[] = 'Bitte die Kosten als Betrag in Euro angeben, z. B. 180 oder 180,50.';
    }

    if (!empty($e['begleitpersonen_zu_viele'])) {
        $fehler[] = 'Der Vordruck hat Platz für höchstens ' . LBV_BEGLEITPERSONEN_MAX . ' Begleitpersonen.';
    }
    // Bei einer Lehrkraft aus dem Kollegium ergaenzt der Controller den Namen
    // aus dem Konto (lbv_begleitpersonen_abgleichen), frei eingetragene
    // Personen brauchen ihn.
    foreach ($e['begleitpersonen'] as $i => $p) {
        if ($p['teacher_id'] === null && $p['nachname'] === '') {
            $fehler[] = 'Bei Begleitperson ' . ($i + 1) . ' fehlt der Name.';
        }
    }

    return $fehler;
}

/**
 * Die Spalten, die das Formular schreibt, mit ihren Werten.
 *
 * Nicht darin: aud_type, transport, supervisors und schedule_notified. Das
 * Formular fragt sie nicht mehr ab - stuenden sie im UPDATE, loeschte jedes
 * Bearbeiten die Altwerte.
 */
function lbv_spalten(array $e): array
{
    $leer_ist_null = static fn (string $wert): ?string => $wert === '' ? null : $wert;
    $betrag = lbv_betrag($e['lbv_kosten_eur']);

    return [
        'lbv_personalnummer' => $e['lbv_personalnummer'],
        'lbv_drm' => $leer_ist_null($e['lbv_drm']),
        'lk_nachname' => $e['lk_nachname'],
        'lk_vorname' => $e['lk_vorname'],
        'lk_in_ausbildung' => $e['lk_in_ausbildung'],
        'role' => $e['role'],
        // Der Kalender zeigt den Veranstaltungsnamen. Bleibt er leer, steht
        // dort die Art der Veranstaltung statt einer leeren Zeile.
        'event_name' => $e['event_name'] !== '' ? $e['event_name'] : $e['role'],
        'destination' => $e['destination'],
        'schueler_anzahl' => (int) $e['schueler_anzahl'],
        'event_date' => $e['event_date'],
        'start_time' => $e['start_time'],
        'ankunft_datum' => $e['ankunft_datum'],
        'ankunft_zeit' => $e['ankunft_zeit'],
        'abfahrt_datum' => $e['abfahrt_datum'],
        'abfahrt_zeit' => $e['abfahrt_zeit'],
        'event_date_to' => $e['event_date_to'],
        'return_time' => $e['return_time'],
        'aufenthaltstage' => $e['aufenthaltstage'] !== ''
            ? (int) $e['aufenthaltstage']
            : lbv_tage($e['event_date'], $e['event_date_to']),
        'bef_oepnv' => $e['bef_oepnv'],
        'bef_reisebus' => $e['bef_reisebus'],
        'bef_sonstiges' => $e['bef_sonstiges'],
        'bef_sonstiges_text' => $e['bef_sonstiges'] ? $e['bef_sonstiges_text'] : null,
        'lbv_kosten_eur' => $betrag === null ? null : sprintf('%.2f', $betrag),
        'lbv_kosten_erlaeuterung' => $leer_ist_null($e['lbv_kosten_erlaeuterung']),
        'costs' => $e['costs'],
        'start_location' => $e['start_location'],
        'return_location' => $e['return_location'],
        'return_trip_arranged' => $e['return_trip_arranged'],
        'consent_form' => $leer_ist_null($e['consent_form']),
    ];
}

/**
 * Begleitpersonen je Antrag, in der Reihenfolge des Vordrucks.
 *
 * @param list<int|string> $ids
 * @return array<int, list<array<string,mixed>>>
 */
function lbv_begleitpersonen_laden(PDO $conn, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT request_id, position, teacher_id, nachname, vorname, in_ausbildung
                              FROM extracurricular_begleitpersonen
                             WHERE request_id IN ($platzhalter)
                             ORDER BY request_id, position");
    $stmt->execute($ids);

    $je_antrag = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $je_antrag[(int) $p['request_id']][] = $p;
    }

    return $je_antrag;
}

/** Ersetzt die Begleitpersonen eines Antrags. */
function lbv_begleitpersonen_speichern(PDO $conn, int $antrag_id, array $personen): void
{
    $conn->prepare('DELETE FROM extracurricular_begleitpersonen WHERE request_id = ?')->execute([$antrag_id]);

    $einfuegen = $conn->prepare('INSERT INTO extracurricular_begleitpersonen
        (request_id, position, teacher_id, nachname, vorname, in_ausbildung) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_values($personen) as $i => $p) {
        $einfuegen->execute([$antrag_id, $i + 1, $p['teacher_id'], $p['nachname'], $p['vorname'], $p['in_ausbildung']]);
    }
}

/**
 * Gleicht gewaehlte Lehrkraefte mit dem Kollegium ab: unbekannte IDs
 * fallen weg, fehlende Namen kommen aus dem Konto.
 *
 * @param array<int, array{id:int|string, name:string, kuerzel:string}> $kollegium nach ID
 */
function lbv_begleitpersonen_abgleichen(array $personen, array $kollegium): array
{
    foreach ($personen as &$p) {
        if ($p['teacher_id'] !== null && !isset($kollegium[$p['teacher_id']])) {
            $p['teacher_id'] = null;
        }
        if ($p['teacher_id'] !== null && $p['nachname'] === '' && $p['vorname'] === '') {
            $p = array_merge($p, lbv_name_teilen((string) $kollegium[$p['teacher_id']]['name']));
        }
    }
    unset($p);

    return $personen;
}

/**
 * companion und participating_teacher_id aus den Begleitpersonen.
 *
 * Beide Spalten lesen noch die Verwaltung, der Jahresabschluss und die
 * AUD-Tage-Uebersicht. Lehrkraefte stehen in companion wie frueher als
 * "Name (Kuerzel)" - daran erkennt die Uebersicht, wer schon eingeteilt ist.
 *
 * @return array{companion: ?string, participating_teacher_id: ?int}
 */
function lbv_begleitung_altfelder(array $personen, array $kollegium): array
{
    $namen = [];
    $erste_lehrkraft = null;
    foreach ($personen as $p) {
        if ($p['teacher_id'] !== null && isset($kollegium[$p['teacher_id']])) {
            $t = $kollegium[$p['teacher_id']];
            $namen[] = $t['name'] . ' (' . $t['kuerzel'] . ')';
            $erste_lehrkraft ??= (int) $p['teacher_id'];
        } else {
            $namen[] = trim($p['vorname'] . ' ' . $p['nachname']);
        }
    }

    return [
        'companion' => $namen === [] ? null : mb_substr(implode(', ', $namen), 0, 255),
        'participating_teacher_id' => $erste_lehrkraft,
    ];
}

/**
 * Begleitpersonen eines Antrags von vor der Umstellung, fuer das Formular.
 *
 * Damals gab es eine Begleitlehrkraft per ID und eine Namensliste. Beim
 * Bearbeiten stehen sie so schon in den neuen Zeilen und muessen nicht neu
 * eingetippt werden.
 */
function lbv_begleitpersonen_aus_altfeldern(?int $teilnehmende_id, string $companion, array $kollegium): array
{
    $nach_kuerzel = [];
    foreach ($kollegium as $t) {
        $nach_kuerzel[$t['kuerzel']] = $t;
    }

    $personen = [];
    $gesehen = [];
    $dazu = static function (?array $lehrkraft, string $name) use (&$personen, &$gesehen): void {
        if ($lehrkraft !== null) {
            if (isset($gesehen[(int) $lehrkraft['id']])) {
                return;
            }
            $gesehen[(int) $lehrkraft['id']] = true;
            $name = (string) $lehrkraft['name'];
        }
        $personen[] = ['teacher_id' => $lehrkraft !== null ? (int) $lehrkraft['id'] : null, 'in_ausbildung' => 0]
            + lbv_name_teilen($name);
    };

    if ($teilnehmende_id !== null && isset($kollegium[$teilnehmende_id])) {
        $dazu($kollegium[$teilnehmende_id], '');
    }
    foreach (array_filter(array_map('trim', explode(',', $companion))) as $eintrag) {
        if (preg_match('/^(.*)\s+\(([^()]+)\)$/u', $eintrag, $m) && isset($nach_kuerzel[$m[2]])) {
            $dazu($nach_kuerzel[$m[2]], '');
        } else {
            $dazu(null, $eintrag);
        }
    }

    return array_slice($personen, 0, LBV_BEGLEITPERSONEN_MAX);
}
