<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Prueft und liest ein signiertes JSON Web Token.
 *
 * Bewusst nur RS256. "alg": "none" und die HMAC-Verfahren sind die beiden
 * klassischen Umgehungen - wer den Algorithmus aus dem Token uebernimmt, laesst
 * den Angreifer waehlen, wie geprueft wird.
 */
final class Jwt
{
    /**
     * @return array{kopf: array<string,mixed>, nutzdaten: array<string,mixed>}
     */
    public static function zerlege(string $token): array
    {
        $teile = explode('.', $token);
        if (count($teile) !== 3) {
            throw new SsoFehler('Token besteht nicht aus drei Abschnitten.');
        }

        $kopf = json_decode(Basis64Url::dekodiere($teile[0]), true);
        $nutzdaten = json_decode(Basis64Url::dekodiere($teile[1]), true);

        if (!is_array($kopf) || !is_array($nutzdaten)) {
            throw new SsoFehler('Kopf oder Nutzdaten des Tokens sind kein JSON-Objekt.');
        }

        return ['kopf' => $kopf, 'nutzdaten' => $nutzdaten];
    }

    /**
     * Prueft die Signatur und liefert die Nutzdaten.
     *
     * @return array<string,mixed>
     */
    public static function pruefe(string $token, Schluesselvorrat $vorrat): array
    {
        $teile = explode('.', $token);
        if (count($teile) !== 3) {
            throw new SsoFehler('Token besteht nicht aus drei Abschnitten.');
        }

        ['kopf' => $kopf, 'nutzdaten' => $nutzdaten] = self::zerlege($token);

        if (($kopf['alg'] ?? '') !== 'RS256') {
            throw new SsoFehler('Nicht unterstuetztes Signaturverfahren: ' . var_export($kopf['alg'] ?? null, true));
        }

        $kid = (string) ($kopf['kid'] ?? '');
        if ($kid === '') {
            throw new SsoFehler('Im Token-Kopf fehlt die Schluesselkennung (kid).');
        }

        $pem = $vorrat->schluessel($kid);
        $signatur = Basis64Url::dekodiere($teile[2]);
        $unterschrieben = $teile[0] . '.' . $teile[1];

        $ergebnis = openssl_verify($unterschrieben, $signatur, $pem, OPENSSL_ALGO_SHA256);
        if ($ergebnis !== 1) {
            throw new SsoFehler('Die Signatur des Tokens stimmt nicht.');
        }

        return $nutzdaten;
    }

    /**
     * Prueft die Pflichtangaben eines ID-Tokens.
     *
     * @param array<string,mixed> $nutzdaten
     */
    public static function pruefeAnspruch(
        array $nutzdaten,
        string $aussteller,
        string $clientId,
        ?string $nonce,
        int $zeitabweichung = 60,
    ): void {
        $jetzt = time();

        if (rtrim((string) ($nutzdaten['iss'] ?? ''), '/') !== rtrim($aussteller, '/')) {
            throw new SsoFehler('Das Token stammt von einem anderen Aussteller.');
        }

        $empfaenger = $nutzdaten['aud'] ?? '';
        $empfaengerListe = is_array($empfaenger) ? $empfaenger : [$empfaenger];
        if (!in_array($clientId, $empfaengerListe, true)) {
            throw new SsoFehler('Das Token ist fuer einen anderen Empfaenger ausgestellt.');
        }

        // Bei mehreren Empfaengern verlangt OIDC Core 3.1.3.7 die Angabe azp.
        if (count($empfaengerListe) > 1 && ($nutzdaten['azp'] ?? $clientId) !== $clientId) {
            throw new SsoFehler('Das Token nennt eine andere berechtigte Partei (azp).');
        }

        if (!isset($nutzdaten['exp']) || (int) $nutzdaten['exp'] + $zeitabweichung < $jetzt) {
            throw new SsoFehler('Das Token ist abgelaufen.');
        }

        if (isset($nutzdaten['nbf']) && (int) $nutzdaten['nbf'] - $zeitabweichung > $jetzt) {
            throw new SsoFehler('Das Token gilt noch nicht.');
        }

        if (isset($nutzdaten['iat']) && (int) $nutzdaten['iat'] - $zeitabweichung > $jetzt) {
            throw new SsoFehler('Das Token wurde in der Zukunft ausgestellt.');
        }

        if ($nonce !== null && !hash_equals($nonce, (string) ($nutzdaten['nonce'] ?? ''))) {
            throw new SsoFehler('Der Einmalwert (nonce) des Tokens stimmt nicht.');
        }
    }
}
