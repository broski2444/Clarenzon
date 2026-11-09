#!/bin/sh
set -e

# mod_php needs prefork; make sure no other MPM is enabled (avoids AH00534 on Railway).
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null

# Railway injects $PORT; make Apache listen on it.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# If a Railway volume is mounted on uploads/, it starts empty and root-owned.
# Restore the protective .htaccess files and folder layout without overwriting user files.
UPLOADS=/var/www/html/uploads
mkdir -p "$UPLOADS"
cp -an /opt/uploads-seed/. "$UPLOADS"/ 2>/dev/null || true
mkdir -p "$UPLOADS/sessions" "$UPLOADS/logs" "$UPLOADS/invoices" "$UPLOADS/product_images"
chown -R www-data:www-data "$UPLOADS"

exec apache2-foreground
