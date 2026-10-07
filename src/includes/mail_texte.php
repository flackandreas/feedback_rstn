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
            . "Du findest sie auch im Antragssystem unter „Meine Anträge“.</p>\n";
    } elseif ($status === 'query') {
        $body .= "<p>zu deinem Antrag auf $request_type_text gibt es eine <strong>Rückfrage der Schulleitung</strong>.</p>";
        $body .= "<p>Bitte halte kurz Rücksprache mit der Schulleitung.</p>";
    } else {
        $body .= "<p>dein Antrag auf $request_type_text wurde soeben <strong>{$status_text}</strong>.</p>";
    }

    if ($tabelle === 'extracurricular_requests') {
        $start = date('d.m.Y', strtotime($antrag['event_date']));
        $end = (!empty($antrag['event_date_to']) && $antrag['event_date_to'] !== $antrag['event_date'])
            ? date('d.m.Y', strtotime($antrag['event_date_to']))
            : null;

        $date_str = $end ? "vom $start bis $end" : "am $start";
        $body .= "<p>Details: " . mail_html($antrag['class_name'] ?? '') . " nach " . mail_html($antrag['destination'] ?? '') . " $date_str</p>";
    } else {
        $body .= "<p>Details: Zeitraum vom " . date('d.m.Y', strtotime($antrag['date_from'])) . " bis " . date('d.m.Y', strtotime($antrag['date_to'])) . "</p>";
    }

    $body .= "<p>Viele Grüße,<br>Dein Feedback-System Team</p>";

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
