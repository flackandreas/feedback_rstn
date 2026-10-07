<?php
/**
 * src/includes/mail_texte.php
 * Texte der Benachrichtigungsmails.
 *
 * Namen, Klassen, Ziele und Veranstaltungsnamen kommen aus den Formularen und
 * werden hier fuer HTML maskiert. Vorher standen sie ungefiltert im HTML der
 * Mail: wer "<a href=...>" in einen Veranstaltungsnamen schrieb, schickte der
 * Schulleitung einen fremden Link unter dem Absender der Schule (Audit M2).
 */

function mail_html($wert): string
{
    return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');
}

/**
 * Ein Verweis ins Antragssystem - oder nichts.
 *
 * Die Adresse kommt ausschliesslich aus APP_URL. Den Host-Kopf der Anfrage
 * setzt der Aufrufer selbst; eine Mail mit einem Link, dessen Ziel ein Fremder
 * bestimmen kann, ist eine Einladung zum Phishing unter dem Absender der
 * Schule. Ist APP_URL nicht gesetzt, bleibt der Link einfach weg.
 */
function mail_link(string $pfad, string $beschriftung): string
{
    $basis = rtrim(trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '')), '/');
    $schema = strtolower((string) parse_url($basis, PHP_URL_SCHEME));

    if ($basis === '' || !in_array($schema, ['http', 'https'], true) || filter_var($basis, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $ziel = $basis . '/' . ltrim($pfad, '/');

    return '<p><a href="' . mail_html($ziel) . '">' . mail_html($beschriftung) . '</a></p>' . "\n";
}

/**
 * Die Zeile mit den Eckdaten eines Antrags, wie sie in jeder Mail steht.
 *
 * Mit Zeilenumbruch am Ende: die Klartextfassung der Mail entsteht durch
 * strip_tags(), ohne ihn klebte die naechste Zeile direkt an das Datum.
 *
 * @param array<string,mixed> $antrag
 */
function mail_text_antrag_details(string $tabelle, array $antrag): string
{
    if ($tabelle === 'extracurricular_requests') {
        $start = date('d.m.Y', strtotime($antrag['event_date']));
        $end = (!empty($antrag['event_date_to']) && $antrag['event_date_to'] !== $antrag['event_date'])
            ? date('d.m.Y', strtotime($antrag['event_date_to']))
            : null;

        $date_str = $end ? "vom $start bis $end" : "am $start";

        return "<p>Details: " . mail_html($antrag['class_name'] ?? '') . " nach " . mail_html($antrag['destination'] ?? '') . " $date_str</p>\n";
    }

    return "<p>Details: Zeitraum vom " . date('d.m.Y', strtotime($antrag['date_from'])) . " bis " . date('d.m.Y', strtotime($antrag['date_to'])) . "</p>\n";
}

/**
 * Mail an die Lehrkraft, nachdem die Schulleitung ueber ihren Antrag entschieden hat.
 *
 * @param string $status  approved | rejected | query
 * @param string $tabelle extracurricular_requests | exemption_requests
 * @param array<string,mixed> $antrag teacher_name und die Daten des Antrags
 * @param string|null $rueckfrage Text der Rueckfrage (Audit W2), nur bei query
 * @param string $von wer die Rueckfrage stellt, "Name (Kuerzel)"
 */
function mail_text_entscheidung(string $status, string $tabelle, array $antrag, ?string $rueckfrage = null, string $von = ''): string
{
    $status_text = 'unbekannt';
    if ($status === 'approved') $status_text = 'genehmigt';
    elseif ($status === 'rejected') $status_text = 'abgelehnt';
    elseif ($status === 'query') $status_text = 'mit Rückfrage versehen';

    $request_type_text = ($tabelle === 'extracurricular_requests') ? 'außerunterrichtliche Veranstaltung' : 'Freistellung';

    $body = "<p>Hallo " . mail_html($antrag['teacher_name'] ?? '') . ",</p>";

    if ($status === 'query' && $rueckfrage !== null && $rueckfrage !== '') {
        // Der Text kommt aus dem Formular der Schulleitung: maskieren, Zeilen erhalten.
        $body .= "<p>zu deinem Antrag auf $request_type_text gibt es eine <strong>Rückfrage der Schulleitung</strong>:</p>\n";
        $body .= '<blockquote style="margin: 0 0 1em 0; padding: 8px 12px; border-left: 4px solid #d97706; background: #fdf6ec;">'
            . nl2br(mail_html($rueckfrage)) . "</blockquote>\n";
        $body .= "<p>" . ($von !== '' ? "Die Rückfrage kommt von " . mail_html($von) . ". " : '')
            . "Du findest sie auch im Antragssystem unter „Meine Anträge“ und kannst dort direkt antworten - "
            . "oder du klärst sie im Gespräch mit der Schulleitung.</p>\n";
        $body .= mail_link('/meine_antraege.php?status=offen', 'Zur Rückfrage in „Meine Anträge“');
    } elseif ($status === 'query') {
        $body .= "<p>zu deinem Antrag auf $request_type_text gibt es eine <strong>Rückfrage der Schulleitung</strong>.</p>";
        $body .= "<p>Bitte halte kurz Rücksprache mit der Schulleitung.</p>";
    } else {
        $body .= "<p>dein Antrag auf $request_type_text wurde soeben <strong>{$status_text}</strong>.</p>";
    }

    $body .= mail_text_antrag_details($tabelle, $antrag);

    $body .= "<p>Viele Grüße,<br>Dein Feedback-System Team</p>";

    return $body;
}

/**
 * Mail an die Schulleitung, wenn eine Lehrkraft auf ihre Rueckfrage antwortet.
 *
 * Frage und Antwort stehen beide drin: wer die Mail liest, soll entscheiden
 * koennen, ohne erst nachzusehen, was eigentlich gefragt war. Beide kommen
 * aus Formularen und werden maskiert, die Zeilenumbrueche bleiben.
 *
 * @param array<string,mixed> $antrag Eckdaten des Antrags
 * @param array{text: string, von: string, am: string}|null $frage die beantwortete Rueckfrage
 */
function mail_text_rueckfrage_antwort(string $tabelle, array $antrag, string $lehrkraft, ?array $frage, string $antwort): string
{
    $art = ($tabelle === 'extracurricular_requests') ? 'außerunterrichtliche Veranstaltung' : 'Freistellung';
    $zitat = '<blockquote style="margin: 0 0 1em 0; padding: 8px 12px; border-left: 4px solid %s; background: %s;">%s</blockquote>' . "\n";

    $body = "<p><strong>" . mail_html($lehrkraft) . "</strong> hat auf die Rückfrage zum Antrag auf $art geantwortet. "
        . "Der Antrag steht wieder auf „Ausstehend“.</p>\n";

    if ($frage !== null) {
        $body .= "<p>Rückfrage von " . mail_html($frage['von']) . " am " . mail_html($frage['am']) . ":</p>\n";
        $body .= sprintf($zitat, '#d97706', '#fdf6ec', nl2br(mail_html($frage['text'])));
    }

    $body .= "<p>Antwort:</p>\n";
    $body .= sprintf($zitat, '#2563eb', '#eff6ff', nl2br(mail_html($antwort)));

    $body .= mail_text_antrag_details($tabelle, $antrag);
    $body .= mail_link(
        $tabelle === 'extracurricular_requests' ? '/admin_aud.php' : '/admin_dashboard.php',
        'Antrag im Antragssystem öffnen'
    );

    return $body;
}

/**
 * Mail an die Schulleitung, wenn eine schon genehmigte Veranstaltung geaendert wurde.
 */
function mail_text_aenderung_nach_genehmigung(string $lehrkraft, string $veranstaltung, string $klasse): string
{
    return "<h3>Achtung: Änderung!</h3>
        <p>Der bereits genehmigte Antrag von <strong>" . mail_html($lehrkraft) . "</strong>
        für die Veranstaltung <strong>" . mail_html($veranstaltung) . "</strong> (Klasse " . mail_html($klasse) . ") wurde nachträglich geändert.</p>
        <p>Bitte überprüfen Sie die Änderungen im Admin-Dashboard.</p>";
}
