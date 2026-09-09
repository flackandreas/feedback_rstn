<?php
/**
 * src/includes/sso.php
 * Anbindung an das SchulOS-Portal.
 *
 * Solange PORTAL_ISSUER, PORTAL_CLIENT_ID und PORTAL_CLIENT_SECRET nicht
 * gesetzt sind, ist hier alles aus und das Modul verhaelt sich wie bisher:
 * eigene Anmeldemaske, eigene Konten. Das ist Absicht - eine Schule soll
 * dieses Modul auch ohne Portal betreiben koennen.
 *
 * Der eigentliche Anmeldeablauf steckt im Paket schulos/sso, damit er nicht
 * in jedem Modul einzeln und unterschiedlich vollstaendig dasteht.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use SchulOS\Sso\Anmeldung;
use SchulOS\Sso\Identitaet;
use SchulOS\Sso\Konfiguration;

/** Liest eine Umgebungsvariable, leer gilt als nicht gesetzt. */
function sso_umgebung(string $name, string $vorgabe = ''): string
{
    $wert = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return (is_string($wert) && trim($wert) !== '') ? trim($wert) : $vorgabe;
}

function sso_konfiguration(): Konfiguration
{
    static $konfiguration = null;

    if ($konfiguration === null) {
        $ablage = __DIR__ . '/../storage/sso';
        if (!is_dir($ablage)) {
            @mkdir($ablage, 0750, true);
        }

        $konfiguration = Konfiguration::ausUmgebung('PORTAL_', is_writable($ablage) ? $ablage : '');
    }

    return $konfiguration;
}

/** Laeuft dieses Modul am Portal? */
function sso_aktiv(): bool
{
    $konfiguration = sso_konfiguration();

    // Eine halb ausgefuellte Konfiguration ist der gefaehrlichere Fall: das
    // Modul meint, es sei am Portal, und scheitert erst beim Anmelden.
    $fehlt = $konfiguration->fehlendeAngaben();
    if ($fehlt !== []) {
        error_log('Antragssystem: Portal-Anbindung unvollstaendig, es fehlt: PORTAL_' . implode(', PORTAL_', $fehlt));
    }

    return $konfiguration->aktiv();
}

function sso_anmeldung(): Anmeldung
{
    return new Anmeldung(sso_konfiguration());
}

/** Adresse des Portals, fuer den Rueckweg aus der Navigation. */
function sso_portal_adresse(): string
{
    return rtrim(sso_umgebung('PORTAL_ISSUER'), '/');
}

/**
 * Steht die Zuordnungstabelle schon?
 *
 * Sie entsteht ueber die Migrationen. Fehlt sie, ist etwas beim Update
 * schiefgelaufen - dann soll die Anmeldung mit einer klaren Meldung
 * stehenbleiben statt mit einem SQL-Fehler.
 */
function sso_zuordnung_bereit(PDO $conn): bool
{
    static $bereit = null;

    if ($bereit === null) {
        try {
            $bereit = $conn->query("SHOW TABLES LIKE 'portal_konten'")->fetch() !== false;
        } catch (PDOException $e) {
            error_log('Antragssystem: Pruefung der Zuordnungstabelle fehlgeschlagen: ' . $e->getMessage());
            $bereit = false;
        }
    }

    return $bereit;
}

/**
 * Sucht das Konto zur Portal-Identitaet, legt es bei Bedarf an und bringt
 * Name, E-Mail und Verwaltungsrecht auf den Stand des Portals.
 *
 * Reihenfolge der Suche:
 *   1. ueber sso_sub - die einzige dauerhaft verlaessliche Kennung
 *   2. ueber das Kuerzel, dann wird sso_sub nachgetragen; so wandert ein
 *      bestehendes Konto beim ersten Portal-Login mit
 *   3. neu anlegen
 *
 * @return array<string,mixed>|null null heisst: kein Zugang
 */
function sso_konto(PDO $conn, Identitaet $identitaet): ?array
{
    $zugangsGruppen = sso_umgebung('PORTAL_ZUGANG_GRUPPEN');
    if ($zugangsGruppen !== '' && !$identitaet->inGruppenListe($zugangsGruppen)) {
        error_log('Antragssystem: Zugang verweigert fuer ' . $identitaet->kuerzel . ' - nicht in ' . $zugangsGruppen);

        return null;
    }

    $istVerwaltung = $identitaet->inGruppenListe(sso_umgebung('PORTAL_ADMIN_GRUPPEN', 'Schulleitung'));

    $suche = $conn->prepare(
        'SELECT t.* FROM teachers t
           JOIN portal_konten p ON p.teacher_id = t.id
          WHERE p.sso_sub = ? LIMIT 1'
    );
    $suche->execute([$identitaet->sub]);
    $konto = $suche->fetch();

    if (!$konto && $identitaet->kuerzel !== '') {
        $suche = $conn->prepare('SELECT * FROM teachers WHERE kuerzel = ? LIMIT 1');
        $suche->execute([$identitaet->kuerzel]);
        $konto = $suche->fetch();

        if ($konto) {
            sso_verknuepfe($conn, $identitaet->sub, (int) $konto['id']);
            error_log('Antragssystem: bestehendes Konto ' . $identitaet->kuerzel . ' mit dem Portal verknuepft.');
        }
    }

    if (!$konto) {
        // SSO-Konten brauchen kein lokales Passwort. bin2hex, weil
        // random_bytes Nullbytes enthalten kann und bcrypt dort abschneidet.
        $platzhalter = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $kuerzel = mb_substr(
            $identitaet->kuerzel !== '' ? $identitaet->kuerzel : 'p_' . substr(sha1($identitaet->sub), 0, 8),
            0,
            50
        );

        $conn->prepare(
            'INSERT INTO teachers (kuerzel, name, email, passwort_hash, is_admin, force_password_change)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $kuerzel,
            mb_substr($identitaet->name, 0, 100),
            $identitaet->email !== '' ? mb_substr($identitaet->email, 0, 255) : null,
            $platzhalter,
            $istVerwaltung ? 1 : 0,
        ]);

        // Ueber das Kuerzel nachlesen: im Unterrichtsmodul ist teachers eine
        // Sicht, und lastInsertId liefert dort nichts Brauchbares.
        $suche = $conn->prepare('SELECT * FROM teachers WHERE kuerzel = ? LIMIT 1');
        $suche->execute([$kuerzel]);
        $konto = $suche->fetch();

        if (!is_array($konto)) {
            error_log('Antragssystem: Das angelegte Konto liess sich nicht wieder lesen.');

            return null;
        }

        sso_verknuepfe($conn, $identitaet->sub, (int) $konto['id']);

        error_log('Antragssystem: Konto aus dem Portal angelegt: ' . $identitaet->kuerzel);
    } else {
        // Das Portal ist die fuehrende Quelle. Wer dort umbenannt oder aus der
        // Schulleitung genommen wird, ist es hier ab der naechsten Anmeldung
        // auch - sonst blieben Rechte in den Modulen stehen.
        $conn->prepare(
            'UPDATE teachers SET name = ?, email = COALESCE(?, email), is_admin = ?, force_password_change = 0 WHERE id = ?'
        )->execute([
            mb_substr($identitaet->name !== '' ? $identitaet->name : (string) $konto['name'], 0, 100),
            $identitaet->email !== '' ? mb_substr($identitaet->email, 0, 255) : null,
            $istVerwaltung ? 1 : 0,
            $konto['id'],
        ]);

        $konto['name'] = $identitaet->name !== '' ? $identitaet->name : $konto['name'];
        $konto['is_admin'] = $istVerwaltung ? 1 : 0;
        $konto['force_password_change'] = 0;
    }

    return is_array($konto) ? $konto : null;
}

/**
 * Haelt fest, welches Portalkonto zu welcher Lehrkraft gehoert.
 *
 * Vorher wird eine eventuelle aeltere Zuordnung derselben Lehrkraft geloest:
 * sonst scheiterte das Verknuepfen, wenn jemand im Portal ein neues Konto
 * bekommen hat.
 */
function sso_verknuepfe(PDO $conn, string $sub, int $teacherId): void
{
    $conn->prepare('DELETE FROM portal_konten WHERE teacher_id = ? OR sso_sub = ?')
        ->execute([$teacherId, $sub]);
    $conn->prepare('INSERT INTO portal_konten (sso_sub, teacher_id) VALUES (?, ?)')
        ->execute([$sub, $teacherId]);
}

/**
 * Uebernimmt das Konto in die Sitzung dieses Moduls.
 *
 * @param array<string,mixed> $konto
 */
function sso_sitzung_starten(array $konto, Identitaet $identitaet, string $idToken): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = $konto['id'];
    $_SESSION['user_kuerzel'] = $konto['kuerzel'];
    $_SESSION['user_name'] = $konto['name'];
    $_SESSION['is_admin'] = $konto['is_admin'];
    $_SESSION['force_password_change'] = 0;

    // Die Sitzungskennung des Portals. Ohne sie liesse sich eine Abmeldung
    // ueber den Vorderkanal nicht der richtigen Sitzung zuordnen.
    $_SESSION['sso_sid'] = $identitaet->sitzung;
    $_SESSION['sso_id_token'] = $idToken;
    $_SESSION['sso_gruppen'] = $identitaet->gruppen;
}

/** Ist die laufende Sitzung ueber das Portal entstanden? */
function sso_sitzung_vom_portal(): bool
{
    return !empty($_SESSION['sso_sid']);
}
