<?php
/**
 * src/includes/lbv1211_felder.php
 * Wo die Werte auf dem Vordruck LBV 1211 (Stand 07/20) stehen.
 *
 * Jeder Kasten ist das /Rect eines Formularfelds im Original von 2023, in
 * Punkt von unten links: [x1, y1, x2, y2]. Ausgelesen mit
 *
 *   qpdf --json lbv1211_original.pdf
 *
 * Dahinter steht der Feldname im Original. Gedruckt wird nach der Lage, nicht
 * nach dem Namen - einige Namen fuehren in die Irre:
 *
 * - "Veranstaltungsziel Ort Stadt Land" liegt unter "Art der
 *   ausserunterrichtlichen Veranstaltung", "ausserunterrichtliche
 *   Veranstaltung" unter "Veranstaltungsziel". Die beiden sind vertauscht.
 * - Die drei Kaestchen in Abschnitt 6 heissen "Ich werde die volle
 *   Reisekostenverguetung beantragen_2" usw. - ein Ueberbleibsel einer
 *   aelteren Fassung. Ihre Lage passt genau auf die Kaestchen.
 * - "/DRM" hinter der Personalnummer ist aufgedruckt, kein eigenes Feld.
 *
 * Die Ankreuzkaestchen sind nicht die Feldrahmen, sondern die auf der Seite
 * gedruckten Kaestchen, ausgemessen an der Vorlage bei 300 dpi. In
 * Abschnitt 3 liegen die Feldrahmen gut 2 pt unter den Kaestchen - ein Kreuz
 * nach dem Feldrahmen stuende halb darunter.
 */

return [
    1 => [
        'personalnummer' => [406.7, 614.4, 505.5, 630.6],       // Personalnummer
        'lk_nachname' => [60.2, 569.3, 246.4, 584.4],           // Nachname.0
        'lk_vorname' => [248.6, 568.3, 404.5, 583.6],           // Text6
        'lk_in_ausbildung' => [410.6, 579.0, 420.5, 588.8],     // ja
        // Begleitperson 1 bis 4: Name, Vorname, "in Ausbildung"
        'begleitung' => [
            [[60.1, 523.6, 246.2, 539.1], [248.4, 523.9, 406.2, 539.4], [410.6, 533.8, 420.5, 543.7]], // Nachname.1, Vorname_2, ja_2
            [[59.8, 478.7, 248.0, 493.0], [249.4, 478.8, 406.1, 494.5], [410.6, 488.7, 420.5, 498.6]], // Nachname.2, Vorname_3, ja_3
            [[60.8, 432.5, 247.1, 448.3], [249.6, 432.6, 406.3, 448.8], [410.6, 444.8, 420.5, 454.6]], // Text7, Text8, Check Box11
            [[60.4, 391.1, 247.2, 406.4], [248.4, 390.6, 405.3, 406.7], [410.6, 400.6, 420.5, 410.5]], // Text9, Text10, Check Box12
        ],
        'art' => [60.9, 335.9, 532.8, 354.5],                   // "Veranstaltungsziel Ort Stadt Land" (vertauscht)
        'ziel' => [60.5, 300.2, 532.8, 318.3],                  // "außerunterrichtliche Veranstaltung" (vertauscht)
        'beginn_datum' => [113.7, 267.8, 170.9, 285.6],         // Datum_2
        'beginn_zeit' => [224.4, 267.2, 280.0, 285.2],          // Uhrzeit_2
        'ankunft_datum' => [333.0, 265.3, 388.7, 283.2],        // Text4
        'ankunft_zeit' => [443.8, 267.4, 498.7, 286.3],         // Uhrzeit
        'abfahrt_datum' => [113.1, 229.3, 171.4, 246.7],        // Text1
        'abfahrt_zeit' => [224.9, 229.1, 279.4, 246.0],         // Text2
        'ende_datum' => [334.8, 229.2, 388.9, 246.2],           // Text3
        'ende_zeit' => [443.7, 227.6, 498.2, 245.9],            // Text5
        'aufenthaltstage' => [59.7, 194.4, 277.5, 209.9],       // Text16
        'klasse' => [279.8, 194.9, 551.9, 209.4],               // Klasse
        'schueler_anzahl' => [60.6, 165.5, 291.6, 179.7],       // Zahl der teilnehmenden Schülerinnen
        'bef_oepnv' => [63.8, 112.9, 73.7, 122.7],              // mit einem regelmäßig verkehrenden Beförderungsmittel ...
        'bef_reisebus' => [63.8, 95.4, 73.7, 105.2],            // mit dem Bus
        'bef_sonstiges' => [63.8, 78.1, 73.7, 87.7],            // mit einem sonstigen Verkehrsmittel ...
    ],
    2 => [
        'erlaeuterungen' => [                                   // Text13, Text14, Text15
            [80.9, 764.0, 531.3, 783.6],
            [80.9, 742.2, 532.7, 761.9],
            [80.3, 719.9, 532.1, 739.0],
        ],
        'kosten' => [122.5, 632.8, 193.5, 654.4],               // insgesamt (rechtsbuendig)
        // Kein Feld im Original: die kurze Erlaeuterung rechts neben "EUR".
        'kosten_erlaeuterung' => [225.0, 632.8, 552.0, 654.4],
        'datum_antrag' => [60.0, 422.6, 131.0, 442.2],          // Datum_3
        // Abschnitt 6, erstes Kaestchen: "Die Dienstreise ist notwendig und
        // wird wie beantragt genehmigt."
        'genehmigt' => [63.8, 298.6, 73.7, 308.5],              // "Ich werde die volle Reisekostenvergütung beantragen_2"
    ],
];
