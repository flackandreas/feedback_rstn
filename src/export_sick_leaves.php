<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Composer autoload for dompdf
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;

require_admin();

$teacher_id = $_GET['teacher_id'] ?? null;
if (!$teacher_id) {
    die("Kein Lehrer angegeben.");
}

$conn = db_connect();

// Fetch teacher
$stmt_t = $conn->prepare("SELECT name, kuerzel FROM teachers WHERE id = ?");
$stmt_t->execute([$teacher_id]);
$teacher = $stmt_t->fetch();
if (!$teacher) {
    die("Lehrer nicht gefunden.");
}

// Fetch old records
$stmt_r = $conn->prepare("
    SELECT date_from, date_to, notes 
    FROM sick_leave_reports 
    WHERE teacher_id = ? AND date_to < DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY date_from DESC
");
$stmt_r->execute([$teacher_id]);
$records = $stmt_r->fetchAll();

$total_days = 0;
foreach($records as $r) {
    $d1 = new DateTime($r['date_from']);
    $d2 = new DateTime($r['date_to']);
    $total_days += ($d2->diff($d1)->days + 1);
}

// Build HTML
$html = "
<html>
<head>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background-color: #f4f4f4; }
        h1, h2, h3 { color: #333; }
        .header-info { margin-bottom: 30px; }
    </style>
</head>
<body>
    <h1>Historie Krankmeldungen</h1>
    <div class='header-info'>
        <h2>Lehrkraft: {$teacher['name']} ({$teacher['kuerzel']})</h2>
        <h3 style='color: #e74c3c;'>Bisherige Krankheitstage: {$total_days}</h3>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Von</th>
                <th>Bis</th>
                <th>Notizen</th>
            </tr>
        </thead>
        <tbody>
";

if (empty($records)) {
    $html .= "<tr><td colspan='3'>Keine Einträge vorhanden.</td></tr>";
} else {
    foreach($records as $r) {
        $von = date('d.m.Y', strtotime($r['date_from']));
        $bis = date('d.m.Y', strtotime($r['date_to']));
        $notes = htmlspecialchars($r['notes'] ?? '-');
        $html .= "
                <tr>
                    <td>{$von}</td>
                    <td>{$bis}</td>
                    <td>{$notes}</td>
                </tr>
        ";
    }
}

$html .= "
        </tbody>
    </table>
</body>
</html>
";

if (class_exists('Dompdf\Dompdf')) {
    // Render PDF
    $options = new Options();
    $options->set('defaultFont', 'Helvetica');
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = "krankmeldungen_" . $teacher['kuerzel'] . "_" . date('Ymd') . ".pdf";
    $dompdf->stream($filename, ["Attachment" => true]);
} else {
    // Fallback if dompdf is not yet installed for some reason
    echo "<h1>DomPDF ist noch nicht installiert.</h1>";
    echo $html;
}
