<?php

declare(strict_types=1);

namespace SchulOS\Sso\Tests;

use PHPUnit\Framework\TestCase;
use SchulOS\Sso\Basis64Url;
use SchulOS\Sso\Jwt;
use SchulOS\Sso\SsoFehler;

final class JwtTest extends TestCase
{
    public function testBase64UrlLaeuftHinUndZurueck(): void
    {
        $rohdaten = random_bytes(64) . "\x00\xff+/=";
        self::assertSame($rohdaten, Basis64Url::dekodiere(Basis64Url::kodiere($rohdaten)));
        self::assertStringNotContainsString('=', Basis64Url::kodiere($rohdaten));
    }

    public function testEchteSignaturWirdAngenommen(): void
    {
        $token = SchluesselHelfer::token(SchluesselHelfer::nutzdaten());
        $nutzdaten = Jwt::pruefe($token, SchluesselHelfer::vorrat());

        self::assertSame('u_abc123', $nutzdaten['sub']);
    }

    public function testVeraenderteNutzdatenWerdenAbgelehnt(): void
    {
        $token = SchluesselHelfer::token(SchluesselHelfer::nutzdaten());
        [$kopf, , $signatur] = explode('.', $token);
        $gefaelscht = $kopf . '.'
            . Basis64Url::kodiere((string) json_encode(SchluesselHelfer::nutzdaten(['sub' => 'u_fremd'])))
            . '.' . $signatur;

        $this->expectException(SsoFehler::class);
        Jwt::pruefe($gefaelscht, SchluesselHelfer::vorrat());
    }

    public function testAlgNoneWirdAbgelehnt(): void
    {
        $token = SchluesselHelfer::token(SchluesselHelfer::nutzdaten(), 'none');

        $this->expectException(SsoFehler::class);
        $this->expectExceptionMessageMatches('/Signaturverfahren/');
        Jwt::pruefe($token, SchluesselHelfer::vorrat());
    }

    public function testHmacVerfahrenWirdAbgelehnt(): void
    {
        $token = SchluesselHelfer::token(SchluesselHelfer::nutzdaten(), 'HS256');

        $this->expectException(SsoFehler::class);
        Jwt::pruefe($token, SchluesselHelfer::vorrat());
    }

    public function testUnbekannteSchluesselkennungWirdAbgelehnt(): void
    {
        $token = SchluesselHelfer::token(SchluesselHelfer::nutzdaten(), 'RS256', 'fremder-schluessel');

        $this->expectException(SsoFehler::class);
        Jwt::pruefe($token, SchluesselHelfer::vorrat());
    }
}
