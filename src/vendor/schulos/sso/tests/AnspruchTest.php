<?php

declare(strict_types=1);

namespace SchulOS\Sso\Tests;

use PHPUnit\Framework\TestCase;
use SchulOS\Sso\Jwt;
use SchulOS\Sso\SsoFehler;

final class AnspruchTest extends TestCase
{
    private const AUSSTELLER = 'https://portal.test';
    private const CLIENT = 'modul-antrag';

    public function testGueltigeAngabenGehenDurch(): void
    {
        Jwt::pruefeAnspruch(SchluesselHelfer::nutzdaten(), self::AUSSTELLER, self::CLIENT, 'nonce-wert');
        $this->addToAssertionCount(1);
    }

    public function testAbschliessenderSchraegstrichStoertNicht(): void
    {
        Jwt::pruefeAnspruch(SchluesselHelfer::nutzdaten(), self::AUSSTELLER . '/', self::CLIENT, 'nonce-wert');
        $this->addToAssertionCount(1);
    }

    public function testFremderAusstellerWirdAbgelehnt(): void
    {
        $this->expectException(SsoFehler::class);
        Jwt::pruefeAnspruch(
            SchluesselHelfer::nutzdaten(['iss' => 'https://boese.test']),
            self::AUSSTELLER,
            self::CLIENT,
            'nonce-wert'
        );
    }

    public function testFremderEmpfaengerWirdAbgelehnt(): void
    {
        $this->expectException(SsoFehler::class);
        Jwt::pruefeAnspruch(
            SchluesselHelfer::nutzdaten(['aud' => 'modul-unterricht']),
            self::AUSSTELLER,
            self::CLIENT,
            'nonce-wert'
        );
    }

    public function testAbgelaufenesTokenWirdAbgelehnt(): void
    {
        $this->expectException(SsoFehler::class);
        Jwt::pruefeAnspruch(
            SchluesselHelfer::nutzdaten(['exp' => time() - 3600]),
            self::AUSSTELLER,
            self::CLIENT,
            'nonce-wert'
        );
    }

    public function testFalscherEinmalwertWirdAbgelehnt(): void
    {
        $this->expectException(SsoFehler::class);
        Jwt::pruefeAnspruch(
            SchluesselHelfer::nutzdaten(),
            self::AUSSTELLER,
            self::CLIENT,
            'anderer-nonce'
        );
    }

    public function testMehrereEmpfaengerBrauchenAzp(): void
    {
        $this->expectException(SsoFehler::class);
        Jwt::pruefeAnspruch(
            SchluesselHelfer::nutzdaten(['aud' => [self::CLIENT, 'modul-unterricht'], 'azp' => 'modul-unterricht']),
            self::AUSSTELLER,
            self::CLIENT,
            'nonce-wert'
        );
    }
}
