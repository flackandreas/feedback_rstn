<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Zugangsdaten und Adressen eines OpenID-Connect-Anbieters.
 *
 * Bewusst unveraenderlich: die Werte werden einmal aus der Umgebung gelesen
 * und danach nicht mehr angefasst. Ist "aktiv" falsch, laeuft das Modul im
 * Alleinbetrieb weiter und zeigt seine eigene Anmeldung - genau das war die
 * Bedingung dafuer, dass eine Schule ein Modul auch ohne Portal betreiben kann.
 */
final class Konfiguration
{
    public function __construct(
        public readonly string $aussteller,
        public readonly string $clientId,
        public readonly string $clientGeheimnis,
        public readonly string $rueckadresse,
        public readonly string $bereiche = 'openid profile email groups',
        /**
         * Adresse, unter der der Anbieter vom Server aus erreichbar ist.
         *
         * Im Containerverbund ist die oeffentliche Adresse von innen oft nicht
         * routbar: der Browser spricht "https://schulos.schule.de", der
         * Container erreicht denselben Dienst nur als "http://portal". Fuer
         * die Weiterleitungen zaehlt weiterhin die oeffentliche Adresse - nur
         * die Abrufe von Server zu Server (Entdeckung, Token, UserInfo, JWKS)
         * laufen ueber diese hier.
         *
         * Leer heisst: es gibt keinen Unterschied.
         */
        public readonly string $internerAussteller = '',
        public readonly string $cacheVerzeichnis = '',
        public readonly int $zeitabweichung = 60,
        public readonly bool $tlsPruefen = true,
    ) {
    }

    /**
     * Liest die Konfiguration aus Umgebungsvariablen mit gemeinsamem Vorsatz,
     * z.B. PORTAL_ISSUER, PORTAL_CLIENT_ID, PORTAL_CLIENT_SECRET.
     *
     * Fehlt eine der drei Pflichtangaben, ist das kein Fehler: das Modul soll
     * dann schlicht ohne Portal laufen. Erst ein halb ausgefuellter Satz waere
     * einer - dagegen hilft istVollstaendig().
     */
    public static function ausUmgebung(string $vorsatz, string $cacheVerzeichnis = ''): self
    {
        $lies = static function (string $name, string $vorgabe = '') use ($vorsatz): string {
            $wert = $_ENV[$vorsatz . $name] ?? $_SERVER[$vorsatz . $name] ?? getenv($vorsatz . $name);

            return (is_string($wert) && $wert !== '') ? trim($wert) : $vorgabe;
        };

        return new self(
            aussteller: rtrim($lies('ISSUER'), '/'),
            clientId: $lies('CLIENT_ID'),
            clientGeheimnis: $lies('CLIENT_SECRET'),
            rueckadresse: $lies('REDIRECT_URI'),
            bereiche: $lies('SCOPES', 'openid profile email groups'),
            internerAussteller: rtrim($lies('INTERN'), '/'),
            cacheVerzeichnis: $cacheVerzeichnis,
            zeitabweichung: (int) $lies('LEEWAY', '60'),
            tlsPruefen: $lies('TLS_VERIFY', '1') !== '0',
        );
    }

    /** Ist ein Anbieter hinterlegt? Sonst laeuft das Modul allein weiter. */
    public function aktiv(): bool
    {
        return $this->aussteller !== '' && $this->clientId !== '' && $this->clientGeheimnis !== '';
    }

    /**
     * Angaben, die fehlen, obwohl schon etwas gesetzt ist.
     *
     * Eine halb ausgefuellte Konfiguration ist der gefaehrlichere Fall: das
     * Modul meint, es sei am Portal, und scheitert erst bei der Anmeldung.
     *
     * @return list<string>
     */
    public function fehlendeAngaben(): array
    {
        $gesetzt = $this->aussteller !== '' || $this->clientId !== ''
            || $this->clientGeheimnis !== '' || $this->rueckadresse !== '';

        if (!$gesetzt) {
            return [];
        }

        $fehlt = [];
        foreach ([
            'ISSUER' => $this->aussteller,
            'CLIENT_ID' => $this->clientId,
            'CLIENT_SECRET' => $this->clientGeheimnis,
            'REDIRECT_URI' => $this->rueckadresse,
        ] as $name => $wert) {
            if ($wert === '') {
                $fehlt[] = $name;
            }
        }

        return $fehlt;
    }
}
