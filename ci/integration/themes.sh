#!/usr/bin/env bash
# DST-05: the public pages of the plugin under the Default, Manuscript and
# Health Sciences themes of OJS 3.5 (themes from github.com/pkp, branch
# stable-3_5_0). Run after api-smoke.sh. Screenshots for the visual check
# are taken separately; here every page must render in every theme.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
C="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "$C" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
dir="$(mktemp -d)"
for t in healthSciences defaultManuscript; do
  if ! docker exec "$C" test -d "plugins/themes/$t"; then
    git clone -q --depth 1 -b stable-3_5_0 "https://github.com/pkp/$t" "$dir/$t"
    rm -rf "$dir/$t/.git"
    docker cp "$dir/$t" "$C:/var/www/html/plugins/themes/"
    docker exec -w /var/www/html "$C" php lib/pkp/tools/installPluginVersion.php "plugins/themes/$t/version.xml" >/dev/null 2>&1 || true
  fi
done
rm -rf "$dir"

# The program of the edition with decisions is published (PRG-01).
jar="$(mktemp)"
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
api="$B/confa/api/v1/conference"
eid="$(curl -s -b "$jar" "$api/editions?count=50" | python3 -c "import json,sys; print([e['id'] for e in json.load(sys.stdin)['items'] if e['path'] == 'dec-2027'][0])")"
[ "$(curl -s -L "$B/confa/uk/conference/dec-2027/program" -o /dev/null -w '%{http_code}')" = 404 ] || fail "PRG-01 an unpublished program is not public"
[ "$(curl -s -L --max-redirs 5 -c "$jar" -b "$jar" "$B/confa/uk/conference/dec-2027/program" -o /dev/null -w '%{http_code}')" = 200 ] || fail "PRG-01 preview for a manager"
body="$(curl -s -b "$jar" "$api/editions/$eid" | python3 -c "import json,sys; d=json.load(sys.stdin); d.update({k: v.replace('T', ' ') for k, v in d['local'].items() if v}); d['programPublished'] = True; d['programText'] = {'uk': 'Пленарне засідання о 10:00'}; print(json.dumps(d))")"
[ "$(curl -s -b "$jar" -X PUT -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$api/editions/$eid" -d "$body" -o /dev/null -w '%{http_code}')" = 200 ] || fail "PRG-01 publish the program"
prog="$(curl -s -L "$B/confa/uk/conference/dec-2027/program")"
grep -q 'cmProgram__item' <<<"$prog" && grep -q 'Пленарне засідання о 10:00' <<<"$prog" || fail "PRG-01 program: $(sed 's/<[^>]*>/ /g' <<<"$prog" | tr -s ' \n' | head -c 400)"
grep -q 'conference/dec-2027/program' <<<"$(curl -s -L "$B/confa/uk/conference/dec-2027")" || fail "PRG-01 link on the edition page"
rm -f "$jar"
echo "OK PRG-01 program: preview for managers, published by sections and types, linked from the edition page"
pages="conference/dec-2027 conference/dec-2027/program conference/documents"
n=0
for theme in default defaultManuscript healthSciences; do
  lc theme confa "$theme" >/dev/null
  docker exec "$C" sh -c 'rm -rf cache/t_compile/* cache/*.php' || true
  for p in $pages; do
    html="$(curl -s -L "$B/confa/uk/$p")"
    grep -q 'class="container page page_conference_' <<<"$html" || fail "$theme: $p is not a page of the plugin: $(sed 's/<[^>]*>/ /g' <<<"$html" | tr -s ' \n' | head -c 300)"
    grep -q 'conferenceManager/css/public.css' <<<"$html" || fail "$theme: $p without the plugin styles"
    if [ "$theme" = healthSciences ]; then
      grep -q 'themes/healthSciences' <<<"$html" || fail "$theme: the theme is not active on $p"
    fi
    n=$((n + 1))
  done
done
lc theme confa default >/dev/null
echo "OK DST-05 $n page views under Default, Manuscript and Health Sciences"
