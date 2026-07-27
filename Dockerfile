# syntax=docker/dockerfile:1
#
# BASE IMAGES ARE PINNED BY DIGEST, not by tag.
#
# A tag is mutable: `php:8.4-fpm-alpine` today and in six months are different bits, so a
# rebuild — which is exactly what a Coolify "redeploy last good" does — could ship
# something never tested, and a rollback could differ from the thing it is rolling back
# to. The digest makes a rebuild reproducible.
#
# The trade-off is that security updates no longer arrive silently. That is deliberate and
# handled: .github/dependabot.yml watches the docker ecosystem and opens a PR when a base
# image moves, so the bump is a reviewed change rather than an invisible one.
#
# Digests captured 2026-07-27. To refresh by hand:
#   docker pull php:8.4-fpm-alpine && docker inspect php:8.4-fpm-alpine --format '{{index .RepoDigests 0}}'
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
#  - every application process runs as the NON-ROOT `app` user: the php-fpm pool workers,
#    the scheduler, and the boot-time artisan commands. supervisord itself stays root and
#    cannot be dropped — see the note in docker/entrypoint.sh — so the nginx and php-fpm
#    MASTERS are root too, and the container drops its capabilities to compensate;
#  - config/route/view caches are built at boot, when the real env is present.

# ---------- stage 1: front-end assets ----------
FROM node:22-alpine@sha256:16e22a550f3863206a3f701448c45f7912c6896a62de43add43bb9c86130c3e2 AS assets
WORKDIR /build
# .npmrc carries `ignore-scripts=true`. It was not copied, so the policy applied on a
# developer's laptop and NOT in the build — which is the one place it matters, because this
# build runs as root on the same docker daemon as the patient database. A dependency's
# postinstall script had a free hand exactly where it could do the most damage.
COPY package*.json .npmrc vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- stage 2: PHP dependencies ----------
FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# Scripts are skipped here: artisan is not present yet and must not run at build time.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

# ---------- stage 3: runtime ----------
FROM php:8.4-fpm-alpine@sha256:913ddd6934a805429618a16aa36da47cd8a8aec8b2f111c294936ba4003fded6 AS runtime

RUN apk add --no-cache \
        nginx supervisor tzdata icu-data-full \
        libpng libjpeg-turbo freetype libzip icu-libs oniguruma gmp \
        mysql-client gzip openssl \
        # Drops the boot-time artisan commands to `app`. They bootstrap the framework out
        # of bootstrap/cache, which is app-writable — so running them as root means anything
        # able to write there executes as uid 0 on the next restart.
        su-exec \
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
    && chmod -R 775 storage bootstrap/cache \
    # nginx's temp and cache trees must belong to `app`, because its WORKERS write there.
    # /var/lib/nginx/logs is a SYMLINK to /var/log/nginx and `chown -R` does not follow
    # symlinks, so the real directory is named explicitly.
    #
    # (The masters run as root, so they can open their own logs regardless. These chowns
    # were widened during an abandoned `USER app` attempt and the revert left the comment
    # claiming more than it does; /var/log/nginx and /usr/local/var/log are now belt and
    # braces rather than load-bearing.)
    && mkdir -p /var/lib/nginx/tmp /var/log/nginx /usr/local/var/log \
    && chown -R app:app /var/lib/nginx /var/log/nginx /usr/local/var/log

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
