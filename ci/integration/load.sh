#!/usr/bin/env bash
# Plan, section 15.2 and DST-04 in a real OJS, after api-smoke.sh: the site
# page of the administrator, the load profile, and a mailing that waits
# for the submission deadline of its journal (quiet period).
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }
j() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
text() { sed 's/<[^>]*>/ /g' | tr -s ' \n'; }
jar="$(mktemp)"
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
site="$B/index/en/conferenceSite"

page="$(curl -s -b "$jar" "$site")"
grep -q 'cmSite__table' <<<"$page" || fail "DST-04 site page: $(text <<<"$page" | head -c 300)"
grep -q '<code>confa</code>' <<<"$page" && grep -q '<code>confb</code>' <<<"$page" || fail "DST-04 both journals"
grep -q '0010_load_indexes\|9999_upgrade_probe' <<<"$page" || fail "DST-04 schema version"
[ "$(curl -s "$site" -o /dev/null -w '%{http_code}')" = 302 ] || fail "anonymous on the site page"
[ "$(curl -s -b "$jar" "$B/confa/en/conferenceSite" -o /dev/null -w '%{http_code}')" = 404 ] || fail "the site page in a journal"
c="$(grep -o 'name="csrfToken" value="[^"]*"' <<<"$page" | head -1 | cut -d'"' -f4)"
grep -q 'whole number' <<<"$(curl -s -b "$jar" "$site" --data "csrfToken=$c&loadProfile=standard&mailsPerHour=x" | text)" || fail "15.2 validation"
saved="$(curl -s -b "$jar" "$site" --data "csrfToken=$c&loadProfile=shared_hosting&mailsPerHour=50" | text)"
grep -q 'Saved' <<<"$saved" && grep -q 'portions of 10, 50 letters an hour' <<<"$saved" || fail "15.2 save: $(head -c 400 <<<"$saved")"
grep -q 'job_runner_max_jobs = 5' <<<"$saved" || fail "15.2 advice for the OJS queue"
curl -s -b "$jar" "$site" --data "csrfToken=$c&loadProfile=standard&mailsPerHour=" -o /dev/null
ok() { echo "OK $*"; }
ok "DST-04 site page of the administrator (journals, schema, queue, tasks); 15.2 load profile saved with advice"

# Quiet period: a deadline of the journal tomorrow holds its mailings.
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
api="$B/confa/api/v1/conference"
put() { curl -s -b "$jar" -X PUT -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$1" -d "$2" -w '\n%{http_code}'; }
post() { curl -s -b "$jar" -X POST -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$1" -d "$2" -w '\n%{http_code}'; }
eds="$(curl -s -b "$jar" "$api/editions?count=50")"
oid="$(j '[e["id"] for e in d["items"] if e["path"] == "open-call"][0]' <<<"$eds")"
oe="$(curl -s -b "$jar" "$api/editions/$oid")"
tomorrow="$(date -u -d '+1 day' +%Y-%m-%d)"
r="$(put "$api/editions/$oid" "$(j 'json.dumps(dict(d, status="cfp_open", abstractDeadline="'"$tomorrow"' 12:00"))' <<<"$oe")")"
[ "$(tail -1 <<<"$r")" = 200 ] || fail "deadline tomorrow: $(head -c 300 <<<"$r")"
did=""
for e in $(j '" ".join(str(e["id"]) for e in d["items"])' <<<"$eds"); do
  n="$(post "$api/editions/$e/campaigns/preview" '{}' | head -1 | j 'd.get("count", 0)' 2>/dev/null || echo 0)"
  if [ "$n" -ge 1 ]; then did="$e"; break; fi
done
[ -n "$did" ] || fail "no edition with recipients"
cs="$(post "$api/editions/$did/campaigns" '{"subject":"Тиша","body":"Шановні {$recipientName}"}')"
[ "$(tail -1 <<<"$cs")" = 202 ] || fail "mailing: $cs"
cid="$(head -1 <<<"$cs" | j 'd["id"]')"
docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php lib/pkp/tools/jobs.php run >/dev/null 2>&1 || true
st="$(curl -s -b "$jar" "$api/campaigns/$cid")"
[ "$(j 'd["counts"]["sent"], d["finished"]' <<<"$st")" = "(0, False)" ] || fail "15.1 quiet period must hold the mailing: $st"
grep -q 'Plugin jobs in the queue: [1-9]' <<<"$(curl -s -b "$jar" "$site" | text)" || fail "the held job is in the queue"
rs="$(put "$api/editions/$oid" "$(j 'json.dumps(dict(d, abstractDeadline=d["local"]["abstractDeadline"].replace("T", " ")))' <<<"$oe")")"
[ "$(tail -1 <<<"$rs")" = 200 ] || fail "deadline restored: $(head -c 300 <<<"$rs")"
ok "15.1 quiet period: a mailing of the journal waits for its submission deadline (job held in the queue)"
rm -f "$jar"
