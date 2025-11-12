# syntax=docker/dockerfile:1.6

# Stage: builder - run javascript-obfuscator and produce obfuscated outputs in /out
FROM node:18-alpine AS builder

WORKDIR /src

# Install javascript-obfuscator globally
RUN npm install -g javascript-obfuscator@4

# Copy only the JS files we need to obfuscate from the build context
# (these paths should match your repo layout)
COPY assets/app/app.js assets/app/app.js
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

# NOTE: service-worker.js is intentionally excluded from obfuscation to preserve exact
# caching behavior and signatures. We keep the original file in the final image.


# Stage: final - base PHP image with app files; we'll remove originals and copy obfuscated outputs
FROM php:8.3-apache AS final

# Install system dependencies, PHP extensions, and Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libonig-dev libxml2-dev unzip mc curl \
    && docker-php-ext-install bcmath mbstring pdo_mysql xml zip \
    && a2enmod rewrite headers \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP upload and post limits
RUN echo "upload_max_filesize = 128M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

# Copy full project into the image (we'll remove original JS files afterwards)
COPY . /var/www/html

WORKDIR /var/www/html

# Remove original JS files from the final image so only obfuscated versions remain
# NOTE: do NOT remove service-worker.js here — we want the original service-worker preserved
RUN rm -f /var/www/html/assets/app/app.js \
    /var/www/html/dashboard/dashboard.js \
    /var/www/html/dashboard/observers/observers.js || true

# Copy obfuscated outputs produced in builder stage into their final locations
COPY --from=builder /out/ /var/www/html/

# Ensure Composer dependencies are installed (including OpenSpout)
# Installing at build time keeps vendor inside the image; adjust if you prefer multi-stage composer
RUN composer require openspout/openspout --no-interaction --prefer-dist || true

# Ensure correct permissions for Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
