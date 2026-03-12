FROM php:8.2-apache

# Install PDO MySQL extension and zip/unzip for Composer
RUN apt-get update && apt-get install -y unzip zip \
    && docker-php-ext-install pdo pdo_mysql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Enable apache mod_rewrite if needed
RUN a2enmod rewrite
