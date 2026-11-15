#!/usr/bin/env bash
# REG-09, REG-10, FEE-15 in a real OJS, after api-smoke.sh (edition pay-2027
# with a listener tariff and a treasury rule): one invoice for two listeners
# paid by an organisation, a part and a full refund in the export for
# accounting, an invited speaker and an invited listener whose invoice is
# waived.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

jar="$(mktemp)"; pa="$(mktemp)"; pb="$(mktemp)"; pc="$(mktemp)"
trap 'rm -f "$jar" "$pa" "$pb" "$pc"' EXIT
signin() {
  local jarf="$1" tok
  tok="$(curl -s -L -c "$jarf" -b "$jarf" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$jarf" -b "$jarf" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=$2&password=$3"
}
signin "$jar" admin admin12345
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3" "${@:4}"; }
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }

eid="$(curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == 'pay-2027'][0])")"
other="$(curl -s -b "$jar" "$api/editions/$eid/participants?count=50" | py "print([p['categoryId'] for p in d['items'] if p['categoryId']][0])")"
regp="$B/confa/uk/conference/pay-2027/register"
register() {
  local jarf="$1" tok
  lc new-user confa "$2" "$2@example.org" >/dev/null
  signin "$jarf" "$2" reader12345
  tok="$(curl -s -L -c "$jarf" -b "$jarf" "$regp" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -L -c "$jarf" -b "$jarf" -o /dev/null "$regp" --data "csrfToken=$tok&categoryId=$other&participationForm=online&consent=1"
  curl -s -b "$jarf" "$api/editions/$eid/participants" -o /dev/null
}
register "$pa" group1; register "$pb" group2; register "$pc" guest1
pid() { curl -s -b "$jar" "$api/editions/$eid/participants?searchPhrase=$1" | py "print(d['items'][0]['participationId'])"; }
p1="$(pid group1)"; p2="$(pid group2)"; p3="$(pid guest1)"

# REG-09: one invoice for two listeners.
[ "$(send POST "$api/editions/$eid/group-invoices" "{\"participationIds\":[$p1],\"contactParticipationId\":$p1,\"organization\":\"Ліцей № 5\",\"legalId\":\"02070944\"}" -o /dev/null -w '%{http_code}')" = 400 ] || fail "REG-09 one participant"
r="$(send POST "$api/editions/$eid/group-invoices" "{\"participationIds\":[$p1,$p2],\"contactParticipationId\":$p1,\"organization\":\"Ліцей № 5\",\"legalId\":\"1\"}")"
py "assert 'legalId' in d, d" <<<"$r" || fail "REG-09 the legal id is checked: $r"
g="$(send POST "$api/editions/$eid/group-invoices" "{\"participationIds\":[$p1,$p2],\"contactParticipationId\":$p1,\"organization\":\"Ліцей № 5\",\"legalId\":\"02070944\"}")"
py "assert d['isGroup'] and d['total']['minor'] == 30000 and len(d['items']) == 2 and d['payer']['name'] == 'Ліцей № 5' and d['payer']['legalId'] == '02070944', d" <<<"$g" || fail "REG-09 group invoice: $g"
gid="$(py "print(d['id'])" <<<"$g")"
curl -s -b "$pa" "$api/me/invoices" | py "s=[(i['id'], i['status']) for i in d['items']]; assert s[0] == ($gid, 'issued') and s[1][1] == 'superseded', s" || fail "REG-09 the contact sees the group invoice"
curl -s -b "$pb" "$api/me/invoices" | py "assert [i['status'] for i in d['items']] == ['superseded'], d" || fail "REG-09 the member's own invoice is replaced"
r="$(send POST "$api/editions/$eid/group-invoices" "{\"participationIds\":[$p2,$p3],\"contactParticipationId\":$p3,\"organization\":\"Ліцей № 5\",\"legalId\":\"02070944\"}")"
py "assert [x['participationId'] for x in d['refused']] == [$p2], d" <<<"$r" || fail "REG-09 a member of another group refused: $r"
[ "$(send POST "$api/invoices/$gid/reissue" '{}' -o /dev/null -w '%{http_code}')" = 409 ] || fail "REG-09 a group invoice is not reissued"
curl -s -b "$jar" "$api/invoices/$gid/pdf" | pdftotext - - | grep -q "Ліцей № 5" || fail "REG-09 the PDF names the organisation"
echo "OK REG-09 one invoice for two listeners, the organisation checked, members' invoices replaced, the PDF"

# FEE-15: refunds.
[ "$(send POST "$api/invoices/$gid/refund-request" '{"note":"x"}' -o /dev/null -w '%{http_code}')" = 409 ] || fail "FEE-15 nothing paid yet"
send POST "$api/invoices/$gid/paid" '{}' | py "assert d['status'] == 'paid', d" || fail "FEE-15 paid"
send POST "$api/invoices/$gid/refund-request" '{"note":"Учасник group2 не приїде"}' | py "assert d['status'] == 'refund_requested', d" || fail "FEE-15 refund requested"
[ "$(send POST "$api/invoices/$gid/refund" '{"amount":"150"}' -o /dev/null -w '%{http_code}')" = 400 ] || fail "FEE-15 a refund needs a note"
send POST "$api/invoices/$gid/refund" '{"amount":"150","note":"п/д 15 від 10.06.2027"}' | py "assert d['status'] == 'paid' and d['refunded']['minor'] == 15000, d" || fail "FEE-15 part refund"
send POST "$api/invoices/$gid/refund" '{"note":"п/д 16 від 11.06.2027"}' | py "assert d['status'] == 'refunded' and d['refunded']['minor'] == 30000, d" || fail "FEE-15 the rest refunded"
curl -s -b "$jar" "$api/invoices/$gid" | py "t=[e['type'] for e in d['events']]; assert t[-3:] == ['refund_requested', 'part_refunded', 'refunded'], t" || fail "FEE-15 payment journal"
cols="$(curl -s -b "$jar" "$api/exports/accounting/columns" | py "print(json.dumps(d['selected']))")"
send PUT "$api/exports/accounting/columns" '{"columns":["number","status","total","refunded","refundNote"]}' -o /dev/null
csv="$(curl -s -b "$jar" "$api/exports/accounting?format=csv&editionId=$eid")"
grep -q "300.00.*300.00.*п/д 16 від 11.06.2027" <<<"$csv" || fail "FEE-15 refunds in the export: $csv"
send PUT "$api/exports/accounting/columns" "{\"columns\":$cols}" -o /dev/null
echo "OK FEE-15 a refund request, a part refund, the rest, the journal and the export for accounting"

# REG-10: invitations.
r="$(send POST "$api/editions/$eid/invitations" '{"email":"bad","givenName":"","role":"reviewer"}')"
py "assert set(d) == {'email', 'givenName', 'role'}, d" <<<"$r" || fail "REG-10 checks: $r"
r="$(send POST "$api/editions/$eid/invitations" '{"email":"keynote@example.org","givenName":"Ірина","familyName":"Мельник","affiliation":"Інститут","role":"presenter","note":"Пленарна доповідь"}')"
py "assert d['created'] and d['letter'], d" <<<"$r" || fail "REG-10 a keynote speaker invited: $r"
curl -s -b "$jar" "$api/editions/$eid/participants?searchPhrase=keynote" | py "p=d['items'][0]; assert p['invited'] and p['role'] == 'presenter' and p['name'] == 'Ірина Мельник', p" || fail "REG-10 in the register"
inv3="$(curl -s -b "$pc" "$api/me/invoices" | py "print(d['items'][0]['id'])")"
r="$(send POST "$api/editions/$eid/invitations" '{"email":"guest1@example.org","givenName":"Гість","role":"listener","sendLetter":false}')"
py "assert not d['created'] and d['participationId'] == $p3 and not d['letter'], d" <<<"$r" || fail "REG-10 a registered listener invited: $r"
curl -s -b "$pc" "$api/me/invoices" | py "s=[(i['status'], i['total']['minor']) for i in d['items']]; assert s[0] == ('waived', 0) and s[1][0] == 'superseded', s" || fail "REG-10 the invoice of an invited listener is waived"
[ "$(curl -s -b "$jar" -X POST -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$B/confb/api/v1/conference/editions/$eid/invitations" -d '{"email":"x@example.org","givenName":"X","role":"listener"}' -o /dev/null -w '%{http_code}')" != 200 ] || fail "REG-10 another journal"
[ "$(curl -s -b "$pa" -X POST -H 'Content-Type: application/json' "$api/editions/$eid/invitations" -d '{}' -o /dev/null -w '%{http_code}')" != 200 ] || fail "REG-10 a reader cannot invite"
echo "OK REG-10 an invited speaker in the register with a letter, an invited listener's invoice waived, access"
echo "OK groups, invitations, refunds"
