FROM php:8.2-apache

# Install PDO MySQL extension and zip/unzip for Composer
RUN apt-get update && apt-get install -y unzip zip \
    && docker-php-ext-install pdo pdo_mysql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Enable apache mod_rewrite
RUN a2enmod rewrite

# Produktionsvorgaben von PHP aktivieren.
#
# Das Abbild legt php.ini-production und php.ini-development nebeneinander,
# aktiviert aber keine von beiden. Ohne php.ini gelten die eingebauten
# Vorgaben - und dort ist display_errors eingeschaltet: jede Warnung landete
# samt absolutem Pfad im Browser. Bei einem Datenbankfehler steht in der
# Meldung auch schon mal der Verbindungsstring.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Die PHP-Version gehoert nicht in jeden Antwort-Header: sie erspart die
# Suche danach, welche Luecken sich zu probieren lohnen. Ob die
# php.ini-production des Abbilds sie schon abschaltet, haengt von der
# Herkunft der Datei ab - die Fassung aus dem PHP-Quellpaket laesst
# expose_php an, die aus Debian nicht. Diese Zeile gilt in beiden Faellen.
#
# Wichtig, dass die Datei in conf.d liegt und nicht in der php.ini: conf.d
# wird danach geparst und gewinnt.
RUN echo "expose_php = Off" > /usr/local/etc/php/conf.d/haerten.ini

# Grenzen fuer Atteste.
#
# Die eingebauten Vorgaben sind 2M/8M, und die php.ini-production oben setzt
# dasselbe. Ein Foto einer Arbeitsunfaehigkeitsbescheinigung aus einem
# Telefon liegt regelmaessig darueber - PHP weist den Upload dann ab, noch
# bevor der Code ihn sieht. Wichtig, dass die Datei in conf.d liegt: das
# Verzeichnis wird nach der php.ini geparst und gewinnt.
RUN printf 'upload_max_filesize = 20M\npost_max_size = 22M\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

# Change DocumentRoot to /var/www/html/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# src/ wird zur Laufzeit eingehaengt; die Rechte des eingehaengten
# Upload-Verzeichnisses richtet der Entrypoint beim Start.
COPY docker-entrypoint.sh /usr/local/bin/feedback-entrypoint.sh
RUN chmod +x /usr/local/bin/feedback-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/feedback-entrypoint.sh"]
CMD ["apache2-foreground"]
