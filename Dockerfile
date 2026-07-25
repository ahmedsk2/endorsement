# syntax=docker/dockerfile:1
#
# Production image for the Paediatric Endorsement system.
#
# Target host: OCI VM.Standard.A1.Flex (ARM64, Ubuntu) running Coolify + Traefik, so the
# image must build natively on aarch64 — every base here is multi-arch.
#
# Deliberate properties:
#  - assets are built in a throwaway stage; node never ships to production;
#  - composer runs --no-dev, and the vendor tree is baked into the image (no network at boot);
#  - MIGRATIONS ARE NOT RUN AT BOOT. The owner runs them (project rule: production
#    migrations and live-DB changes are theirs), so a restart can never alter the schema;
#  - the app runs as a NON-ROOT user;
#  - config/route/view caches are built at boot, when the real env is present.

# ---------- stage 1: front-end assets ----------
FROM node:22-alpine AS assets
WORKDIR /build
COPY package*.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- stage 2: PHP dependencies ----------
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# Scripts are skipped here: artisan is not present yet and must not run at build time.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

# ---------- stage 3: runtime ----------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx supervisor tzdata icu-data-full \
        libpng libjpeg-turbo freetype libzip icu-libs oniguruma gmp \
        mysql-client gzip openssl \
        # Alpine's `mysql-client` is MariaDB's client, and on its own it CANNOT authenticate
        # to MySQL 8.4: the default auth is caching_sha2_password and the plugin .so is not
        # in that package. mariadb-connector-c ships it. Without this the nightly backup
        # fails every night with "Plugin caching_sha2_password could not be loaded".
        mariadb-connector-c \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev icu-dev oniguromo-dev gmp-dev 2>/dev/null \
    || apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev icu-dev oniguruma-dev gmp-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql gd intl zip bcmath gmp opcache \
    && apk del .build-deps

# Opcache tuned for a long-running production container; JIT left off (no measurable win
# for this workload and it complicates crash triage).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'expose_php=0'; \
        echo 'upload_max_filesize=4M'; \
        echo 'post_max_size=8M'; \
        echo 'memory_limit=256M'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT'; \
    } > /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /var/www/html

COPY --from=vendor /build/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build

# Discover packages against the PRODUCTION dependency tree (the dev-built manifest is
# excluded by .dockerignore, so this is the only source of truth in the image).
RUN php artisan package:discover --ansi

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# php-fpm must run as the SAME user that owns storage/. The base image's pool runs as
# www-data, which cannot write to storage owned by `app` — signature uploads and the log
# would fail silently in production while the pages still rendered.
RUN { \
        echo '[www]'; \
        echo 'user = app'; \
        echo 'group = app'; \
        echo 'listen = 127.0.0.1:9000'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 20'; \
        echo 'pm.start_servers = 3'; \
        echo 'pm.min_spare_servers = 2'; \
        echo 'pm.max_spare_servers = 6'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zz-app.conf

# The app never writes outside storage/ and bootstrap/cache.
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/private/signatures storage/backups bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
