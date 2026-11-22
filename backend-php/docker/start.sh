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

# Settings are read once here and cached, so a missing one fails now, not mid-request.
php artisan config:cache

# Bring the database up to date (like "alembic upgrade head").
php artisan migrate --force

exec apache2-foreground
