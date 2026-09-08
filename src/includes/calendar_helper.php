<?php
/**
 * src/includes/calendar_helper.php
 * Helper functions for fetching and parsing local and external IServ calendars.
 */

/**
 * Prueft, ob eine Adresse als Kalenderquelle zulaessig ist.
 *
 * Der Server ruft diese Adresse selbst ab. Ohne Pruefung koennte darueber das
 * Hausnetz abgetastet werden - auf demselben Rechner laeuft unter anderem ein
 * phpMyAdmin. Deshalb nur http/https, keine Zugangsdaten in der Adresse und
 * keine Ziele im privaten oder reservierten Adressbereich.
 *
 * @return array{ok:bool,fehler?:string}
 */
function validate_calendar_url($url) {
    $url = trim($url);

    if ($url === '' || mb_strlen($url) > 1000) {
        return ['ok' => false, 'fehler' => 'Die Adresse fehlt oder ist zu lang.'];
    }

    $teile = parse_url($url);
    if ($teile === false || empty($teile['scheme']) || empty($teile['host'])) {
        return ['ok' => false, 'fehler' => 'Das ist keine vollständige Adresse.'];
    }

    if (!in_array(strtolower($teile['scheme']), ['http', 'https'], true)) {
        return ['ok' => false, 'fehler' => 'Nur http- und https-Adressen sind zulässig.'];
    }

    if (isset($teile['user']) || isset($teile['pass'])) {
        return ['ok' => false, 'fehler' => 'Zugangsdaten in der Adresse sind nicht zulässig.'];
    }

    // parse_url liefert IPv6-Literale in eckigen Klammern zurueck; ohne das
    // Abstreifen scheitert auch eine gueltige IPv6-Adresse an der Aufloesung.
    $host = trim($teile['host'], '[]');
    $adressen = [];

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $adressen[] = $host;
    } else {
        $adressen = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $satz) {
            if (!empty($satz['ipv6'])) {
                $adressen[] = $satz['ipv6'];
            }
        }
    }

    if ($adressen === []) {
        return ['ok' => false, 'fehler' => 'Der Rechnername „' . $host . '" ist nicht auflösbar.'];
    }

    foreach ($adressen as $adresse) {
        $oeffentlich = filter_var(
            $adresse,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($oeffentlich === false) {
            return ['ok' => false, 'fehler' => 'Die Adresse zeigt ins interne Netz (' . $adresse . ').'];
        }
    }

    return ['ok' => true];
}

/**
 * Holt, zwischenspeichert und zerlegt einen iCal-Feed.
 *
 * Der Zwischenspeicher haengt am Ziel: vorher teilten sich alle Feeds eine
 * einzige Datei, sodass ein zweiter Kalender den ersten ueberschrieben haette.
 *
 * @return array{events:list<array<string,mixed>>,status:string,aus_cache:bool}
 */
function fetch_calendar_feed($url, $cache_time = 900) {
    $uid = function_exists('posix_getuid') ? posix_getuid() : '';
    $cache_file = sys_get_temp_dir() . '/kalender_' . substr(hash('sha256', $url), 0, 24)
        . ($uid !== '' ? '_' . $uid : '') . '.json';

    if (is_file($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
        $gespeichert = json_decode((string)file_get_contents($cache_file), true);
        if (is_array($gespeichert)) {
            return ['events' => $gespeichert, 'status' => 'aus dem Zwischenspeicher', 'aus_cache' => true];
        }
    }

    $pruefung = validate_calendar_url($url);
    if (!$pruefung['ok']) {
        return ['events' => [], 'status' => $pruefung['fehler'], 'aus_cache' => false];
    }

    $antwort = fetch_url_with_curl($url);

    if ($antwort['body'] === '' || $antwort['body'] === false) {
        // Lieber veraltete Termine als gar keine.
        if (is_file($cache_file)) {
            $gespeichert = json_decode((string)file_get_contents($cache_file), true) ?: [];
            return ['events' => $gespeichert, 'status' => $antwort['fehler'] . ' – zeige letzten Stand', 'aus_cache' => true];
        }
        return ['events' => [], 'status' => $antwort['fehler'], 'aus_cache' => false];
    }

    if (stripos($antwort['body'], 'BEGIN:VCALENDAR') === false) {
        return ['events' => [], 'status' => 'Die Antwort ist kein iCal-Kalender.', 'aus_cache' => false];
    }

    $termine = parse_ics_content($antwort['body']);
    @file_put_contents($cache_file, json_encode($termine));

    return ['events' => $termine, 'status' => 'OK', 'aus_cache' => false];
}

/**
 * Alte Schnittstelle - liefert weiterhin nur die Terminliste.
 */
function get_iserv_events($url) {
    return fetch_calendar_feed($url)['events'];
}

/**
 * Holt eine Adresse per cURL mit knappem Zeitlimit.
 *
 * Zertifikate werden geprueft (vorher abgeschaltet) und Weiterleitungen nicht
 * verfolgt - sonst liesse sich die Pruefung des Ziels umgehen.
 *
 * @return array{body:string|false,fehler:string}
 */
function fetch_url_with_curl($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'SchulOS/1.0 (Kalender-Import)',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehlertext = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['body' => false, 'fehler' => 'Nicht erreichbar: ' . ($fehlertext ?: 'unbekannter Fehler')];
    }
    if ($code >= 400) {
        return ['body' => false, 'fehler' => 'Der Server antwortete mit HTTP ' . $code . '.'];
    }

    return ['body' => (string)$body, 'fehler' => ''];
}

/**
 * Parses iCal/ICS content into structured array.
 */
function parse_ics_content($ics) {
    // Unfold lines
    $unfolded = [];
    $lines = explode("\n", str_replace("\r", "", $ics));
    foreach ($lines as $line) {
        if (empty($line)) continue;
        if ($line[0] === ' ' || $line[0] === "\t") {
            if (count($unfolded) > 0) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            }
        } else {
            $unfolded[] = $line;
        }
    }

    $parsed = [];
    $current_event = null;

    foreach ($unfolded as $line) {
        $line = trim($line);
        if ($line === 'BEGIN:VEVENT') {
            $current_event = [
                'uid' => '',
                'start' => '',
                'end' => '',
                'summary' => '',
                'description' => '',
                'location' => ''
            ];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if ($current_event && !empty($current_event['start']) && !empty($current_event['summary'])) {
                // Decode fields
                $summary = decode_ics_text($current_event['summary']);
                $description = decode_ics_text($current_event['description']);
                $location = decode_ics_text($current_event['location']);
                
                $details = '';
                if (!empty($location)) {
                    $details .= "Ort: " . $location . "\n";
                }
                if (!empty($description)) {
                    $details .= $description;
                }
                $details = trim($details);

                $parsed[] = [
                    'id' => 'iserv_' . md5($current_event['uid'] ?: $current_event['start'] . $summary),
                    'type' => 'iserv',
                    'start' => $current_event['start'],
                    'end' => $current_event['end'] ?: $current_event['start'],
                    'title' => '📅 ' . $summary,
                    'details' => $details ?: 'Keine weiteren Details.'
                ];
            }
            $current_event = null;
            continue;
        }

        if ($current_event !== null) {
            $parts = explode(':', $line, 2);
            if (count($parts) < 2) continue;
            
            $key_part = $parts[0];
            $val = $parts[1];

            $key_subparts = explode(';', $key_part, 2);
            $key = strtoupper($key_subparts[0]);

            if ($key === 'UID') {
                $current_event['uid'] = $val;
            } elseif ($key === 'DTSTART') {
                $current_event['start'] = parse_ics_date($val);
            } elseif ($key === 'DTEND') {
                $current_event['end'] = parse_ics_date($val, true);
            } elseif ($key === 'SUMMARY') {
                $current_event['summary'] = $val;
            } elseif ($key === 'DESCRIPTION') {
                $current_event['description'] = $val;
            } elseif ($key === 'LOCATION') {
                $current_event['location'] = $val;
            }
        }
    }

    return $parsed;
}

/**
 * Parse date strings into standard YYYY-MM-DD.
 */
function parse_ics_date($val, $is_end = false) {
    $val = trim($val);
    if (preg_match('/^(\d{8})/', $val, $m)) {
        $date_str = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        if ($is_end && (strlen($val) == 8 || strpos($val, 'VALUE=DATE') !== false)) {
            // All-day end date is exclusive -> subtract 1 day
            return date('Y-m-d', strtotime($date_str . ' -1 day'));
        }
        return $date_str;
    }
    return null;
}

/**
 * Decode backslashes and escaped text in ICS values.
 */
function decode_ics_text($str) {
    $str = str_replace(
        ['\\,', '\\;', '\\n', '\\N', '\\\\'],
        [',', ';', "\n", "\n", '\\'],
        $str
    );
    return trim($str);
}
