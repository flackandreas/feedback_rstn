<?php
/**
 * src/antrag_pdf.php
 * Ein Antrag auf ausserunterrichtliche Veranstaltung als PDF im Vordruck
 * LBV 1211 - zum Ausdrucken, Unterschreiben und Weitergeben.
 *
 * Die Lehrkraft bekommt nur ihre eigenen Antraege, die Schulleitung alle. Wer
 * keinen Zugriff hat, erfaehrt auch nicht, ob es die Nummer gibt. Im Ausdruck
 * steht die Personalnummer: deshalb kein Zwischenspeicher und ein Dateiname
 * ohne sie.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lbv1211.php';
require_once __DIR__ . '/includes/lbv1211_pdf.php';

require_login();

$id = (string) ($_GET['id'] ?? '');
$conn = db_connect();

$antrag = false;
if (ctype_digit($id)) {
    $stmt = $conn->prepare('SELECT * FROM extracurricular_requests WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $id]);
    $antrag = $stmt->fetch(PDO::FETCH_ASSOC);
}

$eigener = $antrag && (int) $antrag['teacher_id'] === (int) get_current_user_id();
if (!$antrag || (!$eigener && !is_current_user_admin())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Antrag nicht gefunden.';
    exit;
}

// Antraege von vor der Umstellung auf den Vordruck - oder eine Luecke, die
// das Formular nicht abfangen konnte. Ein halb leerer Vordruck hilft
// niemandem; die Lehrkraft wird zum Ergaenzen geschickt.
$fehlend = lbv_fehlende_angaben($antrag);
if ($fehlend !== []) {
    $liste = implode(', ', $fehlend);
    if ($eigener) {
        $_SESSION['flash_error'] = "Bitte ergänzen Sie den Antrag. Für den Ausdruck nach Vordruck LBV 1211 fehlen: $liste.";
        header('Location: /antrag_ausserunterrichtlich.php?edit_id=' . (int) $antrag['id']);
    } else {
        $_SESSION['flash_error'] = "Für den Ausdruck fehlen noch Angaben, die die Lehrkraft ergänzen muss: $liste.";
        header('Location: /admin_aud.php');
    }
    exit;
}

$begleitpersonen = lbv_begleitpersonen_laden($conn, [(int) $antrag['id']])[(int) $antrag['id']] ?? [];

try {
    $pdf = lbv_pdf_erzeugen($antrag, $begleitpersonen);
} catch (\Throwable $e) {
    error_log('antrag_pdf: Ausdruck fuer Antrag ' . (int) $antrag['id'] . ' fehlgeschlagen: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Der Ausdruck konnte nicht erstellt werden. Bitte die Administration informieren.';
    header('Location: ' . ($eigener ? '/meine_antraege.php' : '/admin_aud.php'));
    exit;
}

header('Content-Type: application/pdf');
// Die Richtlinie des Front-Controllers verbietet mit object-src 'none' jedes
// eingebettete Objekt. Chrome zeigt ein PDF ueber genau so eines an und
// wendet die Richtlinie der Antwort darauf an. Fuer das PDF gilt deshalb
// eine eigene, ebenso enge: nichts nachladen, nur das PDF selbst einbetten.
header("Content-Security-Policy: default-src 'none'; object-src 'self'; frame-ancestors 'none'");
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: inline; filename="' . lbv_pdf_dateiname($antrag) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
echo $pdf;
