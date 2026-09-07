#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# First boot in a fresh checkout: create the env file and an app key.
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

# Wait for MySQL. Compose healthchecks already gate startup, but a container
# restarted on its own (docker restart app) has no such guarantee.
until php -r 'new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    echo "Waiting for MySQL..."
    sleep 2
done

# Only the web container owns schema migration; the queue worker must never
# race it, so it exits this branch immediately.
if [ "${1:-}" = "php-fpm" ]; then
    php artisan migrate --force
    php artisan storage:link || true
fi

exec "$@"
