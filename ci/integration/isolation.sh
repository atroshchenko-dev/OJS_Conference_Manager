#!/usr/bin/env bash
# DST-02: a manager of journal B gets 403/404 on every record of journal A,
# through B's API with A's ids, through A's API, and on the pages; lists of
# B show nothing of A. Run after api-smoke.sh (journal A = confa has data).
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
j() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }

ids="$(lc ids confa | tail -1)"
first() { j "(d['$1'] or [0])[0]" <<<"$ids"; }
E="$(first editions)"; P="$(first participations)"; I="$(first invoices)"; F="$(first proofs)"
C="$(first certificates)"; G="$(first campaigns)"; T="$(first batches)"; S="$(first submissions)"
for v in E P I F C S; do [ "${!v}" != 0 ] || fail "journal A has no $v records: $ids"; done

lc new-user confb mgrb mgrb@example.org manager >/dev/null || true
jar="$(mktemp)"
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=mgrb&password=reader12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confb/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "manager of journal B cannot log in"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/editions" -o /dev/null -w '%{http_code}')" = 200 ] || fail "manager of B reads B"

before="$(lc ids confa | tail -1)"
routes="
GET editions/{E}
PUT editions/{E}
DELETE editions/{E}
POST editions/{E}/copy
GET editions/{E}/participants
GET editions/{E}/participants/export
POST editions/{E}/listeners
GET editions/{E}/fees
POST editions/{E}/fees/categories
GET editions/{E}/fees/quote
GET editions/{E}/payment-rules
POST editions/{E}/payment-rules
GET editions/{E}/invoices
GET editions/{E}/payment-events
GET editions/{E}/dashboard
GET editions/{E}/decision-candidates
POST editions/{E}/decisions/bulk
GET editions/{E}/campaigns
POST editions/{E}/campaigns
POST editions/{E}/campaigns/preview
POST editions/{E}/campaigns/test
PUT editions/{E}/attendance
POST editions/{E}/attendance/import
GET editions/{E}/certificate-candidates
GET editions/{E}/certificates
POST editions/{E}/certificates
POST editions/{E}/certificates/batch
POST editions/{E}/reviewers
GET editions/{E}/vector-export
GET editions/{E}/program-builder
POST editions/{E}/rooms
DELETE editions/{E}/rooms/1
POST editions/{E}/sessions
PUT editions/{E}/sessions/1
POST editions/{E}/program-moves
POST participations/{P}/cancel
POST participations/{P}/invoice
PUT participations/{P}/category
GET invoices/{I}
GET invoices/{I}/pdf
POST invoices/{I}/paid
POST invoices/{I}/waive
POST invoices/{I}/cancel
POST invoices/{I}/reissue
GET proofs/{F}/file
POST proofs/{F}/accept
POST proofs/{F}/reject
POST certificates/{C}/revoke
POST certificates/{C}/reissue
GET campaigns/{G}
GET decisions/bulk/{T}
GET submissions/{S}/conference
GET submissions/{S}/registration
PUT submissions/{S}/registration
PUT submissions/{S}/presentation-type
"
n=0
while read -r m path; do
  [ -n "$m" ] || continue
  path="${path//\{E\}/$E}"; path="${path//\{P\}/$P}"; path="${path//\{I\}/$I}"; path="${path//\{F\}/$F}"
  path="${path//\{C\}/$C}"; path="${path//\{G\}/$G}"; path="${path//\{T\}/$T}"; path="${path//\{S\}/$S}"
  for base in "$B/confb/api/v1/conference" "$B/confa/api/v1/conference"; do
    code="$(curl -s -b "$jar" -X "$m" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$base/$path" -d '{"participationIds":['"$P"'],"submissionIds":['"$S"'],"reason":"x","amount":"1"}' -o /tmp/cm-iso.txt -w '%{http_code}')"
    [[ "$code" =~ ^40[134]$ ]] || fail "$m ${base#$B/} $path gave $code: $(head -c 300 /tmp/cm-iso.txt)"
    n=$((n + 1))
  done
done <<<"$routes"
[ "$(lc ids confa | tail -1)" = "$before" ] || fail "records of A changed"

for page in "invoice/$I" "certificate/$C" "receipt/$F"; do
  code="$(curl -s -L -b "$jar" "$B/confb/uk/conference/$page" -o /dev/null -w '%{http_code}')"
  [[ "$code" =~ ^40[34]$ ]] || fail "page $page of A in B gave $code"
done
apib="$B/confb/api/v1/conference"
[ "$(curl -s -b "$jar" "$apib/editions" | j "[e['id'] for e in d['items'] if e['id'] in $(j "d['editions']" <<<"$ids")]")" = "[]" ] || fail "editions of A in the list of B"
[ "$(curl -s -b "$jar" "$apib/proofs" | j "d['itemsMax']")" = 0 ] || fail "receipts of A in the queue of B"
[ "$(curl -s -b "$jar" "$apib/personal-data?email=admin@example.org" | j "d['participantIds']")" = "[]" ] || fail "people of A in B"
[ "$(curl -s -b "$jar" "$apib/audit-log?count=100" | j "[i['id'] for i in d['items'] if (i['after'] or {}).get('contextId', 2) != 2 or (i['entity'] == 'invoice' and i['entityId'] in $(j "d['invoices']" <<<"$ids"))]")" = "[]" ] || fail "audit log of A in B"
grep -q '2026-0000\|2027-0000' <<<"$(curl -s -b "$jar" "$apib/exports/accounting?format=csv")" && fail "invoices of A in the accounting export of B"
rm -f "$jar" /tmp/cm-iso.txt
echo "OK DST-02 isolation: $n requests of a manager of journal B to records of journal A refused, lists of B clean"
