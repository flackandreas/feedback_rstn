FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable apache mod_rewrite if needed
RUN a2enmod rewrite
