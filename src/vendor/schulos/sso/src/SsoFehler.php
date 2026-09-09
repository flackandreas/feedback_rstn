<?php

declare(strict_types=1);

namespace SchulOS\Sso;

use RuntimeException;

/**
 * Jeder Fehler im Anmeldeablauf.
 *
 * Die Meldung ist fuer das Protokoll gedacht, nicht fuer die Anzeige - sie
 * kann Namen von Endpunkten und Claims enthalten. Was die Nutzerin zu sehen
 * bekommt, entscheidet das aufrufende Modul.
 */
final class SsoFehler extends RuntimeException
{
}
