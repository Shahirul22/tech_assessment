# syntax=docker/dockerfile:1

# Stage 1: build the front-end assets.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
# VITE_* values (Reverb host/port the browser uses) are read from here at build time.
COPY .env.docker ./.env.production
RUN npm run build

# Stage 2: nginx with the public/ directory, so it can serve static assets.
# PHP requests are proxied to the app service (docker/nginx/default.conf).
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

# Stage 3: PHP-FPM with PostgreSQL support. Keep it last, so a plain `docker build` produces it.
# A full Composer install (dev dependencies included) because the tests run in this image.
FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache git unzip postgresql-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql pcntl bcmath \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
COPY --from=assets /app/public/build ./public/build
# Package discovery boots the app; a log broadcaster avoids needing Reverb keys at build time.
RUN BROADCAST_CONNECTION=log composer dump-autoload --optimize \
    && chmod -R a+rwX storage bootstrap/cache \
    # A pristine copy of the built assets. In development a named volume sits over public/build and would keep
    # a stale Vite manifest after a rebuild; the dev entrypoint (docker-compose.override.yml) refreshes it from here.
    && cp -a public/build /opt/public-build \
    && chmod -R a+rwX public/build /opt/public-build \
    # Pest and its mutation plugin keep caches in a .temp directory inside vendor. They must exist and be writable for
    # any user: `APP` runs as the host user inside a root-owned vendor volume, and without this the test run is full of
    # "Permission denied" and "file_get_contents" warnings.
    && mkdir -p vendor/pestphp/pest/.temp vendor/pestphp/pest-plugin-mutate/.temp \
    && chmod -R a+rwX vendor/pestphp/pest/.temp vendor/pestphp/pest-plugin-mutate/.temp
