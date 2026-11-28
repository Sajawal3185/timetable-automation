#!/bin/sh
# Container start (Render runs this on every deploy and restart).
# Replaces the Python service's "alembic upgrade head && uvicorn ..." start command.
set -e

# Render tells the app which port to listen on through $PORT.
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# SQLite (CI's container test only): the file must exist and be Apache's.
if [ "${DB_CONNECTION}" = "sqlite" ] && [ -n "${DB_DATABASE}" ] && [ ! -f "${DB_DATABASE}" ]; then
    touch "${DB_DATABASE}" && chown www-data:www-data "${DB_DATABASE}" "$(dirname "${DB_DATABASE}")"
fi

# Render's secret files (/etc/secrets/ca.pem) are readable by root only. This script
# runs as root, so migrations connected fine, but Apache's PHP runs as www-data: it
# could not read the CA, so every database query from a web request failed (500).
# Copy the certificate to a place www-data can read; it is a public CA, not a secret.
if [ -n "${DB_SSL_CA}" ] && [ -f "${DB_SSL_CA}" ]; then
    install -m 0644 "${DB_SSL_CA}" /usr/local/share/db-ca.pem
    export DB_SSL_CA=/usr/local/share/db-ca.pem
fi

# Settings are read once here and cached, so a missing one fails now, not mid-request.
php artisan config:cache

# Bring the database up to date (like "alembic upgrade head"). migrate:deploy
# (routes/console.php) refuses FastAPI's database and takes turns with other
# containers starting at the same moment.
php artisan migrate:deploy

exec apache2-foreground
