# Petani Pintar dashboard — Coolify Docker build.
# Build context: dashboard/ (set Base Directory = dashboard in Coolify).
# Runtime: nginx + php-fpm + MQTT consumer under supervisord.

# ---------- PHP dependencies ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

# ---------- Frontend assets ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . ./
RUN npm run build

# ---------- Runtime ----------
FROM php:8.4-fpm-alpine
WORKDIR /app

RUN apk add --no-cache nginx supervisor libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache pcntl bcmath \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

COPY . ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN chmod +x /app/docker/entrypoint.sh \
    && mkdir -p /app/storage/logs /run/nginx /var/log/supervisor \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && php artisan storage:link --no-interaction --force || true

EXPOSE 80

ENTRYPOINT ["/app/docker/entrypoint.sh"]
