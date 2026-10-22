#!/bin/bash
# Starts one part of the box. The first argument says which: app (web), worker (queue) or scheduler.
set -euo pipefail

role="${1:-app}"
cd /var/www/html

# Memory-backed working folders (the box mounts /run/vistud-tmp from memory). Nothing personal touches the disk here.
mkdir -p /run/vistud-tmp/app /run/vistud-tmp/nginx/body /run/vistud-tmp/nginx/fastcgi /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi
chmod 700 /run/vistud-tmp/app

# Folders the app writes to, inside the storage folder kept on the server's disk.
mkdir -p storage/app/private storage/app/derived storage/framework/cache storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

case "$role" in
  app)
    # The web address comes from the settings file (VISTUD_HOST).
    : "${VISTUD_HOST:?VISTUD_HOST is not set}"
    envsubst '${VISTUD_HOST}' < /etc/nginx/vistud-site.conf.template > /tmp/vistud-site.conf

    # PHP-FPM and nginx run side by side. If either stops, the box stops and Docker starts it again.
    php-fpm -F &
    fpm=$!
    nginx -g 'daemon off;' &
    web=$!
    trap 'kill -TERM "$fpm" "$web" 2>/dev/null; wait; exit 0' TERM INT
    wait -n
    echo "A web process stopped; restarting the box." >&2
    kill -TERM "$fpm" "$web" 2>/dev/null || true
    exit 1
    ;;
  worker)
    # Big PDFs and Office files are read here, so the worker may use more memory than the web side.
    exec php -d memory_limit=1024M artisan queue:work database --tries=3 --max-time=3600 --memory=768 --sleep=3
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    # Anything else (for example "php artisan migrate") runs as given.
    exec "$@"
    ;;
esac
