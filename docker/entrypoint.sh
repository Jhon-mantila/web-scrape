#!/bin/bash
set -e

APP_UID="${APP_UID:-1000}"
APP_GID="${APP_GID:-1000}"
WEB_USER="${WEB_USER:-laravel}"

mkdir -p storage/app/public/featured-images storage/app/public/social-videos storage/app/public/social-thumbnails storage/framework/{cache,sessions,views} bootstrap/cache storage/logs storage/inertia-devtools

if [ "$(id -u)" = "0" ]; then
    # Primero: Apache debe correr como el mismo UID/GID que el bind mount (evita touch() en Blade).
    cat > /etc/apache2/conf-available/app-runtime-user.conf <<EOF
# Usuario numérico (evita fallos si el nombre no está en passwd)
User #${APP_UID}
Group #${APP_GID}
EOF
    a2enconf app-runtime-user >/dev/null 2>&1 || true

    export APACHE_RUN_USER="#${APP_UID}"
    export APACHE_RUN_GROUP="#${APP_GID}"

    if ! getent group "${WEB_USER}" >/dev/null 2>&1; then
        groupadd -g "${APP_GID}" "${WEB_USER}" 2>/dev/null || groupadd "${WEB_USER}" 2>/dev/null || true
    fi
    if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
        useradd -u "${APP_UID}" -g "${WEB_USER}" -s /sbin/nologin "${WEB_USER}" 2>/dev/null \
            || useradd -g "${WEB_USER}" -s /sbin/nologin "${WEB_USER}" 2>/dev/null || true
    fi
    chown -R "${APP_UID}:${APP_GID}" storage bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
    chmod -R a+rwX storage/framework/views storage/framework/cache storage/framework/sessions storage/logs 2>/dev/null || true
    touch storage/logs/laravel.log storage/logs/editorial-agent.log 2>/dev/null || true
    chmod a+rw storage/logs/*.log 2>/dev/null || true
    chmod -R ug+rwX storage/app/public/featured-images 2>/dev/null || true
    chmod -R a+rwX storage/app/public/featured-images 2>/dev/null || true
    chmod -R a+rwX storage/app/public/social-videos storage/app/public/social-thumbnails 2>/dev/null || true
    chmod -R a+rwX storage/inertia-devtools 2>/dev/null || true
fi

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec apache2-foreground
