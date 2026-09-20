<?php
/**
 * src/includes/request.php
 * Schema, Host und Adresse des Anfragenden.
 *
 * Beides wurde bisher direkt aus $_SERVER gelesen. Hinter einem Reverse
 * Proxy steht dort kein HTTPS - das Secure-Flag der Sitzung fiel deshalb
 * weg, und Strict-Transport-Security wurde nie gesendet. Die Adresse des
 * Anfragenden wiederum kam ungeprueft aus X-Forwarded-For, einem Kopf, den
 * jeder Aufrufer selbst setzen kann.
 *
 * Gleiche Fassung wie im Unterrichtsmodul. Die drei Module stehen hinter
 * demselben Proxy; es waere ein schlechtes Zeichen, wenn sie die Frage
 * unterschiedlich beantworteten.
 */

/**
 * Laeuft die Anfrage ueber TLS?
 *
 * X-Forwarded-Proto kann nur zu "https" hochstufen, niemals herabstufen.
 * Ein gefaelschter Kopf kann einer echten TLS-Verbindung also nicht das
 * Secure-Flag entziehen.
 */
function request_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && strcasecmp((string) $https, 'off') !== 0) {
        return true;
    }

    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwarded !== '') {
        // Proxy-Ketten haengen die Werte kommasepariert aneinander.
        $erster = trim(explode(',', $forwarded)[0]);
        if (strcasecmp($erster, 'https') === 0) {
            return true;
        }
    }

    return strcasecmp((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''), 'on') === 0;
}

/**
 * Vertrauenswuerdige Vermittler aus TRUSTED_PROXIES.
 *
 * Einzeladressen oder CIDR-Bereiche, kommagetrennt. Das Wort "private" steht
 * fuer die privaten Netze und die Rueckschleife - im Containerverbund, wo
 * der Reverse Proxy im selben Bridge-Netz haengt, ist das der Regelfall.
 *
 * @return list<string>
 */
function request_trusted_proxies(): array
{
    $roh = trim((string) ($_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: ''));
    if ($roh === '') {
        return [];
    }

    $bereiche = [];
    foreach (explode(',', $roh) as $eintrag) {
        $eintrag = trim($eintrag);
        if ($eintrag === '') {
            continue;
        }

        if (strcasecmp($eintrag, 'private') === 0) {
            array_push(
                $bereiche,
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '::1/128',
                'fc00::/7'
            );
            continue;
        }

        $bereiche[] = $eintrag;
    }

    return $bereiche;
}

/**
 * Liegt eine Adresse in einem Netz? Einzeladresse oder CIDR, v4 und v6.
 */
function request_ip_in_range(string $ip, string $bereich): bool
{
    $binIp = @inet_pton($ip);
    if ($binIp === false) {
        return false;
    }

    if (!str_contains($bereich, '/')) {
        return @inet_pton(trim($bereich)) === $binIp;
    }

    [$netz, $praefix] = explode('/', $bereich, 2);
    $binNetz = @inet_pton(trim($netz));

    if ($binNetz === false || strlen($binNetz) !== strlen($binIp)) {
        return false;
    }

    $bits = (int) $praefix;
    if ($bits < 0 || $bits > strlen($binIp) * 8) {
        return false;
    }

    $ganzeBytes = intdiv($bits, 8);
    $restBits = $bits % 8;

    if ($ganzeBytes > 0 && strncmp($binIp, $binNetz, $ganzeBytes) !== 0) {
        return false;
    }

    if ($restBits === 0) {
        return true;
    }

    $maske = chr((0xFF << (8 - $restBits)) & 0xFF);

    return ($binIp[$ganzeBytes] & $maske) === ($binNetz[$ganzeBytes] & $maske);
}

/**
 * @param list<string> $bereiche
 */
function request_is_trusted_proxy(string $ip, array $bereiche): bool
{
    foreach ($bereiche as $bereich) {
        if (request_ip_in_range($ip, $bereich)) {
            return true;
        }
    }

    return false;
}

/**
 * Adresse des Anfragenden, fuer die Zugriffsbegrenzung.
 *
 * X-Forwarded-For wird nur ausgewertet, wenn die Anfrage von einem in
 * TRUSTED_PROXIES eingetragenen Vermittler kommt. Vorher wurde der Kopf
 * ungeprueft uebernommen - damit bestimmte jeder Aufrufer seine eigene
 * Kennung und umging saemtliche Grenzen: Anmeldeversuche, Autologin,
 * Krankmeldungen.
 *
 * Aus der Kette wird von rechts nach links der erste Eintrag genommen, der
 * nicht selbst ein bekannter Vermittler ist. Die linken Eintraege kann der
 * Client frei erfinden; sie zaehlen deshalb nicht.
 */
function request_client_ip(): string
{
    $entfernt = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($entfernt === '') {
        return 'unbekannt';
    }

    $vertrauenswuerdig = request_trusted_proxies();

    if ($vertrauenswuerdig === []) {
        if (($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') !== '') {
            error_log('Antragssystem: X-Forwarded-For wird ignoriert, weil TRUSTED_PROXIES nicht gesetzt ist.');
        }

        return $entfernt;
    }

    if (!request_is_trusted_proxy($entfernt, $vertrauenswuerdig)) {
        return $entfernt;
    }

    $kette = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));

    for ($i = count($kette) - 1; $i >= 0; $i--) {
        if (!filter_var($kette[$i], FILTER_VALIDATE_IP)) {
            continue;
        }

        if (!request_is_trusted_proxy($kette[$i], $vertrauenswuerdig)) {
            return $kette[$i];
        }
    }

    return $entfernt;
}
