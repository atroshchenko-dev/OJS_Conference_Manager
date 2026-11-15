#!/usr/bin/env bash
# Demo site (plan, section 21): a throw-away OJS 3.5 with the plugin and
# six example journals, one per typical institution of the configuration
# matrix (tests/fixtures/segments/), each set up by the first setup wizard
# with an open call. Only example data: never run against a real site.
#
# Usage: ci/demo/start-demo.sh [mariadb|postgres] [ojs-image-tag]
# Nightly reset (cron of the demo server), for example:
#   15 3 * * *  cd /opt/conferenceManager && ci/demo/start-demo.sh >> /var/log/cm-demo.log 2>&1
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
ojs="${OJS_CONTAINER:-ojs}"
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }

"$root/ci/integration/start-ojs.sh" "${1:-mariadb}" "${2:-3_5_0-6}"
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"
work="$(mktemp -d)"
pkg="$("$root/ci/package.sh" "$work")"
docker cp "$pkg" "$ojs:/tmp/cm.tar.gz"
run install /tmp/cm.tar.gz
# The site overview (index/{locale}/conferenceSite) needs the plugin on at site level too.
run enable-site
DEMO=1 "$root/ci/integration/segments.sh"
rm -rf "$work"
echo "Demo site: http://localhost:${OJS_PORT:-8080}/ (admin / admin12345; journals s1–s5 and sgen)"
