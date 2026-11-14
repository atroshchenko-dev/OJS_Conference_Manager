#!/usr/bin/env bash
# Development only: copy the working tree into a running OJS container.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
ojs="${OJS_CONTAINER:-ojs}"
work="$(mktemp -d)"
pkg="$("$root/ci/package.sh" "$work")"
docker cp "$pkg" "$ojs:/tmp/cm-dev.tar.gz"
docker exec "$ojs" sh -c 'cd /var/www/html/plugins/generic && tar -xzf /tmp/cm-dev.tar.gz && chown -R www-data:www-data conferenceManager && rm -rf /var/www/html/cache/t_compile/* /var/www/html/cache/fc-* /var/www/html/cache/*.php 2>/dev/null; true'
rm -rf "$work"
