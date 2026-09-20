<?php
/**
 * src/includes/ablage.php
 * Ablage fuer hochgeladene Dateien ausserhalb des DocumentRoot.
 *
 * Atteste lagen bisher unter public/uploads/ und wurden direkt verlinkt. Die
 * .htaccess dort schaltet nur die Ausfuehrung von PHP ab, nicht den Abruf -
 * die Adresse funktionierte also fuer jeden, angemeldet oder nicht.
 *
 * Geschuetzt war eine Arbeitsunfaehigkeitsbescheinigung damit allein durch
 * ihren Dateinamen, und der bestand aus uniqid() - einem Zeitstempel in
 * Hexadezimalschreibweise, also vorhersagbar - plus dem Originalnamen der
 * hochgeladenen Datei, in dem oft der Name der Person steht.
 *
 * Gesundheitsdaten von Beschaeftigten sind besondere Kategorien nach Art. 9
 * DSGVO. Sie gehoeren nicht in ein Verzeichnis, das der Webserver ausliefert.
 *
 * Dasselbe Muster wie im Unterrichtsmodul (includes/storage.php): Ablage
 * darunter, Zufallsname, Auslieferung ausschliesslich ueber ein Skript, das
 * prueft, wer die Datei sehen darf.
 */

/** Unterverzeichnisse, die vergeben werden duerfen. */
const ABLAGE_BEREICHE = ['atteste'];

/** Endungen, die vergeben werden duerfen. */
const ABLAGE_ENDUNGEN = ['pdf', 'jpg', 'jpeg', 'png'];

/**
 * Absoluter Pfad des Ablageverzeichnisses.
 */
function ablage_wurzel() {
    return __DIR__ . '/../storage';
}

/**
 * Legt ein Unterverzeichnis an (falls noetig) und gibt seinen Pfad zurueck.
 */
function ablage_verzeichnis($bereich) {
    if (!in_array($bereich, ABLAGE_BEREICHE, true)) {
        throw new InvalidArgumentException('Unbekannter Ablagebereich: ' . $bereich);
    }

    $verzeichnis = ablage_wurzel() . '/' . $bereich;
    if (!is_dir($verzeichnis)) {
        mkdir($verzeichnis, 0750, true);
    }

    return $verzeichnis;
}

/**
 * Unvorhersagbarer Dateiname mit der uebergebenen Endung.
 *
 * Die Endung wird gegen eine feste Liste geprueft, nicht nur bereinigt: ein
 * blosses Entfernen von Sonderzeichen machte aus "../php" ein "php".
 */
function ablage_zufallsname($endung) {
    $endung = preg_replace('/[^a-z0-9]/', '', strtolower((string)$endung));

    if (!in_array($endung, ABLAGE_ENDUNGEN, true)) {
        $endung = 'bin';
    }

    return bin2hex(random_bytes(16)) . '.' . $endung;
}

/**
 * Verschiebt eine hochgeladene Datei in die Ablage.
 *
 * Rueckgabe ist der in der Datenbank zu speichernde Pfad ("atteste/<name>")
 * oder null, wenn das Verschieben scheitert.
 */
function ablage_speichern($tmpName, $bereich, $endung) {
    $verzeichnis = ablage_verzeichnis($bereich);
    $name = ablage_zufallsname($endung);

    if (!move_uploaded_file($tmpName, $verzeichnis . '/' . $name)) {
        return null;
    }

    chmod($verzeichnis . '/' . $name, 0640);

    return $bereich . '/' . $name;
}

/**
 * Loest einen gespeicherten Pfad zu einem absoluten Pfad auf.
 *
 * Zwei Formen, weil die Umstellung im Betrieb passiert:
 *
 *   "atteste/<name>"  die heutige Ablage
 *   "uploads/<name>"  Altbestand unter public/. Solange bin/migrate_atteste.php
 *                     nicht gelaufen ist, bleiben diese Dateien erreichbar -
 *                     aber nur ueber attest.php, nicht mehr ueber ihre Adresse.
 *
 * Gibt null zurueck, wenn der Pfad nicht in eine der beiden Formen passt.
 * Traversierung ist damit ausgeschlossen, nicht bloss erschwert.
 */
function ablage_aufloesen($gespeichert) {
    if (!is_string($gespeichert) || $gespeichert === '') {
        return null;
    }

    if (strpos($gespeichert, '..') !== false) {
        return null;
    }

    if (preg_match('#^([a-z]+)/([A-Za-z0-9._-]+)$#', $gespeichert, $treffer) === 1
        && in_array($treffer[1], ABLAGE_BEREICHE, true)) {
        $pfad = ablage_wurzel() . '/' . $gespeichert;

        return is_file($pfad) ? $pfad : null;
    }

    // Altbestand: der Name traegt noch den Originaldateinamen, darin duerfen
    // Leerzeichen und Umlaute stehen. Kein Schraegstrich, kein Backslash.
    if (preg_match('#^uploads/([^/\\\\]+)$#u', $gespeichert) === 1) {
        $pfad = __DIR__ . '/../public/' . $gespeichert;

        return is_file($pfad) ? $pfad : null;
    }

    return null;
}

/**
 * Loescht eine abgelegte Datei.
 */
function ablage_loeschen($gespeichert) {
    $pfad = ablage_aufloesen($gespeichert);
    if ($pfad !== null && is_file($pfad)) {
        @unlink($pfad);
    }
}

/**
 * MIME-Typ einer abgelegten Datei - aus dem Inhalt, nicht aus dem Namen.
 */
function ablage_mimetyp($absoluterPfad) {
    $typ = @mime_content_type($absoluterPfad);
    $erlaubt = ['application/pdf', 'image/jpeg', 'image/png'];

    return in_array($typ, $erlaubt, true) ? $typ : 'application/octet-stream';
}
