# Schulverwaltung – Digitales Antragssystem (SchoolHub)

Ein webbasiertes Tool für Lehrkräfte und die Schulleitung der Realschule Titisee-Neustadt zur digitalen Erfassung und Verwaltung von Krankmeldungen, Freistellungsanträgen und Anträgen auf außerunterrichtliche Veranstaltungen.

---

## Inhaltsverzeichnis

- [Features](#features)
- [Technologie-Stack](#technologie-stack)
- [Voraussetzungen](#voraussetzungen)
- [Schnellstart mit Docker](#schnellstart-mit-docker)
- [Projektstruktur](#projektstruktur)
- [Konfiguration & `.env`](#konfiguration--env)
  - [Datenbank](#datenbank)
  - [IServ SSO (OpenID Connect)](#iserv-sso-openid-connect)
  - [E-Mail / SMTP](#e-mail--smtp)
- [Benutzer, Rollen & Login](#benutzer-rollen--login)
  - [Klassisches Login](#klassisches-login)
  - [IServ Single Sign-On (SSO)](#iserv-single-sign-on-sso)
  - [Pflicht zur Passwortänderung](#pflicht-zur-passwortänderung)
- [Funktionsübersicht](#funktionsübersicht)
  - [Lehrkraft-Bereich](#lehrkraft-bereich)
  - [Schulleitungs-Bereich (Admin)](#schulleitungs-bereich-admin)
- [Kalender & iCal-Feed](#kalender--ical-feed)
- [CSV-Import & Export](#csv-import--export)
- [E-Mail-Benachrichtigungen & Cronjob](#e-mail-benachrichtigungen--cronjob)
- [Datenbank-Migrationen](#datenbank-migrationen)
- [Standard-Zugangsdaten](#standard-zugangsdaten)
- [Sicherheit](#sicherheit)

---

## Features

- 🔐 **Rollenbasiertes Login & IServ SSO** – Klassischer Login oder nahtloses Single Sign-On via IServ OpenID Connect (OIDC).
- 📝 **Krankmeldung** – Schnelle Erfassung von Abwesenheiten inklusive automatischer Benachrichtigung und Admin-Übersicht.
- 🏖️ **Freistellungsantrag** – Digitale Beantragung von Freistellungen mit Details zu Tagen, Grund und stundenweiser Option.
- 🚌 **Antrag auf außerunterrichtliche Veranstaltung (AUD)** – Vollständiges digitales Formular für Exkursionen, Studienfahrten und Unterrichtsgänge (inkl. Mehrtages-Zeiträumen, Begleitung, Kosten uvm.).
- 📊 **Strukturierte Admin-Verwaltung** – Aufgeteilter Schulleitungsbereich für Übersicht, Krankmeldungen, AUD-Anträge, Lehrerverwaltung, Archiv und Systemsteuerung.
- 📋 **Slide-In Detailpanel** – Antragsdetails lassen sich in der Admin-Ansicht ohne Seitenwechsel einsehen und bearbeiten.
- 📅 **Kalender & iCal Feed** – Übersicht über alle genehmigten Anträge und Termine sowie iCal-Feed-Schnittstelle.
- 📧 **E-Mail-Benachrichtigungen & Cronjob** – Automatische Benachrichtigungen bei Entscheidungen sowie optionaler täglicher Zusammenfassungs-Report (`cron_report.php`).
- 👥 **CSV-Import & Export** – Bequemer Import von Lehrkräften sowie Export von Krankmeldungen.
- 🔑 **Passwort-Änderungszwang** – Pflicht zur Passwortänderung bei neu angelegten oder zurückgesetzten Konten.
- 🔄 **Automatische DB-Migrationen** – Integriertes Schemamanagement für reibungslose Updates.

---

## Technologie-Stack

| Schicht | Technologie |
|---|---|
| Webserver | Apache (PHP 8.2, Docker) |
| Sprache | PHP 8.2 |
| Datenbank | MariaDB 10.11 |
| Templates | Twig 3 |
| SSO / Auth | OpenID Connect (Guzzle / OIDC Client via `.env`) |
| E-Mail | PHPMailer 6 |
| Umgebungsverwaltung | `vlucas/phpdotenv` |
| Container | Docker / Docker Compose |

---

## Voraussetzungen

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (inkl. Docker Compose)
- Kein lokales PHP oder MariaDB zwingend erforderlich

---

## Schnellstart mit Docker

```bash
# 1. Repository klonen / Projektordner öffnen
cd /pfad/zum/projekt

# 2. Umgebungsdatei anlegen
cp src/.env.example src/.env

# 3. Container starten und bauen
docker compose up -d --build

# 4. Datenbank wird via init.sql und automatischen Migrationen initialisiert

# 5. Anwendung im Browser öffnen
open http://localhost:8080
```

> **Hinweis:** Beim ersten Start baut Docker das Image und startet den MariaDB-Container. Falls beim ersten Aufruf eine Datenbankverbindung fehlschlägt, kurz ein paar Sekunden warten, bis MariaDB vollständig gestartet ist.

Um die Container zu stoppen:

```bash
docker compose down
```

---

## Projektstruktur

```
feedback_rstn/
├── Dockerfile                  # PHP 8.2 + Apache + Composer Image
├── docker-compose.yml          # Web + MariaDB Services
├── init.sql                    # Initiales Datenbankschema + Testdaten
├── README.md                   # Dokumentation
├── src/                        # Webroot
│   ├── .env                    # Aktive Umgebungskonfiguration (gitignored)
│   ├── .env.example            # Vorlage für Umgebungsvariablen
│   ├── index.php               # Haupt-Dashboard / Navigation
│   ├── login.php               # Klassisches Login
│   ├── login_sso.php           # IServ OIDC Single Sign-On Handler
│   ├── logout.php              # Session-Beendigung
│   ├── change_password.php     # Passwortänderung
│   ├── krankmeldung.php        # Krankmeldung einreichen
│   ├── antrag_freistellung.php # Freistellungsantrag
│   ├── antrag_ausserunterrichtlich.php # AUD-Antrag
│   ├── meine_antraege.php      # Eigene Anträge der Lehrkraft
│   ├── calendar.php            # Kalenderansicht
│   ├── calendar_feed.php       # iCal Feed Endpunkt
│   ├── admin_dashboard.php     # Schulleitung: Gesamtübersicht & Statistik
│   ├── admin_sick_leaves.php   # Schulleitung: Krankmeldungen
│   ├── admin_aud.php           # Schulleitung: Außerunterrichtliche Veranstaltungen
│   ├── admin_lehrer.php        # Schulleitung: Lehrerverwaltung & CSV-Import
│   ├── admin_archive.php       # Schulleitung: Archiv für Anträge
│   ├── admin_system.php       # Schulleitung: System & Migrationen
│   ├── admin_action.php        # Genehmigen / Ablehnen Endpunkt
│   ├── export_sick_leaves.php  # CSV-Export von Krankmeldungen
│   ├── cron_report.php         # Tägliches Cronjob-Skript für E-Mail-Reports
│   ├── config/
│   │   ├── database.php        # DB-Verbindung & Dotenv-Initialisierung
│   │   ├── mail.php            # SMTP-Konfiguration
│   │   └── config_untis.php    # Untis-Schnittstellen-Konfiguration
│   ├── includes/
│   │   ├── auth.php            # Session, Auth-Prüfung, CSRF-Schutz
│   │   ├── admin_helpers.php   # Hilfsfunktionen für Admin-Auswertungen
│   │   ├── calendar_helper.php# Kalender-Hilfsfunktionen
│   │   ├── mailer.php          # PHPMailer Wrapper
│   │   ├── migrations.php      # Automatische DB-Schema-Migrationen
│   │   └── twig_setup.php      # Twig-Initialisierung
│   ├── templates/              # Twig-Templates
│   └── vendor/                 # Composer-Abhängigkeiten
└── db-data/                    # Persistente MariaDB-Daten (gitignored)
```

---

## Konfiguration & `.env`

Die Konfiguration wird zentral über die Datei **`src/.env`** gesteuert. Erstellen Sie vor dem ersten Start eine Kopie von `src/.env.example`:

```ini
# Datenbank-Konfiguration
DB_HOST=db
DB_USER=root
DB_PASS=db_user
DB_NAME=db_feedback

# IServ OIDC-Konfiguration (für SSO)
ISERV_HOST=https://iserv.meine-schule.de
ISERV_CLIENT_ID=deine-client-id
ISERV_CLIENT_SECRET=dein-client-secret
```

### Datenbank

Die Datenbankverbindung liest die Zugangsdaten aus der `.env`-Datei. Falls keine `.env` vorhanden ist, greift `src/config/database.php` auf Standardwerte für die Docker-Umgebung zurück.

### IServ SSO (OpenID Connect)

Für die Anbindung an den schuleigenen IServ-Server müssen `ISERV_HOST`, `ISERV_CLIENT_ID` und `ISERV_CLIENT_SECRET` in der `.env` hinterlegt sein. Die Redirect-URI lautet:
`https://<DEIN-HOST>/login_sso.php`

### E-Mail / SMTP

Die SMTP-Konfiguration befindet sich in **`src/config/mail.php`**. Tragen Sie dort Ihre SMTP-Zugangsdaten für den Benachrichtigungsversand ein:

```php
return [
    'host'       => 'smtp.example.com',
    'port'       => 587,
    'username'   => 'user@example.com',
    'password'   => 'secret',
    'from_email' => 'noreply@schule.de',
    'from_name'  => 'SchoolHub Feedback',
    'encryption' => 'tls',
    'auth'       => true
];
```

---

## Benutzer, Rollen & Login

Das System unterscheidet zwei Benutzerrollen:

| Rolle | Beschreibung |
|---|---|
| **Lehrkraft** (`is_admin = 0`) | Anträge einreichen, eigene Anträge verwalten, Kalender einsehen |
| **Admin / Schulleitung** (`is_admin = 1`) | Anträge prüfen, genehmigen/ablehnen, Lehrer verwalten, Archiv & System einsehen |

### Klassisches Login

Benutzer können sich über `/login.php` mit ihrem Lehrerkürzel und Passwort anmelden.

### IServ Single Sign-On (SSO)

Über `/login_sso.php` ist eine Anmeldung per IServ OIDC möglich. Meldet sich eine Lehrkraft zum ersten Mal per SSO an, wird automatisch ein entsprechendes Benutzerkonto in der Datenbank angelegt (`is_admin = 0`).

### Pflicht zur Passwortänderung

Wenn das Flag `force_password_change` bei einer Lehrkraft auf `1` steht (z.B. nach Neuanlage mit Standardpasswort), wird der Benutzer nach dem Login automatisch auf `/change_password.php` umgeleitet und kann erst fortfahren, nachdem ein neues Passwort vergeben wurde.

---

## Funktionsübersicht

### Lehrkraft-Bereich

| Seite | URL | Beschreibung |
|---|---|---|
| Dashboard | `/index.php` | Übersicht mit Schnellzugriffen |
| Krankmeldung | `/krankmeldung.php` | Abwesenheitsmeldung (Zeitraum, Vertretung, Notiz) |
| Freistellungsantrag | `/antrag_freistellung.php` | Antrag auf Freistellung (Tage, Grund, Stundenweise) |
| Außerunterrichtliche Veranstaltung | `/antrag_ausserunterrichtlich.php` | Ausflüge, Exkursionen, Studienfahrten |
| Meine Anträge | `/meine_antraege.php` | Status-Übersicht der eigenen Anträge |
| Kalender | `/calendar.php` | Kalenderdarstellung aller relevanten Termine |
| Passwort ändern | `/change_password.php` | Eigenes Passwort aktualisieren |

### Schulleitungs-Bereich (Admin)

| Seite | URL | Beschreibung |
|---|---|---|
| Admin-Dashboard | `/admin_dashboard.php` | Hauptansicht mit Kennzahlen, Event-Statistik und Schnellaktionen |
| Krankmeldungen | `/admin_sick_leaves.php` | Übersicht & Bearbeitung aller Krankmeldungen |
| Außerunterrichtliche Veranstaltungen | `/admin_aud.php` | Übersicht & Genehmigung aller AUD-Anträge |
| Lehrerverwaltung | `/admin_lehrer.php` | Verwaltung von Lehrkraft-Konten & CSV-Import |
| Archiv | `/admin_archive.php` | Durchsuchbares Archiv vergangener Anträge |
| System & Migrationen | `/admin_system.php` | Systemstatus, Ausführung von DB-Migrationen |

---

## Kalender & iCal-Feed

- **Interner Kalender**: Unter `/calendar.php` können Lehrkräfte und Admins genehmigte Anträge und Termine im Kalender einsehen.
- **iCal Feed**: Über `/calendar_feed.php` steht eine Schnittstelle für externe Kalenderanwendungen (z.B. Outlook, Apple Kalender, Thunderbird) bereit.

---

## CSV-Import & Export

### Lehrer-Import

Unter `/admin_lehrer.php` können Lehrkräfte per CSV-Datei importiert werden.

**Format** (Semikolon-getrennt, 1. Zeile = Header):
```csv
Kürzel;Name;Email
mb;Max Mustermann;max@schule.de
mm;Maria Musterfrau;maria@schule.de
```

- Bereits existierende Kürzel werden übersprungen.
- Neue Konten erhalten das Passwort `lehrer` und werden zur Passwortänderung beim ersten Login aufgefordert.

### Krankmeldungen-Export

Über `/export_sick_leaves.php` können gefilterte Krankmeldungsdaten als CSV-Datei für die Weiterverarbeitung heruntergeladen werden.

---

## E-Mail-Benachrichtigungen & Cronjob

- **Statusänderungen**: Bei Genehmigung oder Ablehnung eines Antrags erhält die betroffene Lehrkraft automatisch eine E-Mail-Benachrichtigung.
- **Tagesreports**: Skript `/cron_report.php` kann per System-Cronjob (z.B. täglich um 07:00 Uhr) aufgerufen werden, um zusammenfassende E-Mail-Berichte an die Schulleitung zu versenden:
  ```bash
  0 7 * * 1-5 curl -s http://localhost:8080/cron_report.php > /dev/null
  ```

---

## Datenbank-Migrationen

Das Skript `src/includes/migrations.php` führt beim Start automatisch ausstehende Datenbankschema-Anpassungen aus. Zudem können Schema-Updates im Admin-Bereich unter `/admin_system.php` manuell eingesehen und getriggert werden.

---

## Standard-Zugangsdaten

> ⚠️ **Wichtig:** Diese Zugangsdaten dienen ausschließlich der Entwicklungs- und Testumgebung und müssen vor dem Produktiveinsatz geändert werden!

| Rolle | Kürzel | Passwort |
|---|---|---|
| Administrator | `admin` | `admin` |
| Test-Lehrer | `test` | `lehrer` |

---

## Sicherheit

- **Password Hashing**: Verwendung von `password_hash()` / `password_verify()` (bcrypt).
- **IServ OIDC Security**: OAuth State Verification & Token Validation.
- **CSRF-Schutz**: CSRF-Token-Prüfung bei allen formularbasierten Aktionen.
- **Prepared Statements**: PDO Prepared Statements gegen SQL-Injections across all queries.
- **Session Security**: Sichere Session-Handling-Mechanismen (`httponly`, `SameSite=Strict`).

