<?php

declare(strict_types=1);

namespace SchulOS\Sso\Tests;

use PHPUnit\Framework\TestCase;
use SchulOS\Sso\Identitaet;
use SchulOS\Sso\Konfiguration;

final class IdentitaetTest extends TestCase
{
    private function identitaet(array $gruppen = ['Kollegium']): Identitaet
    {
        return new Identitaet('u_1', 'MUS', 'Maria Muster', 'mus@schule.test', $gruppen, 'sid-1');
    }

    public function testGruppenvergleichIgnoriertGrossschreibungUndLeerzeichen(): void
    {
        self::assertTrue($this->identitaet([' Kollegium '])->inGruppe('kollegium'));
        self::assertFalse($this->identitaet(['Kollegium'])->inGruppe('Schulleitung'));
    }

    public function testLeereListeErlaubtNiemanden(): void
    {
        self::assertFalse($this->identitaet()->inGruppe());
        self::assertFalse($this->identitaet()->inGruppenListe(''));
        self::assertFalse($this->identitaet()->inGruppenListe('  ,  '));
    }

    public function testKommaListeAusDerUmgebung(): void
    {
        self::assertTrue($this->identitaet(['Schulleitung'])->inGruppenListe('Sekretariat, Schulleitung'));
    }

    public function testKonfigurationOhneAngabenIstNichtAktiv(): void
    {
        $konfiguration = new Konfiguration('', '', '', '');
        self::assertFalse($konfiguration->aktiv());
        self::assertSame([], $konfiguration->fehlendeAngaben());
    }

    public function testHalbeKonfigurationWirdBenannt(): void
    {
        $konfiguration = new Konfiguration('https://portal.test', 'modul-antrag', '', '');
        self::assertFalse($konfiguration->aktiv());
        self::assertSame(['CLIENT_SECRET', 'REDIRECT_URI'], $konfiguration->fehlendeAngaben());
    }
}
