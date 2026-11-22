# PHP backend image for Render (Render has no native PHP; it runs this Dockerfile).
# Build context = backend-php/ (Render: Root Directory "backend-php").
#
#   docker build -t timetable-php backend-php
#   docker run -p 8080:8080 -e PORT=8080 -e APP_KEY=... -e JWT_SECRET_KEY=... timetable-php
#
# On start: migrate the database, then serve with Apache on $PORT. See DEPLOY.md.

FROM php:8.3-apache

# PHP extensions: MySQL, PhpSpreadsheet (zip, gd), intl, opcache.
# (mbstring, openssl, curl, dom, fileinfo, ctype, tokenizer are built into this image.)
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql zip gd intl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Production PHP settings, uploads up to 25 MB (the app limits them to MAX_UPLOAD_MB).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf "upload_max_filesize=25M\npost_max_size=26M\nmemory_limit=256M\nexpose_php=Off\n" > "$PHP_INI_DIR/conf.d/app.ini"

# Apache serves public/ (Laravel's front controller) and reads its .htaccess.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Dependencies first, so code changes don't reinstall every package.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache

# Defaults for Render; every one can be overridden in the Environment tab.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    SESSION_DRIVER=array \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    PORT=8080

COPY docker/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start

CMD ["start"]
