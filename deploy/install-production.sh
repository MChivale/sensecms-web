#!/bin/sh
set -eu
umask 077
stage=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
case "$stage" in /root/sensecms-deploy.*) ;; *) echo 'Invalid deployment staging directory'; exit 1;; esac
web=/home/sensecms.com/web
test "$(id -u)" = 0
test "$(realpath "$web")" = "$web"
test ! -e "$web/public/index.php"
test ! -e "$web/storage/installed.json"
test ! -e /etc/php/8.5/fpm/pool.d/sensecms.conf
test ! -e /root/sensecms-private/owner.json
backup=/root/sensecms-backups/$(date -u +%Y%m%dT%H%M%SZ)-pre-application
mkdir -m 700 "$backup"
tar -czf "$backup/web.tar.gz" -C /home/sensecms.com web
cp /etc/nginx/sites-available/sensecms.com "$backup/nginx.conf"
printf '%s\n' "$backup" > "$stage/backup-path.txt"
if ! getent passwd sensecms >/dev/null; then useradd --system --user-group --home-dir /home/sensecms.com --shell /usr/sbin/nologin sensecms; fi
for dir in app config database scripts; do
    test ! -e "$web/$dir"
    cp -R "$stage/.cms/source/$dir" "$web/$dir"
done
cp "$stage/.cms/source/bootstrap.php" "$web/bootstrap.php"
php "$stage/deploy/provision-site.php" --provision-sensecms "$stage/.cfg/License.txt" "$stage/.themes/sensecms"
# Runtime data belongs exclusively to this application's FPM pool.
chown -R sensecms:sensecms "$web/storage"
find "$web/storage" -type d -exec chmod 700 {} +
find "$web/storage" -type f -exec chmod 600 {} +
for dir in app config database scripts; do
    chown -R root:root "$web/$dir"
    find "$web/$dir" -type d -exec chmod 755 {} +
    find "$web/$dir" -type f -exec chmod 644 {} +
done
chmod 644 "$web/bootstrap.php"
install -m 644 "$stage/.cms/source/.htaccess" "$web/.htaccess"
install -m 644 "$stage/deploy/php/sensecms.conf" /etc/php/8.5/fpm/pool.d/sensecms.conf
php-fpm8.5 -t
systemctl reload php8.5-fpm
sh "$stage/deploy/publish-site.sh"
printf 'Published initialized Core. Backup: %s\n' "$backup"
