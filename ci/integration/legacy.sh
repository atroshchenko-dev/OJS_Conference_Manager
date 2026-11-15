#!/usr/bin/env bash
# LEG-02, LEG-03 in a real OJS, after api-smoke.sh: a list of OCS papers
# matched with published articles of journal confa, old addresses checked,
# the nginx and Apache maps, and the redirect of the plugin itself when the
# journal path is the first part of the old address.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3"; }

# Two published articles of confa stand for papers migrated from OCS.
read -r a1 a2 <<<"$(curl -s -b "$jar" "$B/confa/api/v1/submissions?count=50" | python3 -c "import json,sys; ids=sorted(i['id'] for i in json.load(sys.stdin)['items']); print(ids[0], ids[1])")"
run publish-article "$a1" 2025-06-01 'Освіта і наука в умовах змін' >/dev/null
run publish-article "$a2" 2025-06-01 'Права людини' >/dev/null

csv=$'ocs_paper_id,conference,sched_conf,title,submission_id\n101,confa,confa-2025,"Освіта і наука в умовах змін",\n102,confa,confa-2025,Права людини,\n103,confa,confa-2025,Немає такої статті,\nabc,confa,confa-2025,x,'
report="$(send POST "$api/legacy-urls/import" "$(python3 -c 'import json,sys; print(json.dumps({"csv": sys.argv[1]}))' "$csv")")"
python3 - "$report" <<'PY' || fail "LEG-02 import: $report"
import json, sys
r = json.loads(sys.argv[1])
assert (r['rows'], r['matched']) == (3, 2), r
assert [u['ocsPaperId'] for u in r['unmatched']] == [103], r
assert r['errors'][0]['line'] == 5 and r['errors'][0]['text'], r
PY
check="$(curl -s -b "$jar" "$api/legacy-urls/check?url=https%3A%2F%2Fconf.example.edu%2Findex.php%2Fconfa%2Fconfa-2025%2Fpaper%2Fview%2F101")"
[ "$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["target"].rsplit("/", 1)[1])' "$check")" = "$a1" ] || fail "LEG-02 check: $check"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" "$api/legacy-urls/check?url=%2Fconfa%2Farticle%2Fview%2F1")" = 404 ] || fail "LEG-02 an OJS address is not OCS"
echo "OK LEG-02 OCS papers matched by title, unmatched and bad rows reported, an address checked"

nginx="$(curl -s -b "$jar" "$api/legacy-urls/map?server=nginx")"
grep -q "\"confa/confa-2025/102\" \".*article/view/$a2\";" <<<"$nginx" && grep -q 'return 301 $cm_legacy_target;' <<<"$nginx" || fail "LEG-02 nginx map: $nginx"
if grep -q '/103"' <<<"$nginx"; then fail "LEG-02 an unmatched paper in the map"; fi
amap="$(curl -s -b "$jar" "$api/legacy-urls/map?server=apache-map")"
grep -q "^confa/confa-2025/101 .*article/view/$a1\$" <<<"$amap" || fail "LEG-02 Apache map: $amap"
grep -q 'RewriteRule .*cmlegacy:\$1/\$2/\$3' <<<"$(curl -s -b "$jar" "$api/legacy-urls/map?server=apache-rules")" || fail "LEG-02 Apache rules"
# nginx itself accepts the generated file.
if docker image inspect nginx:alpine >/dev/null 2>&1 || docker pull -q nginx:alpine >/dev/null 2>&1; then
  conf="$(mktemp)"
  python3 - "$nginx" > "$conf" <<'PY'
import sys
text = sys.argv[1]
maps, locs = text.split('# 2.', 1)
print('events {}\nhttp {\n' + maps + '\nserver {\nlisten 8081;\nroot /usr/share/nginx/html;\n' + locs.split('\n', 1)[1] + '\n}\n}')
PY
  docker run --rm --entrypoint nginx -v "$conf:/etc/nginx/nginx.conf:ro" nginx:alpine -t -q || fail "LEG-02 nginx rejects the map"
  rm -f "$conf"
  echo "OK LEG-02 nginx -t accepts the map"
fi
# Apache accepts the rules with the map.
if docker image inspect httpd:2.4-alpine >/dev/null 2>&1 || docker pull -q httpd:2.4-alpine >/dev/null 2>&1; then
  dir="$(mktemp -d)"
  curl -s -b "$jar" "$api/legacy-urls/map?server=apache-map" > "$dir/map.txt"
  curl -s -b "$jar" "$api/legacy-urls/map?server=apache-rules" | sed 's#txt:[^"]*#txt:/cm/map.txt#' > "$dir/rules.conf"
  printf 'ServerName localhost\nListen 8082\nLoadModule mpm_event_module modules/mod_mpm_event.so\nLoadModule authz_core_module modules/mod_authz_core.so\nLoadModule unixd_module modules/mod_unixd.so\nLoadModule rewrite_module modules/mod_rewrite.so\n<VirtualHost *:8082>\nInclude /cm/rules.conf\n</VirtualHost>\n' > "$dir/httpd.conf"
  docker run --rm -v "$dir:/cm:ro" httpd:2.4-alpine httpd -t -f /cm/httpd.conf 2>&1 | grep -q 'Syntax OK' || fail "LEG-02 Apache rejects the rules: $(docker run --rm -v "$dir:/cm:ro" httpd:2.4-alpine httpd -t -f /cm/httpd.conf 2>&1)"
  rm -rf "$dir"
  echo "OK LEG-02 httpd -t accepts the rules"
fi
echo "OK LEG-02 maps for nginx and Apache"

loc="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/confa/confa-2025/paper/view/101")"
[[ "$loc" == "301 "*"article/view/$a1" ]] || fail "LEG-03 redirect of the plugin: $loc"
loc="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/index.php/confa/confa-2025/paper/download/102/77")"
[[ "$loc" == "301 "*"article/view/$a2" ]] || fail "LEG-03 redirect with index.php and a file: $loc"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/confa/confa-2025/paper/view/103")" != 301 ] || fail "LEG-03 an unmatched paper is not redirected"
echo "OK LEG-03 the plugin redirects old addresses with 301"

[ "$(send DELETE "$api/legacy-urls" '{}' | python3 -c 'import json,sys; print(json.load(sys.stdin)["deleted"])')" = 3 ] || fail "LEG-02 clear"
echo "OK legacy addresses"
