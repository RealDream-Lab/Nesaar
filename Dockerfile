# syntax=docker/dockerfile:1.6

FROM php:8.2-apache

# Install system dependencies and PHP extensions needed by the app
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install bcmath mbstring pdo_mysql xml zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP upload and post limits
RUN echo "upload_max_filesize = 128M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

# Copy application source
COPY . /var/www/html

# Ensure correct permissions for Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
