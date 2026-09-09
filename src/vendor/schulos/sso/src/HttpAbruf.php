<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Der kleinste HTTP-Zugriff, der fuer OpenID Connect reicht.
 *
 * Bewusst ohne Guzzle: das Paket wird von Modulen eingebunden, die
 * unterschiedliche Guzzle-Staende haben. Eine Abhaengigkeit weniger heisst
 * hier eine Versionskollision weniger.
 */
class HttpAbruf
{
    public function __construct(
        private readonly bool $tlsPruefen = true,
        private readonly int $zeitgrenze = 10,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function holeJson(string $adresse, array $kopfzeilen = []): array
    {
        return $this->alsJson($this->anfrage('GET', $adresse, null, $kopfzeilen), $adresse);
    }

    /**
     * @param array<string,string> $felder
     * @return array<string,mixed>
     */
    public function sendeFormular(string $adresse, array $felder, array $kopfzeilen = []): array
    {
        $kopfzeilen[] = 'Content-Type: application/x-www-form-urlencoded';

        return $this->alsJson(
            $this->anfrage('POST', $adresse, http_build_query($felder, '', '&', PHP_QUERY_RFC3986), $kopfzeilen),
            $adresse
        );
    }

    /**
     * Feuert ohne auf ein Ergebnis zu warten, so weit das mit curl geht.
     * Fuer Abmelde-Benachrichtigungen: ein nicht erreichbares Modul darf die
     * Abmeldung nicht aufhalten.
     */
    public function sendeFormularStillschweigend(string $adresse, array $felder, int $zeitgrenze = 3): bool
    {
        $kanal = curl_init($adresse);
        if ($kanal === false) {
            return false;
        }

        curl_setopt_array($kanal, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($felder, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $zeitgrenze,
            CURLOPT_CONNECTTIMEOUT => $zeitgrenze,
            CURLOPT_SSL_VERIFYPEER => $this->tlsPruefen,
            CURLOPT_SSL_VERIFYHOST => $this->tlsPruefen ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $antwort = curl_exec($kanal);
        $status = (int) curl_getinfo($kanal, CURLINFO_RESPONSE_CODE);
        curl_close($kanal);

        return $antwort !== false && $status >= 200 && $status < 300;
    }

    /**
     * @param list<string> $kopfzeilen
     */
    protected function anfrage(string $verfahren, string $adresse, ?string $koerper, array $kopfzeilen): string
    {
        $kanal = curl_init($adresse);
        if ($kanal === false) {
            throw new SsoFehler('HTTP-Kanal liess sich nicht oeffnen: ' . $adresse);
        }

        curl_setopt_array($kanal, [
            CURLOPT_CUSTOMREQUEST => $verfahren,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->zeitgrenze,
            CURLOPT_CONNECTTIMEOUT => $this->zeitgrenze,
            CURLOPT_SSL_VERIFYPEER => $this->tlsPruefen,
            CURLOPT_SSL_VERIFYHOST => $this->tlsPruefen ? 2 : 0,
            // Eine Weiterleitung des Token-Endpunkts waere ein Angriffsversuch,
            // kein Betriebsfall.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $kopfzeilen),
        ]);

        if ($koerper !== null) {
            curl_setopt($kanal, CURLOPT_POSTFIELDS, $koerper);
        }

        $antwort = curl_exec($kanal);
        $fehler = curl_error($kanal);
        $status = (int) curl_getinfo($kanal, CURLINFO_RESPONSE_CODE);
        curl_close($kanal);

        if ($antwort === false) {
            throw new SsoFehler(sprintf('%s %s nicht erreichbar: %s', $verfahren, $adresse, $fehler));
        }

        if ($status < 200 || $status >= 300) {
            throw new SsoFehler(sprintf('%s %s antwortete mit %d: %s', $verfahren, $adresse, $status, mb_substr((string) $antwort, 0, 300)));
        }

        return (string) $antwort;
    }

    /**
     * @return array<string,mixed>
     */
    private function alsJson(string $rohtext, string $adresse): array
    {
        $daten = json_decode($rohtext, true);
        if (!is_array($daten)) {
            throw new SsoFehler('Antwort von ' . $adresse . ' war kein JSON-Objekt.');
        }

        return $daten;
    }
}
