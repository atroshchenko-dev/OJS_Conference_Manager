#!/usr/bin/env bash
# Moves the published papers of an OCS 2.3.6 copy into OJS 3.5:
# OCS (PHP 5.6) -> ocs-to-ojs export -> OJS 3.4 import -> OJS 3.5 upgrade,
# then compares papers and galleys edition by edition.
#
# Usage: tools/ocs-migration/migrate.sh <ocs-dump.sql[.gz]> [ocs-files-dir]
#
# Needs Docker. Only the first step (sources at pinned commits) uses the
# internet; every container runs on an internal Docker network without it,
# so the personal data in the dump stays on this machine.
#
# Environment (all optional):
#   WORK          work folder (default ./ocs-migration-work)
#   PREFIX        prefix of container and network names (default ocsmig)
#   YEAR_FROM     issue year from the edition's start (default) or end date
#   MARIADB_IMAGE, PHP56_IMAGE, OJS34_IMAGE, OJS35_IMAGE  Docker images
#
# Result: the OJS 3.5 container "$PREFIX-ojs35" with the migrated journals
# (database "ojs" in "$PREFIX-db", admin / admin12345), verify.txt with the
# comparison, and legacy/<conference>.tsv for "Old OCS addresses" in the plugin.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
dump="${1:?Usage: migrate.sh <ocs-dump.sql[.gz]> [ocs-files-dir]}"
files="${2:-}"
work="${WORK:-$PWD/ocs-migration-work}"
P="${PREFIX:-ocsmig}"
net="$P-net"
MARIADB_IMAGE="${MARIADB_IMAGE:-mariadb:11.4}"
PHP56_IMAGE="${PHP56_IMAGE:-php:5.6-cli}"
OJS34_IMAGE="${OJS34_IMAGE:-pkpofficial/ojs:3_4_0-9}"
OJS35_IMAGE="${OJS35_IMAGE:-pkpofficial/ojs:3_5_0-6}"
OCS_COMMIT=b24cc4c0960337a01af9e4ed78d5c529c959afc1        # pkp/ocs ocs-stable-2_3_6
PKPLIB_COMMIT=a1fb264a21c8f0cdd7427773b254f9a2865b5ede     # pkp/pkp-lib used by it
TOOL_COMMIT=94b421e5178e3e3294a7104d31eae2077c7154ea       # jonasraoni/ocs-to-ojs
DBPW=migration
say() { printf '\n== %s\n' "$*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
[ -f "$dump" ] || fail "no dump $dump"
[ -z "$files" ] || [ -d "$files" ] || fail "no files folder $files"
mkdir -p "$work"
work="$(cd "$work" && pwd)"

fetch() { # <dir> <repo> <commit>
  [ -d "$1/.git" ] && return 0
  git init -q "$1" && git -C "$1" remote add origin "$2"
  git -C "$1" fetch -q --depth 1 origin "$3" && git -C "$1" checkout -q FETCH_HEAD
}
say "1. Sources at pinned commits (internet)"
fetch "$work/src/ocs" https://github.com/pkp/ocs.git "$OCS_COMMIT"
rm -rf "$work/src/ocs/lib/pkp"
fetch "$work/src/ocs/lib/pkp" https://github.com/pkp/pkp-lib.git "$PKPLIB_COMMIT"
fetch "$work/src/ocs-to-ojs" https://github.com/jonasraoni/ocs-to-ojs.git "$TOOL_COMMIT"
for i in "$MARIADB_IMAGE" "$PHP56_IMAGE" "$OJS34_IMAGE" "$OJS35_IMAGE"; do
  docker image inspect "$i" >/dev/null 2>&1 || docker pull -q "$i" >/dev/null
done

say "2. Database with the OCS copy (no internet from here on)"
docker rm -f "$P-db" "$P-php56" "$P-ojs34" "$P-ojs35" >/dev/null 2>&1 || true
docker network rm "$net" >/dev/null 2>&1 || true
docker network create --internal "$net" >/dev/null
docker run -d --name "$P-db" --network "$net" -e MARIADB_ROOT_PASSWORD="$DBPW" "$MARIADB_IMAGE" >/dev/null
for _ in $(seq 1 60); do docker exec "$P-db" mariadb -uroot -p"$DBPW" -e 'select 1' >/dev/null 2>&1 && break; sleep 2; done
docker exec "$P-db" mariadb -uroot -p"$DBPW" -e "CREATE DATABASE ocs CHARACTER SET utf8mb4; CREATE DATABASE ojs CHARACTER SET utf8mb4;"
case "$dump" in *.gz) gzip -dc "$dump" ;; *) cat "$dump" ;; esac | docker exec -i "$P-db" mariadb -uroot -p"$DBPW" ocs
docker exec "$P-db" mariadb -uroot -p"$DBPW" ocs -N -e "SELECT CONCAT('OCS ', major, '.', minor, '.', revision, ', published papers: ', (SELECT COUNT(*) FROM papers WHERE status = 3)) FROM versions WHERE product = 'ocs2' AND current = 1"

say "3. Export from OCS with ocs-to-ojs (PHP 5.6)"
vol=()
[ -n "$files" ] && vol=(-v "$(cd "$files" && pwd):/var/ocsfiles:ro")
docker run -d --name "$P-php56" --network "$net" "${vol[@]}" "$PHP56_IMAGE" sleep infinity >/dev/null
docker exec "$P-php56" sh -c 'php -m | grep -qx mysql || docker-php-ext-install mysql >/tmp/ext.log 2>&1' || fail "PHP mysql extension"
docker cp "$work/src/ocs" "$P-php56:/var/ocs"
docker cp "$work/src/ocs-to-ojs" "$P-php56:/var/ocs-to-ojs"
docker exec "$P-php56" sh -c "mkdir -p /var/ocsfiles; cd /var/ocs && sed -e 's/^installed = Off/installed = On/' -e 's/^host = localhost/host = $P-db/' \
  -e 's/^username = ocs/username = root/' -e 's/^password = ocs/password = $DBPW/' -e 's#^files_dir = .*#files_dir = /var/ocsfiles#' \
  -e 's/^connection_charset = Off/connection_charset = utf8/' -e 's/^database_charset = Off/database_charset = utf8/' config.TEMPLATE.inc.php > config.inc.php"
docker exec -w /var/ocs-to-ojs "$P-php56" php -d date.timezone=UTC export.php -i /var/ocs -o /var/ocsexport -t stable-3_4_0 > "$work/export.log" 2>&1 || true
grep -q 'Export finished with success' "$work/export.log" || fail "export, see $work/export.log"
grep -E '^(Exported|Failed) papers' "$work/export.log"
rm -rf "$work/export" && docker cp "$P-php56:/var/ocsexport" "$work/export"
docker rm -f "$P-php56" >/dev/null

say "4. Import into OJS 3.4"
docker run -d --name "$P-ojs34" --network "$net" "$OJS34_IMAGE" >/dev/null
for _ in $(seq 1 60); do docker exec "$P-ojs34" curl -s -o /dev/null http://localhost/ && break; sleep 2; done
docker exec "$P-ojs34" sh -c "mkdir -p /var/www/files && chown -R apache:apache /var/www/files; J=/tmp/j; curl -s -L -c \$J -b \$J http://localhost/index/install >/dev/null; \
  curl -s -c \$J -b \$J --max-time 900 -o /dev/null http://localhost/index/install/install --data 'installing=0&adminUsername=admin&adminPassword=admin12345&adminPassword2=admin12345&adminEmail=admin%40example.org&locale=en&additionalLocales%5B%5D=en&additionalLocales%5B%5D=uk&timeZone=UTC&filesDir=%2Fvar%2Fwww%2Ffiles&databaseDriver=mysqli&databaseHost=$P-db&databaseUsername=root&databasePassword=$DBPW&databaseName=ojs&oaiRepositoryId=localhost&enableBeacon=0'; \
  grep -q '^installed = On' config.inc.php && sed -i 's/^display_errors = Off/display_errors = On/' config.inc.php" || fail "OJS 3.4 install"
docker cp "$work/src/ocs-to-ojs" "$P-ojs34:/var/ocs-to-ojs"
docker cp "$here/patch-import.php" "$P-ojs34:/tmp/patch-import.php"
docker cp "$here/fix-metadata.php" "$P-ojs34:/tmp/fix-metadata.php"
docker cp "$work/export" "$P-ojs34:/var/ocsexport"
docker exec "$P-ojs34" php /tmp/patch-import.php /var/ocs-to-ojs/import.php
docker exec "$P-ojs34" php /tmp/fix-metadata.php /var/ocsexport/metadata.json "${YEAR_FROM:-start}" | tail -1
docker exec -w /var/ocs-to-ojs "$P-ojs34" sh -c 'chown -R apache:apache /var/ocsexport; php import.php -i /var/ocsexport -o /var/www/html -u admin -a admin -f 5 </dev/null' > "$work/import.log" 2>&1 || true
grep -E '^(Processed|Imported|Skipped|Failed) papers' "$work/import.log" || fail "import, see $work/import.log"
grep -q '^Failed papers: 0' "$work/import.log" || fail "some papers failed, see $work/import.log"
docker cp "$P-ojs34:/var/ocsexport/metadata.json" "$work/metadata.json"
rm -rf "$work/files" && docker cp "$P-ojs34:/var/www/files" "$work/files"
docker rm -f "$P-ojs34" >/dev/null

say "5. Upgrade to OJS 3.5"
docker run -d --name "$P-ojs35" --network "$net" "$OJS35_IMAGE" >/dev/null
for _ in $(seq 1 60); do docker exec "$P-ojs35" test -f config.TEMPLATE.inc.php && break; sleep 2; done
docker exec -u 0 "$P-ojs35" sh -c 'mkdir -p /var/www/files && find /var/www/files -mindepth 1 -delete'
docker cp "$work/files/." "$P-ojs35:/var/www/files/"
docker exec -u 0 "$P-ojs35" sh -c "sed -e 's/^installed = Off/installed = On/' -e 's/^host = localhost/host = $P-db/' -e 's/^username = ojs/username = root/' \
  -e 's/^password = ojs/password = $DBPW/' -e 's#^files_dir = files#files_dir = /var/www/files#' config.TEMPLATE.inc.php > config.inc.php \
  && php lib/pkp/tools/appKey.php generate >/dev/null && php -d memory_limit=-1 tools/upgrade.php upgrade > /tmp/upgrade.log 2>&1; \
  chown -R www-data:www-data /var/www/files /var/www/html/config.inc.php; grep -q 'Successfully upgraded' /tmp/upgrade.log" || fail "upgrade, see /tmp/upgrade.log in $P-ojs35"
docker exec "$P-ojs35" sh -c "grep 'Successfully upgraded' /tmp/upgrade.log"

say "6. Comparison by edition"
docker cp "$here/verify.php" "$P-ojs35:/tmp/verify.php"
docker cp "$work/metadata.json" "$P-ojs35:/tmp/metadata.json"
set +e
docker exec "$P-ojs35" php /tmp/verify.php /tmp/metadata.json "$P-db" root "$DBPW" ocs ojs /var/www/files | tee "$work/verify.txt"
ok=${PIPESTATUS[0]}
set -e

say "7. Lists of papers for the old addresses"
mkdir -p "$work/legacy"
for c in $(docker exec "$P-db" mariadb -uroot -p"$DBPW" ocs -N -e 'SELECT path FROM conferences'); do
  docker exec "$P-db" mariadb -uroot -p"$DBPW" ocs --batch -e "SELECT p.paper_id AS ocs_paper_id, c.path AS conference, sc.path AS sched_conf,
      (SELECT ps.setting_value FROM paper_settings ps WHERE ps.paper_id = p.paper_id AND ps.setting_name = 'title'
        ORDER BY ps.locale = 'uk_UA' DESC, ps.locale LIMIT 1) AS title
    FROM published_papers pp JOIN papers p ON p.paper_id = pp.paper_id
    JOIN sched_confs sc ON sc.sched_conf_id = p.sched_conf_id JOIN conferences c ON c.conference_id = sc.conference_id
    WHERE p.status = 3 AND c.path = '$c' ORDER BY p.paper_id" > "$work/legacy/$c.tsv"
done
ls "$work/legacy"
[ "$ok" = 0 ] || fail "the comparison found differences, see $work/verify.txt"
echo "Done. OJS 3.5 with the migrated journals: container $P-ojs35, database ojs in $P-db."
