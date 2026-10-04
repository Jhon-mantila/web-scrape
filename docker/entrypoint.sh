#!/bin/bash
set -e

APP_UID="${APP_UID:-1000}"
APP_GID="${APP_GID:-1000}"
WEB_USER="${WEB_USER:-laravel}"

mkdir -p storage/app/public/featured-images storage/framework/{cache,sessions,views} bootstrap/cache storage/logs

if [ "$(id -u)" = "0" ]; then
    if ! getent group "${WEB_USER}" >/dev/null 2>&1; then
        groupadd -g "${APP_GID}" "${WEB_USER}" 2>/dev/null || groupadd "${WEB_USER}"
    fi
    if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
        useradd -u "${APP_UID}" -g "${WEB_USER}" -s /sbin/nologin "${WEB_USER}" 2>/dev/null \
            || useradd -g "${WEB_USER}" -s /sbin/nologin "${WEB_USER}"
    fi
    chown -R "${WEB_USER}:${WEB_USER}" storage bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX storage/app/public/featured-images 2>/dev/null || true
    export APACHE_RUN_USER="${WEB_USER}"
    export APACHE_RUN_GROUP="${WEB_USER}"
fi

exec apache2-foreground
