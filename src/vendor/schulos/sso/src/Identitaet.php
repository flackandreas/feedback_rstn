<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Wer sich angemeldet hat - so, wie der Anbieter es bestaetigt hat.
 *
 * "sub" ist der einzige dauerhaft verlaessliche Schluessel. Kuerzel werden an
 * Schulen nach Jahren neu vergeben; wer Konten daran festmacht, vererbt die
 * Daten der Vorgaengerin an die Nachfolgerin.
 */
final class Identitaet
{
    /**
     * @param list<string> $gruppen
     * @param array<string,mixed> $claims
     */
    public function __construct(
        public readonly string $sub,
        public readonly string $kuerzel,
        public readonly string $name,
        public readonly string $email,
        public readonly array $gruppen,
        public readonly string $sitzung,
        public readonly array $claims = [],
    ) {
    }

    /** Gehoert die Person mindestens einer der genannten Gruppen an? */
    public function inGruppe(string ...$gesucht): bool
    {
        if ($gesucht === []) {
            return false;
        }

        $eigene = array_map(static fn (string $g): string => mb_strtolower(trim($g)), $this->gruppen);

        foreach ($gesucht as $name) {
            $name = mb_strtolower(trim($name));
            if ($name !== '' && in_array($name, $eigene, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wie inGruppe(), nimmt aber eine kommagetrennte Liste - so stehen die
     * Gruppen in den Umgebungsvariablen der Module.
     */
    public function inGruppenListe(string $liste): bool
    {
        $namen = array_values(array_filter(array_map('trim', explode(',', $liste)), static fn ($n) => $n !== ''));

        return $namen !== [] && $this->inGruppe(...$namen);
    }
}
