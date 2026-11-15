#!/usr/bin/env bash
# PUB-04 in a real OJS, after program.sh (which puts two accepted papers of
# dec-2027 into a session): the settings of the book of abstracts, the
# papers by sessions, abstracts and keywords read from OJS, and the PDF.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

jar="$(mktemp)"
pdf="$(mktemp)"
trap 'rm -f "$jar" "$pdf"' EXIT
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
eid="$(curl -s -b "$jar" "$api/editions?count=50" | python3 -c "import json,sys; print([e['id'] for e in json.load(sys.stdin)['items'] if e['path'] == 'dec-2027'][0])")"
A="$api/editions/$eid/abstract-book"
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3" "${@:4}"; }

overview="$(curl -s -b "$jar" "$A")"
python3 - "$overview" <<'PY' || fail "PUB-04 overview: $overview"
import json, sys
d = json.loads(sys.argv[1])
assert d['settings']['format'] == 'A5' and d['settings']['order'] == 'program', d
assert d['groups'][0]['title'] == 'Секція 1. Педагогіка' and d['groups'][0]['count'] == 2, d
PY
p1="$(curl -s -b "$jar" "$api/editions/$eid/program-builder" | python3 -c "import json,sys; print([s for s in json.load(sys.stdin)['sessions'] if s['paperItems']][0]['paperItems'][0]['submissionId'])")"
run set-abstract "$p1" en '' '' >/dev/null
run set-abstract "$p1" uk '' '' >/dev/null
python3 -c "import json,sys; d=json.loads(sys.argv[1]); assert $p1 in [p['submissionId'] for p in d['withoutAbstract']], d" "$(curl -s -b "$jar" "$A")" || fail "PUB-04 a paper without an abstract listed"
run set-abstract "$p1" en '<p style="color:red">Мета роботи — <b>перевірка</b> збірника.</p><script>x()</script>' 'збірник тез, перевірка' >/dev/null
after="$(curl -s -b "$jar" "$A")"
python3 -c "import json,sys; d=json.loads(sys.argv[1]); assert $p1 not in [p['submissionId'] for p in d['withoutAbstract']], d" "$after" || fail "PUB-04 the abstract from OJS: $after"
echo "OK PUB-04 papers by program sessions, papers without an abstract listed"

bad="$(send PUT "$A" '{"format":"B5"}' -o /dev/null -w '%{http_code}')"
[ "$bad" = 400 ] || fail "PUB-04 a bad format accepted: $bad"
saved="$(send PUT "$A" '{"format":"A5","order":"program","header":"Міністерство освіти і науки України","title":"Збірник тез","subtitle":"Матеріали конференції","place":"Київ","imprint":"Тези подано в авторській редакції.","authorIndex":true,"keywords":true}')"
python3 -c "import json,sys; d=json.loads(sys.argv[1]); assert d['settings']['title'] == 'Збірник тез', d" "$saved" || fail "PUB-04 settings: $saved"
code="$(curl -s -b "$jar" -o "$pdf" -w '%{http_code} %{content_type}' "$A/pdf")"
[ "$code" = "200 application/pdf" ] && [ "$(head -c 5 "$pdf")" = "%PDF-" ] || fail "PUB-04 PDF: $code"
if command -v pdftotext >/dev/null; then
  text="$(pdftotext -layout "$pdf" -)"
  for s in 'Збірник тез' 'Contents|Зміст' 'Секція 1. Педагогіка' 'перевірка збірника' 'збірник тез, перевірка' 'Author index|Покажчик авторів' 'МІНІСТЕРСТВО'; do
    grep -Eq "$s" <<<"$text" || fail "PUB-04 the PDF has no \"$s\": $(head -c 3000 <<<"$text")"
  done
  if grep -q 'x()' <<<"$text"; then fail "PUB-04 a script in the PDF"; fi
  pdfinfo "$pdf" | grep -q '419.53 x 595.28' || fail "PUB-04 not A5"
fi
[ "$(curl -s -o /dev/null -w '%{http_code}' "$A/pdf")" != 200 ] || fail "PUB-04 the book without signing in"
echo "OK PUB-04 settings saved, a bad format refused, the PDF in A5 with contents, abstracts and index"
