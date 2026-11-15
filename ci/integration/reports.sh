#!/usr/bin/env bash
# RPT-02, RPT-03, COM-04 in a real OJS, after groups.sh: reports of an
# edition by dimension in JSON and CSV, the scheduler status, invitation
# letters for the manager and for the participant, and who gets none.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }

jar="$(mktemp)"; pa="$(mktemp)"; pdf="$(mktemp)"
trap 'rm -f "$jar" "$pa" "$pdf"' EXIT
signin() {
  local jarf="$1" tok
  tok="$(curl -s -L -c "$jarf" -b "$jarf" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$jarf" -b "$jarf" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=$2&password=$3"
}
signin "$jar" admin admin12345
api="$B/confa/api/v1/conference"
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }
edition() { curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == '$1'][0])"; }
pay="$(edition pay-2027)"; dec="$(edition dec-2027)"

# RPT-02: reports.
curl -s -b "$jar" "$api/editions/$pay/dashboard" | py "assert len(d['reportDimensions']) == 7 and d['scheduler']['status'] in ('ok', 'stale', 'never'), d" || fail "RPT-02 the dashboard lists the reports"
curl -s -b "$jar" "$api/editions/$pay/reports/roles" | py "
t=d['totals']; assert [c['value'] for c in d['columns']] == ['active', 'attended', 'cancelled'], d
assert t['active'] == sum(r['counts']['active'] for r in d['rows']) and t['active'] >= 4, d" || fail "RPT-02 roles"
curl -s -b "$jar" "$api/editions/$pay/reports/countries" | py "assert any('(UA)' in r['label'] for r in d['rows']), d" || fail "RPT-02 countries by name"
curl -s -b "$jar" "$api/editions/$dec/reports/sections" | py "
t=d['totals']; assert t['submissions'] >= 2 and t['accepted'] >= 1 and t['submissions'] >= t['accepted'] + t['declined'], d" || fail "RPT-02 submissions by section"
curl -s -b "$jar" "$api/editions/$dec/reports/types" | py "assert d['totals']['submissions'] >= 2, d" || fail "RPT-02 submissions by type"
for dim in affiliations categories forms; do
  curl -s -b "$jar" "$api/editions/$pay/reports/$dim" | py "assert d['rows'], d" || fail "RPT-02 $dim"
done
h="$(curl -s -b "$jar" -D - -o "$pdf" "$api/editions/$pay/reports/categories?format=csv")"
grep -qi 'content-type: text/csv' <<<"$h" || fail "RPT-02 CSV type: $h"
[ "$(head -c 3 "$pdf" | od -An -tx1 | tr -d ' ')" = efbbbf ] || fail "RPT-02 CSV with BOM"
[ "$(grep -c '";"' "$pdf")" -ge 2 ] || fail "RPT-02 CSV rows"
[ "$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$api/editions/$pay/reports/bogus")" = 404 ] || fail "RPT-02 unknown dimension"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$api/editions/$pay/reports/roles")" != 200 ] || fail "RPT-02 needs sign-in"
echo "OK RPT-02 reports by role, country, section, type, institution, category and form, CSV"

# RPT-03: the scheduler status (run.sh ran the tasks).
curl -s -b "$jar" "$api/health" | py "s=d['scheduler']; assert s['status'] == 'ok' and s['lastRunAt'], d" || fail "RPT-03 the scheduler ran"
echo "OK RPT-03 the scheduler status"

# COM-04: invitation letters.
rows="$(curl -s -b "$jar" "$api/editions/$dec/participants?count=50")"
accepted="$(py "print([p['participationId'] for p in d['items'] if p['role'] == 'presenter' and p['letterAvailable']][0])" <<<"$rows")"
waiting="$(py "print([p['participationId'] for p in d['items'] if p['role'] == 'presenter' and not p['letterAvailable']][0])" <<<"$rows")"
text() { curl -s -b "$1" "$2" -o "$pdf"; pdftotext "$pdf" - 2>/dev/null || true; }
t="$(text "$jar" "$api/participations/$accepted/invitation-letter")"
grep -q "Лист-запрошення" <<<"$t" || fail "COM-04 the letter: $t"
grep -q "dec-2027/$accepted" <<<"$t" || fail "COM-04 the outgoing number"
grep -q "прийнято до програми" <<<"$t" || fail "COM-04 the accepted paper"
grep -q "Letter of invitation" <<<"$(text "$jar" "$api/participations/$accepted/invitation-letter?lang=en")" || fail "COM-04 in English"
[ "$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$api/participations/$waiting/invitation-letter")" = 404 ] || fail "COM-04 none before the paper is accepted"
listeners="$(curl -s -b "$jar" "$api/editions/$pay/participants?searchPhrase=group")"
own="$(py "print([p['participationId'] for p in d['items'] if p['email'] == 'group1@example.org'][0])" <<<"$listeners")"
other="$(py "print([p['participationId'] for p in d['items'] if p['email'] == 'group2@example.org'][0])" <<<"$listeners")"
signin "$pa" group1 reader12345
# OJS 3.5.0-5 redirects the first signed-in visit of a /uk/ page once for the locale.
my="$(curl -s -L -c "$pa" -b "$pa" "$B/confa/uk/conference/my")"
grep -q "invitationLetter/$own" <<<"$my" || fail "COM-04 the link on the participant's page"
grep -q "pay-2027/$own" <<<"$(text "$pa" "$B/confa/uk/conference/invitationLetter/$own")" || fail "COM-04 the participant downloads their letter"
[ "$(curl -s -b "$pa" -o /dev/null -w '%{http_code}' "$B/confa/uk/conference/invitationLetter/$other")" = 404 ] || fail "COM-04 not another person's letter"
code="$(curl -s -b "$pa" -o /dev/null -w '%{http_code}' "$api/participations/$own/invitation-letter")"
[ "$code" = 401 ] || [ "$code" = 403 ] || fail "COM-04 the manager route needs a manager"
echo "OK COM-04 letters for the manager and the participant, in Ukrainian and English, none before acceptance or for another person"
