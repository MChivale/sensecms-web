#!/bin/sh
# Preflight for the existing ISPConfig installation. Fresh PostgreSQL installation
# is a separate workflow; site provisioning belongs to the ISPConfig master.
set -eu
test "${1:-}" = "--check" || { echo "Usage: sh deploy/install-production.sh --check" >&2; exit 2; }
test "$(id -u)" = 0
site=/var/www/clients/client9/web128
app=$site/private/sensecms
test "$(realpath /home/sensecms.com)" = "$site"
test "$(stat -c '%U:%G' "$site/web")" = web128:client9
test -f "$app/storage/installed.json"
test -S /var/lib/php8.5-fpm/web128.sock
apache2ctl configtest
for service in apache2 php8.5-fpm postgresql@17-main; do systemctl is-active --quiet "$service"; done
for sapi in cli fpm; do
    for driver in pdo_pgsql pdo_mysql; do phpquery -v 8.5 -s "$sapi" -m "$driver" >/dev/null; done
done
runuser -u web128 -- php8.5 -r '
    require $argv[1]."/bootstrap.php";
    $runtime=new App\Core\Runtime($argv[1]);
    $config=$runtime->read("installed")["database"];
    if (($config["driver"]??null)!=="pgsql" || $config["name"]!=="sensecms_site") exit(1);
    $db=App\Core\Runtime::connect($config);
    if (!$db->query("SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname=\x27vector\x27)")->fetchColumn()) exit(1);
' "$app"
echo "www: ISPConfig / Apache2 / PHP 8.5 / PostgreSQL with pgvector ready."
