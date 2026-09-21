# syntax=docker/dockerfile:1

##############################################
# 1. Composer dependencies
##############################################
FROM composer:2 AS vendor
WORKDIR /app
COPY database/ database/
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

##############################################
# 2. Frontend assets (Vite)
##############################################
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

##############################################
# 3. PHP-FPM application image
##############################################
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        libpng-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
        pcntl \
        opcache \
    && apk del --no-cache icu-dev libzip-dev libpng-dev oniguruma-dev freetype-dev libjpeg-turbo-dev

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Baked at build time so the nginx image (stage below) inherits the same
# relative symlink; the volume mounted at runtime supplies its target.
RUN php artisan storage:link --force \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

##############################################
# 4. Nginx image (static assets + PHP proxy)
##############################################
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
WORKDIR /var/www/html
COPY --from=app /var/www/html/public ./public

EXPOSE 80
