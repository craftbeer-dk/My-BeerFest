#!/bin/sh
set -e

# Generate .htpasswd for stats page basic auth (stats user + admin user both have access)
printf '%s:%s\n' "$STATS_USER" "$(busybox mkpasswd -m sha-256 "$STATS_PASSWORD")" > /etc/nginx/.htpasswd_stats
printf '%s:%s\n' "$ADMIN_USER" "$(busybox mkpasswd -m sha-256 "$ADMIN_PASSWORD")" >> /etc/nginx/.htpasswd_stats

# Generate .htpasswd for admin panel (admin user only)
printf '%s:%s\n' "$ADMIN_USER" "$(busybox mkpasswd -m sha-256 "$ADMIN_PASSWORD")" > /etc/nginx/.htpasswd_admin

exec nginx -g 'daemon off;'
