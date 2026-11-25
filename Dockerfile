# Exact versions, so every build uses the same PHP, Debian and Composer.
# Keep PHP_IMAGE in sync with "config.platform.php" in composer.json, and COMPOSER_IMAGE with the Makefile.
ARG PHP_IMAGE=php:8.4.28-cli-trixie
ARG COMPOSER_IMAGE=composer:2.11.2

FROM ${COMPOSER_IMAGE} AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY src/ src/
RUN composer dump-autoload --no-dev --optimize

# Dependencies including dev ones (PHPUnit), used only by the test image
FROM ${COMPOSER_IMAGE} AS vendor-dev

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY src/ src/
COPY tests/ tests/
RUN composer dump-autoload --optimize

# Shared by the app and test images
FROM ${PHP_IMAGE} AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron \
    && rm -rf /var/lib/apt/lists/*

# Scheduler is picked by the CRON_RUNNER_ADAPTER env variable passed to `docker run`: "laravel" or "crunz".
# Without it, src/AdapterFactory.php uses Laravel.
WORKDIR /app

COPY src/ src/
COPY composer.json index.php ./
COPY start-cron.sh /usr/local/bin/start-cron

# Test image: `make test`
FROM base AS test

COPY --from=vendor-dev /app/vendor/ vendor/
COPY tests/ tests/
COPY phpunit.xml.dist ./

CMD ["vendor/bin/phpunit"]

# App image, kept last so it's the default build target
FROM base AS app

COPY --from=vendor /app/vendor/ vendor/

# Crontab is written on start, so it gets the CRON_RUNNER_ADAPTER passed to `docker run`
CMD ["sh", "/usr/local/bin/start-cron"]
