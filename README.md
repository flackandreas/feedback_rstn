# Schulverwaltung – Digitales Antragssystem

Ein webbasiertes Tool für Lehrkräfte und die Schulleitung der Realschule Titisee-Neustadt zur digitalen Erfassung und Verwaltung von Krankmeldungen, Freistellungsanträgen und Anträgen auf außerunterrichtliche Veranstaltungen.

---

## Inhaltsverzeichnis

- [Features](#features)
- [Technologie-Stack](#technologie-stack)
- [Voraussetzungen](#voraussetzungen)
- [Schnellstart mit Docker](#schnellstart-mit-docker)
- [Projektstruktur](#projektstruktur)
- [Konfiguration](#konfiguration)
  - [Datenbank](#datenbank)
  - [E-Mail / SMTP](#e-mail--smtp)
- [Benutzer & Rollen](#benutzer--rollen)
- [Funktionsübersicht](#funktionsübersicht)
  - [Lehrkraft-Bereich](#lehrkraft-bereich)
  - [Schulleitungs-Bereich (Admin)](#schulleitungs-bereich-admin)
- [CSV-Import von Lehrkräften](#csv-import-von-lehrkräften)
- [E-Mail-Benachrichtigungen](#e-mail-benachrichtigungen)
- [Standard-Zugangsdaten](#standard-zugangsdaten)
- [Sicherheit](#sicherheit)

---

## Features

- 🔐 **Rollenbasiertes Login** – Lehrkräfte und Administrator haben getrennte Ansichten
- 📝 **Krankmeldung** einreichen
- 🏖️ **Freistellungsantrag** mit vollständigen Detailfeldern (Tage, Grund, Stundenweise, etc.)
- 🚌 **Antrag auf außerunterrichtliche Veranstaltung** – digitale Version des offiziellen Papierformulars
- ✅ **Admin-Dashboard** – Übersicht aller Anträge mit Genehmigen / Ablehnen
- 📋 **Slide-In Detailpanel** – Alle Felder eines Antrags auf Klick sichtbar, ohne Seite zu verlassen
- 📧 **E-Mail-Benachrichtigung** per PHPMailer – Lehrer wird informiert, sobald ein Antrag bearbeitet wird
- 👥 **CSV-Import** für Lehrkräfte (Kürzel, Name, E-Mail)

---

## Technologie-Stack

| Schicht | Technologie |
|---|---|
| Webserver | Apache (PHP 8.2, Docker) |
| Sprache | PHP 8.2 |
| Datenbank | MariaDB 10.11 |
| Templates | Twig 3 |
| E-Mail | PHPMailer 6 |
| Container | Docker / Docker Compose |

---

## Voraussetzungen

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (inkl. Docker Compose)
- Kein lokales PHP oder MySQL erforderlich

---

## Schnellstart mit Docker

```bash
# 1. Repository klonen / Projektordner öffnen
cd /pfad/zum/projekt

# 2. Container starten
docker compose up -d --build

# 3. Datenbank wird automatisch initialisiert
#    (via init.sql beim ersten Start)

# 4. Anwendung im Browser öffnen
open http://localhost:8080
```

> **Hinweis:** Beim ersten Start kann es einen Moment dauern, bis die MariaDB-Datenbank bereit ist. Falls die Seite sofort einen Datenbankfehler zeigt, einfach kurz warten und neu laden.

Um die Container zu stoppen:

```bash
docker compose down
```

---

## Projektstruktur

```
feedback/
├── Dockerfile                  # PHP 8.2 + Apache + Composer Image
├── docker-compose.yml          # Web + MariaDB Services
├── init.sql                    # Initiales Datenbankschema + Testdaten
├── src/                        # Webroot (wird in Container gemountet)
│   ├── index.php               # Dashboard für Lehrkräfte
│   ├── login.php               # Loginseite
│   ├── logout.php
│   ├── krankmeldung.php        # Krankmeldung einreichen
│   ├── antrag_freistellung.php # Freistellungsantrag
│   ├── antrag_ausserunterrichtlich.php
│   ├── meine_antraege.php      # Eigene Anträge der Lehrkraft
│   ├── admin_dashboard.php     # Schulleitungs-Übersicht
│   ├── admin_action.php        # Genehmigen / Ablehnen (POST-Endpunkt)
│   ├── config/
│   │   ├── database.php        # DB-Verbindung
│   │   └── mail.php            # SMTP-Konfiguration ⚠️
│   ├── includes/
│   │   ├── auth.php            # Session, Login, CSRF
│   │   ├── mailer.php          # PHPMailer Wrapper
│   │   └── twig_setup.php      # Twig-Initialisierung
│   ├── templates/              # Twig-Templates
│   ├── css/
│   ├── js/
│   └── vendor/                 # Composer-Abhängigkeiten (Twig, PHPMailer)
└── db-data/                    # Persistente MariaDB-Daten (gitignored)
```

---

## Konfiguration

### Datenbank

Die Datenbankverbindung ist in `src/config/database.php` definiert. Standardmäßig sind die Werte auf die Docker-Umgebung abgestimmt:

```php
$host = 'db';          // Docker-Service-Name
$db   = 'db_feedback';
$user = 'root';
$pass = 'db_user';
```

Bei Betrieb außerhalb von Docker diese Werte entsprechend anpassen.

### E-Mail / SMTP

Die SMTP-Konfiguration befindet sich in **`src/config/mail.php`**. Diese Datei enthält Platzhalter und muss mit echten Zugangsdaten befüllt werden:

```php
return [
    'host'       => 'smtp.example.com',    // z.B. smtp.gmail.com, mail.schule.de
    'port'       => 587,                   // 587 (STARTTLS) oder 465 (SSL)
    'username'   => 'your_username',
    'password'   => 'your_password',
    'from_email' => 'noreply@school.edu',
    'from_name'  => 'Feedback System',
    'encryption' => 'tls',                 // 'tls' oder 'ssl'
    'auth'       => true
];
```

> ⚠️ Diese Datei sollte **nie** in die Versionskontrolle eingecheckt werden, wenn echte Credentials enthalten sind. Sie ist bereits in `.gitignore` aufgeführt.

Wenn die SMTP-Konfiguration fehlt oder falsch ist, wird beim Bearbeiten eines Antrags trotzdem eine Erfolgsmeldung gezeigt – lediglich die E-Mail-Benachrichtigung entfällt (der Fehler wird in die PHP-Error-Log geschrieben).

---

## Benutzer & Rollen

Das System kennt zwei Rollen:

| Rolle | Beschreibung |
|---|---|
| **Lehrkraft** | Kann Anträge einreichen und eigene Anträge einsehen |
| **Admin (Schulleitung)** | Sieht alle Anträge, kann genehmigen/ablehnen, verwaltet Lehrkräfte |

Die Rolle wird über das Feld `is_admin` in der `teachers`-Tabelle gesteuert (`0` = Lehrkraft, `1` = Admin).

---

## Funktionsübersicht

### Lehrkraft-Bereich

| Seite | URL | Beschreibung |
|---|---|---|
| Dashboard | `/index.php` | Startseite mit Links zu allen Formularen |
| Krankmeldung | `/krankmeldung.php` | Kurze Abwesenheitsmeldung mit Zeitraum & Notiz |
| Freistellungsantrag | `/antrag_freistellung.php` | Detaillierter Antrag mit Tagen, Grund, Stundenweise-Option |
| Außerunterrichtliche Veranstaltung | `/antrag_ausserunterrichtlich.php` | Vollständiges Formular nach dem offiziellen Papierformular (Begleitung, Kosten, Rückkehr, Aufsicht, Einverständnis, ...) |
| Meine Anträge | `/meine_antraege.php` | Übersicht der eigenen letzten Anträge mit Status |

### Schulleitungs-Bereich (Admin)

| Seite | URL | Beschreibung |
|---|---|---|
| Admin-Dashboard | `/admin_dashboard.php` | Übersicht aller Anträge; Zeile anklicken → Slide-In-Panel mit allen Details; ✓/✗ Buttons zum Genehmigen/Ablehnen |
| CSV-Import | Im Admin-Dashboard integriert | Lehrkräfte aus CSV-Datei importieren |

#### Detailpanel im Admin-Dashboard

- Jede Tabellenzeile ist **anklickbar** und öffnet ein **Slide-In-Panel** von rechts
- Das Panel zeigt alle Felder des Antrags übersichtlich an
- Panel schließen: **× Button**, **Klick auf den abgedunkelten Hintergrund** oder **Escape-Taste**
- Die Genehmigen (✓) / Ablehnen (✗) Buttons sind direkt in der Tabellenzeile und öffnen zur Sicherheit einen SweetAlert2-Bestätigungsdialog

---

## CSV-Import von Lehrkräften

Im Admin-Dashboard gibt es den Bereich **„Lehrerverwaltung"**. Dort kann eine CSV-Datei mit Lehrkräften hochgeladen werden.

**Erwartetes Format** (Semikolon-getrennt, erste Zeile wird als Überschrift ignoriert):

```csv
Kürzel;Name;Email
mb;Max Mustermann;max@schule.de
mm;Maria Musterfrau;maria@schule.de
jd;John Doe;
```

Eine Beispieldatei liegt unter `src/test_lehrer.csv`.

**Hinweise:**
- Bereits vorhandene Kürzel werden übersprungen (kein Duplikat)
- Neu importierte Lehrkräfte erhalten das Standard-Passwort: `lehrer`
- Die E-Mail-Spalte ist optional

---

## E-Mail-Benachrichtigungen

Wenn ein Admin einen Antrag **genehmigt oder ablehnt**, wird automatisch an die hinterlegte E-Mail-Adresse der Lehrkraft eine Benachrichtigung versendet. Die E-Mail enthält:

- Den Status der Entscheidung (genehmigt / abgelehnt)
- Die Art des Antrags
- Relevante Details (Zeitraum, Klasse, o.ä.)

**Voraussetzung:** Die Lehrkraft muss eine E-Mail-Adresse in der Datenbank haben (entweder via CSV-Import oder manuell in der DB gepflegt).

---

## Standard-Zugangsdaten

> ⚠️ **Diese Zugangsdaten sind nur für die Entwicklungsumgebung!** Bitte vor dem Produktiveinsatz ändern.

| Rolle | Kürzel | Passwort |
|---|---|---|
| Administrator | `admin` | `admin` |
| Lehrer (Test) | `test` | `lehrer` |

Passwörter können in der Datenbank durch neues Hashing mit `password_hash('neues_passwort', PASSWORD_DEFAULT)` in der Spalte `passwort_hash` der Tabelle `teachers` geändert werden.

---

## Sicherheit

Das System implementiert folgende Sicherheitsmechanismen:

- **Passwort-Hashing** mit `password_hash()` / `password_verify()` (bcrypt)
- **CSRF-Schutz** für alle POST-Formulare via Token
- **Rollenbasierte Zugriffskontrolle** – Admin-Seiten prüfen `is_admin` in der Session
- **Prepared Statements** mit PDO für alle Datenbankabfragen (kein SQL-Injection-Risiko)
- **Session-Management** sicher über PHP-Sessions
