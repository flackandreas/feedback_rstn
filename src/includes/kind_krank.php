<?php
/**
 * src/includes/kind_krank.php
 * Meldung "Mein Kind ist krank": Arbeitstage zaehlen und das Kontingent je
 * Kind und Kalenderjahr.
 *
 * Rechtlich ist das Sonderurlaub (Paragraf 29 Abs. 2 AzUVO), bei Angestellten
 * eine Freistellung (Paragraf 29 TV-L, Paragraf 45 SGB V). Die Grenze gilt je
 * Kind und Kalenderjahr in Arbeitstagen - nicht je Schuljahr und nicht in
 * Kalendertagen wie bei den eigenen Krankheitstagen. Ob eine Meldung ueber
 * der Grenze liegt, zeigt das System nur an; entscheiden muss die Schulleitung.
 */

require_once __DIR__ . '/admin_helpers.php';

/**
 * Ostersonntag nach der Gausschen Osterformel (Anonymous Gregorian algorithm).
 * easter_date() braeuchte die calendar-Erweiterung, die das Image nicht hat.
 */
function ostersonntag(int $jahr): DateTimeImmutable
{
    $a = $jahr % 19;
    $b = intdiv($jahr, 100);
    $c = $jahr % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $monat = intdiv($h + $l - 7 * $m + 114, 31);
    $tag = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
}

/**
 * Gesetzliche Feiertage in Baden-Wuerttemberg, als Menge von 'Y-m-d'.
 *
 * @return array<string, true>
 */
function feiertage_bw(int $jahr): array
{
    $ostern = ostersonntag($jahr);
    $tage = [
        "$jahr-01-01", "$jahr-01-06", "$jahr-05-01", "$jahr-10-03",
        "$jahr-11-01", "$jahr-12-25", "$jahr-12-26",
    ];
    foreach ([-2, 1, 39, 50, 60] as $abstand) {
        // Karfreitag, Ostermontag, Christi Himmelfahrt, Pfingstmontag, Fronleichnam
        $tage[] = $ostern->modify(($abstand >= 0 ? '+' : '') . $abstand . ' days')->format('Y-m-d');
    }

    return array_fill_keys($tage, true);
}

/**
 * Arbeitstage zwischen zwei Daten (beide eingeschlossen), aufgeteilt nach
 * Kalenderjahr: Montag bis Freitag ohne Feiertage.
 *
 * Ferien zaehlen mit - wer in den Ferien keinen Dienst hat, meldet dort auch
 * nichts. Teilzeit kennt das System nicht; die Schulleitung kann die Zahl
 * je Meldung korrigieren.
 *
 * @return array<int, int> Jahr => Arbeitstage
 */
function arbeitstage_je_jahr(string $von, string $bis): array
{
    $tag = new DateTimeImmutable($von);
    $ende = new DateTimeImmutable($bis);
    $feiertage = [];
    $je_jahr = [];

    for (; $tag <= $ende; $tag = $tag->modify('+1 day')) {
        $jahr = (int) $tag->format('Y');
        $feiertage[$jahr] ??= feiertage_bw($jahr);
        $je_jahr[$jahr] ??= 0;
        if ((int) $tag->format('N') <= 5 && !isset($feiertage[$jahr][$tag->format('Y-m-d')])) {
            $je_jahr[$jahr]++;
        }
    }

    return $je_jahr;
}

/**
 * Wie viele Tage eine Meldung im Jahr $jahr verbraucht.
 *
 * Hat die Schulleitung die Zahl korrigiert, gilt die - ganz im Jahr des
 * ersten Tages. Eine Betreuung ueber den Jahreswechsel, die zudem korrigiert
 * wurde, ist selten genug, um sie nicht aufzuteilen.
 *
 * @param array<string,mixed> $meldung date_from, date_to, arbeitstage
 */
function kind_krank_tage(array $meldung, int $jahr): int
{
    if ($meldung['arbeitstage'] !== null) {
        return (int) substr((string) $meldung['date_from'], 0, 4) === $jahr ? (int) $meldung['arbeitstage'] : 0;
    }

    return arbeitstage_je_jahr((string) $meldung['date_from'], (string) $meldung['date_to'])[$jahr] ?? 0;
}

/** Alle Arbeitstage einer Meldung, ueber den Jahreswechsel hinweg. */
function kind_krank_tage_gesamt(array $meldung): int
{
    return $meldung['arbeitstage'] !== null
        ? (int) $meldung['arbeitstage']
        : array_sum(arbeitstage_je_jahr((string) $meldung['date_from'], (string) $meldung['date_to']));
}

/**
 * Die Grenzen aus der Systemverwaltung. Alleinerziehende haben nach Paragraf 29
 * Abs. 2 AzUVO wie nach Paragraf 45 SGB V das Doppelte.
 *
 * @return array{je_kind: int, hoechstens: int}
 */
function kind_krank_grenzen(PDO $conn, bool $alleinerziehend): array
{
    $faktor = $alleinerziehend ? 2 : 1;

    return [
        'je_kind' => $faktor * max(1, (int) app_einstellung($conn, 'kind_krank_tage_je_kind', '10')),
        'hoechstens' => $faktor * max(1, (int) app_einstellung($conn, 'kind_krank_tage_hoechstens', '25')),
    ];
}

/** Zwei Schreibweisen desselben Kindes ("Lena", "lena ") zaehlen zusammen. */
function kind_schluessel(string $kind): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $kind)));
}

/**
 * Kind-krank-Meldungen eines Kalenderjahres, je Lehrkraft und je Kind
 * zusammengezaehlt, mit den Grenzen und den Ueberschreitungen.
 *
 * @return list<array<string,mixed>> nach Name der Lehrkraft sortiert
 */
function kind_krank_uebersicht(PDO $conn, int $jahr, ?int $teacher_id = null): array
{
    $sql = "SELECT r.id, r.teacher_id, r.date_from, r.date_to, r.kind, r.alleinerziehend, r.arbeitstage,
                   r.notes, r.attachment_path, r.is_seen, r.seen_at, r.created_at, t.name, t.kuerzel
              FROM sick_leave_reports r
              JOIN teachers t ON t.id = r.teacher_id
             WHERE r.art = 'kind' AND r.date_from <= ? AND r.date_to >= ?"
        . ($teacher_id !== null ? ' AND r.teacher_id = ?' : '')
        . ' ORDER BY t.name, r.date_from';
    $werte = ["$jahr-12-31", "$jahr-01-01"];
    if ($teacher_id !== null) {
        $werte[] = $teacher_id;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($werte);

    $je_lehrkraft = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $tid = (int) $m['teacher_id'];
        $je_lehrkraft[$tid] ??= [
            'id' => $tid,
            'name' => $m['name'],
            'kuerzel' => $m['kuerzel'],
            'alleinerziehend' => false,
            'kinder' => [],
            'gesamt' => 0,
            'meldungen' => [],
        ];
        $l = &$je_lehrkraft[$tid];

        $m['tage'] = kind_krank_tage($m, $jahr);
        $m['automatisch'] = $m['arbeitstage'] === null;
        $l['meldungen'][] = $m;
        $l['gesamt'] += $m['tage'];
        $l['alleinerziehend'] = $l['alleinerziehend'] || (bool) $m['alleinerziehend'];

        // Angezeigt wird die juengste Schreibweise - nach Datum sortiert also
        // die zuletzt gelesene.
        $schluessel = kind_schluessel((string) $m['kind']);
        $l['kinder'][$schluessel]['tage'] = ($l['kinder'][$schluessel]['tage'] ?? 0) + $m['tage'];
        $l['kinder'][$schluessel]['name'] = trim((string) $m['kind']);
        unset($l);
    }

    foreach ($je_lehrkraft as &$l) {
        $l['grenzen'] = kind_krank_grenzen($conn, $l['alleinerziehend']);
        $l['kinder'] = array_values($l['kinder']);
        foreach ($l['kinder'] as &$k) {
            $k['ueber'] = $k['tage'] > $l['grenzen']['je_kind'];
        }
        unset($k);
        $l['ueber'] = $l['gesamt'] > $l['grenzen']['hoechstens']
            || in_array(true, array_column($l['kinder'], 'ueber'), true);
    }
    unset($l);

    return array_values($je_lehrkraft);
}

/**
 * Die Kinder, fuer die eine Lehrkraft schon gemeldet hat - als Vorschlag im
 * Formular, damit "Lena" nicht beim naechsten Mal "Lene" heisst und getrennt
 * gezaehlt wird.
 *
 * @return list<string>
 */
function kind_krank_vorschlaege(PDO $conn, int $teacher_id): array
{
    $stmt = $conn->prepare("SELECT kind FROM sick_leave_reports
                             WHERE teacher_id = ? AND art = 'kind' AND kind IS NOT NULL
                             ORDER BY date_from DESC");
    $stmt->execute([$teacher_id]);

    $namen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $kind) {
        $namen[kind_schluessel((string) $kind)] ??= trim((string) $kind);
    }

    return array_values($namen);
}
