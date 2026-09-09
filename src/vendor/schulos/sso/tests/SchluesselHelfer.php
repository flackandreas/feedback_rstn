<?php

declare(strict_types=1);

namespace SchulOS\Sso\Tests;

use SchulOS\Sso\Basis64Url;
use SchulOS\Sso\HttpAbruf;
use SchulOS\Sso\Schluesselvorrat;

/**
 * Erzeugt einmal je Testlauf ein RSA-Schluesselpaar und baut damit Tokens.
 * Ein echtes Schluesselpaar statt eines Doubles: die Signaturpruefung ist
 * genau das, was hier geprueft werden soll.
 */
final class SchluesselHelfer
{
    private static mixed $privat = null;
    private static string $kid = 'test-schluessel';

    public static function privat(): mixed
    {
        return self::$privat ??= openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    public static function kid(): string
    {
        return self::$kid;
    }

    /**
     * Ein Vorrat, der ohne Netz auskommt: der oeffentliche Schluessel wird als
     * JWK gebaut und ueber einen HttpAbruf-Ersatz geliefert.
     */
    public static function vorrat(): Schluesselvorrat
    {
        $einzelheiten = openssl_pkey_get_details(self::privat());

        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::$kid,
            'n' => Basis64Url::kodiere($einzelheiten['rsa']['n']),
            'e' => Basis64Url::kodiere($einzelheiten['rsa']['e']),
        ]]];

        $http = new class ($jwks) extends HttpAbruf {
            public function __construct(private readonly array $jwks)
            {
                parent::__construct();
            }

            public function holeJson(string $adresse, array $kopfzeilen = []): array
            {
                return $this->jwks;
            }
        };

        return new Schluesselvorrat($http, 'https://portal.test/oidc/jwks.json');
    }

    /**
     * @param array<string,mixed> $nutzdaten
     */
    public static function token(array $nutzdaten, string $alg = 'RS256', ?string $kid = null): string
    {
        $kopf = ['alg' => $alg, 'typ' => 'JWT'];
        if ($alg !== 'none') {
            $kopf['kid'] = $kid ?? self::$kid;
        }

        $teil1 = Basis64Url::kodiere((string) json_encode($kopf));
        $teil2 = Basis64Url::kodiere((string) json_encode($nutzdaten));

        if ($alg === 'none') {
            return $teil1 . '.' . $teil2 . '.';
        }

        if ($alg === 'HS256') {
            return $teil1 . '.' . $teil2 . '.'
                . Basis64Url::kodiere(hash_hmac('sha256', $teil1 . '.' . $teil2, 'geheim', true));
        }

        $signatur = '';
        openssl_sign($teil1 . '.' . $teil2, $signatur, self::privat(), OPENSSL_ALGO_SHA256);

        return $teil1 . '.' . $teil2 . '.' . Basis64Url::kodiere($signatur);
    }

    /**
     * @return array<string,mixed>
     */
    public static function nutzdaten(array $ueberschreiben = []): array
    {
        return array_merge([
            'iss' => 'https://portal.test',
            'sub' => 'u_abc123',
            'aud' => 'modul-antrag',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => 'nonce-wert',
            'preferred_username' => 'MUS',
            'name' => 'Maria Muster',
            'email' => 'mus@schule.test',
            'groups' => ['Kollegium', 'Schulleitung'],
            'sid' => 'sitzung-1',
        ], $ueberschreiben);
    }
}
