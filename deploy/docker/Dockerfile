# syntax=docker/dockerfile:1
#
# ViStud in a sealed box (see docs/development/docker-deploy.md). One image runs three ways: the web server
# (nginx + PHP-FPM), the queue worker and the scheduler. The database is a separate container.
#
# Build from the repository root:   docker build -f deploy/docker/Dockerfile -t vistud-app:local .

# 1. The stylesheet and scripts (Vite). Node is not kept in the final image.
FROM node:22-bookworm-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# 2. What the app runs on.
FROM php:8.4-fpm-bookworm AS runtime

# nginx serves the web side. ffmpeg cuts class recordings. LibreOffice (headless) makes previews of
# Word, PowerPoint and Excel files and the PDF/Word exports of notes.
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        nginx gettext-base unzip curl ca-certificates \
        ffmpeg \
        libreoffice-writer libreoffice-impress libreoffice-calc \
        fonts-dejavu-core fonts-liberation \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
 && rm -rf /var/lib/apt/lists/*

# mbstring, sodium, openssl, fileinfo, curl and xml come with the official PHP image.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j2 pdo_mysql intl zip gd pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so a code-only change does not reinstall them.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

COPY . .
COPY --from=assets /src/public/build public/build
RUN composer dump-autoload --no-dev --optimize --no-scripts \
 && rm -f /usr/bin/composer

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-vistud.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-vistud.conf
COPY deploy/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/docker/nginx-site.conf.template /etc/nginx/vistud-site.conf.template
COPY deploy/docker/entrypoint.sh /usr/local/bin/vistud-entrypoint
RUN chmod +x /usr/local/bin/vistud-entrypoint \
 && mkdir -p bootstrap/cache storage \
 && chown -R www-data:www-data bootstrap/cache storage /var/www/html/public/build

# Everything runs as the unprivileged web user; nothing in the box needs root.
ENV HOME=/tmp \
    VISTUD_TEMP_ROOT=/run/vistud-tmp/app \
    APP_ENV=production
USER www-data
EXPOSE 8080
ENTRYPOINT ["vistud-entrypoint"]
CMD ["app"]
