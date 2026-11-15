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

# Submission wizard step, checks on submit, participants, co-author links (REG-02…04, EDN-03).
# The "log" mailer writes letters to the PHP error log; in OJS 3.5.0-5 it fails (no storagePath()).
maillog=0
if docker exec "${OJS_CONTAINER:-ojs}" grep -q 'function storagePath' lib/pkp/classes/core/PKPContainer.php; then
  docker exec "${OJS_CONTAINER:-ojs}" sed -i 's/^default = sendmail/default = log/' config.inc.php; maillog=1
fi
form() { curl -s -b "$jar" -X POST -H "X-Csrf-Token: $csrf" -H "X-Http-Method-Override: PUT" "$@" -w '\n%{http_code}'; }
lc() { docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
oc="$(post "$api/editions" '{"path":"open-call","status":"cfp_open","eventType":"conference","submissionMode":"abstracts","publicationMode":"none","format":"online","name":{"uk":"Відкритий прийом","en":"Open call"},"startsAt":"2027-05-20 09:00","endsAt":"2027-05-21 18:00","cfpOpensAt":"2026-01-01","abstractDeadline":"2027-03-01"}')"
oid="$(head -1 <<<"$oc" | j 'd["id"]')"
[ "$(post "$api/editions" '{"path":"confirm","name":{"uk":"x"},"eventType":"webinar","startsAt":"2027-01-01 10:00","endsAt":"2027-01-01 11:00"}' | tail -1)" = 400 ] || fail "reserved edition path"
ag="$(lc author-group confa)"
newsub() {
  local s; s="$(post "$B/confa/api/v1/submissions" '{"sectionId":1,"locale":"uk"}')"
  [ "$(tail -1 <<<"$s")" = 200 ] || fail "create submission: $s"
  sid="$(head -1 <<<"$s" | j 'd["id"]')"; pub="$(head -1 <<<"$s" | j 'd["currentPublicationId"]')"
  P="$B/confa/api/v1/submissions/$sid/publications/$pub"
  for who in '"givenName":{"uk":"Адмін"},"familyName":{"uk":"Сайту"},"email":"admin@example.org"' '"givenName":{"uk":"Олена"},"familyName":{"uk":"Коваль"},"email":"Olena@example.org"'; do
    [ "$(post "$P/contributors" "{$who,\"country\":\"UA\",\"userGroupId\":$ag,\"includeInBrowse\":true}" | tail -1)" = 200 ] || fail "add contributor"
  done
  [ "$(form "$P" --data-urlencode 'title[uk]=Тези про освіту' --data-urlencode 'abstract[uk]=<p>Зміст.</p>' | tail -1)" = 200 ] || fail "title"
  printf '%%PDF-1.4\n' > /tmp/cm-t.pdf
  [ "$(curl -s -b "$jar" -X POST -H "X-Csrf-Token: $csrf" "$B/confa/api/v1/submissions/$sid/files" -F fileStage=2 -F genreId=1 -F file=@/tmp/cm-t.pdf -F 'name[uk]=t.pdf' -o /dev/null -w '%{http_code}')" = 200 ] || fail "upload"
}
newsub
wiz="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/uk/submission?id=$sid")"
grep -q '"id":"conference"' <<<"$wiz" || fail "wizard has the conference step"
grep -q 'conferenceManager/js/wizardReview.js' <<<"$wiz" || fail "wizard review script"
[ "$(curl -s -b "$jar" "$api/submissions/$sid/registration" | j 'd["registration"]["editionId"], d["registration"]["presenterEmail"], d["registration"]["participationForm"]')" = "($oid, 'admin@example.org', 'online')" ] || fail "prefill: the only open call, the submitter, online for an online event"
v="$(form "$B/confa/api/v1/submissions/$sid/submit" --data '_validateOnly=1')"
[ "$(tail -1 <<<"$v")" = 400 ] && head -1 <<<"$v" | j '",".join(sorted(d))' | grep -qx 'conferenceConsent' || fail "REG-02: no submit without consent: $v"
[ "$(curl -s -b "$jar" -X PUT -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$B/confb/api/v1/conference/submissions/$sid/registration" -d '{}' -o /dev/null -w '%{http_code}')" != 200 ] || fail "registration of another journal's submission"
r="$(form "$api/submissions/$sid/registration" --data "conferenceEditionId=$oid&conferencePresentationType=poster&conferenceParticipationForm=online&conferencePresenterEmail=olena%40example.org&conferenceConsent%5B%5D=true")"
[ "$(tail -1 <<<"$r")" = 200 ] || fail "autosave: $r"
head -1 <<<"$r" | j '"submissionId" not in d and "dateSubmitted" not in d and d["registration"]["consentVersion"] is not None' | grep -q True || fail "autosave response: $r"
[ "$(form "$B/confa/api/v1/submissions/$sid/submit" --data '_validateOnly=1' | tail -1)" = 200 ] || fail "valid questionnaire passes"
[ "$(form "$B/confa/api/v1/submissions/$sid/submit" --data 'confirmCopyright=1' | tail -1)" = 200 ] || fail "submit"
reg="$(curl -s -b "$jar" "$api/submissions/$sid/registration")"
[ "$(j 'd["registration"]["registeredAt"] is not None, sorted((p["email"], p["role"], p["confirmationStatus"]) for p in d["participants"])' <<<"$reg")" = "(True, [('admin@example.org', 'coauthor', 'confirmed'), ('olena@example.org', 'presenter', 'pending')])" ] || fail "REG-03 participants: $reg"
[ "$(curl -s -b "$jar" "$P" | j 'd["conferenceEditionId"], d["presentationType"]')" = "($oid, 'poster')" ] || fail "publication linked to the edition"
ok "wizard step, consent check, participants, publication link"

sleep 2
if [ "$maillog" = 1 ]; then
  link="$(docker logs "${OJS_CONTAINER:-ojs}" 2>&1 | grep -o "confa/[a-z]*/*conference/confirm/[A-Za-z0-9_-]\{43\}" | tail -1 || true)"
  [ -n "$link" ] || fail "REG-04: letter with a confirmation link"
else
  link="$(lc new-token confa olena@example.org | tail -1)"
fi
anon="$(mktemp)"
# Follow the language redirect once and use the final address from then on.
link="$(curl -s -L -c "$anon" -b "$anon" -o /dev/null -w '%{url_effective}' "$B/$link" | sed "s#^$B/##")"
cp="$(curl -s -L -c "$anon" -b "$anon" "$B/$link")"
grep -q 'name="answer" value="accept"' <<<"$cp" || fail "confirmation page"
ctok="$(sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' <<<"$cp" | head -1)"
grep -q 'name="answer"' <<<"$(curl -s -c "$anon" -b "$anon" "$B/$link" --data 'answer=accept')" || fail "without CSRF nothing is recorded and the question stays"
done="$(curl -s -L -c "$anon" -b "$anon" "$B/$link" --data "csrfToken=$ctok&answer=accept")"
grep -q 'cmConfirm__result' <<<"$done" && ! grep -q 'name="answer"' <<<"$done" || fail "confirm"
[ "$(curl -s -b "$jar" "$api/submissions/$sid/registration" | j '[p["confirmationStatus"] for p in d["participants"]]')" = "['confirmed', 'confirmed']" ] || fail "status confirmed"
grep -q 'name="answer"' <<<"$(curl -s -L -c "$anon" -b "$anon" "$B/$link")" && fail "the link must work once"
rm -f "$anon"
ok "co-author confirmation link (one-time)"

newsub
curl -s -L -c "$jar" -b "$jar" -o /dev/null "$B/confa/uk/submission?id=$sid"
form "$api/submissions/$sid/registration" --data "conferenceEditionId=$oid&conferencePresentationType=oral&conferenceParticipationForm=online&conferencePresenterEmail=admin%40example.org&conferenceConsent%5B%5D=true" >/dev/null
[ "$(put "$api/editions/$oid" "$(curl -s -b "$jar" "$api/editions/$oid" | j 'json.dumps({**d, "status": "cfp_closed"})')" | tail -1)" = 200 ] || fail "close the call"
v="$(form "$B/confa/api/v1/submissions/$sid/submit" --data '_validateOnly=1')"
[ "$(tail -1 <<<"$v")" = 400 ] && head -1 <<<"$v" | j '",".join(sorted(d))' | grep -qx 'conferenceEditionId' || fail "EDN-03: no submit after the call is closed: $v"
ok "submission blocked outside the call window"

# Fees, listeners, cabinet, register, cancellation (FEE-01…03, REG-05…08).
le="$(post "$api/editions" '{"path":"listen-2027","status":"cfp_open","eventType":"conference","submissionMode":"abstracts","publicationMode":"none","format":"hybrid","name":{"uk":"Конференція зі слухачами","en":"Conference with listeners"},"startsAt":"2027-06-20 09:00","endsAt":"2027-06-21 18:00","cfpOpensAt":"2026-01-01","abstractDeadline":"2027-03-01","registrationClosesAt":"2027-06-10"}')"
[ "$(tail -1 <<<"$le")" = 200 ] || fail "edition with listeners: $le"
lid="$(head -1 <<<"$le" | j 'd["id"]')"
pr="$(post "$api/editions/$lid/fees/presets" '{"institutionType":"university"}')"
stud="$(head -1 <<<"$pr" | j '[c["id"] for c in d["categories"] if c["code"] == "students"][0]')"
[ "$(head -1 <<<"$pr" | j 'len(d["categories"]) >= 5')" = True ] || fail "FEE-01 presets: $pr"
t="$(post "$api/editions/$lid/fees/tariffs" "{\"categoryId\":$stud,\"material\":\"listener\",\"pricing\":\"flat\",\"amount\":\"100.50\",\"currency\":\"uah\"}")"
[ "$(head -1 <<<"$t" | j 'd["amountMinor"], d["currency"]')" = "(10050, 'UAH')" ] || fail "FEE-02 tariff in minor units: $t"
[ "$(post "$api/editions/$lid/fees/tariffs" "{\"categoryId\":$stud,\"material\":\"listener\",\"pricing\":\"per_page\",\"amount\":\"5\",\"currency\":\"UAH\"}" | tail -1)" = 400 ] || fail "listener tariff must be flat"
[ "$(curl -s -b "$jar" "$api/editions/$lid/fees/quote?categoryId=$stud&material=listener" | j 'd["total"]["minor"]')" = 10050 ] || fail "FEE-03 quote"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/editions/$lid/fees" -o /dev/null -w '%{http_code}')" = 404 ] || fail "fees context isolation"
ok "categories, tariffs, quote"

grep -q "listen-2027/register" <<<"$(curl -s -L -b "$jar" "$B/confa/uk/conference/listen-2027")" || fail "register button on the edition page"
anon="$(mktemp)"
[[ "$(curl -s -L -c "$anon" -b "$anon" -o /dev/null -w '%{url_effective}' "$B/confa/uk/conference/listen-2027/register")" == *login* ]] || fail "REG-05: anonymous goes to login"
rm -f "$anon"
lc new-user confa reader1 reader1@example.org >/dev/null
rj="$(mktemp)"
rtok="$(curl -s -L -c "$rj" -b "$rj" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$rj" -b "$rj" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$rtok&username=reader1&password=reader12345"
regurl="$B/confa/uk/conference/listen-2027/register"
rp="$(curl -s -L -c "$rj" -b "$rj" "$regurl")"
grep -q 'name="categoryId"' <<<"$rp" || fail "listener form: $(head -c 300 <<<"$rp")"
rcsrf="$(sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' <<<"$rp" | head -1)"
grep -q 'cmConfirm__result' <<<"$(curl -s -c "$rj" -b "$rj" "$regurl" --data "csrfToken=$rcsrf&categoryId=$stud&participationForm=online&consent=1&website=http://spam")" || fail "honeypot"
grep -q 'cmRegister__error' <<<"$(curl -s -c "$rj" -b "$rj" "$regurl" --data "csrfToken=$rcsrf&participationForm=online&consent=1")" || fail "category required"
my="$(curl -s -L -c "$rj" -b "$rj" "$regurl" --data "csrfToken=$rcsrf&categoryId=$stud&participationForm=online&consent=1&affiliation=Університет&position=доцент")"
grep -q 'Конференція зі слухачами' <<<"$my" && grep -q 'cmMy__cancel' <<<"$my" || fail "REG-06 cabinet after registration"
rpost() { curl -s -b "$rj" -X POST -H "X-Csrf-Token: $rcsrf" -H 'Content-Type: application/json' "$1" -d "$2" -w '\n%{http_code}'; }
mine="$(curl -s -b "$rj" "$api/me/participations")"
[ "$(j 'd["itemsMax"], d["items"][0]["role"], d["items"][0]["canCancel"], d["items"][0]["categoryName"] != ""' <<<"$mine")" = "(1, 'listener', True, True)" ] || fail "me/participations: $mine"
mpid="$(j 'd["items"][0]["participationId"]' <<<"$mine")"
[ "$(rpost "$api/editions/$lid/listeners" "{\"categoryId\":$stud,\"participationForm\":\"online\",\"consent\":true}" | tail -1)" = 409 ] || fail "one listener registration per person"
# OJS answers a role refusal with 401.
[[ "$(curl -s -b "$rj" "$api/editions/$lid/participants" -o /dev/null -w '%{http_code}')" =~ ^40[13]$ ]] || fail "a reader has no register"
apid="$(curl -s -b "$jar" "$api/me/participations" | j 'd["items"][0]["participationId"]')"
[ "$(rpost "$api/me/participations/$apid/cancel" '{}' | tail -1)" = 403 ] || fail "REG-06: another person's participation gives 403"
ok "listener registration, cabinet, own records only"

lc seed-participants confa "$lid" 1000 >/dev/null
reg="$(curl -s -b "$jar" "$api/editions/$lid/participants?count=50&offset=950" -w '\n%{time_total}')"
[ "$(head -1 <<<"$reg" | j 'd["itemsMax"], len(d["items"]), len(d["categories"]) >= 5')" = "(1001, 50, True)" ] || fail "REG-07 register paging: $(head -c 300 <<<"$reg")"
python3 -c "import sys; sys.exit(0 if float(sys.argv[1]) < 1.0 else 1)" "$(tail -1 <<<"$reg")" || fail "REG-07: the register of 1000 must open in under 1 s ($(tail -1 <<<"$reg") s)"
s1="$(curl -s -b "$jar" "$api/editions/$lid/participants?searchPhrase=%D0%BD%D0%BE%D0%BC%D0%B5%D1%8099" -w '\n%{time_total}')"
[ "$(head -1 <<<"$s1" | j 'd["itemsMax"]')" = 11 ] || fail "Cyrillic search, any case: $(head -c 300 <<<"$s1")"
[ "$(curl -s -b "$jar" "$api/editions/$lid/participants?categoryId=$stud" | j 'd["itemsMax"]')" = 1 ] || fail "filter by category"
csv="$(curl -s -b "$jar" "$api/editions/$lid/participants/export" -D /tmp/cm-h.txt)"
grep -qi 'content-type: text/csv' /tmp/cm-h.txt && [ "$(wc -l <<<"$csv")" = 1002 ] || fail "CSV export ($(wc -l <<<"$csv") lines)"
ok "register of 1001: paging, search, filter, export in $(tail -1 <<<"$reg") s"

out="$(curl -s -L -c "$rj" -b "$rj" "$B/confa/uk/conference/my" --data "csrfToken=$rcsrf&participationId=$mpid")"
grep -q 'cmMy__item--cancelled' <<<"$out" || fail "REG-08: cancel in the cabinet"
[ "$(curl -s -b "$rj" "$api/me/participations" | j 'd["items"][0]["status"]')" = cancelled ] || fail "cancelled status"
seedp="$(curl -s -b "$jar" "$api/editions/$lid/participants?searchPhrase=seed5%40" | j 'd["items"][0]["participationId"]')"
[ "$(post "$api/participations/$seedp/cancel" '{}' | tail -1)" = 200 ] || fail "manager cancels"
[ "$(post "$api/participations/$seedp/cancel" '{}' | tail -1)" = 409 ] || fail "already cancelled"
ok "cancellation by the participant and by the manager"

newsub
wiz="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/uk/submission?id=$sid")"
grep -q 'conferenceFeeCategory' <<<"$wiz" || fail "category in the questionnaire"
v="$(form "$B/confa/api/v1/submissions/$sid/submit" --data '_validateOnly=1')"
head -1 <<<"$v" | j '",".join(sorted(d))' | grep -q 'conferenceFeeCategory' || fail "category required on submit: $v"
form "$api/submissions/$sid/registration" --data "conferenceFeeCategory=$stud&conferencePresentationType=oral&conferenceParticipationForm=online&conferencePresenterEmail=admin%40example.org&conferenceConsent%5B%5D=true" >/dev/null
[ "$(form "$B/confa/api/v1/submissions/$sid/submit" --data 'confirmCopyright=1' | tail -1)" = 200 ] || fail "submit with category"
[ "$(curl -s -b "$jar" "$api/editions/$lid/participants?role=presenter&categoryId=$stud" | j 'd["itemsMax"]')" = 1 ] || fail "presenter got the category"
rm -f "$rj"
ok "participant category in the questionnaire"

# Payment rules, invoices, PDF, waiver (PAY-01…08, FEE-04…07).
acc="$(curl -s -b "$jar" "$api/institution" | j '[a["id"] for a in d["paymentAccounts"] if a["kind"] == "treasury"][0]')"
pe="$(post "$api/editions" '{"path":"pay-2027","status":"cfp_open","eventType":"conference","submissionMode":"abstracts","publicationMode":"none","format":"online","name":{"uk":"Конференція з оргвнеском","en":"Conference with a fee"},"startsAt":"2027-06-20 09:00","endsAt":"2027-06-21 18:00","cfpOpensAt":"2026-01-01","abstractDeadline":"2027-03-01","registrationClosesAt":"2027-06-10","paymentDeadline":"2027-06-01"}')"
[ "$(tail -1 <<<"$pe")" = 200 ] || fail "edition with a fee: $pe"
pid="$(head -1 <<<"$pe" | j 'd["id"]')"
pc="$(post "$api/editions/$pid/fees/presets" '{"institutionType":"university"}')"
other="$(head -1 <<<"$pc" | j '[c["id"] for c in d["categories"] if c["code"] == "other_faculty"][0]')"
pstud="$(head -1 <<<"$pc" | j '[c["id"] for c in d["categories"] if c["code"] == "students"][0]')"
post "$api/editions/$pid/fees/tariffs" "{\"categoryId\":$other,\"material\":\"listener\",\"pricing\":\"flat\",\"amount\":\"150\",\"currency\":\"UAH\"}" >/dev/null
[ "$(post "$api/editions/$pid/payment-rules" '{"mode":"bank_transfer","paymentAccountId":'"$acc"'}' | tail -1)" = 400 ] || fail "PAY-02: a bank transfer needs a bank account"
[ "$(post "$api/editions/$pid/payment-rules" '{"mode":"external_link","externalUrl":"javascript:alert(1)"}' | tail -1)" = 400 ] || fail "external link must be https"
[ "$(post "$api/editions/$pid/payment-rules" '{"mode":"treasury_transfer","paymentAccountId":'"$acc"'}' | tail -1)" = 200 ] || fail "default rule"
[ "$(post "$api/editions/$pid/payment-rules" '{"mode":"free","categoryId":'"$pstud"'}' | tail -1)" = 200 ] || fail "free rule for students"
[ "$(curl -s -b "$jar" "$api/editions/$pid/payment-rules" | j 'len(d["rules"]), len(d["options"]["modes"])')" = "(2, 5)" ] || fail "rules list"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/editions/$pid/payment-rules" -o /dev/null -w '%{http_code}')" = 404 ] || fail "rules context isolation"
ok "payment rules"

lc new-user confa payer1 payer1@example.org >/dev/null
lc new-user confa payer2 payer2@example.org >/dev/null
login() {
  local jarf="$1" tok
  tok="$(curl -s -L -c "$jarf" -b "$jarf" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$jarf" -b "$jarf" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=$2&password=reader12345"
}
pj1="$(mktemp)"; pj2="$(mktemp)"
login "$pj1" payer1; login "$pj2" payer2
regp="$B/confa/uk/conference/pay-2027/register"
ptok="$(curl -s -L -c "$pj1" -b "$pj1" "$regp" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
cab="$(curl -s -L -c "$pj1" -b "$pj1" "$regp" --data "csrfToken=$ptok&categoryId=$other&participationForm=online&consent=1&affiliation=Ліцей")"
grep -q 'cmInvoice' <<<"$cab" && grep -q '150.00 UAH' <<<"$cab" || fail "FEE-04: invoice in the cabinet after registration"
mi="$(curl -s -b "$pj1" "$api/me/invoices")"
[ "$(j 'd["itemsMax"], d["items"][0]["total"]["minor"], d["items"][0]["status"], d["items"][0]["hasDocument"]' <<<"$mi")" = "(1, 15000, 'issued', True)" ] || fail "me/invoices: $mi"
[ "$(j 'd["items"][0]["totalInWords"]' <<<"$mi")" = "Сто п'ятдесят гривень 00 копійок" ] || fail "amount in words: $mi"
inv="$(j 'd["items"][0]["id"]' <<<"$mi")"
num="$(j 'd["items"][0]["number"]' <<<"$mi")"
[[ "$num" =~ ^20[0-9]{2}-00001$ ]] || fail "FEE-05 number format: $num"
j 'd["items"][0]["instructions"]' <<<"$mi" | grep -q 'номер картки' || fail "FEE-14: UA card warning"
if [ "$maillog" = 1 ]; then
  docker logs "${OJS_CONTAINER:-ojs}" 2>&1 | grep -q "$num" || fail "FEE-04: letter with the invoice number"
fi
pdf="$(mktemp)"
curl -s -L -b "$pj1" "$B/confa/uk/conference/invoice/$inv" -D /tmp/cm-ph.txt -o "$pdf"
grep -qi 'content-type: application/pdf' /tmp/cm-ph.txt && [ "$(head -c 4 "$pdf")" = "%PDF" ] || fail "FEE-06: PDF for the payer"
if command -v pdftotext >/dev/null; then
  txt="$(pdftotext -layout "$pdf" -)"
  grep -q "$num" <<<"$txt" && grep -q "Сто п.ятдесят гривень 00 копійок" <<<"$txt" && grep -q 'UA11 8201 7200' <<<"$txt" || fail "PDF text: $(head -c 600 <<<"$txt")"
fi
[ "$(curl -s -L -b "$pj2" "$B/confa/uk/conference/invoice/$inv" -o /dev/null -w '%{http_code}')" = 404 ] || fail "another person's PDF"
[ "$(curl -s -b "$pj2" "$api/me/invoices/$inv/pdf" -o /dev/null -w '%{http_code}')" = 403 ] || fail "another person's PDF via API"
[ "$(curl -s -b "$jar" "$api/invoices/$inv/pdf" -o /dev/null -w '%{content_type}')" = application/pdf ] || fail "manager PDF"
ok "listener invoice $num: cabinet, letter, PDF, own only"

[ "$(put "$api/invoice-templates/treasury" '{"html":"<p>{secret}</p>"}' | tail -1)" = 400 ] || fail "PAY-07: unknown variables"
[ "$(put "$api/invoice-templates/treasury" '{"html":"<h1>Власний рахунок {invoiceNumber}</h1>{itemsTable}"}' | tail -1)" = 200 ] || fail "own template"
if command -v pdftotext >/dev/null; then
  curl -s -b "$jar" "$api/invoices/$inv/pdf" | pdftotext - - | grep -q "Власний рахунок $num" || fail "PDF from the own template"
fi
[ "$(put "$api/invoice-templates/treasury" '{"html":""}' | tail -1)" = 200 ] || fail "back to built-in"
[ "$(curl -s -b "$jar" "$api/invoice-templates" | j '[t["custom"] for t in d["templates"]]')" = "[None, None]" ] || fail "templates reset"
ok "invoice templates of the context"

paid="$(post "$api/invoices/$inv/paid" '{"amount":"100","note":"виписка 1"}')"
[ "$(head -1 <<<"$paid" | j 'd["status"], d["paid"]["minor"], d["due"]["minor"]')" = "('issued', 10000, 5000)" ] || fail "part payment: $paid"
[ "$(post "$api/invoices/$inv/paid" '{}' | head -1 | j 'd["status"]')" = paid ] || fail "paid in full"
[ "$(post "$api/invoices/$inv/waive" '{"reason":"x"}' | tail -1)" = 409 ] || fail "no waiver for a paid invoice"
[ "$(curl -s -b "$jar" "$api/invoices/$inv" | j '[e["type"] for e in d["events"]]')" = "['issued', 'part_paid', 'paid']" ] || fail "payment journal"
ptok2="$(curl -s -L -c "$pj2" -b "$pj2" "$regp" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -L -c "$pj2" -b "$pj2" -o /dev/null "$regp" --data "csrfToken=$ptok2&categoryId=$other&participationForm=online&consent=1"
inv2="$(curl -s -b "$pj2" "$api/me/invoices" | j 'd["items"][0]["id"]')"
w="$(post "$api/invoices/$inv2/waive" '{"reason":"Член оргкомітету"}')"
[ "$(head -1 <<<"$w" | j 'd["status"], d["total"]["minor"], d["method"], d["supersedesId"]')" = "('waived', 0, 'waiver', $inv2)" ] || fail "FEE-07 waiver: $w"
[ "$(curl -s -b "$pj2" "$api/me/invoices" | j '[i["status"] for i in d["items"]]')" = "['waived', 'superseded']" ] || fail "waiver in the cabinet"
[ "$(curl -s -b "$jar" "$api/editions/$pid/invoices?searchPhrase=payer2" | j 'd["itemsMax"]')" = 2 ] || fail "invoice search"
[ "$(curl -s -b "$pj1" "$api/editions/$pid/invoices" -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader has no invoice list"
ok "part payment, payment, waiver, journal"

# Receipts, review queue, payment journal, register filter (FEE-09, FEE-10, FEE-13).
lc new-user confa payer3 payer3@example.org >/dev/null
pj3="$(mktemp)"; login "$pj3" payer3
ptok3="$(curl -s -L -c "$pj3" -b "$pj3" "$regp" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
cab3="$(curl -s -L -c "$pj3" -b "$pj3" "$regp" --data "csrfToken=$ptok3&categoryId=$other&participationForm=online&consent=1")"
grep -q 'name="proof"' <<<"$cab3" || fail "FEE-09: upload form in the cabinet"
inv3="$(curl -s -b "$pj3" "$api/me/invoices" | j 'd["items"][0]["id"]')"
num3="$(curl -s -b "$pj3" "$api/me/invoices" | j 'd["items"][0]["number"]')"
rcpt="$(mktemp --suffix=.pdf)"; printf '%%PDF-1.4\n%% receipt for %s\n%%%%EOF\n' "$num3" > "$rcpt"
fake="$(mktemp --suffix=.pdf)"; printf '<html><script>alert(1)</script></html>' > "$fake"
myurl="$B/confa/uk/conference/my"
sent="$(curl -s -L -c "$pj3" -b "$pj3" "$myurl" -F "csrfToken=$ptok3" -F "invoiceId=$inv3" -F "proof=@$rcpt;type=application/pdf;filename=Квитанція.pdf")"
grep -q 'cmInvoice__proof--pending' <<<"$sent" || fail "FEE-09: receipt sent from the cabinet: $(grep -o 'cmMy__message[^<]*<[^<]*' <<<"$sent" | head -2)"
bad="$(curl -s -L -c "$pj3" -b "$pj3" "$myurl" -F "csrfToken=$ptok3" -F "invoiceId=$inv3" -F "proof=@$fake;type=application/pdf")"
grep -q 'Можна надіслати лише' <<<"$bad" || fail "FEE-09: a file that is not PDF/JPG/PNG by content"
p3csrf="$(curl -s -L -b "$pj3" "$myurl" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
[ "$(curl -s -b "$pj3" -H "X-Csrf-Token: $p3csrf" -F "file=@$rcpt" "$api/me/invoices/$inv3/proofs" -o /dev/null -w '%{http_code}')" = 400 ] || fail "the same file twice for one invoice"
p2csrf="$(curl -s -L -b "$pj2" "$myurl" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
[ "$(curl -s -b "$pj2" -H "X-Csrf-Token: $p2csrf" -F "file=@$rcpt" "$api/me/invoices/$inv3/proofs" -o /dev/null -w '%{http_code}')" = 403 ] || fail "a receipt for another person's invoice"
q="$(curl -s -b "$jar" "$api/proofs?status=pending&editionId=$pid")"
[ "$(j 'd["itemsMax"], d["items"][0]["invoice"]["number"], d["items"][0]["name"], d["items"][0]["mime"]' <<<"$q")" = "(1, '$num3', 'Квитанція.pdf', 'application/pdf')" ] || fail "FEE-10 queue: $q"
prid="$(j 'd["items"][0]["id"]' <<<"$q")"
[ "$(curl -s -b "$pj3" "$api/proofs?status=pending" -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader has no queue"
[ "$(curl -s -L -b "$pj3" "$B/confa/uk/conference/receipt/$prid" -o /dev/null -w '%{http_code} %{content_type}')" = "200 application/pdf" ] || fail "own receipt"
[ "$(curl -s -L -b "$pj2" "$B/confa/uk/conference/receipt/$prid" -o /dev/null -w '%{http_code}')" = 404 ] || fail "another person's receipt"
curl -s -b "$jar" "$api/proofs/$prid/file" | grep -q "receipt for $num3" || fail "manager gets the receipt"
fdir="$(docker exec "${OJS_CONTAINER:-ojs}" sh -c "sed -n 's/^files_dir *= *//p' /var/www/html/config.inc.php" | tr -d '\r')"
stored="$(docker exec "${OJS_CONTAINER:-ojs}" sh -c "find '$fdir/conferenceManager/proofs' -type f | head -1")"
[ -n "$stored" ] || fail "receipt in files_dir ($fdir)"
case "$stored" in /var/www/html/*) fail "receipt inside the web root: $stored" ;; esac
[ "$(curl -s "$B/${stored#/}" -o /dev/null -w '%{http_code}')" != 200 ] || fail "receipt reachable by a direct URL"
[ "$(post "$api/proofs/$prid/reject" '{"note":""}' | tail -1)" = 400 ] || fail "rejection needs a note"
rej="$(post "$api/proofs/$prid/reject" '{"note":"Сума не збігається"}')"
[ "$(head -1 <<<"$rej" | j 'd["status"], d["note"]')" = "('rejected', 'Сума не збігається')" ] || fail "reject: $rej"
[ "$(post "$api/proofs/$prid/accept" '{}' | tail -1)" = 409 ] || fail "a reviewed receipt cannot be accepted"
grep -q 'Сума не збігається' <<<"$(curl -s -L -b "$pj3" "$myurl")" || fail "rejection note in the cabinet"
if [ "$maillog" = 1 ]; then
  docker logs "${OJS_CONTAINER:-ojs}" 2>&1 | grep -q 'Сума не збігається' || fail "FEE-10: rejection letter"
fi
up="$(curl -s -b "$pj3" -H "X-Csrf-Token: $p3csrf" -F "file=@$rcpt;filename=again.pdf" "$api/me/invoices/$inv3/proofs" -w '\n%{http_code}')"
[ "$(tail -1 <<<"$up")" = 200 ] && [ "$(head -1 <<<"$up" | j 'd["warnings"]')" = "['duplicate']" ] || fail "re-upload after rejection with a duplicate warning: $up"
prid2="$(head -1 <<<"$up" | j 'd["id"]')"
[ "$(curl -s -b "$jar" "$api/editions/$pid/participants?payment=proof_pending" | j '[p["email"] for p in d["items"]]')" = "['payer3@example.org']" ] || fail "register filter: receipt awaiting review"
acc3="$(post "$api/proofs/$prid2/accept" '{}')"
[ "$(head -1 <<<"$acc3" | j 'd["status"], d["invoice"]["status"]')" = "('accepted', 'paid')" ] || fail "FEE-10 accept: $acc3"
[ "$(curl -s -b "$jar" "$api/editions/$pid/participants?payment=paid" | j 'sorted(p["email"] for p in d["items"])')" = "['payer1@example.org', 'payer2@example.org', 'payer3@example.org']" ] || fail "register filter: paid"
[ "$(curl -s -b "$jar" "$api/proofs?status=pending&editionId=$pid" | j 'd["itemsMax"]')" = 0 ] || fail "queue is empty"
jr="$(curl -s -b "$jar" "$api/editions/$pid/payment-events?count=100")"
[ "$(j '[e["type"] for e in d["items"] if e["invoiceId"] == '"$inv3"']' <<<"$jr")" = "['paid', 'proof_accepted', 'proof_uploaded', 'proof_rejected', 'proof_uploaded', 'issued']" ] || fail "FEE-13 journal: $jr"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/editions/$pid/payment-events" -o /dev/null -w '%{http_code}')" = 404 ] || fail "journal context isolation"
rm -f "$pj1" "$pj2" "$pj3" "$pdf" "$rcpt" "$fake"
ok "receipts: upload, checks, private files, queue, reject, accept, journal, register filter"

# EVT-04: a minor listener needs the guardian's consent.
lc new-user confa pupil1 pupil1@example.org >/dev/null
pj4="$(mktemp)"; login "$pj4" pupil1
ptok4="$(curl -s -L -c "$pj4" -b "$pj4" "$regp" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
grep -q 'cmRegister__error' <<<"$(curl -s -L -c "$pj4" -b "$pj4" "$regp" --data "csrfToken=$ptok4&categoryId=$pstud&participationForm=online&consent=1&minor=1&position=учень")" || fail "EVT-04: guardian consent required"
curl -s -L -c "$pj4" -b "$pj4" -o /dev/null "$regp" --data "csrfToken=$ptok4&categoryId=$pstud&participationForm=online&consent=1&minor=1&guardianConsent=1&position=учень"
[ "$(curl -s -b "$jar" "$api/editions/$pid/participants?searchPhrase=pupil1" | j 'd["items"][0]["minor"], d["items"][0]["guardianConsentAt"] is not None, d["items"][0]["position"]')" = "(True, True, '')" ] || fail "EVT-04: minor in the register"
rm -f "$pj4"
ok "minor listener with guardian consent, no position stored"

# FEE-04 after acceptance: the presenter of the last submission (edition listen-2027).
post "$api/editions/$lid/payment-rules" '{"mode":"treasury_transfer","paymentAccountId":'"$acc"'}' >/dev/null
post "$api/editions/$lid/fees/tariffs" "{\"categoryId\":$stud,\"material\":\"abstract\",\"pricing\":\"flat\",\"amount\":\"200\",\"currency\":\"UAH\"}" >/dev/null
dec="$(post "$B/confa/api/v1/submissions/$sid/decisions" '{"decision":17,"stageId":1}')"
[ "$(tail -1 <<<"$dec")" = 200 ] || fail "accept decision: $dec"
[ "$(curl -s -b "$jar" "$api/editions/$lid/invoices" | j '[(i["total"]["minor"], i["payer"]["email"]) for i in d["items"]]')" = "[(20000, 'admin@example.org')]" ] || fail "FEE-04: presenter invoice after acceptance"
ok "presenter invoice after the acceptance decision"

# Decisions 901–904, type change, panel, bulk decisions (DEC-01…06).
de="$(post "$api/editions" '{"path":"dec-2027","status":"cfp_open","eventType":"conference","submissionMode":"abstracts","publicationMode":"ojs_issue","format":"hybrid","name":{"uk":"Конференція з рішеннями","en":"Conference with decisions"},"startsAt":"2027-06-20 09:00","endsAt":"2027-06-21 18:00","cfpOpensAt":"2026-01-01","abstractDeadline":"2027-03-01"}')"
[ "$(tail -1 <<<"$de")" = 200 ] || fail "edition with an issue: $de"
did="$(head -1 <<<"$de" | j 'd["id"]')"; dissue="$(head -1 <<<"$de" | j 'd["issueId"]')"
[ "$dissue" != None ] || fail "EDN-04 issue of the edition"
dc="$(post "$api/editions/$did/fees/presets" '{"institutionType":"university"}')"
dstud="$(head -1 <<<"$dc" | j '[c["id"] for c in d["categories"] if c["code"] == "students"][0]')"
post "$api/editions/$did/payment-rules" '{"mode":"treasury_transfer","paymentAccountId":'"$acc"'}' >/dev/null
for tt in '"oral","300"' '"poster","250"' '"","200"'; do
  IFS=, read -r ptype amount <<<"$tt"
  [ "$(post "$api/editions/$did/fees/tariffs" "{\"categoryId\":$dstud,\"material\":\"abstract\",\"pricing\":\"flat\",\"amount\":$amount,\"currency\":\"UAH\",\"presentationType\":$ptype}" | tail -1)" = 200 ] || fail "tariff $tt"
done
auid="$(lc new-user confa author1 author1@example.org | tail -1)"
decsub() {
  newsub
  curl -s -L -c "$jar" -b "$jar" -o /dev/null "$B/confa/uk/submission?id=$sid"
  form "$api/submissions/$sid/registration" --data "conferenceEditionId=$did&conferenceFeeCategory=$dstud&conferencePresentationType=$1&conferenceParticipationForm=in_person&conferencePresenterEmail=admin%40example.org&conferenceConsent%5B%5D=true" >/dev/null
  [ "$(form "$B/confa/api/v1/submissions/$sid/submit" --data 'confirmCopyright=1' | tail -1)" = 200 ] || fail "submit to dec-2027"
  lc assign-author confa "$sid" "$auid" >/dev/null
}
decsub oral
s1="$sid"; p1="$P"
[ "$(curl -s -b "$jar" "$B/confa/api/v1/submissions/$s1" | j 'sorted(x["id"] for x in d["availableEditorialDecisions"] if x["id"] > 900)')" = "[901, 902, 903, 904]" ] || fail "DEC-01: decisions 901–904 offered in the submission stage"
rec="$(curl -s -L -b "$jar" "$B/confa/uk/decision/record/$s1?decision=902" -w '\n%{http_code}')"
[ "$(tail -1 <<<"$rec")" = 200 ] && grep -q 'Прийняти: стендова доповідь' <<<"$rec" || fail "DEC-01: decision page of 902"
grep -q '"id":"notifyAuthors"' <<<"$rec" && grep -q 'CONF_DECISION_POSTER' <<<"$rec" || fail "decision page uses the conference letter"
tpl="$(curl -s -b "$jar" "$B/confa/api/v1/emailTemplates/CONF_DECISION_POSTER")"
[ "$(j 'd["key"], "{$conferenceName}" in d["body"]["uk"], bool(d["subject"]["en"])' <<<"$tpl")" = "('CONF_DECISION_POSTER', True, True)" ] || fail "default letter installed: $tpl"
[ "$(curl -s -b "$jar" "$B/confa/api/v1/mailables?searchPhrase=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("тип виступу"))')" | j 'len(d) >= 1')" = True ] || fail "mailables listed in settings"
action="$(python3 -c 'import json,sys; t=json.loads(sys.argv[1]); print(json.dumps({"decision":902,"stageId":1,"actions":[{"id":"notifyAuthors","to":[int(sys.argv[2])],"subject":t["subject"]["uk"],"body":t["body"]["uk"],"locale":"uk"}]}))' "$tpl" "$auid")"
dec="$(post "$B/confa/api/v1/submissions/$s1/decisions" "$action")"
[ "$(tail -1 <<<"$dec")" = 200 ] || fail "decision 902: $dec"
[ "$(curl -s -b "$jar" "$B/confa/api/v1/submissions/$s1" | j 'd["stageId"]')" = 4 ] || fail "DEC-02: copyediting after 902"
[ "$(curl -s -b "$jar" "$p1" | j 'd["presentationType"], d["issueId"]')" = "('poster', $dissue)" ] || fail "DEC-02: type and issue on the publication"
[ "$(curl -s -b "$jar" "$api/submissions/$s1/registration" | j 'd["registration"]["presentationType"]')" = poster ] || fail "DEC-02: questionnaire type"
[ "$(curl -s -b "$jar" "$api/editions/$did/invoices" | j '[(i["total"]["minor"], i["status"]) for i in d["items"]]')" = "[(25000, 'issued')]" ] || fail "DEC-02: invoice by the poster tariff"
[ "$(lc email-log "$s1" CONF_DECISION_POSTER | tail -1)" = 1 ] || fail "DEC-02: the letter is in the submission email log"
ok "decision 902: letter, copyediting, type, issue, invoice"

panel="$(curl -s -b "$jar" "$api/submissions/$s1/conference")"
[ "$(j 'd["edition"]["id"], d["presentationType"], d["canChangeType"], d["invoice"]["total"]["minor"], len(d["participants"])' <<<"$panel")" = "($did, 'poster', True, 25000, 2)" ] || fail "DEC-06 panel: $panel"
[ "$(curl -s -b "$jar" "$B/confb/api/v1/conference/submissions/$s1/conference" -o /dev/null -w '%{http_code}')" != 200 ] || fail "panel of another journal"
ct="$(put "$api/submissions/$s1/presentation-type" '{"presentationType":"keynote"}')"
[ "$(tail -1 <<<"$ct")" = 400 ] || fail "bad type: $ct"
ct="$(put "$api/submissions/$s1/presentation-type" '{"presentationType":"oral"}')"
[ "$(tail -1 <<<"$ct")" = 200 ] && [ "$(head -1 <<<"$ct" | j 'd["changed"], d["invoice"], d["panel"]["presentationType"]')" = "(True, 'issued', 'oral')" ] || fail "DEC-05 type change: $ct"
[ "$(curl -s -b "$jar" "$api/editions/$did/invoices" | j 'sorted((i["total"]["minor"], i["status"]) for i in d["items"])')" = "[(25000, 'cancelled'), (30000, 'issued')]" ] || fail "DEC-05: invoice issued anew"
[ "$(curl -s -b "$jar" "$p1" | j 'd["presentationType"]')" = oral ] || fail "DEC-05: publication type"
[ "$(lc email-log "$s1" CONF_TYPE_CHANGED | tail -1)" = 1 ] || fail "DEC-05: letter about the change"
ok "type change after the decision: new invoice, letter; panel"

decsub poster
s2="$sid"
first="$(lc clone-abstracts confa "$s2" 3 "$auid" | grep -E "^[0-9,]+$" | tail -1)"
bulk_ids="$s2,$first"
cand="$(curl -s -b "$jar" "$api/editions/$did/decision-candidates")"
[ "$(j 'sorted(i["submissionId"] for i in d["items"]) == sorted(['"$bulk_ids"']) and len(d["decisions"]) == 5' <<<"$cand")" = True ] || fail "DEC-03 candidates: $cand"
[ "$(curl -s -b "$jar" "$api/editions/$did/decision-candidates?search=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("масового рішення 2"))')" | j 'd["itemsMax"]')" = 1 ] || fail "candidates search"
[ "$(post "$api/editions/$did/decisions/bulk" '{"decision":17,"submissionIds":['"$s2"']}' | tail -1)" = 400 ] || fail "core accept is not a bulk decision"
[ "$(post "$api/editions/$did/decisions/bulk" '{"decision":901,"submissionIds":['"$s1"']}' | tail -1)" = 400 ] || fail "decided submission is not a candidate"
last="${first##*,}"
others="${bulk_ids%,$last}"
bs="$(post "$api/editions/$did/decisions/bulk" '{"decision":903,"submissionIds":['"$others"']}')"
[ "$(tail -1 <<<"$bs")" = 202 ] || fail "DEC-03 start: $bs"
bid="$(head -1 <<<"$bs" | j 'd["batchId"]')"
bd="$(post "$api/editions/$did/decisions/bulk" '{"decision":8,"submissionIds":['"$last"']}')"
[ "$(tail -1 <<<"$bd")" = 202 ] || fail "DEC-04 bulk decline: $bd"
did2="$(head -1 <<<"$bd" | j 'd["batchId"]')"
t0=$(date +%s)
for k in 1 2 3 4 5 6; do docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php lib/pkp/tools/jobs.php run >/dev/null 2>&1 || true; done
st="$(curl -s -b "$jar" "$api/decisions/bulk/$bid")"
[ "$(j 'd["finished"], d["counts"]["done"], d["total"]' <<<"$st")" = "(True, 3, 3)" ] || fail "DEC-03 run: $st $(docker exec "${OJS_CONTAINER:-ojs}" php lib/pkp/tools/jobs.php list 2>&1 | tail -5)"
for s in ${others//,/ }; do
  [ "$(curl -s -b "$jar" "$B/confa/api/v1/submissions/$s" | j 'd["stageId"]')" = 4 ] || fail "DEC-03: submission $s in copyediting"
  [ "$(curl -s -b "$jar" "$api/submissions/$s/registration" | j 'd["registration"]["presentationType"]')" = online ] || fail "DEC-03: type online for $s"
  [ "$(lc email-log "$s" CONF_DECISION_ONLINE | tail -1)" = 1 ] || fail "DEC-03: one letter for $s"
done
[ "$(curl -s -b "$jar" "$api/decisions/bulk/$did2" | j 'd["finished"], d["counts"]["done"]')" = "(True, 1)" ] || fail "DEC-04 run"
[ "$(curl -s -b "$jar" "$B/confa/api/v1/submissions/$last" | j 'd["status"], d["stageId"]')" = "(4, 1)" ] || fail "DEC-04: declined"
[ "$(curl -s -b "$jar" "$api/editions/$did/invoices" | j 'len(d["items"])')" = 5 ] || fail "DEC-03: invoices for the 3 accepted abstracts"
[ "$(curl -s -b "$jar" "$api/editions/$did/decision-candidates" | j 'd["itemsMax"]')" = 0 ] || fail "nothing waits for a decision"
echo "bulk run of 4 decisions took $(( $(date +%s) - t0 )) s"
ok "bulk decisions through the queue, one letter each, bulk decline"

# Sprint 9: reissue, publishing waits for payment, accounting export,
# reminders, mailings, summary, audit log (FEE-08, 11, 12; COM-01…03; RPT-01; DAT-01, 05).
for key in CONF_PARTICIPATION_CONFIRM CONF_INVOICE_ISSUED CONF_REMINDER_PAYMENT CONF_REMINDER_PAPER CONF_REMINDER_EVENT; do
  [ "$(curl -s -b "$jar" "$B/confa/api/v1/emailTemplates/$key" | j 'd["key"], bool(d["body"]["uk"]), bool(d["body"]["en"])')" = "('$key', True, True)" ] || fail "COM-01 letter $key"
done
ok "COM-01: participant letters are editable templates"
lc new-user confa payer4 payer4@example.org >/dev/null
pj4="$(mktemp)"; login "$pj4" payer4
[ "$(curl -s -L -b "$pj4" "$B/confa/uk/conference/pay-2027/register" | grep -c payer4@example.org)" -ge 1 ] || fail "reader session for the access checks"

i1="$(curl -s -b "$jar" "$api/editions/$did/invoices" | j '[i["id"] for i in d["items"] if i["status"] == "issued" and i["total"]["minor"] == 30000][0]')"
[ "$(post "$api/invoices/$i1/reissue" '{}' | tail -1)" = 400 ] || fail "FEE-08: nothing to reissue"
[ "$(post "$api/invoices/$i1/paid" '{"amount":"100"}' | head -1 | j 'd["paid"]["minor"]')" = 10000 ] || fail "part payment of $i1"
pubid="${p1##*/}"
pb="$(curl -s -b "$jar" -X PUT -H "X-Csrf-Token: $csrf" "$p1/publish" -w '\n%{http_code}')"
[ "$(tail -1 <<<"$pb")" = 400 ] && grep -q conferencePayment <<<"$pb" || fail "FEE-11: publishing must wait for the payment: $pb"
ct="$(put "$api/submissions/$s1/presentation-type" '{"presentationType":"poster"}')"
[ "$(tail -1 <<<"$ct")" = 200 ] || fail "type change with a part payment: $ct"
ri="$(curl -s -b "$jar" "$api/editions/$did/invoices" | j 'json.dumps([[i["id"], i["paid"]["minor"], i["supersedesId"]] for i in d["items"] if i["status"] == "issued" and i["total"]["minor"] == 25000])')"
[ "$(j 'd[0][1], d[0][2]' <<<"$ri")" = "(10000, $i1)" ] || fail "FEE-08: paid amount carried to the new invoice: $ri"
i2="$(j 'd[0][0]' <<<"$ri")"
[ "$(curl -s -b "$jar" "$api/invoices/$i2" | j 'len(d["history"]) >= 2')" = True ] || fail "invoice chain"
[ "$(post "$api/invoices/$i2/paid" '{}' | head -1 | j 'd["status"]')" = paid ] || fail "rest paid"
pb="$(curl -s -b "$jar" -X PUT -H "X-Csrf-Token: $csrf" "$p1/publish" -w '\n%{http_code}')"
! grep -q conferencePayment <<<"$pb" || fail "FEE-11: paid, no more block: $pb"
ok "FEE-08 reissue with the paid part carried over; FEE-11 publishing after payment (publication $pubid)"

hd="$(mktemp)"; body="$(mktemp)"
curl -s -b "$jar" -D "$hd" -o "$body" "$api/exports/accounting?format=csv&editionId=$did"
grep -qi 'content-type: text/csv' "$hd" && [ "$(head -c 3 "$body" | od -An -tx1 | tr -d ' ')" = efbbbf ] || fail "FEE-12 CSV: $(cat "$hd")"
rows="$(grep -i '^x-rows:' "$hd" | tr -dc 0-9)"
[ "$rows" -ge 4 ] && grep -q "$(curl -s -b "$jar" "$api/invoices/$i2" | j 'd["number"]')" "$body" || fail "CSV rows: $rows"
curl -s -b "$jar" -o "$body" "$api/exports/accounting?format=xlsx&editionId=$did&withReplaced=1"
python3 -c "import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); assert 'xl/worksheets/sheet1.xml' in z.namelist(); print('xlsx', len(z.read('xl/worksheets/sheet1.xml')))" "$body" || fail "FEE-12 XLSX"
[ "$(curl -s -b "$jar" "$api/exports/accounting?format=csv&from=2026-13-01" -o /dev/null -w '%{http_code}')" = 400 ] || fail "bad export date"
[ "$(put "$api/exports/accounting/columns" '{"columns":["number","payerName","total"]}' | head -1 | j 'd["selected"]')" = "['number', 'payerName', 'total']" ] || fail "export columns"
curl -s -b "$jar" -o "$body" "$api/exports/accounting?format=csv&editionId=$did"
[ "$(sed -n 1p "$body" | tr -d '\r\357\273\277' | awk -F';' '{print NF}')" = 3 ] || fail "chosen columns in the file: $(head -1 "$body")"
[ "$(put "$api/exports/accounting/columns" '{"columns":[]}' | tail -1)" = 400 ] || fail "no columns"
[ "$(curl -s -b "$pj4" "$api/exports/accounting?format=csv" -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader has no export"
rm -f "$hd" "$body"
ok "FEE-12 export to CSV and XLSX with chosen columns"

soon="$(date -u -d '+5 days' +%F)"; start="$(date -u -d '+20 days' +%F)"
re="$(post "$api/editions" '{"path":"remind","status":"cfp_open","eventType":"conference","submissionMode":"none","publicationMode":"none","format":"online","name":{"uk":"Захід із нагадуваннями","en":"Event with reminders"},"startsAt":"'"$start"' 10:00","endsAt":"'"$start"' 18:00","paymentDeadline":"'"$soon"'"}')"
[ "$(tail -1 <<<"$re")" = 200 ] || fail "edition with reminders: $re"
rid="$(head -1 <<<"$re" | j 'd["id"]')"
rother="$(post "$api/editions/$rid/fees/presets" '{"institutionType":"university"}' | head -1 | j '[c["id"] for c in d["categories"] if c["code"] == "other_faculty"][0]')"
post "$api/editions/$rid/fees/tariffs" "{\"categoryId\":$rother,\"material\":\"listener\",\"pricing\":\"flat\",\"amount\":\"100\",\"currency\":\"UAH\"}" >/dev/null
post "$api/editions/$rid/payment-rules" '{"mode":"treasury_transfer","paymentAccountId":'"$acc"'}' >/dev/null
rregp="$B/confa/uk/conference/remind/register"
rtok="$(curl -s -L -c "$pj4" -b "$pj4" "$rregp" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
rcab="$(curl -s -L -c "$pj4" -b "$pj4" "$rregp" --data "csrfToken=$rtok&categoryId=$rother&participationForm=online&consent=1")"
grep -q '100.00 UAH' <<<"$rcab" || fail "listener of the reminder edition: $(sed 's/<[^>]*>/ /g' <<<"$rcab" | tr -s ' \n' | grep -iE 'помил|error|обов' | head -3)"
[ "$(curl -s -b "$jar" "$api/reminders" | j 'd["settings"]["payment"]["enabled"]')" = False ] || fail "COM-03: off by default"
[ "$(put "$api/reminders" '{"payment":{"enabled":true,"days":99}}' | tail -1)" = 400 ] || fail "days out of range"
[ "$(curl -s -b "$pj4" -X PUT -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$api/reminders" -d '{}' -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader cannot change reminders"
[ "$(put "$api/reminders" '{"payment":{"enabled":true,"days":7}}' | head -1 | j 'd["settings"]["payment"]')" = "{'enabled': True, 'days': 7}" ] || fail "save reminders"
rout="$(lc run-reminders-task)"
grep -q 'OK reminders task' <<<"$rout" || fail "reminders task: $rout"
lc run-reminders-task >/dev/null
[ "$(curl -s -b "$jar" "$api/editions/$rid/dashboard" | j 'd["reminders"]["payment"]')" = 1 ] || fail "COM-03: one payment reminder, once"
if [ "$maillog" = 1 ]; then
  grep -q 'payer4@example.org' <<<"$rout" || fail "reminder letter: $(head -c 600 <<<"$rout")"
fi
sch="$(docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php lib/pkp/tools/scheduler.php list 2>&1 || true)"
grep -q 'ReminderTask' <<<"$sch" || echo "note: scheduler list did not show ReminderTask: $(head -c 300 <<<"$sch")"
ok "COM-03 payment reminder once, in the window, off by default"

pv="$(post "$api/editions/$did/campaigns/preview" '{}')"
cnt="$(head -1 <<<"$pv" | j 'd["count"]')"
[ "$cnt" -ge 2 ] || fail "COM-02 preview: $pv"
[ "$(post "$api/editions/$did/campaigns/test" '{"subject":"Програма","body":"Шановні {$unknown}"}' | tail -1)" = 400 ] || fail "unknown variable"
[ "$(post "$api/editions/$did/campaigns/test" '{"subject":"Програма {$conferenceName}","body":"Шановні {$recipientName}, програма в кабінеті: {$cabinetUrl}"}' | tail -1)" = 200 ] || fail "test letter"
cs="$(post "$api/editions/$did/campaigns" '{"subject":"Програма {$conferenceName}","body":"Шановні {$recipientName}, програма в кабінеті: {$cabinetUrl}"}')"
[ "$(tail -1 <<<"$cs")" = 202 ] || fail "COM-02 start: $cs"
cid="$(head -1 <<<"$cs" | j 'd["id"]')"
jout=""
for k in 1 2 3; do jout+="$(docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php lib/pkp/tools/jobs.php run 2>&1 || true)"; done
cst="$(curl -s -b "$jar" "$api/campaigns/$cid")"
[ "$(j 'd["finished"], d["counts"]["sent"], d["total"]' <<<"$cst")" = "(True, $cnt, $cnt)" ] || fail "COM-02 delivery: $cst"
[ "$(curl -s -b "$jar" "$api/editions/$did/campaigns" | j 'len(d["items"])')" = 1 ] || fail "mailings of the edition"
if [ "$maillog" = 1 ]; then
  for e in admin@example.org olena@example.org; do grep -q "$e" <<<"$jout" || fail "mailing letter to $e: $(head -c 600 <<<"$jout")"; done
fi
ok "COM-02 mailing of $cnt letters through the queue, test letter, variables"

db="$(curl -s -b "$jar" "$api/editions/$did/dashboard")"
[ "$(j 'd["submissions"]["total"], d["submissions"]["accepted"], d["submissions"]["declined"], d["submissions"]["waiting"]' <<<"$db")" = "(5, 4, 1, 0)" ] || fail "RPT-01 submissions: $db"
[ "$(j '[p for p in d["payments"] if p["currency"] == "UAH"][0]["paid"]["count"] >= 1' <<<"$db")" = True ] || fail "RPT-01 payments: $db"
[ "$(curl -s -b "$pj4" "$api/editions/$did/dashboard" -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader has no summary"
ok "RPT-01 summary of the edition"

al="$(curl -s -b "$jar" "$api/audit-log?count=200")"
[ "$(j 'all(e in d["entities"] for e in ["campaign", "reminders", "export", "invoice", "edition"]) and d["itemsMax"] > 10' <<<"$al")" = True ] || fail "DAT-05 audit log: $(head -c 400 <<<"$al")"
[ "$(curl -s -b "$jar" "$api/audit-log?entity=campaign" | j 'd["items"][0]["action"], d["items"][0]["actor"]')" = "('started', 'admin')" ] || fail "audit filter"
[ "$(curl -s -b "$pj4" "$api/audit-log" -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader has no audit log"
cv="$(curl -s -b "$jar" "$api/consent-versions")"
[ "$(j 'len(d["items"]) >= 1 and sum(v["agreements"] for v in d["items"]) >= 3 and all(v["texts"] for v in d["items"])' <<<"$cv")" = True ] || fail "DAT-01 consent versions: $(head -c 400 <<<"$cv")"
ok "DAT-05 audit log; DAT-01 consent versions with their texts"

# Sprint 10: attendance, meeting report import, certificates with QR check
# (ATT-01, ATT-02, EVT-05, CRT-01, 02, 04, 05, 07).
rp="$(curl -s -b "$jar" "$api/editions/$rid/participants")"
rpid="$(j '[p["participationId"] for p in d["items"] if p["email"] == "payer4@example.org"][0]' <<<"$rp")"
[ "$(put "$api/editions/$rid/attendance" "{\"participationIds\":[$rpid],\"attended\":true}" | head -1 | j 'd["changed"]')" = 1 ] || fail "ATT-01 mark"
[ "$(curl -s -b "$jar" "$api/editions/$rid/participants?attended=1" | j 'd["itemsMax"], d["items"][0]["attended"], d["items"][0]["attendanceSource"]')" = "(1, True, 'manual')" ] || fail "ATT-01 filter"
[ "$(curl -s -b "$pj4" -X PUT -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$api/editions/$rid/attendance" -d '{}' -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader cannot mark attendance"
ok "ATT-01 manual attendance and the filter"

teams="$(python3 - <<'PY'
import base64
rows = ["1. Summary", "Meeting title\tКонференція", "", "2. Participants",
        "Name\tFirst Join\tLast Leave\tIn-Meeting Duration\tEmail\tRole",
        "Адмін Сайту\t6/20/27, 9:00\t6/20/27, 9:40\t40m 5s\tAdmin@Example.org\tOrganizer",
        "Олена Коваль\t6/20/27, 9:00\t6/20/27, 9:10\t10m\tolena@example.org\tPresenter",
        "Олена Коваль\t6/20/27, 11:00\t6/20/27, 11:15\t15m\tolena@example.org\tPresenter",
        "Гість\t6/20/27, 9:00\t6/20/27, 10:00\t1h\tguest@elsewhere.org\tAttendee"]
print(base64.b64encode(b"\xff\xfe" + "\r\n".join(rows).encode("utf-16-le")).decode())
PY
)"
pv="$(post "$api/editions/$did/attendance/import" "{\"csvBase64\":\"$teams\",\"threshold\":30,\"apply\":false}")"
[ "$(tail -1 <<<"$pv")" = 200 ] || fail "ATT-02 preview: $pv"
[ "$(head -1 <<<"$pv" | j 'd["applied"], d["rows"], d["present"], sorted((m["email"], m["minutes"], m["attended"]) for m in d["matched"]), [u["email"] for u in d["unmatched"]]')" = "(False, 4, 1, [('admin@example.org', 40, True), ('olena@example.org', 25, False)], ['guest@elsewhere.org'])" ] || fail "ATT-02 preview result: $pv"
[ "$(curl -s -b "$jar" "$api/editions/$did/participants?attended=1" | j 'd["itemsMax"]')" = 0 ] || fail "preview saves nothing"
[ "$(post "$api/editions/$did/attendance/import" '{"csv":"name,phone\nA,1","threshold":30}' | tail -1)" = 400 ] || fail "ATT-02 unknown format"
[ "$(post "$api/editions/$did/attendance/import" "{\"csvBase64\":\"$teams\",\"threshold\":0}" | tail -1)" = 400 ] || fail "ATT-02 bad threshold"
ap="$(post "$api/editions/$did/attendance/import" "{\"csvBase64\":\"$teams\",\"threshold\":30,\"apply\":true}")"
[ "$(head -1 <<<"$ap" | j 'd["applied"], d["present"]')" = "(True, 1)" ] || fail "ATT-02 apply: $ap"
[ "$(curl -s -b "$jar" "$api/editions/$did/participants?attended=1" | j 'sorted(set((p["email"], p["attendedMinutes"], p["attendanceSource"]) for p in d["items"]))')" = "[('admin@example.org', 40, 'import')]" ] || fail "ATT-02 saved"
ok "ATT-02/EVT-05 Teams report (UTF-16, two joins added up) with a 30 min threshold: preview, then apply"

tp="$(curl -s -b "$jar" "$api/certificate-templates")"
[ "$(j 'sorted(i["type"] for i in d["items"]), "qrCode" in d["variables"]' <<<"$tp")" = "(['listener', 'organizer', 'participation', 'reviewer', 'speaker'], True)" ] || fail "CRT-01 templates: $(head -c 300 <<<"$tp")"
[ "$(put "$api/certificate-templates/speaker" '{"html":"<p onclick=\"x()\">{recipientName}</p>"}' | tail -1)" = 400 ] || fail "CRT-01 unsafe template"
[ "$(put "$api/certificate-templates/speaker" '{"html":"<p>{nope}</p>"}' | tail -1)" = 400 ] || fail "CRT-01 unknown variable"
[ "$(post "$api/certificate-templates/speaker/preview" '{"html":""}' | head -1 | j '"Олена Петренко" in d["html"] and "data:image/svg+xml;base64," in d["html"]')" = True ] || fail "CRT-01 preview"
[ "$(curl -s -b "$pj4" "$api/certificate-templates" -o /dev/null -w '%{http_code}')" != 200 ] || fail "a reader has no templates"
[ "$(put "$api/certificate-settings" '{"series":"bad series"}' | tail -1)" = 400 ] || fail "CRT-02 bad series"
[ "$(put "$api/certificate-settings" '{"series":"conf"}' | head -1 | j 'd["series"]')" = CONF ] || fail "CRT-02 series"
ok "CRT-01 templates, checks and preview; CRT-02 series"

cc="$(curl -s -b "$jar" "$api/editions/$did/certificate-candidates")"
want="$(j 'json.dumps([c["participationId"] for c in d["items"] if c["type"] and not c["issued"]])' <<<"$cc")"
[ "$(j 'any(c["type"] for c in d["items"])' <<<"$cc")" = True ] || fail "CRT-05 candidates: $(j 'json.dumps([(c["email"], c["role"], c["attended"], c["type"], c["reason"]) for c in d["items"]])' <<<"$cc")"
echo "certificate candidates: $(j 'json.dumps([(c["email"], c["role"], c["type"] or c["reason"]) for c in d["items"]])' <<<"$cc")"
[ "$(j 'all(c["reasonLabel"] for c in d["items"] if not c["type"])' <<<"$cc")" = True ] || fail "CRT-05 reasons"
is="$(post "$api/editions/$did/certificates" "{\"participationIds\":$want}")"
[ "$(head -1 <<<"$is" | j 'len([x for x in d["items"] if x["status"] == "issued"]) >= 1 and all(x["number"].startswith("CONF-") for x in d["items"] if x["status"] == "issued")')" = True ] || fail "CRT-04 issue: $is"
[ "$(post "$api/editions/$did/certificates" "{\"participationIds\":$want}" | head -1 | j 'set(x["reason"] for x in d["items"])')" = "{'alreadyIssued'}" ] || fail "CRT-04 issued once"
cl="$(curl -s -b "$jar" "$api/editions/$did/certificates")"
cert="$(j 'd["items"][0]["id"]' <<<"$cl")"; vurl="$(j 'd["items"][0]["verifyUrl"]' <<<"$cl")"; cnum="$(j 'd["items"][0]["number"]' <<<"$cl")"
[[ "$vurl" =~ /confa/conference/verify/[0-9a-f-]{36}$ ]] || [[ "$vurl" =~ /confa/[a-z]+/conference/verify/[0-9a-f-]{36}$ ]] || fail "CRT-07 verify URL: $vurl"
cpdf="$(mktemp)"
curl -s -L -b "$jar" "$B/confa/uk/conference/certificate/$cert" -D /tmp/cm-ch.txt -o "$cpdf"
grep -qi 'content-type: application/pdf' /tmp/cm-ch.txt && [ "$(head -c 4 "$cpdf")" = '%PDF' ] || fail "CRT-04 PDF: $(head -c 300 "$cpdf")"
pdfinfo "$cpdf" 2>/dev/null | grep -q 'Page size: *841.89 x 595.28' || fail "A4 landscape: $(pdfinfo "$cpdf" 2>&1 | grep -i size)"
[ -n "${CM_SHOTS:-}" ] && cp "$cpdf" "$CM_SHOTS/certificate.pdf"
[ "$(curl -s -L -b "$pj4" "$B/confa/uk/conference/certificate/$cert" -o /dev/null -w '%{http_code}')" = 404 ] || fail "a stranger cannot download the certificate"
vpage="$(curl -s -L "$vurl")"
grep -q "$cnum" <<<"$vpage" && grep -q 'cmVerify--valid' <<<"$vpage" || fail "CRT-07 public check: $(sed 's/<[^>]*>/ /g' <<<"$vpage" | tr -s ' \n' | head -c 400)"
[ "$(curl -s -L "${vurl%/*}/00000000-0000-4000-8000-000000000000" -o /dev/null -w '%{http_code}')" = 404 ] || fail "unknown code"
lim=""
for k in $(seq 1 31); do lim="$(curl -s -L "${vurl%/*}/00000000-0000-4000-8000-00000000000$((k % 10))" -o /dev/null -w '%{http_code}')"; done
[ "$lim" = 429 ] || fail "CRT-07 rate limit: $lim"
ok "CRT-04/05 issue with numbers $cnum…, PDF A4 landscape, access; CRT-07 public check with QR link and rate limit"

[ "$(post "$api/editions/$rid/certificates" "{\"participationIds\":[$rpid]}" | head -1 | j 'd["items"][0]["status"]')" = issued ] || fail "listener certificate"
mycab="$(curl -s -L -b "$pj4" "$B/confa/uk/conference/my")"
mycert="$(grep -o 'conference/certificate/[0-9]*' <<<"$mycab" | head -1)"
[ -n "$mycert" ] || fail "CRT-04 certificate in the cabinet"
[ "$(curl -s -L -b "$pj4" "$B/confa/uk/$mycert" | head -c 4)" = '%PDF' ] || fail "holder downloads the certificate"
ok "CRT-04 listener certificate in the cabinet"

# FEE-05: parallel numbering gives 1…100 with no gaps or repeats.
for k in $(seq 1 10); do lc next-numbers confa 2099 10 > "/tmp/cm-num-$k.txt" & done
wait
[ "$(cat /tmp/cm-num-*.txt | grep -E '^[0-9]+$' | sort -n | tr '\n' ' ')" = "$(seq 1 100 | tr '\n' ' ')" ] || fail "parallel numbers: $(cat /tmp/cm-num-*.txt | sort -n | tr '\n' ' ')"
rm -f /tmp/cm-num-*.txt
ok "100 invoice numbers from 10 parallel processes: 1…100"

modal="$B/confa/\$\$\$call\$\$\$/grid/settings/plugins/settings-plugin-grid/manage?verb=institution&plugin=conferencemanagerplugin&category=generic"
curl -s -b "$jar" "$modal" | j 'd["status"] and "paymentAccounts[0][iban]" in d["content"]' | grep -q True || fail "settings modal"
ok "settings modal"
rm -f "$jar"
echo "API SMOKE OK"
