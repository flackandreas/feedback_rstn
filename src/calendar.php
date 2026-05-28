<?php
/**
 * src/calendar.php
 * Controller for the Schulkalender view, including database and IServ external feeds.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$conn = db_connect();

// Fetch local events:
// 1. Sick leaves (all)
$stmt_sick = $conn->query("
    SELECT r.id, r.date_from, r.date_to, r.notes, t.name as teacher_name 
    FROM sick_leave_reports r 
    JOIN teachers t ON r.teacher_id = t.id
");
$sick_leaves = $stmt_sick->fetchAll();

// 2. Approved exemptions
$stmt_exempt = $conn->query("
    SELECT r.id, r.date_from, r.date_to, r.reason, r.reason_type, t.name as teacher_name 
    FROM exemption_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'approved'
");
$exemptions = $stmt_exempt->fetchAll();

// 3. Approved extracurricular events
$stmt_extra = $conn->query("
    SELECT r.id, r.event_date, r.event_date_to, r.event_name, r.class_name, t.name as teacher_name, r.destination
    FROM extracurricular_requests r 
    JOIN teachers t ON r.teacher_id = t.id 
    WHERE r.status = 'approved'
");
$extracurriculars = $stmt_extra->fetchAll();

$events = [];

// Map Sick Leaves
foreach ($sick_leaves as $s) {
    $events[] = [
        'id' => 'sick_' . $s['id'],
        'type' => 'sick',
        'start' => $s['date_from'],
        'end' => $s['date_to'],
        'title' => '🤒 Krankmeldung: ' . $s['teacher_name'],
        'details' => 'Krankmeldung für ' . $s['teacher_name'] . ($s['notes'] ? "\nNotizen: " . $s['notes'] : '')
    ];
}

// Map Exemptions
foreach ($exemptions as $e) {
    $events[] = [
        'id' => 'exempt_' . $e['id'],
        'type' => 'exempt',
        'start' => $e['date_from'],
        'end' => $e['date_to'],
        'title' => '🏖️ Freistellung: ' . $e['teacher_name'],
        'details' => 'Genehmigte Freistellung für ' . $e['teacher_name'] . "\nArt: " . $e['reason_type'] . "\nGrund: " . $e['reason']
    ];
}

// Map Extracurriculars
foreach ($extracurriculars as $ex) {
    $events[] = [
        'id' => 'extra_' . $ex['id'],
        'type' => 'extra',
        'start' => $ex['event_date'],
        'end' => $ex['event_date_to'] ?: $ex['event_date'],
        'title' => '🚌 Ausflug (' . $ex['class_name'] . '): ' . $ex['event_name'],
        'details' => 'Veranstaltung: ' . $ex['event_name'] . "\nKlasse(n): " . $ex['class_name'] . "\nZiel: " . $ex['destination'] . "\nLeitung: " . $ex['teacher_name']
    ];
}

// 4. Fetch and parse external IServ calendar events
$iserv_url = 'https://rstn.de/iserv/public/calendar?key=f5c7249d68e573f308af152f75f832e8';
$iserv_events = get_iserv_events($iserv_url);
$events = array_merge($events, $iserv_events);


/**
 * Helper to fetch, cache, and parse IServ iCal feed.
 */
function get_iserv_events($url) {
    $uid = function_exists('posix_getuid') ? posix_getuid() : '';
    $cache_file = sys_get_temp_dir() . '/iserv_calendar_cache' . ($uid !== '' ? '_' . $uid : '') . '.json';
    $cache_time = 900; // 15 minutes

    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
        $cached_data = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached_data)) {
            return $cached_data;
        }
    }

    $ics_content = fetch_url_with_curl($url);
    if (!$ics_content) {
        // Fallback to cache if request fails, even if expired
        if (file_exists($cache_file)) {
            return json_decode(file_get_contents($cache_file), true) ?: [];
        }
        return [];
    }

    $parsed_events = parse_ics_content($ics_content);
    file_put_contents($cache_file, json_encode($parsed_events));
    return $parsed_events;
}

/**
 * Fetch URL using cURL with a low timeout.
 */
function fetch_url_with_curl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 seconds timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SchoolApp/1.0 (Calendar Importer)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignore SSL verification errors
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
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


require_once __DIR__ . '/includes/twig_setup.php';

echo $twig->render('calendar.twig', [
    'events_json' => json_encode($events),
    'current_user_name' => get_current_user_name(),
    'is_admin' => is_current_user_admin(),
    'is_logged_in' => true
]);
