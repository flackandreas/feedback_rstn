# schulos/sso

OpenID-Connect-Client für die SchulOS-Module. Eine Quelle statt einer Kopie je
Modul.

## Warum es das gibt

Der Anmeldeablauf stand vorher in jedem Modul einzeln — und in
unterschiedlicher Vollständigkeit. Das Unterrichtsmodul prüfte Einmalwert und
Empfänger und benutzte PKCE, das Antragssystem nicht; Fehler endeten dort in
rohen `die()`-Ausgaben. Wer eine Lücke in einer Fassung schloss, ließ sie in
der anderen offen.

Hier steht der Ablauf einmal, mit allen Prüfungen an allen Stellen:

- **PKCE mit S256** — ein abgefangener Autorisierungscode nützt allein nichts.
- **Einmalwert (nonce)** und **state**, beide in der Sitzung, nicht in der
  Adresse.
- **Signaturprüfung über JWKS**, ausschließlich RS256. `alg: none` und die
  HMAC-Verfahren werden abgelehnt — das sind die beiden klassischen
  Umgehungen.
- **Aussteller, Empfänger, Ablauf und `azp`** werden geprüft.
- Das Entdeckungsdokument muss den Aussteller nennen, nach dem gefragt wurde.

## Benutzung

```php
use SchulOS\Sso\Konfiguration;
use SchulOS\Sso\Anmeldung;

$konfiguration = Konfiguration::ausUmgebung('PORTAL_', $cacheVerzeichnis);

if (!$konfiguration->aktiv()) {
    // Kein Anbieter hinterlegt: das Modul zeigt seine eigene Anmeldung.
}

$anmeldung = new Anmeldung($konfiguration);

// Hinweg
header('Location: ' . $anmeldung->startAdresse('/dashboard'));

// Rückweg
$identitaet = $anmeldung->abschliessen($_GET);
$identitaet->sub;        // dauerhafte Kennung — hieran wird verknüpft
$identitaet->kuerzel;    // kann sich ändern, taugt nicht als Schlüssel
$identitaet->gruppen;    // für Rollen im Modul
$identitaet->sitzung;    // sid, für die Abmeldung über den Vorderkanal
```

Die Sitzung muss vorher gestartet sein. Das Paket startet sie bewusst nicht
selbst: die Module setzen eigene Cookie-Namen und -Flags, und ein zweites
`session_start()` würde sie überschreiben.

## Umgebungsvariablen

Alle mit gemeinsamem Vorsatz, hier `PORTAL_`:

| Variable | Bedeutung |
|---|---|
| `ISSUER` | Öffentliche Adresse des Anbieters |
| `CLIENT_ID` | Kennung des Moduls |
| `CLIENT_SECRET` | Geheimnis zwischen Modul und Anbieter |
| `REDIRECT_URI` | Rückadresse, muss beim Anbieter hinterlegt sein |
| `SCOPES` | Vorgabe `openid profile email groups` |
| `INTERN` | Adresse für Abrufe von Server zu Server, siehe unten |
| `LEEWAY` | Erlaubte Zeitabweichung in Sekunden, Vorgabe 60 |

Fehlen `ISSUER`, `CLIENT_ID` oder `CLIENT_SECRET`, meldet `aktiv()` false und
das Modul läuft im Alleinbetrieb weiter. Ist der Satz nur halb ausgefüllt,
nennt `fehlendeAngaben()` die fehlenden Namen — das ist der gefährlichere Fall,
weil das Modul sonst erst beim Anmelden scheitert.

## PORTAL_INTERN

Im Containerverbund spricht der Browser `https://schulos.schule.de`, der
Container erreicht denselben Dienst aber nur als `http://portal`. `INTERN`
setzt die Adresse für Entdeckung, Token, UserInfo und JWKS; Weiterleitungen
bleiben öffentlich.

Umgeschrieben wird nur der bekannte Anfang. Ein untergeschobenes
Entdeckungsdokument kann den internen Aufruf damit nicht auf einen fremden
Server lenken.

Ein Sonderfall macht das nötig: **curl löst Namen unterhalb von `.localhost`
immer selbst auf 127.0.0.1 auf** und übergeht dabei `/etc/hosts`. In einer
Testumgebung mit `schulos.localhost` scheitert der Token-Abruf sonst, egal
welche Einträge gesetzt sind.

## Tests

```bash
docker run --rm -v "$PWD":/app -w /app composer:latest composer install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit
```

Die Tests erzeugen echte RSA-Schlüsselpaare und prüfen damit die Signatur —
kein Double, weil genau diese Prüfung der Kern des Pakets ist.

## Einbindung

Solange das Paket nicht auf GitHub liegt, binden die Module es aus dem
Nachbarverzeichnis ein:

```json
"repositories": [
    { "type": "path", "url": "../../schulos-sso", "options": { "symlink": false } }
]
```

`symlink: false` kopiert statt zu verknüpfen — so landet das Paket in `vendor/`
und wird mit ausgeliefert. **Nach jeder Änderung hier müssen die Module es neu
ziehen:**

```bash
cd /pfad/zum/modul/src
composer update schulos/sso
```

Sobald das Paket auf GitHub liegt, wird daraus ein `vcs`-Repository und der
Schritt entfällt.
