<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Der Anmeldeablauf aus Sicht eines Moduls (Relying Party).
 *
 * Enthaelt genau das, was in den Modulen bisher je einmal von Hand stand - mit
 * PKCE, Einmalwert, geprueftem Aussteller und geprueftem Empfaenger an allen
 * Stellen, nicht nur an der zuletzt angefassten.
 *
 * Die Sitzung muss vor dem Aufruf gestartet sein. Das Paket startet sie
 * bewusst nicht selbst: die Module setzen eigene Cookie-Namen und -Flags, und
 * ein zweites session_start() an dieser Stelle wuerde sie ueberschreiben.
 */
final class Anmeldung
{
    private const SITZUNGSSCHLUESSEL = 'schulos_sso';

    private readonly HttpAbruf $http;
    private readonly Entdeckung $entdeckung;
    private ?Schluesselvorrat $vorrat = null;
    private string $letztesZiel = '/';
    private string $letztesIdToken = '';

    public function __construct(
        private readonly Konfiguration $konfiguration,
        ?HttpAbruf $http = null,
        ?Entdeckung $entdeckung = null,
    ) {
        $this->http = $http ?? new HttpAbruf($konfiguration->tlsPruefen);
        $this->entdeckung = $entdeckung ?? new Entdeckung(
            $this->http,
            $konfiguration->aussteller,
            $konfiguration->cacheVerzeichnis,
            $konfiguration->internerAussteller
        );
    }

    /**
     * Baut die Adresse, zu der der Browser geschickt wird.
     *
     * $zielNachAnmeldung wird in der Sitzung gemerkt, nicht im state-Wert: ein
     * Ziel in der Adresszeile waere eine offene Weiterleitung.
     */
    public function startAdresse(?string $zielNachAnmeldung = null): string
    {
        $this->verlangeSitzung();

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $pruefwert = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $herausforderung = Basis64Url::kodiere(hash('sha256', $pruefwert, true));

        $_SESSION[self::SITZUNGSSCHLUESSEL] = [
            'state' => $state,
            'nonce' => $nonce,
            'pruefwert' => $pruefwert,
            'ziel' => $this->sicheresZiel($zielNachAnmeldung),
            'erzeugt' => time(),
        ];

        return $this->entdeckung->endpunkt('authorization_endpoint') . '?' . http_build_query([
            'client_id' => $this->konfiguration->clientId,
            'redirect_uri' => $this->konfiguration->rueckadresse,
            'response_type' => 'code',
            'scope' => $this->konfiguration->bereiche,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $herausforderung,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Loest den Autorisierungscode ein und liefert die gepruefte Identitaet.
     *
     * @param array<string,mixed> $anfrage Ueblicherweise $_GET
     */
    public function abschliessen(array $anfrage): Identitaet
    {
        $this->verlangeSitzung();

        $merker = $_SESSION[self::SITZUNGSSCHLUESSEL] ?? null;
        // Einmalwerte sofort entwerten, egal wie es ausgeht.
        unset($_SESSION[self::SITZUNGSSCHLUESSEL]);

        if (!is_array($merker)) {
            throw new SsoFehler('Zu diesem Rueckweg gibt es keinen begonnenen Anmeldevorgang.');
        }

        $this->letztesZiel = $this->sicheresZiel((string) ($merker['ziel'] ?? '/'));

        if (time() - (int) ($merker['erzeugt'] ?? 0) > 600) {
            throw new SsoFehler('Der Anmeldevorgang ist zu alt.');
        }

        if (isset($anfrage['error'])) {
            throw new SsoFehler('Der Anbieter hat die Anmeldung abgelehnt: ' . (string) $anfrage['error']);
        }

        $state = (string) ($anfrage['state'] ?? '');
        if ($state === '' || !hash_equals((string) $merker['state'], $state)) {
            throw new SsoFehler('Der state-Wert stimmt nicht.');
        }

        $code = (string) ($anfrage['code'] ?? '');
        if ($code === '') {
            throw new SsoFehler('Es wurde kein Autorisierungscode uebergeben.');
        }

        $antwort = $this->http->sendeFormular($this->entdeckung->intern($this->entdeckung->endpunkt('token_endpoint')), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->konfiguration->rueckadresse,
            'client_id' => $this->konfiguration->clientId,
            'client_secret' => $this->konfiguration->clientGeheimnis,
            'code_verifier' => (string) $merker['pruefwert'],
        ]);

        $idToken = (string) ($antwort['id_token'] ?? '');
        if ($idToken === '') {
            throw new SsoFehler('Die Antwort des Token-Endpunkts enthaelt kein ID-Token.');
        }

        $nutzdaten = Jwt::pruefe($idToken, $this->schluesselvorrat());
        $this->letztesIdToken = $idToken;
        Jwt::pruefeAnspruch(
            $nutzdaten,
            $this->konfiguration->aussteller,
            $this->konfiguration->clientId,
            (string) $merker['nonce'],
            $this->konfiguration->zeitabweichung
        );

        $claims = $nutzdaten;

        // Der UserInfo-Endpunkt ist die verlaesslichere Quelle fuer Angaben,
        // die sich aendern koennen - Name, Adresse, Gruppen.
        $zugriffstoken = (string) ($antwort['access_token'] ?? '');
        if ($zugriffstoken !== '') {
            try {
                $weitere = $this->http->holeJson(
                    $this->entdeckung->intern($this->entdeckung->endpunkt('userinfo_endpoint')),
                    ['Authorization: Bearer ' . $zugriffstoken]
                );

                // Ein UserInfo mit fremdem sub gehoert zu einer anderen Person.
                if (($weitere['sub'] ?? null) === ($nutzdaten['sub'] ?? null)) {
                    $claims = array_merge($claims, $weitere);
                } else {
                    error_log('SchulOS-SSO: UserInfo nennt ein anderes sub als das ID-Token - verworfen.');
                }
            } catch (SsoFehler $e) {
                // Kein Grund, die Anmeldung scheitern zu lassen: alle
                // Pflichtangaben stehen bereits im geprueften ID-Token.
                error_log('SchulOS-SSO: UserInfo nicht abrufbar: ' . $e->getMessage());
            }
        }

        return $this->alsIdentitaet($claims);
    }

    /**
     * Wohin nach erfolgreicher Anmeldung? Der Wert stammt aus der Sitzung, nie
     * aus der Adresszeile - sonst waere jede Anmeldung eine offene
     * Weiterleitung. Erst nach abschliessen() belegt.
     */
    public function zielNachAnmeldung(): string
    {
        return $this->letztesZiel;
    }

    /**
     * Das zuletzt geprueft ID-Token im Rohformat.
     *
     * Das Modul legt es in seiner Sitzung ab und reicht es beim Abmelden als
     * id_token_hint zurueck - daran erkennt der Anbieter, wen er abmeldet.
     */
    public function letztesIdToken(): string
    {
        return $this->letztesIdToken;
    }

    /**
     * Adresse, die den Browser beim Anbieter abmeldet.
     *
     * Ohne end_session_endpoint liefert die Methode null - dann meldet sich das
     * Modul nur lokal ab, und das ist auch alles, was es dann tun kann.
     */
    public function abmeldeAdresse(?string $idToken, ?string $zielNachAbmeldung = null): ?string
    {
        $endpunkt = $this->entdeckung->endpunktOderNull('end_session_endpoint');
        if ($endpunkt === null) {
            return null;
        }

        $felder = ['client_id' => $this->konfiguration->clientId];
        if ($idToken !== null && $idToken !== '') {
            $felder['id_token_hint'] = $idToken;
        }
        if ($zielNachAbmeldung !== null && $zielNachAbmeldung !== '') {
            $felder['post_logout_redirect_uri'] = $zielNachAbmeldung;
        }

        return $endpunkt . '?' . http_build_query($felder, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Prueft eine Abmeldeaufforderung ueber den Vorderkanal
     * (OpenID Connect Front-Channel Logout 1.0).
     *
     * Der Aussteller muss stimmen - sonst meldet jede fremde Seite, die das
     * Modul in einen Rahmen laedt, die Lehrkraft ab.
     */
    public function pruefeVorderkanalAbmeldung(array $anfrage, ?string $erwarteteSitzung): bool
    {
        $aussteller = rtrim((string) ($anfrage['iss'] ?? ''), '/');
        if ($aussteller === '' || $aussteller !== rtrim($this->konfiguration->aussteller, '/')) {
            return false;
        }

        $sitzung = (string) ($anfrage['sid'] ?? '');
        if ($sitzung === '' || $erwarteteSitzung === null || $erwarteteSitzung === '') {
            return false;
        }

        return hash_equals($erwarteteSitzung, $sitzung);
    }

    /**
     * @param array<string,mixed> $claims
     */
    private function alsIdentitaet(array $claims): Identitaet
    {
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new SsoFehler('Die Antwort enthaelt kein sub.');
        }

        $kuerzel = trim((string) ($claims['preferred_username'] ?? ''));
        if ($kuerzel === '') {
            $kuerzel = trim((string) ($claims['username'] ?? ''));
        }
        if ($kuerzel === '' && isset($claims['email'])) {
            $kuerzel = explode('@', (string) $claims['email'])[0];
        }

        $name = trim((string) ($claims['name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($claims['given_name'] ?? '')) . ' ' . ((string) ($claims['family_name'] ?? '')));
        }
        if ($name === '') {
            $name = $kuerzel;
        }

        return new Identitaet(
            sub: $sub,
            kuerzel: $kuerzel,
            name: $name,
            email: trim((string) ($claims['email'] ?? '')),
            gruppen: self::gruppenAus($claims),
            sitzung: (string) ($claims['sid'] ?? ''),
            claims: $claims,
        );
    }

    /**
     * Sammelt Gruppenangaben aus den ueblichen Claims ein.
     *
     * IServ liefert "groups", andere Anbieter "roles" oder "memberOf" - und
     * manche eine Zeichenkette statt einer Liste.
     *
     * @param array<string,mixed> $claims
     * @return list<string>
     */
    private static function gruppenAus(array $claims): array
    {
        $gruppen = [];
        foreach (['groups', 'roles', 'group', 'memberOf'] as $name) {
            $wert = $claims[$name] ?? null;
            if (is_string($wert) && $wert !== '') {
                $gruppen[] = $wert;
            } elseif (is_array($wert)) {
                foreach ($wert as $eintrag) {
                    if (is_string($eintrag) && $eintrag !== '') {
                        $gruppen[] = $eintrag;
                    }
                }
            }
        }

        return array_values(array_unique(array_map('trim', $gruppen)));
    }

    /** Nur Ziele auf dem eigenen Server, und keine protokollrelativen Adressen. */
    private function sicheresZiel(?string $ziel): string
    {
        if ($ziel === null || $ziel === '' || $ziel[0] !== '/' || str_starts_with($ziel, '//')) {
            return '/';
        }

        return $ziel;
    }

    private function schluesselvorrat(): Schluesselvorrat
    {
        return $this->vorrat ??= new Schluesselvorrat(
            $this->http,
            $this->entdeckung->intern($this->entdeckung->endpunkt('jwks_uri')),
            $this->konfiguration->cacheVerzeichnis
        );
    }

    private function verlangeSitzung(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new SsoFehler('Vor dem Anmeldevorgang muss die Sitzung gestartet sein.');
        }
    }
}
