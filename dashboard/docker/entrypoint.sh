#!/bin/sh
# Container entrypoint: migrate, warm caches, then supervise nginx/php-fpm/MQTT.
set -e

cd /app

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

echo "==> Caching config..."
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

echo "==> Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
