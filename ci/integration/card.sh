#!/usr/bin/env bash
# PAY-09 in a real OJS, after promo.sh: LiqPay in sandbox mode with test
# keys, a card-payment rule for students of listen-2027, the button in the
# cabinet, the form sent to LiqPay, and signed reports posted to the
# callback page as LiqPay would. Nothing is sent to LiqPay itself.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }
lc() { docker exec -w /var/www/html "${OJS_CONTAINER:-ojs}" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
txt() { sed 's/<[^>]*>/ /g' <<<"$1" | tr -s ' \n' | head -c 500; }

jar="$(mktemp)"; j1="$(mktemp)"
trap 'rm -f "$jar" "$j1"' EXIT
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

PUB="sandbox_i00000000000"
PRIV="sandbox_ci_secret_key"
# A LiqPay report: data = base64(json), signature = base64(sha1(key + data + key)).
signed() {
  python3 - "$1" "$2" <<'EOF'
import base64, hashlib, json, sys, urllib.parse
key, fields = sys.argv[1], json.loads(sys.argv[2])
data = base64.b64encode(json.dumps(fields, separators=(',', ':')).encode()).decode()
sig = base64.b64encode(hashlib.sha1((key + data + key).encode()).digest()).decode()
print(urllib.parse.urlencode({'data': data, 'signature': sig}))
EOF
}

# A rule left by an interrupted run.
for old in $(curl -s -b "$jar" "$A/payment-rules" | py "print(' '.join(str(x['id']) for x in d['rules'] if x['mode'] == 'acquiring'))"); do
  send DELETE "$A/payment-rules/$old" '{}' >/dev/null
done
send PUT "$api/payment-gateway" '{"gateway":""}' >/dev/null

# Off by default; the card mode needs the service first.
curl -s -b "$jar" "$api/payment-gateway" | py "assert d['gateway'] is None and [g['id'] for g in d['gateways']] == ['liqpay'], d" || fail "PAY-09 off by default"
r="$(send POST "$A/payment-rules" "{\"mode\":\"acquiring\",\"categoryId\":$stud}")"
[ "$(tail -1 <<<"$r")" = 400 ] && grep -q 'mode' <<<"$(head -1 <<<"$r")" || fail "PAY-09 the rule without a service: $r"
r="$(send PUT "$api/payment-gateway" '{"gateway":"liqpay","values":{"publicKey":"'"$PUB"'"}}')"
[ "$(tail -1 <<<"$r")" = 400 ] || fail "PAY-09 the private key is required: $r"
r="$(send PUT "$api/payment-gateway" '{"gateway":"liqpay","sandbox":true,"values":{"publicKey":"'"$PUB"'","privateKey":"'"$PRIV"'"}}')"
[ "$(tail -1 <<<"$r")" = 200 ] || fail "PAY-09 the setup: $r"
grep -q "$PRIV" <<<"$r" && fail "PAY-09 the private key came back"
head -1 <<<"$r" | py "assert d['gateway'] == 'liqpay' and d['sandbox'] and d['secretsSet'] == {'privateKey': True}, d" || fail "PAY-09 the setup answer: $r"
r="$(send PUT "$api/payment-gateway" '{"gateway":"liqpay","sandbox":true,"values":{"publicKey":"'"$PUB"'","privateKey":""}}')"
[ "$(tail -1 <<<"$r")" = 200 ] || fail "PAY-09 an empty secret keeps the stored one: $r"
# Security audit 0.33: the private key is stored encrypted, never as it is.
stored="$(docker exec ojsdb sh -c "command -v mariadb >/dev/null && mariadb -uojs -pojs -N -e \"select setting_value from plugin_settings where plugin_name='conferencemanagerplugin' and setting_name='paymentGateway'\" ojs || psql -U ojs -d ojs -tAc \"select setting_value from plugin_settings where plugin_name='conferencemanagerplugin' and setting_name='paymentGateway'\"")"
grep -q 'cmenc1:' <<<"$stored" || fail "PAY-09 the private key must be stored encrypted: $stored"
grep -q "$PRIV" <<<"$stored" && fail "PAY-09 the private key is stored as plain text"
r="$(send POST "$A/payment-rules" "{\"mode\":\"acquiring\",\"categoryId\":$stud}")"
[ "$(tail -1 <<<"$r")" = 200 ] || fail "PAY-09 the card rule: $r"
rule="$(head -1 <<<"$r" | py "print(d['id'])")"
echo "OK PAY-09 setup: off by default, keys kept secret, card rule after the service"

# A student registers online and gets an invoice to pay by card.
u="card$(date +%s)"
lc new-user confa "$u" "$u@example.org" >/dev/null
login "$j1" "$u" reader12345
reg="$B/confa/uk/conference/listen-2027/register"
form="$(curl -s -L -c "$j1" -b "$j1" "$reg")"
loc="$(curl -s -c "$j1" -b "$j1" -o /dev/null -w '%{redirect_url}' "$reg" --data "csrfToken=$(token "$form")&categoryId=$stud&participationForm=online&consent=1")"
[[ "$loc" == *registered=* ]] || fail "PAY-09 registration: $loc"
inv="$(curl -s -b "$j1" "$api/me/invoices" | py "i = [x for x in d['items'] if x['status'] == 'issued'][0]; print(i['id'], i['total']['minor'], i['number'])")"
read -r iid total number <<<"$inv"
[ "$total" = 10050 ] || fail "PAY-09 the invoice total: $inv"
my="$(curl -s -L -c "$j1" -b "$j1" "$B/confa/uk/conference/my")"
grep -q "conference/payment/$iid\"" <<<"$my" && grep -q 'Оплатити карткою' <<<"$my" || fail "PAY-09 the button in the cabinet: $(txt "$my")"
echo "OK PAY-09 the cabinet offers card payment of invoice $number"

# The button: a page with the form signed for LiqPay.
pay="$(curl -s -c "$j1" -b "$j1" "$B/confa/uk/conference/payment/$iid" --data "csrfToken=$(token "$my")")"
grep -q 'action="https://www.liqpay.ua/api/3/checkout"' <<<"$pay" || fail "PAY-09 the pay page: $(txt "$pay")"
data="$(sed -n 's/.*name="data" value="\([^"]*\)".*/\1/p' <<<"$pay" | head -1)"
sig="$(sed -n 's/.*name="signature" value="\([^"]*\)".*/\1/p' <<<"$pay" | head -1)"
order="$(python3 - "$data" "$sig" "$PRIV" "$PUB" "$number" <<'EOF'
import base64, hashlib, json, sys
data, sig, key, pub, number = sys.argv[1:]
assert base64.b64encode(hashlib.sha1((key + data + key).encode()).digest()).decode() == sig, 'signature'
d = json.loads(base64.b64decode(data))
assert d['public_key'] == pub and d['action'] == 'pay' and d['version'] == 3, d
assert d['amount'] == 100.5 and d['currency'] == 'UAH' and d['sandbox'] == '1' and d['language'] == 'uk', d
assert d['server_url'].endswith('/conference/payment/callback/liqpay'), d['server_url']
assert '/conference/payment/' in d['result_url'] and d['result_url'].endswith('/return'), d['result_url']
assert number in d['description'] and d['order_id'].endswith(number + '-1'), d
print(d['order_id'])
EOF
)" || fail "PAY-09 the form data"
[ -n "$order" ] || fail "PAY-09 no order id"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$j1" "$B/confa/uk/conference/payment/$iid")" = 302 ] || fail "PAY-09 GET does not start an attempt"
echo "OK PAY-09 the pay page: form signed for LiqPay, order $order"

# LiqPay's reports to the callback page.
cb="$B/confa/uk/conference/payment/callback/liqpay"
report() { signed "$1" "{\"public_key\":\"$PUB\",\"version\":3,\"order_id\":\"$order\",\"status\":\"$2\",\"amount\":100.5,\"currency\":\"UAH\",\"payment_id\":424242,\"paytype\":\"card\",\"sender_card_mask2\":\"4242*42\"}"; }
code="$(curl -s -o /dev/null -w '%{http_code}' "$cb" --data "$(report wrong_key success)")"
[ "$code" = 400 ] || fail "PAY-09 a forged report: $code"
code="$(curl -s -o /dev/null -w '%{http_code}' "$B/confa/uk/conference/payment/callback/other" --data "$(report "$PRIV" success)")"
[ "$code" = 400 ] || fail "PAY-09 another gateway: $code"
[ "$(curl -s -b "$j1" "$api/me/invoices" | py "print([x['status'] for x in d['items'] if x['id'] == $iid][0])")" = issued ] || fail "PAY-09 paid by a forged report"
out="$(curl -s -w '\n%{http_code}' "$cb" --data "$(report "$PRIV" sandbox)")"
[ "$(tail -1 <<<"$out")" = 200 ] && [ "$(head -1 <<<"$out")" = OK ] || fail "PAY-09 the signed report: $out"
[ "$(curl -s -b "$j1" "$api/me/invoices" | py "print([x['status'] for x in d['items'] if x['id'] == $iid][0])")" = paid ] || fail "PAY-09 the invoice is not paid"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$cb" --data "$(report "$PRIV" sandbox)")" = 200 ] || fail "PAY-09 a repeated report"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$cb" --data "$(report "$PRIV" failure)")" = 200 ] || fail "PAY-09 a late failure"
att="$(curl -s -b "$jar" "$api/invoices/$iid/gateway-payments")"
py "assert [a['status'] for a in d['items']] == ['success'] and d['items'][0]['transactionId'] == '424242', d" <<<"$att" || fail "PAY-09 the attempts: $att"
gp="$(lc gateway-payment "$order")"
py "assert d['paidMinor'] == 10050 and d['status'] == 'success', d; assert 'sender_card' not in d['details'] and '4242*42' not in d['details'], d['details']" <<<"$gp" || fail "PAY-09 paid once, no card data kept: $gp"
echo "OK PAY-09 signed reports: forged ones refused, the invoice paid once, no card data kept"

# Back from LiqPay: to the cabinet with the result; the button is gone.
loc="$(curl -s -c "$j1" -b "$j1" -o /dev/null -w '%{redirect_url}' "$B/confa/uk/conference/payment/$iid/return")"
[[ "$loc" == *card=paid* ]] || fail "PAY-09 the return: $loc"
my="$(curl -s -L -c "$j1" -b "$j1" "$loc")"
grep -q 'Оплату отримано, рахунок оплачено' <<<"$my" || fail "PAY-09 the message in the cabinet: $(txt "$my")"
grep -q "conference/payment/$iid\"" <<<"$my" && fail "PAY-09 the button on a paid invoice"
echo "OK PAY-09 the return page and the cabinet after payment"

# Leave listen-2027 as it was for later scripts and re-runs.
[ "$(tail -1 <<<"$(send DELETE "$A/payment-rules/$rule" '{}')")" = 200 ] || fail "PAY-09 rule cleanup"
r="$(send PUT "$api/payment-gateway" '{"gateway":""}')"
head -1 <<<"$r" | py "assert d['gateway'] is None, d" || fail "PAY-09 turning it off: $r"
echo "OK PAY-09 card payment through LiqPay"
