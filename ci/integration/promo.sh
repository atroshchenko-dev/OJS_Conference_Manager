#!/usr/bin/env bash
# REG-11 and REG-12 in a real OJS, after checkin.sh: promo codes of
# listen-2027 through the API, a code at registration and in the cabinet,
# in-person seats with a waiting list, a cancelled seat given to the first
# in the queue with an invoice.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
txt() { sed 's/<[^>]*>/ /g' <<<"$1" | tr -s ' \n' | head -c 500; }

jar="$(mktemp)"; j1="$(mktemp)"; j2="$(mktemp)"
trap 'rm -f "$jar" "$j1" "$j2"' EXIT
login() {
  local t
  t="$(curl -s -L -c "$1" -b "$1" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$1" -b "$1" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$t&username=$2&password=$3"
}
token() { sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' <<<"$1" | head -1; }
login "$jar" admin admin12345
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3" -w '\n%{http_code}'; }
eid="$(curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == 'listen-2027'][0])")"
A="$api/editions/$eid"
stud="$(curl -s -b "$jar" "$A/fees" | py "print([c['id'] for c in d['categories'] if c['code'] == 'students'][0])")"

# REG-11: the manager's codes.
r="$(send POST "$A/promo-codes" '{"code":"x","percent":0}')"
[ "$(tail -1 <<<"$r")" = 400 ] && head -1 <<<"$r" | py "assert 'code' in d and 'percent' in d" || fail "REG-11 validation: $r"
r="$(send POST "$A/promo-codes" '{"code":"half-2027","percent":50,"maxUses":1}')"
[ "$(tail -1 <<<"$r")" = 201 ] || fail "REG-11 a code: $r"
half="$(head -1 <<<"$r" | py "print(d['saved'])")"
r="$(send POST "$A/promo-codes" '{"code":"FIX20","discountType":"amount","amount":"20","currency":"UAH"}')"
[ "$(tail -1 <<<"$r")" = 201 ] || fail "REG-11 a fixed amount: $r"
[ "$(tail -1 <<<"$(send POST "$A/promo-codes" '{"code":"Half-2027","percent":10}')")" = 400 ] || fail "REG-11 a duplicate"
list="$(curl -s -b "$jar" "$A/promo-codes")"
py "c = {x['code']: x for x in d['codes']}; assert c['HALF-2027']['discount'] == '50 %' and c['FIX20']['discount'] == '20.00 UAH', c; assert 'UAH' in d['currencies']" <<<"$list" || fail "REG-11 the list: $list"
echo "OK REG-11 promo codes: validation, percent and amount, no duplicates"

# REG-12: one free in-person seat.
taken="$(py "print(d['taken'])" <<<"$list")"
r="$(send PUT "$A/seats" "{\"seats\":$((taken + 1))}")"
[ "$(tail -1 <<<"$r")" = 200 ] || fail "REG-12 seats: $r"
for u in promo1 promo2; do lc new-user confa "$u" "$u@example.org" >/dev/null || true; done

# The first listener takes the seat with the half-price code.
login "$j1" promo1 reader12345
reg="$B/confa/uk/conference/listen-2027/register"
form="$(curl -s -L -c "$j1" -b "$j1" "$reg")"
grep -q 'name="promoCode"' <<<"$form" || fail "REG-11 the code field: $(txt "$form")"
out="$(curl -s -L -c "$j1" -b "$j1" "$reg" --data "csrfToken=$(token "$form")&categoryId=$stud&participationForm=in_person&consent=1&promoCode=nope")"
grep -q 'Такого промокоду для цього заходу немає' <<<"$out" || fail "REG-11 an unknown code: $(txt "$out")"
loc="$(curl -s -c "$j1" -b "$j1" -o /dev/null -w '%{redirect_url}' "$reg" --data "csrfToken=$(token "$out")&categoryId=$stud&participationForm=in_person&consent=1&promoCode=half-2027")"
[[ "$loc" == *registered=* && "$loc" != *waitlisted* ]] || fail "REG-05 with a code: $loc"
inv="$(curl -s -b "$j1" "$api/me/invoices")"
py "i = d['items'][0]; assert i['total']['minor'] == 5025, i['total']; assert 'HALF-2027' in i['items'][0]['description'], i['items'][0]" <<<"$inv" || fail "REG-11 the invoice with the discount: $(head -c 600 <<<"$inv")"
echo "OK REG-11 a code at registration halves the invoice"

# The second one joins the waiting list: no invoice, the place in the cabinet.
login "$j2" promo2 reader12345
form="$(curl -s -L -c "$j2" -b "$j2" "$reg")"
grep -q 'Місця для очної участі закінчилися' <<<"$form" || fail "REG-12 the note on the form: $(txt "$form")"
loc="$(curl -s -c "$j2" -b "$j2" -o /dev/null -w '%{redirect_url}' "$reg" --data "csrfToken=$(token "$form")&categoryId=$stud&participationForm=in_person&consent=1")"
[[ "$loc" == *waitlisted=1* ]] || fail "REG-12 the waiting list: $loc"
my="$(curl -s -L -c "$j2" -b "$j2" "$loc")"
grep -q 'у листі очікування (місце в черзі: 1)' <<<"$my" && grep -q 'вас додано до листа очікування' <<<"$my" || fail "REG-12 the cabinet: $(txt "$my")"
[ "$(curl -s -b "$j2" "$api/me/invoices" | py "print(len(d['items']))")" = 0 ] || fail "REG-12 no invoice on the waiting list"
list="$(curl -s -b "$jar" "$A/promo-codes")"
py "assert len(d['queue']) == 1 and d['queue'][0]['email'] == 'promo2@example.org', d['queue']" <<<"$list" || fail "REG-12 the queue: $list"
echo "OK REG-12 the waiting list: note on the form, place in the cabinet, no invoice"

# The first cancels: the seat goes to the second, with an invoice.
my="$(curl -s -L -c "$j1" -b "$j1" "$B/confa/uk/conference/my")"
pid1="$(py "print([p['participationId'] for p in d['items'] if p['editionPath'] == 'listen-2027'][0])" <<<"$(curl -s -b "$j1" "$api/me/participations")")"
curl -s -c "$j1" -b "$j1" -o /dev/null "$B/confa/uk/conference/my" --data "csrfToken=$(token "$my")&participationId=$pid1"
list="$(curl -s -b "$jar" "$A/promo-codes")"
py "assert d['queue'] == [] and d['taken'] == d['seats'], (d['taken'], d['seats'], d['queue']); assert [c for c in d['codes'] if c['code'] == 'HALF-2027'][0]['uses'] == 0" <<<"$list" || fail "REG-12 the seat is given: $(head -c 400 <<<"$list")"
inv="$(curl -s -b "$j2" "$api/me/invoices")"
py "assert d['items'][0]['total']['minor'] == 10050, d['items'][0]['total']" <<<"$inv" || fail "REG-12 the invoice after the seat: $(head -c 300 <<<"$inv")"
echo "OK REG-12 a cancelled seat goes to the first in the queue with an invoice"

# REG-11 in the cabinet: the unpaid invoice is issued anew.
my="$(curl -s -L -c "$j2" -b "$j2" "$B/confa/uk/conference/my")"
pid2="$(py "print([p['participationId'] for p in d['items'] if p['editionPath'] == 'listen-2027'][0])" <<<"$(curl -s -b "$j2" "$api/me/participations")")"
grep -q "name=\"promoParticipationId\" value=\"$pid2\"" <<<"$my" || fail "REG-11 the cabinet form: $(txt "$my")"
loc="$(curl -s -c "$j2" -b "$j2" -o /dev/null -w '%{redirect_url}' "$B/confa/uk/conference/my" --data "csrfToken=$(token "$my")&promoParticipationId=$pid2&promoCode=fix20")"
[[ "$loc" == *promo=1* ]] || fail "REG-11 applied in the cabinet: $loc"
inv="$(curl -s -b "$j2" "$api/me/invoices")"
py "cur = [i for i in d['items'] if i['status'] not in ('superseded', 'cancelled')]; assert [i['total']['minor'] for i in cur] == [8050], [(i['status'], i['total']['minor']) for i in d['items']]" <<<"$inv" || fail "REG-11 the new invoice: $(head -c 600 <<<"$inv")"
echo "OK REG-11 a code in the cabinet issues the unpaid invoice anew"

# A used code stays; the limit goes.
[ "$(tail -1 <<<"$(send DELETE "$A/promo-codes/$half" '')")" = 400 ] || fail "REG-11 a used code is kept"
[ "$(tail -1 <<<"$(send PUT "$A/seats" '{"seats":null}')")" = 200 ] || fail "REG-12 no limit"
echo "OK promo codes and waiting list"
