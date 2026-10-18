# NightGuardian collector: one image for the web app, the queue worker and the scheduler.
# See docker-compose.yml for the 2-minute trial and docs/deploy.md for a production installation.

# --- dependencies ---------------------------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# Scripts need the whole application (artisan, Filament); they run in the final stage.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --ignore-platform-reqs

# --- runtime --------------------------------------------------------------------------------------------------
FROM php:8.3-apache

ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.9/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions intl zip pdo_mysql opcache bcmath \
    && apt-get update && apt-get install -y --no-install-recommends unzip curl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=8M'; \
    } > /usr/local/etc/php/conf.d/nightguardian.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/nightguardian-entrypoint
RUN chmod +x /usr/local/bin/nightguardian-entrypoint \
    && cp .env.example .env \
    && php artisan package:discover --ansi \
    && php artisan filament:upgrade \
    && rm .env \
    && mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["nightguardian-entrypoint"]
CMD ["apache2-foreground"]
