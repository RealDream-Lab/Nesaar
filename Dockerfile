# syntax=docker/dockerfile:1.6

# Stage: builder - run javascript-obfuscator and produce obfuscated outputs in /out
FROM node:16-alpine AS builder

WORKDIR /src

# Install javascript-obfuscator globally (version 4.0.0 for stability)
RUN npm install -g javascript-obfuscator@4.0.0

# Copy only the JS files we need to obfuscate from the build context
# (these paths should match your repo layout)
COPY assets/app/app.js assets/app/app.js
COPY assets/app/push-notifications.js assets/app/push-notifications.js
COPY dashboard/dashboard.js dashboard/dashboard.js
COPY dashboard/observers/observers.js dashboard/observers/observers.js

RUN mkdir -p /out/assets/app /out/dashboard/observers

# Obfuscate files with aggressive parameters (matches the script's settings)
RUN javascript-obfuscator assets/app/app.js \
    --output /out/assets/app/app.js \
    --compact true \
    --control-flow-flattening true \
    --control-flow-flattening-threshold 0.75 \
    --dead-code-injection false \
    --disable-console-output false \
    --identifier-names-generator hexadecimal \
    --rename-globals false \
    --self-defending true \
    --string-array true \
    --string-array-encoding base64 \
    --string-array-threshold 0.75 \
    --transform-object-keys true \
    --unicode-escape-sequence false

RUN javascript-obfuscator dashboard/dashboard.js \
    --output /out/dashboard/dashboard.js \
    --compact true \
    --control-flow-flattening true \
    --control-flow-flattening-threshold 0.75 \
    --dead-code-injection false \
    --disable-console-output false \
    --identifier-names-generator hexadecimal \
    --rename-globals false \
    --self-defending true \
    --string-array true \
    --string-array-encoding base64 \
    --string-array-threshold 0.75 \
    --transform-object-keys true \
    --unicode-escape-sequence false

RUN javascript-obfuscator dashboard/observers/observers.js \
    --output /out/dashboard/observers/observers.js \
    --compact true \
    --control-flow-flattening true \
    --control-flow-flattening-threshold 0.75 \
    --dead-code-injection false \
    --disable-console-output false \
    --identifier-names-generator hexadecimal \
    --rename-globals false \
    --self-defending true \
    --string-array true \
    --string-array-encoding base64 \
    --string-array-threshold 0.75 \
    --transform-object-keys true \
    --unicode-escape-sequence false

RUN javascript-obfuscator assets/app/push-notifications.js \
    --output /out/assets/app/push-notifications.js \
    --compact true \
    --control-flow-flattening true \
    --control-flow-flattening-threshold 0.75 \
    --dead-code-injection false \
    --disable-console-output false \
    --identifier-names-generator hexadecimal \
    --rename-globals false \
    --self-defending true \
    --string-array true \
    --string-array-encoding base64 \
    --string-array-threshold 0.75 \
    --transform-object-keys true \
    --unicode-escape-sequence false

# NOTE: service-worker.js is intentionally excluded from obfuscation to preserve exact
# caching behavior and signatures. We keep the original file in the final image.


# Stage: final - base PHP image with app files; we'll remove originals and copy obfuscated outputs
FROM php:8.3-apache AS final

# Install system dependencies, PHP extensions, Composer, ImageMagick and cron
RUN apt-get update \
     && apt-get install -y --no-install-recommends \
         libzip-dev libonig-dev libxml2-dev unzip mc nano openssh-server curl \
         libpng-dev libjpeg-dev libfreetype6-dev \
         libgmp-dev cron \
         libmagickwand-dev libmagickcore-dev imagemagick pkg-config build-essential autoconf \
     && docker-php-ext-configure gd --with-freetype --with-jpeg \
     && docker-php-ext-install bcmath mbstring pdo_mysql xml zip gd gmp \
     && pecl install imagick || true \
     && docker-php-ext-enable imagick || true \
     && a2enmod rewrite headers \
     && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
     && rm -rf /var/lib/apt/lists/*

# Configure PHP upload and post limits
RUN echo "upload_max_filesize = 128M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

# Copy full project into the image (we'll remove original JS files afterwards)
COPY . /var/www/html

WORKDIR /var/www/html

# Remove original JS files from the final image so only obfuscated versions remain
# NOTE: do NOT remove service-worker.js here — we want the original service-worker preserved
RUN rm -f /var/www/html/assets/app/app.js \
    /var/www/html/assets/app/push-notifications.js \
    /var/www/html/dashboard/dashboard.js \
    /var/www/html/dashboard/observers/observers.js || true

# Copy obfuscated outputs produced in builder stage into their final locations
COPY --from=builder /out/ /var/www/html/

# Ensure Composer dependencies are installed
RUN composer require openspout/openspout:5.0.0 --no-interaction --prefer-dist || true \
    && composer require mpdf/mpdf:8.2.6 --no-interaction --prefer-dist || true \
    && composer require minishlink/web-push:9.0.3 --no-interaction --prefer-dist || true \
    && composer require endroid/qr-code:6.0.9 --no-interaction --prefer-dist || true \
    && composer require khanamiryan/qrcode-detector-decoder:2.0.3 --no-interaction --prefer-dist || true
    
# Create temp directories with proper permissions
RUN mkdir -p /var/www/html/temp/mpdf/ttfontdata \
    && mkdir -p /var/www/html/database \
    && chown -R www-data:www-data /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html/database \
    && chmod -R 777 /var/www/html/temp \
    && chmod -R 777 /var/www/html/database

# Setup cron jobs for push notifications (every minute to catch all exam times)
# 1. Auto push notifications based on exam schedule
# 2. Scheduled push notifications set by admin
RUN echo "* * * * * www-data php /var/www/html/scripts/cron_push_notifications.php >> /var/log/push_cron.log 2>&1" > /etc/cron.d/push-notifications \
    && echo "* * * * * www-data php /var/www/html/API/push/cron_send_scheduled.php >> /var/log/push_scheduled_cron.log 2>&1" >> /etc/cron.d/push-notifications \
    && chmod 0644 /etc/cron.d/push-notifications \
    && crontab -u www-data /etc/cron.d/push-notifications \
    && touch /var/log/push_cron.log /var/log/push_scheduled_cron.log \
    && chown www-data:www-data /var/log/push_cron.log /var/log/push_scheduled_cron.log

# Ensure correct permissions for Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Start cron and Apache
CMD service cron start && apache2-foreground
