#!/bin/sh
set -eu
stage=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
case "$stage" in /root/sensecms-deploy.*) ;; *) exit 1;; esac
web=/home/sensecms.com/web
test "$(id -u)" = 0
test "$(realpath "$web")" = "$web"
test -f "$web/storage/installed.json"
test -f "$web/storage/theme.json"
test ! -e "$web/public/index.php"
# FPM reload returns before newly configured sockets become available.
attempt=0
until test -S /run/php/php8.5-sensecms.sock; do
    attempt=$((attempt + 1))
    test "$attempt" -lt 10
    sleep 1
done
install -m 644 "$stage/deploy/nginx/sensecms.com.conf" /etc/nginx/sites-available/sensecms.com
nginx -t
systemctl reload nginx
mkdir -p "$web/public/assets"
cp "$stage/.cms/source/public/assets/"* "$web/public/assets/"
chmod 755 "$web/public/assets"
find "$web/public/assets" -type f -exec chmod 644 {} +
# Publish last; the installer is already closed by the persisted installed state.
install -m 644 "$stage/.cms/source/public/index.php" "$web/public/index.php.new"
mv "$web/public/index.php.new" "$web/public/index.php"
echo 'Initialized Sense CMS front controller published.'
