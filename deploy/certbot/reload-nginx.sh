#!/bin/sh
set -eu
[ "${RENEWED_LINEAGE:-}" = /etc/letsencrypt/live/sensecms.com ] || exit 0
/usr/sbin/nginx -t
/usr/bin/systemctl reload nginx
