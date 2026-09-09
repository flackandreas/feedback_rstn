<?php

declare(strict_types=1);

namespace SchulOS\Sso\Tests;

use PHPUnit\Framework\TestCase;
use SchulOS\Sso\Entdeckung;
use SchulOS\Sso\HttpAbruf;
use SchulOS\Sso\Konfiguration;
use SchulOS\Sso\SsoFehler;

/**
 * Getrennte Adresse fuer Abrufe von Server zu Server.
 *
 * Im Containerverbund spricht der Browser die oeffentliche Adresse, der
 * Container erreicht denselben Dienst aber nur unter seinem Netznamen. Ohne
 * diese Trennung scheitert der Token-Abruf - und zwar erst mitten in der
 * Anmeldung.
 */
final class InternTest extends TestCase
{
    private const OEFFENTLICH = 'http://schulos.localhost:8090';
    private const INTERN = 'http://portal';

    /** @param array<string,mixed> $dokument */
    private function entdeckung(array $dokument, string $intern = self::INTERN): Entdeckung
    {
        $http = new class ($dokument) extends HttpAbruf {
            /** @var list<string> */
            public array $abgerufen = [];

            /** @param array<string,mixed> $dokument */
            public function __construct(private readonly array $dokument)
            {
                parent::__construct();
            }

            public function holeJson(string $adresse, array $kopfzeilen = []): array
            {
                $this->abgerufen[] = $adresse;

                return $this->dokument;
            }
        };

        return new Entdeckung($http, self::OEFFENTLICH, '', $intern);
    }

    /** @return array<string,mixed> */
    private function dokument(string $aussteller = self::OEFFENTLICH): array
    {
        return [
            'issuer' => $aussteller,
            'authorization_endpoint' => $aussteller . '/oidc/autorisieren',
            'token_endpoint' => $aussteller . '/oidc/token',
            'userinfo_endpoint' => $aussteller . '/oidc/benutzerinfo',
            'jwks_uri' => $aussteller . '/oidc/jwks.json',
        ];
    }

    public function testRueckkanalAdressenWerdenUmgeschrieben(): void
    {
        $entdeckung = $this->entdeckung($this->dokument());

        self::assertSame(
            self::INTERN . '/oidc/token',
            $entdeckung->intern($entdeckung->endpunkt('token_endpoint'))
        );
        self::assertSame(
            self::INTERN . '/oidc/jwks.json',
            $entdeckung->intern($entdeckung->endpunkt('jwks_uri'))
        );
    }

    public function testDieAdresseFuerDenBrowserBleibtOeffentlich(): void
    {
        $entdeckung = $this->entdeckung($this->dokument());

        // Der Browser wird dorthin geschickt - eine interne Adresse waere
        // von aussen nicht erreichbar.
        self::assertSame(
            self::OEFFENTLICH . '/oidc/autorisieren',
            $entdeckung->endpunkt('authorization_endpoint')
        );
    }

    public function testFremdeAdressenWerdenNichtUmgeschrieben(): void
    {
        $entdeckung = $this->entdeckung(array_merge($this->dokument(), [
            'token_endpoint' => 'https://boese.test/token',
        ]));

        // Nur der bekannte Anfang wird ersetzt. Sonst koennte ein
        // untergeschobenes Dokument den internen Aufruf umlenken.
        self::assertSame('https://boese.test/token', $entdeckung->intern($entdeckung->endpunkt('token_endpoint')));
    }

    public function testOhneInterneAdresseAendertSichNichts(): void
    {
        $entdeckung = $this->entdeckung($this->dokument(), '');

        self::assertSame(
            self::OEFFENTLICH . '/oidc/token',
            $entdeckung->intern($entdeckung->endpunkt('token_endpoint'))
        );
    }

    public function testDasDokumentMussDenOeffentlichenAusstellerNennen(): void
    {
        // Abgerufen wird intern, aber der genannte Aussteller muss der
        // oeffentliche sein - sonst passte er nicht zu den ID-Tokens.
        $entdeckung = $this->entdeckung($this->dokument('http://portal'));

        $this->expectException(SsoFehler::class);
        $entdeckung->endpunkt('token_endpoint');
    }

    public function testKonfigurationLiestDieInterneAdresseAusDerUmgebung(): void
    {
        $_ENV['TEST_ISSUER'] = self::OEFFENTLICH;
        $_ENV['TEST_CLIENT_ID'] = 'modul-antrag';
        $_ENV['TEST_CLIENT_SECRET'] = 'geheim';
        $_ENV['TEST_REDIRECT_URI'] = self::OEFFENTLICH . '/sso/rueckweg';
        $_ENV['TEST_INTERN'] = self::INTERN . '/';

        $konfiguration = Konfiguration::ausUmgebung('TEST_');

        self::assertTrue($konfiguration->aktiv());
        self::assertSame(self::INTERN, $konfiguration->internerAussteller);

        foreach (['ISSUER', 'CLIENT_ID', 'CLIENT_SECRET', 'REDIRECT_URI', 'INTERN'] as $name) {
            unset($_ENV['TEST_' . $name]);
        }
    }
}
