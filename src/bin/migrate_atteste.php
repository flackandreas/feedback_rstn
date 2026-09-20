<?php
/**
 * src/bin/migrate_atteste.php
 * Verschiebt den Altbestand der Atteste aus public/uploads/ in die Ablage.
 *
 * Bis zur Umstellung lagen die Atteste zu Krankmeldungen im DocumentRoot und
 * waren ueber ihre Adresse abrufbar - ohne Anmeldung, geschuetzt allein durch
 * einen Dateinamen aus uniqid() und dem Originalnamen der Datei. Neue Atteste
 * landen in src/storage/atteste/ unter einem Zufallsnamen; die alten holt
 * dieses Skript nach.
 *
 * Aufruf im Container:
 *
 *   docker compose exec web php /var/www/html/bin/migrate_atteste.php
 *   docker compose exec web php /var/www/html/bin/migrate_atteste.php --anwenden
 *
 * Ohne --anwenden wird nichts veraendert; der Lauf zeigt nur, was passieren
 * wuerde. Das Skript darf mehrfach laufen: Es fasst nur Zeilen an, deren
 * Pfad noch mit "uploads/" beginnt.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ablage.php';

$anwenden = in_array('--anwenden', $argv, true);

$conn = db_connect();

$stmt = $conn->query(
    "SELECT id, attachment_path FROM sick_leave_reports
      WHERE attachment_path LIKE 'uploads/%'
      ORDER BY id"
);
$zeilen = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$zeilen) {
    echo "Nichts zu tun: kein Attest liegt mehr unter uploads/.\n";
    exit(0);
}

printf("%d Attest(e) im Altbestand.\n\n", count($zeilen));

$verschoben = 0;
$fehlend = 0;
$fehler = 0;

// Fuer die Endung zaehlt der Inhalt, nicht der alte Dateiname: unter den
// Altbestand mischen sich Dateien, deren Name nicht zu ihrem Inhalt passt.
$nachMime = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

foreach ($zeilen as $zeile) {
    $id = (int)$zeile['id'];
    $alt = ablage_aufloesen($zeile['attachment_path']);

    if ($alt === null) {
        printf("  #%-5d fehlt  %s\n", $id, $zeile['attachment_path']);
        $fehlend++;
        continue;
    }

    $mime = @mime_content_type($alt);
    $endung = $nachMime[$mime] ?? strtolower(pathinfo($alt, PATHINFO_EXTENSION));
    $neuerPfad = 'atteste/' . ablage_zufallsname($endung);
    $ziel = ablage_wurzel() . '/' . $neuerPfad;

    if (!$anwenden) {
        printf("  #%-5d wuerde nach %s (%s)\n", $id, $neuerPfad, $mime ?: 'unbekannt');
        $verschoben++;
        continue;
    }

    ablage_verzeichnis('atteste');

    if (!@rename($alt, $ziel)) {
        // Getrennte Dateisysteme: kopieren und erst nach erfolgreicher
        // Datenbankaenderung loeschen.
        if (!@copy($alt, $ziel)) {
            printf("  #%-5d FEHLER beim Verschieben von %s\n", $id, $alt);
            $fehler++;
            continue;
        }
        $kopiert = true;
    } else {
        $kopiert = false;
    }

    @chmod($ziel, 0640);

    try {
        $u = $conn->prepare('UPDATE sick_leave_reports SET attachment_path = ? WHERE id = ?');
        $u->execute([$neuerPfad, $id]);
    } catch (PDOException $e) {
        // Datenbank unveraendert - also auch die Datei zuruecklegen, sonst
        // zeigt die Zeile auf eine Datei, die es nicht mehr gibt.
        if ($kopiert) {
            @unlink($ziel);
        } else {
            @rename($ziel, $alt);
        }
        printf("  #%-5d FEHLER in der Datenbank: %s\n", $id, $e->getMessage());
        $fehler++;
        continue;
    }

    if ($kopiert) {
        @unlink($alt);
    }

    printf("  #%-5d -> %s\n", $id, $neuerPfad);
    $verschoben++;
}

echo "\n";
printf("verschoben: %d   Datei fehlte: %d   Fehler: %d\n", $verschoben, $fehlend, $fehler);

if (!$anwenden) {
    echo "\nProbelauf - es wurde nichts veraendert.\n";
    echo "Mit --anwenden aufrufen, um die Dateien tatsaechlich zu verschieben.\n";
} elseif ($verschoben > 0) {
    echo "\nDie verbliebenen Dateien in src/public/uploads/ koennen nach einer\n";
    echo "Sichtkontrolle geloescht werden.\n";
}

exit($fehler > 0 ? 1 : 0);
