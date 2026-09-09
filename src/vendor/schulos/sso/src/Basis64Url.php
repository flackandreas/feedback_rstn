<?php

declare(strict_types=1);

namespace SchulOS\Sso;

/**
 * Base64url nach RFC 7515 - also ohne Fuellzeichen und mit "-_" statt "+/".
 */
final class Basis64Url
{
    public static function kodiere(string $rohdaten): string
    {
        return rtrim(strtr(base64_encode($rohdaten), '+/', '-_'), '=');
    }

    public static function dekodiere(string $text): string
    {
        $ergaenzt = strtr($text, '-_', '+/');
        $rest = strlen($ergaenzt) % 4;
        if ($rest > 0) {
            $ergaenzt .= str_repeat('=', 4 - $rest);
        }

        $rohdaten = base64_decode($ergaenzt, true);
        if ($rohdaten === false) {
            throw new SsoFehler('Ungueltige base64url-Kodierung.');
        }

        return $rohdaten;
    }
}
