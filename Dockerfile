FROM php:8.2-apache

# Install PDO MySQL extension and zip/unzip for Composer
RUN apt-get update && apt-get install -y unzip zip \
    && docker-php-ext-install pdo pdo_mysql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Enable apache mod_rewrite
RUN a2enmod rewrite

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
