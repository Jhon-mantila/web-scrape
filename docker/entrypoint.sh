#!/bin/bash
set -e

APP_UID="${APP_UID:-1000}"
APP_GID="${APP_GID:-1000}"
WEB_USER="${WEB_USER:-laravel}"

mkdir -p \
    storage/app/public/featured-images \
    storage/app/public/Logo \
    storage/app/public/social-videos \
    storage/app/public/social-thumbnails \
    storage/app/public/social-clips \
    storage/app/private/video-jobs \
    storage/app/private/realesrgan \
    storage/framework/{cache,sessions,views} \
    bootstrap/cache \
    storage/logs \
    storage/inertia-devtools

if [ "$(id -u)" = "0" ]; then
    if [ -f .env ] && grep -qE '^APP_ENV=local' .env; then
        rm -f bootstrap/cache/config.php
    fi

    if ! getent group "${WEB_USER}" >/dev/null 2>&1; then
        groupadd -g "${APP_GID}" "${WEB_USER}" 2>/dev/null || groupadd "${WEB_USER}" 2>/dev/null || true
    fi
    if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
        useradd -u "${APP_UID}" -g "${WEB_USER}" -s /sbin/nologin "${WEB_USER}" 2>/dev/null \
            || useradd -g "${WEB_USER}" -s /sbin/nologin "${WEB_USER}" 2>/dev/null || true
    fi

    # apache2.conf usa User ${APACHE_RUN_USER}. No uses "User #1000": en Apache el # es comentario.
    # Tampoco escribas en conf-enabled: ahí hay un bind-mount de laravel.conf.
    if [ -f /etc/apache2/envvars ]; then
        sed -i "s/www-data/${WEB_USER}/g" /etc/apache2/envvars
    fi
    export APACHE_RUN_USER="${WEB_USER}"
    export APACHE_RUN_GROUP="${WEB_USER}"

    chown -R "${APP_UID}:${APP_GID}" storage bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
    chmod -R a+rX storage/app/public 2>/dev/null || true
    chmod -R a+rwX \
        storage/framework/views \
        storage/framework/cache \
        storage/framework/sessions \
        storage/logs \
        storage/app/public/featured-images \
        storage/app/public/Logo \
        storage/app/public/social-videos \
        storage/app/public/social-thumbnails \
        storage/app/public/social-clips \
        storage/app/private \
        storage/inertia-devtools \
        2>/dev/null || true
    touch storage/logs/laravel.log storage/logs/editorial-agent.log 2>/dev/null || true
    chmod a+rw storage/logs/*.log 2>/dev/null || true
fi

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec apache2-foreground
