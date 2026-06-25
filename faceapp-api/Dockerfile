FROM composer:2 AS build

WORKDIR /app

COPY . .

RUN cp .env.example .env \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev unzip \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-faceapp.ini
COPY docker/entrypoint.sh /usr/local/bin/faceapp-api-entrypoint
COPY --from=build /app /var/www/html

RUN chmod +x /usr/local/bin/faceapp-api-entrypoint \
    && mkdir -p /var/www/html/storage/database \
    # Apache serves as www-data, so the whole app tree must be owned by it.
    # The composer build stage leaves files root:root 750, which makes Apache
    # 403 ("unable to read .htaccess" / can't open /config) — chown fixes that.
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["faceapp-api-entrypoint"]
CMD ["apache2-foreground"]
