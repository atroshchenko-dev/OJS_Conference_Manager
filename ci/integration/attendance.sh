#!/usr/bin/env bash
# ATT-01, ATT-02 per session and CRT-05, PRG-07 in a real OJS, after
# partners.sh: presence at a seminar and a section of dec-2027 through the
# API and a meeting report, the chair's page reached from the cabinet,
# a stranger refused, and a professional development certificate with the
# hours of the attended seminar only.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }

jar="$(mktemp)"; cj="$(mktemp)"; rj="$(mktemp)"; pdf="$(mktemp)"
trap 'rm -f "$jar" "$cj" "$rj" "$pdf"' EXIT
login() {
  local t
  t="$(curl -s -L -c "$1" -b "$1" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$1" -b "$1" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$t&username=$2&password=$3"
}
login "$jar" admin admin12345
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3"; }
eid="$(curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == 'dec-2027'][0])")"
A="$api/editions/$eid"

# A chair with an account and two listeners.
lc new-user confa chair1 chair1@example.org >/dev/null
read -r chair chairPn <<<"$(lc add-listener confa "$eid" chair1@example.org chair1 | tail -1)"
read -r _ l1 <<<"$(lc add-listener confa "$eid" att1@example.org | tail -1)"
read -r _ l2 <<<"$(lc add-listener confa "$eid" att2@example.org | tail -1)"
[ -n "$chairPn" ] && [ -n "$l1" ] && [ -n "$l2" ] || fail "participants"

sem="$(send POST "$A/sessions" "{\"type\":\"seminar\",\"title\":{\"uk\":\"Семінар «Онлайн-заняття»\"},\"startsAt\":\"2027-06-22 10:00\",\"endsAt\":\"2027-06-22 13:00\",\"hours\":3,\"chairs\":[$chair],\"learningOutcomes\":{\"uk\":\"Проводити онлайн-заняття\"}}" | py "print(d['id'])")" || fail "seminar"
sec="$(send POST "$A/sessions" '{"type":"section","title":{"uk":"Секція «Практика»"},"startsAt":"2027-06-22 14:00","endsAt":"2027-06-22 15:30"}' | py "print(d['id'])")" || fail "section"

sheet="$(curl -s -b "$jar" "$A/sessions/$sem/attendance")"
py "assert d['threshold'] == 135 and d['present'] == 0, d['threshold']; ids = [p['participationId'] for p in d['people']]; assert $l1 in ids and $chairPn in ids; assert d['people'][0]['participationId'] == $chairPn, d['people'][0]" <<<"$sheet" || fail "ATT-01 the sheet of a session: $(head -c 400 <<<"$sheet")"
r="$(send PUT "$A/sessions/$sem/attendance" "{\"participationIds\":[$l1],\"attended\":true}")"
py "assert d['changed'] == 1, d" <<<"$r" || fail "ATT-01 a mark: $r"
csv="$(printf 'Name,Email,Duration\nA,att1@example.org,10\nB,att2@example.org,150\nX,ghost@example.org,170\n' | base64 -w0)"
r="$(send POST "$A/sessions/$sem/attendance/import" "{\"csvBase64\":\"$csv\",\"threshold\":135,\"apply\":false}")"
py "assert d['present'] == 1 and d['unmatched'][0]['email'] == 'ghost@example.org' and [m for m in d['matched'] if m['email'] == 'att1@example.org'][0]['keptManual'], d" <<<"$r" || fail "ATT-02 preview: $r"
send POST "$A/sessions/$sem/attendance/import" "{\"csvBase64\":\"$csv\",\"threshold\":135,\"apply\":true}" >/dev/null
r="$(curl -s -b "$jar" "$A/sessions/$sem/attendance")"
py "p = {x['participationId']: x for x in d['people']}; assert d['present'] == 2 and p[$l1]['source'] == 'manual' and p[$l2]['minutes'] == 150, d['present']" <<<"$r" || fail "ATT-02 applied: $(head -c 400 <<<"$r")"
[ "$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$api/editions/$eid/sessions/999999/attendance")" = 404 ] || fail "unknown session"
echo "OK ATT-01/02 per session: marks by hand, a report with a threshold, hand marks kept"

# The chair: the cabinet links the session; the page saves only the changes.
login "$cj" chair1 reader12345
my="$(curl -s -L -c "$cj" -b "$cj" "$B/confa/uk/conference/my")"
grep -q "conference/sessionAttendance/$eid/$sem" <<<"$my" || fail "ATT-01 the session in the chair's cabinet: $(sed 's/<[^>]*>/ /g' <<<"$my" | tr -s ' \n' | head -c 400)"
page="$(curl -s -L -c "$cj" -b "$cj" "$B/confa/uk/conference/sessionAttendance/$eid/$sem")"
grep -q 'Семінар «Онлайн-заняття»' <<<"$page" && grep -q 'name="present\[\]"' <<<"$page" || fail "ATT-01 the chair's page: $(sed 's/<[^>]*>/ /g' <<<"$page" | tr -s ' \n' | head -c 400)"
grep -q 'js/sessionAttendance.js' <<<"$page" || fail "the filter script"
tok="$(sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' <<<"$page" | head -1)"
code="$(curl -s -c "$cj" -b "$cj" -o /dev/null -w '%{http_code}' "$B/confa/uk/conference/sessionAttendance/$eid/$sem" \
  --data "csrfToken=$tok&shown[]=$l1&shown[]=$l2&shown[]=$chairPn&present[]=$l2&present[]=$chairPn")"
[ "$code" = 302 ] || fail "ATT-01 the chair saves: $code"
r="$(curl -s -b "$jar" "$A/sessions/$sem/attendance")"
py "p = {x['participationId']: x for x in d['people']}; assert (p[$l1]['present'], p[$l2]['present'], p[$l2]['source'], p[$chairPn]['present']) == (False, True, 'import', True), [(k, v['present'], v['source']) for k, v in p.items()]" <<<"$r" || fail "ATT-01 only the changes: $(head -c 300 <<<"$r")"
[ "$(curl -s -L -c "$cj" -b "$cj" -o /dev/null -w '%{http_code}' "$B/confa/uk/conference/sessionAttendance/$eid/$sec")" = 404 ] || fail "a chair sees only own sessions"
login "$rj" reader1 reader12345
[ "$(curl -s -L -c "$rj" -b "$rj" -o /dev/null -w '%{http_code}' "$B/confa/uk/conference/sessionAttendance/$eid/$sem")" = 404 ] || fail "a stranger is refused"
[ "$(curl -s -b "$rj" -o /dev/null -w '%{http_code}' "$A/sessions/$sem/attendance")" != 200 ] || fail "a stranger has no API access"
echo "OK ATT-01 the chair marks presence from the cabinet; others are refused"

# CRT-05, PRG-07: hours of the seminar, not of the edition; a section alone gives a listener certificate.
send PUT "$A/sessions/$sec/attendance" "{\"participationIds\":[$l1],\"attended\":true}" >/dev/null
cc="$(curl -s -b "$jar" "$A/certificate-candidates")"
py "c = {x['participationId']: x for x in d['items']}; assert (c[$l2]['type'], c[$l2]['hours']) == ('pk', 3), c[$l2]; assert c[$l1]['type'] == 'listener', c[$l1]" <<<"$cc" || fail "CRT-05 candidates: $(py "print([(c['email'], c['type'], c['hours'], c['sessions']) for c in d['items'] if c['participationId'] in ($l1, $l2, $chairPn)])" <<<"$cc")"
r="$(send POST "$A/certificates" "{\"participationIds\":[$l2]}")"
cert="$(py "print([x['id'] for x in d['items'] if x['status'] == 'issued'][0])" <<<"$r")" || fail "CRT-03 issue: $r"
curl -s -L -b "$jar" -o "$pdf" "$B/confa/uk/conference/certificate/$cert"
text="$(pdftotext -layout "$pdf" - 2>/dev/null || true)"
grep -q 'Семінар «Онлайн-заняття»' <<<"$text" && grep -q '3 год' <<<"$text" && grep -q '0,1' <<<"$text" || fail "PRG-07 the seminar on the certificate: $(head -c 800 <<<"$text")"
echo "OK CRT-05/PRG-07 the certificate gives the hours and the title of the attended seminar"

for s in "$sem" "$sec"; do send DELETE "$A/sessions/$s" '' >/dev/null; done
echo "OK session attendance"
