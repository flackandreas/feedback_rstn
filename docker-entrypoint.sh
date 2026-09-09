#!/bin/sh
# docker-entrypoint.sh
# Sorgt dafuer, dass Apache in public/uploads/ schreiben kann, bevor er startet.
#
# src/ wird zur Laufzeit vom Host eingehaengt. Der Mount verdeckt das
# Verzeichnis aus dem Abbild samt der dort gesetzten Rechte - auf dem Host
# gehoeren die Dateien dem angemeldeten Benutzer, nicht www-data. Ohne
# Schreibrecht scheitert move_uploaded_file() in krankmeldung.php still: Die
# Krankmeldung wird gespeichert, das Attest verschwindet ohne Meldung.
#
# Statt den Besitzer zu uebernehmen - das wuerde dem Host-Benutzer die
# Schreibrechte nehmen - bekommt das Verzeichnis die Gruppe www-data und
# Gruppenschreibrecht. Das setgid-Bit sorgt dafuer, dass auch spaeter
# angelegte Dateien und Unterverzeichnisse in dieser Gruppe landen; das
# braucht admin_archive.php, das seine Ausgabe in Unterordnern ablegt.

set -e

# Zeitzone fuer PHP aus der Umgebung uebernehmen.
#
# TZ setzt die Uhr des Containers, PHP interessiert das aber nicht: ohne
# date.timezone rechnet es in UTC weiter. Im Sommer sind das zwei Stunden
# Unterschied zur Uhr an der Wand - und die stehen dann in jedem Zeitstempel,
# vom "gesendet am" bis zum Zeitpunkt, an dem eine Krankmeldung gelesen wurde.
printf 'date.timezone = %s\n' "${TZ:-Europe/Berlin}" > /usr/local/etc/php/conf.d/zeitzone.ini

UPLOADS=/var/www/html/public/uploads

if [ -d "$UPLOADS" ]; then
    chgrp -R www-data "$UPLOADS" 2>/dev/null || true
    chmod 2775 "$UPLOADS"        2>/dev/null || true
    find "$UPLOADS" -type d -exec chmod 2775 {} + 2>/dev/null || true
    find "$UPLOADS" -type f -exec chmod g+w  {} + 2>/dev/null || true

    if ! su -s /bin/sh www-data -c "test -w $UPLOADS"; then
        echo "feedback_rstn: WARNUNG - $UPLOADS ist fuer www-data nicht beschreibbar." >&2
        echo "feedback_rstn: Anhaenge und Jahresarchive lassen sich nicht speichern." >&2
        echo "feedback_rstn: Auf dem Host abhelfen mit:" >&2
        echo "feedback_rstn:   sudo chown -R \$USER:www-data src/public/uploads" >&2
        echo "feedback_rstn:   sudo chmod -R g+w src/public/uploads" >&2
    fi
fi

exec docker-php-entrypoint "$@"
