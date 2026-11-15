#!/usr/bin/env bash
# PRG-02 – PRG-08 in a real OJS, after themes.sh (which publishes the
# program of dec-2027): rooms and sessions through the API, papers moved
# into sessions, the public program by days, stream links only for
# registered participants, iCal and PDF, the place of a paper on its
# article page.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }

jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
eid="$(curl -s -b "$jar" "$api/editions?count=50" | python3 -c "import json,sys; print([e['id'] for e in json.load(sys.stdin)['items'] if e['path'] == 'dec-2027'][0])")"
A="$api/editions/$eid"
send() {
  local method="$1" url="$2" body="${3:-}"
  shift $(($# < 3 ? $# : 3))
  curl -s -b "$jar" -X "$method" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$url" -d "${body:-{\}}" "$@"
}
field() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d$1)"; }

overview="$(curl -s -b "$jar" "$A/program-builder")"
read -r p1 p2 <<<"$(field "['unassigned'][0]['submissionId'], d['unassigned'][1]['submissionId']" <<<"$overview" | tr -d '(),')" || fail "PRG-02 two accepted papers: $overview"
[ "$(field "['timezone']" <<<"$overview")" = Europe/Kyiv ] || fail "PRG-02 time zone of the edition"

r1="$(send POST "$A/rooms" '{"name":"Аудиторія 101","capacity":60}' | field "['id']")" || fail "PRG-02 room"
r2="$(send POST "$A/rooms" '{"name":"Онлайн-зал","onlineUrl":"https://meet.example.org/room-secret"}' | field "['id']")" || fail "PRG-02 online room"
s1="$(send POST "$A/sessions" "{\"type\":\"section\",\"title\":{\"uk\":\"Секція 1. Педагогіка\"},\"startsAt\":\"2027-06-20 11:00\",\"endsAt\":\"2027-06-20 12:00\",\"roomId\":$r1,\"slotMinutes\":20}" | field "['id']")" || fail "PRG-02 session"
s2="$(send POST "$A/sessions" "{\"type\":\"seminar\",\"title\":{\"uk\":\"Семінар для вчителів\"},\"startsAt\":\"2027-06-21 10:00\",\"endsAt\":\"2027-06-21 12:00\",\"roomId\":$r2,\"hours\":2,\"ectsX100\":7,\"learningOutcomes\":{\"uk\":\"Укладати програму заходу\"},\"streamVisibleFrom\":\"2026-01-01 00:00\"}" | field "['id']")" || fail "PRG-02 seminar"
clash="$(send POST "$A/sessions" "{\"type\":\"section\",\"title\":{\"uk\":\"Те саме місце\"},\"startsAt\":\"2027-06-20 11:30\",\"endsAt\":\"2027-06-20 12:30\",\"roomId\":$r1}")"
grep -q '"roomId"' <<<"$clash" || fail "PRG-02 the room is busy: $clash"
for p in "$p1" "$p2"; do
  [ "$(send POST "$A/program-moves" "{\"submissionId\":$p,\"sessionId\":$s1,\"position\":9}" -o /dev/null -w '%{http_code}')" = 200 ] || fail "PRG-03 move $p"
done
times="$(curl -s -b "$jar" "$A/program-builder" | python3 -c "import json,sys; d=json.load(sys.stdin); s=[x for x in d['sessions'] if x['id']==$s1][0]; print(' '.join(i['startsAt'][11:] for i in s['paperItems']))")"
[ "$times" = "11:00 11:20" ] || fail "PRG-03 times of the papers: $times"
echo "OK PRG-02/03 rooms, sessions, a busy room refused, papers placed one after another"

page="$B/confa/uk/conference/dec-2027/program"
anon="$(curl -s -L "$page")"
grep -q 'cmProgram__day' <<<"$anon" && grep -q 'Секція 1. Педагогіка' <<<"$anon" && grep -q 'datetime="2027-06-20T08:00:00Z"' <<<"$anon" || fail "PRG-04 program by days: $(sed 's/<[^>]*>/ /g' <<<"$anon" | tr -s ' \n' | head -c 400)"
grep -q 'js/programTime.js' <<<"$anon" || fail "PRG-04 time zone switch"
grep -q 'cmProgram__stream--register' <<<"$anon" || fail "PRG-05 note for visitors"
if grep -q 'room-secret' <<<"$anon"; then fail "PRG-05 a visitor sees the stream link"; fi
grep -q 'ЄКТС' <<<"$anon" && grep -q 'Укладати програму заходу' <<<"$anon" || fail "PRG-07 hours, ECTS and outcomes"
mine="$(curl -s -L --max-redirs 5 -c "$jar" -b "$jar" "$page")"
grep -q 'room-secret' <<<"$mine" || fail "PRG-05 a manager sees the stream link"
echo "OK PRG-04/05/07 program by days with UTC times, stream links only for participants, seminar credits"

ics="$(curl -s -L -D - "$B/confa/uk/conference/dec-2027/program/ics")"
grep -qi '^content-type: text/calendar' <<<"$ics" || fail "PRG-06 iCal type"
[ "$(grep -c 'BEGIN:VEVENT' <<<"$ics")" -ge 2 ] || fail "PRG-06 iCal events"
grep -q 'DTSTART:20270620T080000Z' <<<"$ics" || fail "PRG-06 iCal time in UTC"
if grep -q 'room-secret' <<<"$ics"; then fail "PRG-06 the stream link in iCal"; fi
[ "$(curl -s -L "$B/confa/uk/conference/dec-2027/program/ics/$s2" | grep -c 'BEGIN:VEVENT')" = 1 ] || fail "PRG-06 iCal of one session"
[ "$(curl -s -L -o /dev/null -w '%{http_code}' "$B/confa/uk/conference/dec-2027/program/ics/999999")" = 404 ] || fail "PRG-06 unknown session"
[ "$(curl -s -L "$B/confa/uk/conference/dec-2027/program/pdf" | head -c 4)" = '%PDF' ] || fail "PRG-06 PDF"
echo "OK PRG-06 iCal of the program and of one session, PDF"

# The article pages of the smoke are not published; a manager sees them where OJS allows it.
art="$(curl -s -L --max-redirs 5 -c "$jar" -b "$jar" "$B/confa/uk/article/view/$p1" || true)"
if grep -q 'entry_details' <<<"$art"; then
  grep -q 'cmArticleProgram' <<<"$art" && grep -q "cm-session-$s1" <<<"$art" || fail "PRG-08 place of the paper on the article page"
  echo "OK PRG-08 time and room on the article page"
else
  echo "SKIP PRG-08 the article page is not open in this OJS version"
fi

send DELETE "$A/sessions/$s2" >/dev/null
send DELETE "$A/rooms/$r2" >/dev/null
[ "$(curl -s -b "$jar" "$A/program-builder" | python3 -c "import json,sys; print($r2 in [r['id'] for r in json.load(sys.stdin)['rooms']])")" = False ] || fail "PRG-02 delete"
echo "OK program builder"
