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
# packages/ is copied BEFORE `npm ci`, not after, because package.json declares npm workspaces
# ("packages/*") and the lockfile carries their entries. `npm ci` does not fail when a declared
# workspace directory is absent — measured, it exits 0 and silently installs nothing for it — so
# omitting this line produces an image whose node_modules differs from the one CI resolved, and
# the difference only surfaces later, as a resolution error in a build that used to work. Same
# install here as in CI, for the same reason composer's platform check is satisfied for real in
# stage 2 rather than waived.
COPY packages ./packages
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- stage 2: PHP dependencies ----------
#
# composer.json has required ext-intl since commit 2108c39, and composer.lock's own
# "platform" block repeats it — so `composer install`'s platform check is unavoidable, and
# this image's PHP (see below) does not have it compiled in by default. Two ways to satisfy
# that check were considered:
#
#   (a) `composer install --ignore-platform-req=ext-intl` — makes the build pass by telling
#       composer to stop checking, without the check ever becoming true. It genuinely IS
#       satisfied at runtime (intl is installed in stage 3 below), so the waiver is not
#       lying about the eventual image — but it is lying about what THIS command verified,
#       and a real gap (a package resolved here that turns out to need an intl SYMBOL, not
#       just the extension present, would sail through unnoticed).
#   (b) install intl for real, here, for the duration of this RUN — chosen. The platform
#       check then means what it says: composer actually has the extension loaded while it
#       resolves and autoloads, identically to every other declared requirement. It costs
#       one extra `apk add`/`docker-php-ext-install`/`apk del` in a stage whose only output
#       is vendor/ (nothing else from this stage reaches the final image), so there is no
#       runtime cost to the trade.
#
# Separately: this base image's own PHP (8.5.8, as of the pinned digest below) is NOT the
# PHP that executes the code (stage 3 pins 8.4.23) — composer.json's root constraint
# ("php": "^8.3") is satisfied by both, so today's lock resolves identically either way
# (verified: regenerating the lock under this fix changed only content-hash and
# platform-overrides, no package versions moved), but that is not a fact this Dockerfile
# guaranteed on its own. `composer.json`'s `config.platform.php` now pins dependency
# RESOLUTION to 8.4.23 explicitly, so a future `composer update` is decided against the
# runtime version regardless of which PHP the composer binary itself happens to run
# under — closing the gap precisely, without needing this stage's base image to match
# stage 3's (which would be the larger, not-requested change: swapping composer's own image
# for one built on php:8.4-fpm-alpine, carrying its own dependency-availability questions
# for zip/unzip extraction that were not evaluated here).
FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS vendor
WORKDIR /build
# icu-libs stays (the compiled intl.so dlopen()s it at runtime); icu-dev and the compiler
# toolchain are virtual and removed once the extension is built, same split stage 3 uses
# below — `apk del` on a virtual group that ALSO held icu-libs would cascade-remove it as a
# now-unneeded transitive dependency and leave intl.so unloadable (proven: the first attempt
# at this fix did exactly that — "Error loading shared library libicuio.so.78").
RUN apk add --no-cache icu-libs \
    && apk add --no-cache --virtual .intl-build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install intl \
    && apk del .intl-build-deps
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
    # nginx's temp trees must belong to the user its WORKERS run as, which is `nginx` —
    # NOT `app`. They were chowned to `app` during an abandoned `USER app` attempt and left
    # that way when it was reverted, which produced a bug that hid for days:
    #
    #   open() "/var/lib/nginx/tmp/client_body/0000000001" failed (13: Permission denied)
    #
    # nginx buffers a request body in memory up to client_body_buffer_size (~16k) and spills
    # anything larger to a file under /var/lib/nginx/tmp. So a SMALL signature worked and a
    # slightly larger one returned 500 — intermittent by size, which reads as random.
    #
    # `app` is deliberately not used here: it owns the application tree, and nginx workers
    # serve static files and proxy to php-fpm without ever needing to write there. Keeping
    # them as `nginx` with their own writable temp is the better isolation AND the fix.
    #
    # /var/lib/nginx/logs is a SYMLINK to /var/log/nginx and `chown -R` does not follow
    # symlinks, so the real directory is named explicitly.
    && mkdir -p /var/lib/nginx/tmp /var/log/nginx /usr/local/var/log \
    && chown -R nginx:nginx /var/lib/nginx /var/log/nginx \
    && chown -R app:app /usr/local/var/log

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
