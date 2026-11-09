#!/bin/sh
# Publish the official public entry points after the private Core has been deployed.
set -eu
umask 077
mode=${1:-}
case "$mode" in --check|--apply) ;; *) echo "Usage: sh deploy/publish-site.sh --check|--apply" >&2; exit 2;; esac
stage=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
case "$stage" in /root/sensecms-deploy.*) ;; *) echo "Expected private deployment staging directory." >&2; exit 1;; esac
sh "$stage/deploy/install-production.sh" --check
web=/var/www/clients/client9/web128/web
src=$stage/deploy/ispconfig
php8.5 -l "$src/public-index.php"
test -s "$src/public.htaccess"
for f in index.php .htaccess; do test -f "$web/$f" && test ! -L "$web/$f"; done
if [ "$mode" = --check ]; then exit 0; fi
mkdir -p -m 700 /root/sensecms-backups
backup=$(mktemp -d /root/sensecms-backups/apache-www.XXXXXXXX)
cp -p "$web/index.php" "$web/.htaccess" "$backup/"
# Stage outside DocumentRoot on the same filesystem and replace each file atomically.
tmp=$(mktemp -d /var/www/clients/client9/web128/private/.publish.XXXXXXXX)
trap 'rm -f "$tmp/index.php" "$tmp/.htaccess"; rmdir "$tmp"' EXIT
replace() {
    install -o web128 -g client9 -m 0644 "$1" "$tmp/$2" && mv -f "$tmp/$2" "$web/$2"
}
if ! { replace "$src/public.htaccess" .htaccess && replace "$src/public-index.php" index.php &&
       curl -fsS --max-time 15 https://www.sensecms.com/login >/dev/null; }; then
    replace "$backup/.htaccess" .htaccess
    replace "$backup/index.php" index.php
    echo "Publication failed; previous Apache entry points restored." >&2
    exit 1
fi
echo "www entry points published; backup $backup"
