# syntax=docker/dockerfile:1.6

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

FROM php:8.2-apache

# Install system dependencies and PHP extensions needed by the app
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install bcmath mbstring pdo_mysql xml zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Copy application source
COPY . /var/www/html

# Copy Composer dependencies from the build stage if they exist
COPY --from=vendor /app/vendor /var/www/html/vendor

# Ensure correct permissions for Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
