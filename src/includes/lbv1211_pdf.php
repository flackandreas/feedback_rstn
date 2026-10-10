<?php
/**
 * src/includes/lbv1211_pdf.php
 * Druckt einen Antrag in den Vordruck LBV 1211 (Stand 07/20).
 *
 * Die Vorlage vorlagen/lbv1211_0720.pdf ist das Original von 2023, einmal
 * aufbereitet, weil das freie FPDI weder Verschluesselung noch komprimierte
 * Querverweise lesen kann:
 *
 *   qpdf --decrypt --object-streams=disable <original>.pdf src/vorlagen/lbv1211_0720.pdf
 *
 * Am Vordruck selbst aendert das nichts. FPDI uebernimmt nur den
 * Seiteninhalt, nicht die Formularfelder: Der Ausdruck hat keine
 * bearbeitbaren Felder, und auch die roten Hinweise "handschriftliche
 * Unterschrift erforderlich" und die Knoepfe "Drucken" und "Speichern"
 * fehlen - im Original sind das Formularfelder. Kaestchen und Linien
 * gehoeren zum Seiteninhalt und bleiben.
 *
 * Gedruckt wird in Punkt von oben links (FPDF mit Einheit 'pt'). Die Kaesten
 * in lbv1211_felder.php sind in Punkt von unten links, wie im PDF.
 */

use setasign\Fpdi\Tfpdf\Fpdi;

require_once __DIR__ . '/lbv1211.php';

const LBV_PDF_VORLAGE = __DIR__ . '/../vorlagen/lbv1211_0720.pdf';
const LBV_PDF_SCHRIFTGRAD = 10.0;
const LBV_PDF_KLEINSTER_GRAD = 6.5;
// Versalhoehe von DejaVu Sans, Anteil am Schriftgrad
const LBV_PDF_VERSALHOEHE = 0.73;

/**
 * Text in einen Kasten: wird er zu breit, erst kleiner, dann gekuerzt.
 *
 * Die Grundlinie liegt knapp ueber der Unterkante. Die Kaesten im Vordruck
 * sitzen auf einer Linie - Unterstrich oder Tabellenrand.
 */
function lbv_pdf_text(Fpdi $pdf, array $kasten, string $text, string $ausrichtung = 'L'): void
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return;
    }

    $breite = $kasten[2] - $kasten[0] - 4;
    $grad = LBV_PDF_SCHRIFTGRAD;
    $pdf->SetFontSize($grad);
    while ($pdf->GetStringWidth($text) > $breite && $grad > LBV_PDF_KLEINSTER_GRAD) {
        $grad -= 0.5;
        $pdf->SetFontSize($grad);
    }

    lbv_pdf_setzen($pdf, $kasten, $text, $grad, $ausrichtung);
    $pdf->SetFontSize(LBV_PDF_SCHRIFTGRAD);
}

/** Setzt eine Zeile im gegebenen Grad; was nicht passt, endet mit "…". */
function lbv_pdf_setzen(Fpdi $pdf, array $kasten, string $text, float $grad, string $ausrichtung = 'L'): void
{
    [$x1, $y1, $x2, $y2] = $kasten;
    $breite = $x2 - $x1 - 4;

    $pdf->SetFontSize($grad);
    if ($pdf->GetStringWidth($text) > $breite) {
        while (mb_strlen($text) > 1 && $pdf->GetStringWidth($text . '…') > $breite) {
            $text = mb_substr($text, 0, -1);
        }
        $text = rtrim($text) . '…';
    }

    $w = $pdf->GetStringWidth($text);
    $x = match ($ausrichtung) {
        'R' => $x2 - 2 - $w,
        'C' => ($x1 + $x2 - $w) / 2,
        default => $x1 + 2,
    };
    $grundlinie = $y1 + min(4.0, ($y2 - $y1 - LBV_PDF_VERSALHOEHE * $grad) / 2);
    $pdf->Text($x, $pdf->GetPageHeight() - $grundlinie, $text);
}

/** Ein "X" mittig ins Kaestchen. */
function lbv_pdf_kreuz(Fpdi $pdf, array $kasten): void
{
    [$x1, $y1, $x2, $y2] = $kasten;
    $h = $pdf->GetPageHeight();
    $rand = 2.0;
    $pdf->SetLineWidth(1.0);
    $pdf->Line($x1 + $rand, $h - ($y2 - $rand), $x2 - $rand, $h - ($y1 + $rand));
    $pdf->Line($x1 + $rand, $h - ($y1 + $rand), $x2 - $rand, $h - ($y2 - $rand));
}

/**
 * Bricht einen Text auf die gegebenen Zeilen um. Passt er nicht, wird die
 * Schrift kleiner; reicht auch das nicht, endet die letzte Zeile mit "…".
 *
 * @param list<array{0:float,1:float,2:float,3:float}> $zeilen
 */
function lbv_pdf_absatz(Fpdi $pdf, array $zeilen, string $text): void
{
    $woerter = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($woerter === []) {
        return;
    }
    $breite = $zeilen[0][2] - $zeilen[0][0] - 4;

    $umbrechen = static function () use ($pdf, $woerter, $breite): array {
        $ergebnis = [];
        $zeile = '';
        foreach ($woerter as $wort) {
            $probe = $zeile === '' ? $wort : "$zeile $wort";
            if ($zeile !== '' && $pdf->GetStringWidth($probe) > $breite) {
                $ergebnis[] = $zeile;
                $zeile = $wort;
            } else {
                $zeile = $probe;
            }
        }
        $ergebnis[] = $zeile;

        return $ergebnis;
    };

    $grad = LBV_PDF_SCHRIFTGRAD;
    $pdf->SetFontSize($grad);
    $umbrochen = $umbrechen();
    while (count($umbrochen) > count($zeilen) && $grad > 8.0) {
        $grad -= 0.5;
        $pdf->SetFontSize($grad);
        $umbrochen = $umbrechen();
    }
    // Immer noch zu lang: der Rest kommt in die letzte Zeile, die
    // lbv_pdf_setzen() dann mit "…" abschneidet.
    $letzte = count($zeilen) - 1;
    if (count($umbrochen) > count($zeilen)) {
        $umbrochen[$letzte] = implode(' ', array_slice($umbrochen, $letzte));
    }

    foreach ($zeilen as $i => $kasten) {
        if (isset($umbrochen[$i])) {
            lbv_pdf_setzen($pdf, $kasten, $umbrochen[$i], $grad);
        }
    }
    $pdf->SetFontSize(LBV_PDF_SCHRIFTGRAD);
}

function lbv_pdf_datum(?string $datum): string
{
    return $datum ? date('d.m.Y', strtotime($datum)) : '';
}

/**
 * Der fertige Ausdruck als PDF.
 *
 * @param array<string,mixed> $antrag eine Zeile aus extracurricular_requests
 * @param list<array<string,mixed>> $begleitpersonen in der Reihenfolge des Vordrucks
 */
function lbv_pdf_erzeugen(array $antrag, array $begleitpersonen, ?DateTimeInterface $heute = null): string
{
    $felder = require __DIR__ . '/lbv1211_felder.php';

    $pdf = new Fpdi('P', 'pt');
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetTitle('Antrag auf Genehmigung einer Dienstreise - Außerunterrichtliche Veranstaltung', true);
    $pdf->SetCreator('SchulOS Antragssystem', true);
    // Schmaler Schnitt: die Felder des Vordrucks sind knapp, und DejaVu
    // kennt neben Umlauten auch Namen wie "Şahin" oder "Kowalczyk-Łuczak".
    $pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
    $pdf->SetFont('DejaVu', '', LBV_PDF_SCHRIFTGRAD);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(0);

    $pdf->setSourceFile(LBV_PDF_VORLAGE);

    // ── Seite 1 ───────────────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->useTemplate($pdf->importPage(1), ['adjustPageSize' => true]);
    $f = $felder[1];

    lbv_pdf_text($pdf, $f['personalnummer'], (string) $antrag['lbv_personalnummer']);
    lbv_pdf_text($pdf, $f['lk_nachname'], (string) $antrag['lk_nachname']);
    lbv_pdf_text($pdf, $f['lk_vorname'], (string) $antrag['lk_vorname']);
    if (!empty($antrag['lk_in_ausbildung'])) {
        lbv_pdf_kreuz($pdf, $f['lk_in_ausbildung']);
    }

    foreach (array_slice(array_values($begleitpersonen), 0, LBV_BEGLEITPERSONEN_MAX) as $i => $p) {
        [$name, $vorname, $ausbildung] = $f['begleitung'][$i];
        lbv_pdf_text($pdf, $name, (string) $p['nachname']);
        lbv_pdf_text($pdf, $vorname, (string) $p['vorname']);
        if (!empty($p['in_ausbildung'])) {
            lbv_pdf_kreuz($pdf, $ausbildung);
        }
    }

    lbv_pdf_text($pdf, $f['art'], (string) $antrag['role']);
    lbv_pdf_text($pdf, $f['ziel'], (string) $antrag['destination']);
    lbv_pdf_text($pdf, $f['beginn_datum'], lbv_pdf_datum($antrag['event_date']), 'C');
    lbv_pdf_text($pdf, $f['beginn_zeit'], lbv_zeit($antrag['start_time']), 'C');
    lbv_pdf_text($pdf, $f['ankunft_datum'], lbv_pdf_datum($antrag['ankunft_datum']), 'C');
    lbv_pdf_text($pdf, $f['ankunft_zeit'], lbv_zeit($antrag['ankunft_zeit']), 'C');
    lbv_pdf_text($pdf, $f['abfahrt_datum'], lbv_pdf_datum($antrag['abfahrt_datum']), 'C');
    lbv_pdf_text($pdf, $f['abfahrt_zeit'], lbv_zeit($antrag['abfahrt_zeit']), 'C');
    lbv_pdf_text($pdf, $f['ende_datum'], lbv_pdf_datum($antrag['event_date_to']), 'C');
    lbv_pdf_text($pdf, $f['ende_zeit'], lbv_zeit($antrag['return_time']), 'C');
    lbv_pdf_text($pdf, $f['aufenthaltstage'], (string) $antrag['aufenthaltstage']);
    lbv_pdf_text($pdf, $f['klasse'], (string) $antrag['class_name']);
    lbv_pdf_text($pdf, $f['schueler_anzahl'], (string) $antrag['schueler_anzahl']);

    foreach (['bef_oepnv', 'bef_reisebus', 'bef_sonstiges'] as $feld) {
        if (!empty($antrag[$feld])) {
            lbv_pdf_kreuz($pdf, $f[$feld]);
        }
    }

    // ── Seite 2 ───────────────────────────────────────────────────────
    $pdf->AddPage();
    $pdf->useTemplate($pdf->importPage(2), ['adjustPageSize' => true]);
    $f = $felder[2];

    // Der Vordruck verlangt bei "sonstigem Verkehrsmittel" Art und Gruende -
    // Platz dafuer ist oben auf Seite 2 unter "Erlaeuterungen".
    if (!empty($antrag['bef_sonstiges']) && trim((string) $antrag['bef_sonstiges_text']) !== '') {
        lbv_pdf_absatz($pdf, $f['erlaeuterungen'], 'Sonstiges Verkehrsmittel: ' . $antrag['bef_sonstiges_text']);
    }

    if ($antrag['lbv_kosten_eur'] !== null && $antrag['lbv_kosten_eur'] !== '') {
        lbv_pdf_text($pdf, $f['kosten'], number_format((float) $antrag['lbv_kosten_eur'], 2, ',', '.'), 'R');
    }
    lbv_pdf_text($pdf, $f['kosten_erlaeuterung'], (string) $antrag['lbv_kosten_erlaeuterung']);

    // Abschnitt 5: Datum des Ausdrucks. Unterschrieben wird von Hand.
    lbv_pdf_text($pdf, $f['datum_antrag'], ($heute ?? new DateTimeImmutable('now'))->format('d.m.Y'), 'C');

    // Abschnitt 6: nur das Kreuz nach der Genehmigung im System. Datum,
    // Unterschrift und Stempel setzt die Schulleitung von Hand.
    if (($antrag['status'] ?? '') === 'approved') {
        lbv_pdf_kreuz($pdf, $f['genehmigt']);
    }

    return $pdf->Output('S');
}

/** Dateiname ohne Personalnummer, z. B. LBV1211_2026-11-16_10a-10b.pdf */
function lbv_pdf_dateiname(array $antrag): string
{
    $klasse = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $antrag['class_name']), '-');

    return 'LBV1211_' . $antrag['event_date'] . ($klasse !== '' ? '_' . substr($klasse, 0, 30) : '') . '.pdf';
}
