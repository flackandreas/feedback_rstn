<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$html = '
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Projektideen & Erweiterungen - Feedback Tool</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; line-height: 1.5; color: #333; }
        h1 { color: #2c3e50; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        h2 { color: #2980b9; margin-top: 30px; font-size: 18px; }
        h3 { color: #34495e; margin-bottom: 5px; font-size: 15px; }
        p { margin-top: 5px; font-size: 14px; }
        ul { margin-top: 5px; padding-left: 20px; font-size: 14px; }
        li { margin-bottom: 8px; }
        .section { margin-bottom: 30px; }
        .code { background-color: #f8f9fa; padding: 2px 4px; border-radius: 3px; font-family: monospace; font-size: 0.9em; box-shadow: inset 0 0 0 1px #e1e4e8; }
    </style>
</head>
<body>
    <h1>Konzeptionspapier: Projektideen & Erweiterungen</h1>
    <p>Detaillierte Aufschlüsselung potenzieller Features für das Feedback- und Verwaltungs-Tool zur Stärkung der Digitalisierung im Schulalltag.</p>
    
    <div class="section">
        <h2>1. E-Mail- & Benachrichtigungssystem (SMTP)</h2>
        <p><strong>Ziel:</strong> Automatisierte Informationsflüsse zwischen Kollegium, Sekretariat und Schulleitung, ohne dass das System permanent manuell geprüft werden muss.</p>
        <h3>Umsetzung & Architektur:</h3>
        <ul>
            <li><strong>Bibliothek:</strong> Installation des <span class="code">PHPMailer</span>-Pakets via Composer (<span class="code">composer require phpmailer/phpmailer</span>). Das standard <span class="code">mail()</span> von PHP ist fehleranfällig und landet oft ungefragt im Spam.</li>
            <li><strong>Datenbank:</strong> Die Tabelle <span class="code">app_settings</span> wird um SMTP-Zugangsdaten (Host, Port, User, Passwort) erweitert.</li>
            <li><strong>Trigger:</strong> In Dateien wie <span class="code">admin_action.php</span> oder <span class="code">krankmeldung.php</span> wird eine Hilfsfunktion <span class="code">send_notification_email($to, $subject, $body)</span> eingebaut, die direkt nach dem Schreiben der Daten in die DB aufgerufen wird.</li>
            <li><strong>Cronjob:</strong> Für die heute entworfene automatische Liste (Krankmeldungen & Ausflüge) wird eine Datei <span class="code">cron_report.php</span> erstellt. Diese wird vom Betriebssystem des Servers z.B. täglich um 07:00 Uhr über Crontab ausgeführt, liest die DB aus und mailt das fertige PDF an das Sekretariat.</li>
        </ul>
    </div>

    <div class="section">
        <h2>2. Kalender-Integration (iCal/ICS Export)</h2>
        <p><strong>Ziel:</strong> Alle relevanten Abwesenheiten (Krankheit, Freistellung, Ausflüge) direkt in den Schulkalender (Outlook, Nextcloud, Apple) der Schulleitung pushen.</p>
        <h3>Umsetzung & Architektur:</h3>
        <ul>
            <li><strong>Endpunkt:</strong> Erstellung einer <span class="code">calendar_feed.php</span>. Diese Seite erfordert kein klassisches Login, sondern nutzt einen zufällig generierten Secret-Token in der URL (z.B. <span class="code">?token=A1b2...</span>), um den Kalender vor Unbefugten zu schützen.</li>
            <li><strong>Datensammlung:</strong> Die Datei führt SELECT-Abfragen über alle <em>genehmigten</em> Ausflüge und aktuellen Krankmeldungen aus.</li>
            <li><strong>Formatierung:</strong> PHP schreibt keine HTML-Antwort, sondern gibt den HTTP-Header <span class="code">Content-type: text/calendar</span> aus und erzeugt eine Textstruktur im standardisierten vCard/ICS-Format (mit <span class="code">BEGIN:VCALENDAR</span>, <span class="code">DTSTART</span>, <span class="code">DTEND</span>, <span class="code">SUMMARY</span>).</li>
            <li><strong>Nutzen:</strong> Das Sekretariat abonniert diesen Link einmalig. Jede neue Krankmeldung taucht 5 Minuten später auto-synchronisiert im Outlook der Schule auf.</li>
        </ul>
    </div>

    <div class="section">
        <h2>3. Single Sign-On (SSO) & Schul-Login-Anbindung</h2>
        <p><strong>Ziel:</strong> Lehrkräfte müssen sich kein neues Passwort merken. Sie loggen sich mit ihrem bestehenden Schul-Account (IServ, Microsoft 365, Mebis, Nextcloud) ein.</p>
        <h3>Umsetzung & Architektur:</h3>
        <ul>
            <li><strong>Analyse:</strong> Zuerst muss geklärt werden, welchen Identity Provider die Schule nutzt. M365 nutzt OpenID Connect (OIDC), IServ bietet oft SAML oder LDAP an.</li>
            <li><strong>Bibliothek:</strong> Für OIDC nutzt man <span class="code">jumbojett/openid-connect-php</span>, für SAML nutzt man <span class="code">onelogin/php-saml</span>.</li>
            <li><strong>Workflow:</strong> Die <span class="code">login.php</span> wird umgebaut. Statt Formularfeldern gibt es einen Button "Mit Schul-Account einloggen". Dieser leitet Nutzer auf die Microsoft/IServ-Anmeldeseite um. Nach erfolgreichem Login werden sie mit einem Token sicher validiert und zurückgeschickt.</li>
            <li><strong>Datenbank-Sync:</strong> Das System liest E-Mail und Namen aus dem Token. Existiert der Lehrer (<span class="code">teachers</span>) anhand der E-Mail noch nicht, wird er automatisch angelegt.</li>
        </ul>
    </div>

    <div class="section">
        <h2>4. Visuelle Statistiken & Diagramme (Chart.js)</h2>
        <p><strong>Ziel:</strong> Krankheitswellen, Verteilung von Ausflügen auf Wochentage oder das Schülerfeedback visuell aufbereiten, statt nur nackte Tabellen zu zeigen.</p>
        <h3>Umsetzung & Architektur:</h3>
        <ul>
            <li><strong>Bibliothek:</strong> Einbetten der extrem performanten JavaScript-Bibliothek <span class="code">Chart.js</span> über ein CDN im Twig-Template.</li>
            <li><strong>Backend (Datenbereitstellung):</strong> Erstellen einer sauberen JSON-API <span class="code">api_statistics.php</span>, die aggregierte Daten ausliefert. Beispiel: <span class="code">SELECT MONTH(date_from), COUNT(*) FROM sick_leave_reports</span>.</li>
            <li><strong>Frontend:</strong> Im Dashboard wird HTML <span class="code">&lt;canvas&gt;</span> angelegt. Mit JavaScript holt sich die Seite die JSON-Daten asynchron (Fetch-API) und rendert wunderschöne, interaktive Balken- oder Tortendiagramme.</li>
        </ul>
    </div>

    <div class="section">
        <h2>5. Progressive Web App (PWA) für Smartphones</h2>
        <p><strong>Ziel:</strong> Das Werkzeug soll sich wie eine echte Smartphone-App verhalten inklusive Icon, sodass diese jederzeit als Begleiter parat ist.</p>
        <h3>Umsetzung & Architektur:</h3>
        <ul>
            <li><strong>Web App Manifest:</strong> Eine <span class="code">manifest.json</span> im Root-Verzeichnis anlegen. Darin stehen Name, Start-URL, primäre Farben und Pfade zu den App-Icons (PNGs in verschiedenen Größen).</li>
            <li><strong>Service Worker:</strong> Eine <span class="code">sw.js</span>, die Basis-Assets (CSS/Bilder) offline im Local-Cache des Handys abspeichert, sodass die App sofort und blitzschnell lädt, selbst bei schlechtem Internet in der Schule.</li>
            <li><strong>UI Anpassungen:</strong> Die bestehenden Twig-Ansichten noch weiter "Mobile First" trimmen. Elemente und Buttons groß genug für Touchscreens skalieren.</li>
        </ul>
    </div>

    <div class="section">
        <h2>6. Vertretungsmaterial (Cloud-Anbindung)</h2>
        <p><strong>Ziel:</strong> Bei einer Krankmeldung sofortiges Bereitstellen von Aufgaben für die vertretende Lehrkraft, idealerweise DSGVO-konform über die Schul-Cloud.</p>
        <h3>Umsetzung & Architektur:</h3>
        <ul>
            <li><strong>Einfacher Weg (Link-Feld):</strong> In das Krankmeldungsformular wird ein Feld "Link zur Nextcloud/Material" hinzugefügt. Die verhinderte Lehrkraft kopiert den Link zu einem ihrer Cloud-Ordner hinein. Dieser taucht sofort im Vertretungs-Dashboard auf.</li>
            <li><strong>Automatisierung WebDAV (API):</strong> Sobald jemand auf "Meldung abgeben" drückt, spricht das System im Hintergrund automatisch die Schul-Nextcloud an (via WebDAV-Request), legt strukturiert einen Ordner "Vertretung_Mustermann_Datum" an und schickt Links mit den passenden Schreib- und Berechtigungen an die Beteiligten.</li>
        </ul>
    </div>

    <hr style="border: 0; border-top: 1px solid #ccc; margin-top: 30px;">
    <p style="text-align: center; color: #7f8c8d; font-size: 0.9em; margin-bottom: 20px;">Erstellt und formatiert von Antigravity - KI-gestütztes Schulprojekt-Engineering</p>
</body>
</html>
';

$options = new Options();
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$output = $dompdf->output();
file_put_contents(__DIR__ . '/Projektideen_FeedbackTool.pdf', $output);
echo "PDF successfully generated.";
