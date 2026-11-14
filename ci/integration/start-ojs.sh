#!/usr/bin/env bash
# Starts a throw-away OJS 3.5 with MariaDB or PostgreSQL and installs it.
# Usage: ci/integration/start-ojs.sh [mariadb|postgres] [ojs-image-tag]
# Result: container "ojs" on http://localhost:${OJS_PORT:-8080}, admin/admin12345.
set -euo pipefail
db="${1:-mariadb}"
tag="${2:-3_5_0-6}"
port="${OJS_PORT:-8080}"
net=cm-ojsnet
docker network create "$net" >/dev/null 2>&1 || true
docker rm -f ojs ojsdb >/dev/null 2>&1 || true

case "$db" in
  mariadb)
    docker run -d --name ojsdb --network "$net" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=ojs \
      -e MARIADB_USER=ojs -e MARIADB_PASSWORD=ojs mariadb:11.4 >/dev/null
    driver=mariadb
    ready() { docker exec ojsdb mariadb -uojs -pojs -e 'select 1' ojs >/dev/null 2>&1; } ;;
  postgres)
    docker run -d --name ojsdb --network "$net" -e POSTGRES_DB=ojs -e POSTGRES_USER=ojs \
      -e POSTGRES_PASSWORD=ojs postgres:16 >/dev/null
    driver=postgres9
    ready() { docker exec ojsdb pg_isready -U ojs -d ojs >/dev/null 2>&1; } ;;
  *) echo "unknown db $db" >&2; exit 2 ;;
esac
for _ in $(seq 1 60); do ready && break; sleep 2; done

docker run -d --name ojs --network "$net" -p "$port:80" pkpofficial/ojs:"$tag" >/dev/null
for _ in $(seq 1 60); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$port/index/en/install" || true)"
  [ "$code" = 200 ] && break; sleep 2
done
docker exec ojs sh -c 'mkdir -p /var/www/files && chown -R www-data:www-data /var/www/files'

curl -sf --max-time 600 "http://localhost:$port/index/en/install/install" -o /dev/null --data \
"installing=0&adminUsername=admin&adminPassword=admin12345&adminPassword2=admin12345&adminEmail=admin%40example.org\
&locale=uk&additionalLocales%5B%5D=en&additionalLocales%5B%5D=uk&timeZone=Europe%2FKyiv&filesDir=%2Fvar%2Fwww%2Ffiles\
&databaseDriver=$driver&databaseHost=ojsdb&databaseUsername=ojs&databasePassword=ojs&databaseName=ojs\
&oaiRepositoryId=localhost&enableBeacon=0"
docker exec ojs grep -q '^installed = On' config.inc.php || { echo "OJS install failed" >&2; exit 1; }
echo "OJS $tag on $db is up at http://localhost:$port"
