#!/bin/sh
set -e

# Generate .htpasswd for stats page basic auth
printf '%s:%s\n' "$STATS_USER" "$(busybox mkpasswd -m sha-256 "$STATS_PASSWORD")" > /etc/nginx/.htpasswd

exec nginx -g 'daemon off;'
