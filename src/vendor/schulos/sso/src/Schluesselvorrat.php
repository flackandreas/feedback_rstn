<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Haelt die oeffentlichen Schluessel des Anbieters (JWKS) vor und wandelt sie
 * in ein Format, mit dem openssl_verify() arbeiten kann.
 *
 * Warum ueberhaupt pruefen? OIDC Core 3.1.3.7 erlaubt, auf die Signaturpruefung
 * zu verzichten, wenn das Token ueber eine TLS-Verbindung mit
 * Client-Authentifizierung direkt vom Token-Endpunkt kommt. Genau diese
 * Bedingung ist im Schulnetz nicht erfuellt: Portal und Module reden ueber das
 * Docker-Netz unverschluesselt miteinander. Deshalb wird hier geprueft.
 */
final class Schluesselvorrat
{
    private const CACHE_SEKUNDEN = 3600;

    /** @var array<string,string> kid => PEM */
    private array $gemerkt = [];

    public function __construct(
        private readonly HttpAbruf $http,
        private readonly string $jwksAdresse,
        private readonly string $cacheVerzeichnis = '',
    ) {
    }

    /**
     * Liefert den oeffentlichen Schluessel zur Kennung aus dem Token-Kopf.
     *
     * Ist die Kennung unbekannt, wird der Vorrat einmal neu geholt: nach einem
     * Schluesselwechsel im Portal duerfen die Module nicht erst nach Ablauf des
     * Zwischenspeichers wieder funktionieren.
     */
    public function schluessel(string $kid): string
    {
        if (isset($this->gemerkt[$kid])) {
            return $this->gemerkt[$kid];
        }

        $this->lade(false);
        if (isset($this->gemerkt[$kid])) {
            return $this->gemerkt[$kid];
        }

        $this->lade(true);
        if (isset($this->gemerkt[$kid])) {
            return $this->gemerkt[$kid];
        }

        throw new SsoFehler('Kein oeffentlicher Schluessel mit der Kennung ' . $kid . ' im JWKS.');
    }

    private function lade(bool $erzwingen): void
    {
        $daten = $this->ausCache();

        if ($erzwingen || $daten === null) {
            $daten = $this->http->holeJson($this->jwksAdresse);
            $this->inCache($daten);
        }

        foreach ($daten['keys'] ?? [] as $schluessel) {
            if (!is_array($schluessel)) {
                continue;
            }
            if (($schluessel['kty'] ?? '') !== 'RSA' || !isset($schluessel['kid'], $schluessel['n'], $schluessel['e'])) {
                continue;
            }
            if (isset($schluessel['use']) && $schluessel['use'] !== 'sig') {
                continue;
            }

            $this->gemerkt[(string) $schluessel['kid']] = self::alsPem(
                (string) $schluessel['n'],
                (string) $schluessel['e']
            );
        }
    }

    /**
     * Baut aus Modulus und Exponent einen PEM-Schluessel.
     *
     * openssl_verify() nimmt keinen JWK entgegen, deshalb wird hier von Hand
     * ein SubjectPublicKeyInfo nach RFC 5280 zusammengesetzt.
     */
    public static function alsPem(string $modulusB64, string $exponentB64): string
    {
        $modulus = Basis64Url::dekodiere($modulusB64);
        $exponent = Basis64Url::dekodiere($exponentB64);

        $rsaPublicKey = self::folge(self::ganzzahl($modulus) . self::ganzzahl($exponent));

        // OID 1.2.840.113549.1.1.1 (rsaEncryption) gefolgt von NULL.
        $algorithmus = self::folge("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitfolge = "\x03" . self::laenge(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $der = self::folge($algorithmus . $bitfolge);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function folge(string $inhalt): string
    {
        return "\x30" . self::laenge(strlen($inhalt)) . $inhalt;
    }

    private static function ganzzahl(string $rohdaten): string
    {
        $rohdaten = ltrim($rohdaten, "\x00");
        if ($rohdaten === '') {
            $rohdaten = "\x00";
        }
        // Fuehrendes Nullbyte, damit die Zahl nicht als negativ gelesen wird.
        if (ord($rohdaten[0]) > 0x7f) {
            $rohdaten = "\x00" . $rohdaten;
        }

        return "\x02" . self::laenge(strlen($rohdaten)) . $rohdaten;
    }

    private static function laenge(int $anzahl): string
    {
        if ($anzahl < 0x80) {
            return chr($anzahl);
        }

        $bytes = '';
        while ($anzahl > 0) {
            $bytes = chr($anzahl & 0xff) . $bytes;
            $anzahl >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function ausCache(): ?array
    {
        $datei = $this->cacheDatei();
        if ($datei === null || !is_file($datei)) {
            return null;
        }

        if (time() - (int) @filemtime($datei) > self::CACHE_SEKUNDEN) {
            return null;
        }

        $inhalt = @file_get_contents($datei);
        $daten = is_string($inhalt) ? json_decode($inhalt, true) : null;

        return is_array($daten) ? $daten : null;
    }

    /**
     * @param array<string,mixed> $daten
     */
    private function inCache(array $daten): void
    {
        $datei = $this->cacheDatei();
        if ($datei === null) {
            return;
        }

        $verzeichnis = dirname($datei);
        if (!is_dir($verzeichnis)) {
            @mkdir($verzeichnis, 0o750, true);
        }

        // Erst daneben schreiben, dann umbenennen: sonst liest ein paralleler
        // Request eine halb geschriebene Datei.
        $vorlaeufig = $datei . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($vorlaeufig, json_encode($daten, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($vorlaeufig, $datei);
        } else {
            @unlink($vorlaeufig);
        }
    }

    private function cacheDatei(): ?string
    {
        if ($this->cacheVerzeichnis === '') {
            return null;
        }

        return rtrim($this->cacheVerzeichnis, '/') . '/jwks-' . sha1($this->jwksAdresse) . '.json';
    }
}
