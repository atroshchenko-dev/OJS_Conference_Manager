#!/usr/bin/env bash
# ATT-04 and PRG-09 in a real OJS, after attendance.sh (which creates
# chair1): the entry ticket in the cabinet, the scan by a session chair
# (own sessions only) and by a manager (the edition and any session), a
# stranger refused; sessions picked on the public program of dec-2027,
# the "only mine" view, the cabinet and the personal iCal.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
txt() { sed 's/<[^>]*>/ /g' <<<"$1" | tr -s ' \n' | head -c 500; }

jar="$(mktemp)"; cj="$(mktemp)"; oj="$(mktemp)"; rj="$(mktemp)"
trap 'rm -f "$jar" "$cj" "$oj" "$rj"' EXIT
login() {
  local t
  t="$(curl -s -L -c "$1" -b "$1" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$1" -b "$1" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$t&username=$2&password=$3"
}
token() { sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' <<<"$1" | head -1; }
code() { curl -s -c "$1" -b "$1" -o /dev/null -w '%{http_code}' "${@:2}"; }
login "$jar" admin admin12345
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3"; }
eid="$(curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == 'dec-2027'][0])")"
A="$api/editions/$eid"

# chair1 (from attendance.sh) chairs a seminar; a listener in person with an account.
read -r chair _ <<<"$(lc participant-id "$eid" chair1@example.org | tail -1)"
read -r _ pO <<<"$(lc participant-id "$eid" owner1@example.org | tail -1)"
if [ "$pO" = 0 ]; then
  lc new-user confa owner1 owner1@example.org >/dev/null
  read -r _ pO <<<"$(lc add-listener confa "$eid" owner1@example.org owner1 in_person | tail -1)"
fi
[ "${chair:-0}" != 0 ] && [ -n "$pO" ] || fail "participants: chair $chair, owner $pO"
ws="$(send POST "$A/sessions" "{\"type\":\"seminar\",\"title\":{\"uk\":\"Семінар «Квиток»\"},\"startsAt\":\"2027-06-23 10:00\",\"endsAt\":\"2027-06-23 11:30\",\"chairs\":[$chair]}" | py "print(d['id'])")" || fail "seminar"
sec="$(send POST "$A/sessions" '{"type":"section","title":{"uk":"Секція «Вибір»"},"startsAt":"2027-06-23 12:00","endsAt":"2027-06-23 13:30"}' | py "print(d['id'])")" || fail "section"

# The owner finds the ticket in the cabinet and opens it; a stranger cannot.
login "$oj" owner1 reader12345
my="$(curl -s -L -c "$oj" -b "$oj" "$B/confa/uk/conference/my")"
url="$({ grep -o "$B/confa/uk/conference/checkin/[0-9]*-[0-9a-f]*" <<<"$my" || true; } | head -1)"
[ -n "$url" ] && grep -q 'class="cmTicket"><img' <<<"$my" || fail "ATT-04 the ticket in the cabinet: $(txt "$my")"
[ "${url##*/checkin/}" != "${url##*/checkin/$pO-}" ] || fail "the ticket names the participation"
page="$(curl -s -L -c "$oj" -b "$oj" "$url")"
grep -q 'Це ваш вхідний квиток' <<<"$page" && ! grep -q 'name="sessionId"' <<<"$page" || fail "ATT-04 the owner's view: $(txt "$page")"
login "$rj" reader1 reader12345
[ "$(code "$rj" -L "$url")" = 404 ] || fail "a stranger is refused"
[ "$(code "$jar" -L "${url}0")" = 404 ] || fail "a forged code is refused"
echo "OK ATT-04 the ticket in the cabinet, refused to others"

# The chair: only the own workshop; no mark of the edition.
login "$cj" chair1 reader12345
page="$(curl -s -L -c "$cj" -b "$cj" "$url")"
grep -q "value=\"$ws\"" <<<"$page" && ! grep -q "value=\"$sec\"" <<<"$page" && grep -q 'лише на своїх сесіях' <<<"$page" || fail "ATT-04 the chair's choice: $(txt "$page")"
loc="$(curl -s -c "$cj" -b "$cj" -o /dev/null -w '%{redirect_url}' "$url" --data "csrfToken=$(token "$page")&sessionId=$sec")"
[[ "$loc" == *done=already* ]] || fail "a chair cannot mark another session: $loc"
loc="$(curl -s -c "$cj" -b "$cj" -o /dev/null -w '%{redirect_url}' "$url" --data "csrfToken=$(token "$page")&sessionId=$ws")"
[[ "$loc" == *done=yes* ]] || fail "ATT-04 the chair marks: $loc"
done="$(curl -s -L -c "$cj" -b "$cj" "$loc")"
grep -q 'Відмічено.' <<<"$done" && grep -q "value=\"$ws\" selected" <<<"$done" || fail "the result and the remembered session: $(txt "$done")"
r="$(curl -s -b "$jar" "$A/sessions/$ws/attendance")"
py "p = {x['participationId']: x for x in d['people']}; assert p[$pO]['present'] and p[$pO]['source'] == 'manual', p[$pO]" <<<"$r" || fail "the workshop sheet: $(head -c 300 <<<"$r")"
r="$(curl -s -b "$jar" "$A/sessions/$sec/attendance")"
py "p = {x['participationId']: x for x in d['people']}; assert not p[$pO]['present']" <<<"$r" || fail "the section stays unmarked"
echo "OK ATT-04 a chair marks presence at own sessions only"

# The manager: the edition, then again (already marked).
page="$(curl -s -L -c "$jar" -b "$jar" "$url")"
grep -q '<dd>ще не відмічено</dd>' <<<"$page" && grep -q "value=\"$sec\"" <<<"$page" || fail "ATT-04 the manager's view: $(txt "$page")"
loc="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' "$url" --data "csrfToken=$(token "$page")&sessionId=0")"
[[ "$loc" == *done=yes* ]] || fail "ATT-04 the manager marks the edition: $loc"
page="$(curl -s -L -c "$jar" -b "$jar" "$url")"
grep -q '<dd>відмічено</dd>' <<<"$page" || fail "the edition is marked: $(txt "$page")"
loc="$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' "$url" --data "csrfToken=$(token "$page")&sessionId=0")"
[[ "$loc" == *done=already* ]] || fail "a second scan: $loc"
[ "$(code "$jar" -o /dev/null "$url" --data "csrfToken=bad&sessionId=0")" = 200 ] || fail "a bad CSRF token marks nothing"
echo "OK ATT-04 a manager marks the edition; a second scan says already marked"

# PRG-09: the owner picks the section on the public program.
P="$B/confa/uk/conference/dec-2027/program"
page="$(curl -s -L -c "$oj" -b "$oj" "$P")"
grep -q 'До моєї програми' <<<"$page" || fail "PRG-09 the pick buttons: $(txt "$page")"
loc="$(curl -s -c "$oj" -b "$oj" -o /dev/null -w '%{redirect_url}' "$P" --data "csrfToken=$(token "$page")&sessionId=$sec&pick=1")"
[[ "$loc" == *"#cm-session-$sec" ]] || fail "PRG-09 the pick: $loc"
mine="$(curl -s -L -c "$oj" -b "$oj" "$P?mine=1")"
grep -q 'Секція «Вибір»' <<<"$mine" && ! grep -q 'Семінар «Квиток»' <<<"$mine" || fail "PRG-09 only mine: $(txt "$mine")"
ics="$(curl -s -L -c "$oj" -b "$oj" "$P/ics/mine")"
grep -q 'SUMMARY:Секція «Вибір»' <<<"$ics" && ! grep -q "Семінар «Квиток»" <<<"$ics" || fail "PRG-09 the personal iCal: $(head -c 400 <<<"$ics")"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$P/ics/mine")" != 200 ] || fail "the personal iCal needs a login"
my="$(curl -s -L -c "$oj" -b "$oj" "$B/confa/uk/conference/my")"
grep -q 'Моя програма' <<<"$my" && grep -q 'Секція «Вибір»' <<<"$my" || fail "PRG-09 the cabinet: $(txt "$my")"
# The chair's own workshop is in the chair's program without a pick.
mine="$(curl -s -L -c "$cj" -b "$cj" "$P?mine=1")"
grep -q 'Семінар «Квиток»' <<<"$mine" || fail "PRG-09 chaired sessions are mine: $(txt "$mine")"
page="$(curl -s -L -c "$oj" -b "$oj" "$P?mine=1")"
curl -s -c "$oj" -b "$oj" -o /dev/null "$P" --data "csrfToken=$(token "$page")&sessionId=$sec&pick=0&mine=1"
mine="$(curl -s -L -c "$oj" -b "$oj" "$P?mine=1")"
grep -q 'ще немає сесій' <<<"$mine" || fail "PRG-09 unpicked: $(txt "$mine")"
echo "OK PRG-09 sessions picked on the program, only mine, the cabinet and iCal"

for s in "$ws" "$sec"; do send DELETE "$A/sessions/$s" '' >/dev/null; done
echo "OK entry ticket and personal program"
