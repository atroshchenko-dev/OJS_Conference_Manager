#!/usr/bin/env bash
# HTTP checks against a running OJS after ci/integration/run.sh:
# login, REST API (read, validation, save, export), access control,
# settings modal of the plugin grid. Exits non-zero on the first failure.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
jar="$(mktemp)"
fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "OK $*"; }
j() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }

tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "login (no CSRF token on the management page)"
ok login

api="$B/confa/api/v1/conference"
[ "$(curl -s "$api/institution" -o /dev/null -w '%{http_code}')" = 401 ] || fail "anonymous must get 401"
[ "$(curl -s -b "$jar" -X PUT -H 'Content-Type: application/json' "$api/institution" -d '{}' -o /dev/null -w '%{http_code}')" = 403 ] || fail "PUT without CSRF must get 403"
ok "access control"

[ "$(curl -s -b "$jar" "$api/institution" | j 'd["jurisdiction"]')" = UA ] || fail "uk context defaults to UA"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/institution" | j 'd["jurisdiction"]')" = generic ] || fail "en context defaults to generic"
[ "$(curl -s -b "$jar" "$api/jurisdictions" | j 'd["itemsMax"]')" = 2 ] || fail "jurisdictions"
ok "defaults per context"

put() { curl -s -b "$jar" -X PUT -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$1" -d "$2" -w '\n%{http_code}'; }
bad="$(put "$api/institution" '{"jurisdiction":"UA","name":{"en":"x"},"legalId":"02070945","paymentAccounts":[{"kind":"bank","recipientName":"X","iban":"UA00"}]}')"
[ "$(tail -1 <<<"$bad")" = 400 ] || fail "invalid profile must get 400"
head -1 <<<"$bad" | j '",".join(sorted(d))' | grep -q 'legalId,name,paymentAccounts.0.iban' || fail "validation fields: $bad"
ok "validation"

good="$(put "$api/institution" '{"jurisdiction":"UA","institutionType":"ippo","ownership":"communal","legalId":"02070944","name":{"uk":"Інститут","en":"Institute"},"signers":[{"fullName":{"uk":"Коваль О."},"position":{"uk":"Директор"},"documentTypes":["invoice"]}],"paymentAccounts":[{"kind":"treasury","recipientName":"Інститут","iban":"UA118201720000031000000000001"}]}')"
[ "$(tail -1 <<<"$good")" = 200 ] || fail "save: $good"
[ "$(curl -s -b "$jar" "$api/institution" | j 'd["paymentAccounts"][0]["kind"]')" = treasury ] || fail "reload"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/institution" | j 'd["id"]')" = None ] || fail "context isolation"
ok "save, reload, isolation"

curl -s -b "$jar" "$api/institution/export" | j 'd["format"]' | grep -q ojs-conferenceManager-institution || fail export
ok export

# Editions (EDN-01…05, 09; EVT-01).
post() { curl -s -b "$jar" -X POST -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$1" -d "$2" -w '\n%{http_code}'; }
del() { curl -s -b "$jar" -X DELETE -H "X-Csrf-Token: $csrf" "$1" -w '\n%{http_code}'; }
[ "$(curl -s -b "$jar" "$api/editions/options" | j 'len(d["eventTypes"]), d["defaultTimezone"]')" = "(7, 'Europe/Kyiv')" ] || fail "edition options"
bad="$(post "$api/editions" '{"path":"Bad Path","name":{"en":"x"},"startsAt":"2027-05-20 09:00","endsAt":"2027-05-19 09:00","cfpOpensAt":"2027-03-01","abstractDeadline":"2027-01-01","isbn":"9780306406158"}')"
[ "$(tail -1 <<<"$bad")" = 400 ] || fail "invalid edition must get 400: $bad"
head -1 <<<"$bad" | j '",".join(sorted(d))' | grep -q 'abstractDeadline,endsAt,isbn,name,path' || fail "edition validation fields: $bad"
ed="$(post "$api/editions" '{"path":"conf-2027","eventType":"conference","submissionMode":"abstracts","format":"hybrid","name":{"uk":"Конференція 2027","en":"Conference 2027"},"startsAt":"2027-05-20 09:00","endsAt":"2027-05-21 18:00","cfpOpensAt":"2027-01-15","abstractDeadline":"2027-03-01"}')"
[ "$(tail -1 <<<"$ed")" = 200 ] || fail "create edition: $ed"
eid="$(head -1 <<<"$ed" | j 'd["id"]')"
[ "$(head -1 <<<"$ed" | j 'd["startsAt"], d["timezone"], d["publicationMode"], d["issueId"] is not None')" = "('2027-05-20T06:00:00Z', 'Europe/Kyiv', 'ojs_issue', True)" ] || fail "edition dates/issue: $ed"
issue="$(head -1 <<<"$ed" | j 'd["issueId"]')"
[ "$(curl -s -b "$jar" "$B/confa/api/v1/issues/$issue" | j 'd["published"]')" = False ] || fail "EDN-04: unpublished OJS issue"
ok "edition create, UTC dates, OJS issue"
[ "$(put "$api/editions/$eid" "$(curl -s -b "$jar" "$api/editions/$eid" | j 'json.dumps({**d, "status": "cfp_open"})')" | tail -1)" = 200 ] || fail "status change"
[ "$(curl -s -b "$jar" "$api/editions?status=cfp_open" | j 'd["itemsMax"]')" = 1 ] || fail "list by status"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/editions/$eid" -o /dev/null -w '%{http_code}')" = 404 ] || fail "edition context isolation"
[ "$(post "$api/editions" '{"path":"conf-2027","name":{"uk":"x"},"eventType":"webinar","startsAt":"2027-01-01 10:00","endsAt":"2027-01-01 11:00"}' | head -1 | j 'd["path"][0] != ""')" = True ] || fail "path unique"
ok "edition update, list, isolation, unique path"
cp="$(post "$api/editions/$eid/copy" '{"path":"conf-2028","startsAt":"2028-05-18 09:00","endsAt":"2028-05-19 18:00","cfpOpensAt":"2028-01-15","abstractDeadline":"2028-03-01"}')"
[ "$(tail -1 <<<"$cp")" = 200 ] || fail "copy: $cp"
cid="$(head -1 <<<"$cp" | j 'd["id"]')"
[ "$(del "$api/editions/$cid" | tail -1)" = 200 ] || fail "delete unused edition"
[ "$(curl -s -b "$jar" "$api/editions/$cid" -o /dev/null -w '%{http_code}')" = 404 ] || fail "deleted edition is gone"
web="$(post "$B/confb/api/v1/conference/editions" '{"path":"webinar-1","eventType":"webinar","name":{"en":"Webinar"},"startsAt":"2027-02-01 15:00","endsAt":"2027-02-01 16:30"}')"
[ "$(head -1 <<<"$web" | j 'd["submissionMode"], d["publicationMode"], d["issueId"]')" = "('none', 'none', None)" ] || fail "EVT-01 webinar defaults: $web"
ok "edition copy, delete, webinar defaults"

# Pages (EDN-07, EDN-08) and the scheduled task (EDN-03).
# OJS 3.5.0-5 answers the first page request of a session with a redirect to itself.
code() { curl -s -L -o /dev/null -w '%{http_code}' "$@"; }
[ "$(code -c "$jar" -b "$jar" "$B/confa/uk/manageConference")" = 200 ] || fail "manager page"
mgr="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/uk/manageConference")"
grep -q 'conference-editions-manager' <<<"$mgr" || fail "manager page component"
grep -q 'conferenceManager/js/editionsManager.js' <<<"$mgr" || fail "manager page script"
curl -s -L -o /dev/null -w '%{url_effective}' "$B/confa/uk/manageConference" | grep -q '/login' || fail "manager page must send anonymous users to login"
pub="$(curl -s -L "$B/confa/uk/conference/conf-2027")"
grep -q '"@type":"Event"' <<<"$pub" || fail "edition page JSON-LD"
grep -q '"startDate":"2027-05-20T09:00:00+03:00"' <<<"$pub" || fail "edition page startDate in the edition zone"
grep -q 'Конференція 2027' <<<"$pub" || fail "edition page title"
[ "$(code "$B/confa/uk/conference/no-such-edition")" = 404 ] || fail "unknown edition must be 404"
draft="$(post "$api/editions" '{"path":"draft-1","eventType":"webinar","name":{"uk":"Чернетка"},"startsAt":"2027-02-01 15:00","endsAt":"2027-02-01 16:30"}')"
[ "$(tail -1 <<<"$draft")" = 200 ] || fail "create draft: $draft"
[ "$(code "$B/confa/uk/conference/draft-1")" = 404 ] || fail "draft must be hidden from the public"
[ "$(code -c "$jar" -b "$jar" "$B/confa/uk/conference/draft-1")" = 200 ] || fail "draft preview for managers"
ok "manager page, edition page, drafts"

[ "$(code "$B/index/uk/conference/upcoming")" = 404 ] || fail "upcoming must be off until the site admin enables it"
docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php enable-site 2>&1 | grep -q 'OK enabled at site' || fail "enable at site level"
up="$(curl -s -L "$B/index/uk/conference/upcoming")"
grep -q 'conference/conf-2027' <<<"$up" || fail "upcoming lists the open call"
! grep -q 'draft-1' <<<"$up" || fail "upcoming must not list drafts"
[ "$(curl -s "$B/index/api/v1/conference-site/upcoming?type=conference" | j 'd["itemsMax"], d["items"][0]["path"]')" = "(1, 'conf-2027')" ] || fail "upcoming API"
[ "$(curl -s "$B/index/api/v1/conference-site/upcoming?type=webinar" | j 'd["itemsMax"]')" = 0 ] || fail "upcoming API filter"
[ "$(curl -s -G "$B/index/api/v1/conference-site/upcoming" --data-urlencode 'q=КОНФЕРЕНЦІЯ' | j 'd["itemsMax"]')" = 1 ] || fail "upcoming search is case-insensitive for Cyrillic"
ok "upcoming events (page, API, filters)"

past="$(post "$api/editions" '{"path":"past-call","status":"cfp_open","name":{"uk":"Минулий прийом"},"startsAt":"2027-06-01 09:00","endsAt":"2027-06-01 18:00","cfpOpensAt":"2026-01-01","abstractDeadline":"2026-02-01"}')"
pid="$(head -1 <<<"$past" | j 'd["id"]')"
docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php run-windows-task 2>&1 | grep -q 'OK edition windows task' || fail "windows task"
[ "$(curl -s -b "$jar" "$api/editions/$pid" | j 'd["status"]')" = cfp_closed ] || fail "EDN-03: expired call must be closed"
[ "$(curl -s -b "$jar" "$api/editions/$eid" | j 'd["status"]')" = cfp_open ] || fail "EDN-03: open call must stay open"
ok "scheduled closing of calls"

modal="$B/confa/\$\$\$call\$\$\$/grid/settings/plugins/settings-plugin-grid/manage?verb=institution&plugin=conferencemanagerplugin&category=generic"
curl -s -b "$jar" "$modal" | j 'd["status"] and "paymentAccounts[0][iban]" in d["content"]' | grep -q True || fail "settings modal"
ok "settings modal"
rm -f "$jar"
echo "API SMOKE OK"
