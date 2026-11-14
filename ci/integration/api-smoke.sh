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

tok="$(curl -s -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -b "$jar" "$B/confa/en/management/settings/context" | grep -o '"csrfToken":"[^"]*"' | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "login"
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

modal="$B/confa/\$\$\$call\$\$\$/grid/settings/plugins/settings-plugin-grid/manage?verb=institution&plugin=conferencemanagerplugin&category=generic"
curl -s -b "$jar" "$modal" | j 'd["status"] and "paymentAccounts[0][iban]" in d["content"]' | grep -q True || fail "settings modal"
ok "settings modal"
rm -f "$jar"
echo "API SMOKE OK"
