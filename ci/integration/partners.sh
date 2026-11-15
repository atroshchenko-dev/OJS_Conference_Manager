#!/usr/bin/env bash
# INS-04 in a real OJS, after reports.sh: co-organisers and partners of an
# edition, a logo in the public files folder, the order, the account of a
# co-organiser, the public page with JSON-LD, and a copy with the logos.
set -euo pipefail
B="http://localhost:${OJS_PORT:-8080}"
fail() { echo "FAIL: $*" >&2; exit 1; }

jar="$(mktemp)"; png="$(mktemp --suffix=.png)"; svg="$(mktemp --suffix=.svg)"; out="$(mktemp)"
trap 'rm -f "$jar" "$png" "$svg" "$out"' EXIT
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3"; }
edition() { curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == '$1'][0])"; }
pay="$(edition pay-2027)"

# A "partner" payment account in the profile of the journal.
profile="$(curl -s -b "$jar" "$api/institution")"
body="$(py "
def iban(bank, acc):
    bban = bank + acc
    n = int(''.join(str(int(c, 36)) for c in bban + 'UA00'))
    return 'UA%02d%s' % (98 - n % 97, bban)
for k in ('id', 'contextId', 'logoPath', 'sealPath'): d.pop(k, None)
if not any(a['kind'] == 'partner' for a in d['paymentAccounts']):
    d['paymentAccounts'].append({'kind': 'partner', 'label': 'ГО «Наука»', 'recipientName': 'ГО «Наука»', 'iban': iban('305299', '0000026001234567890'), 'bankName': 'Банк'})
print(json.dumps(d))" <<<"$profile")"
r="$(send PUT "$api/institution" "$body")"
account="$(py "print([a['id'] for a in d['paymentAccounts'] if a['kind'] == 'partner'][0])" <<<"$r")" || fail "INS-04 a partner account: $r"

# Start clean, then two partners.
for id in $(curl -s -b "$jar" "$api/editions/$pay/partners" | py "print(' '.join(str(p['id']) for p in d['partners']))"); do
  send DELETE "$api/editions/$pay/partners/$id" '' >/dev/null
done
r="$(send POST "$api/editions/$pay/partners" '{"role":"sponsor","name":{"uk":"Спонсор","en":"Sponsor"},"url":"javascript:alert(1)"}')"
py "assert 'url' in d, d" <<<"$r" || fail "INS-04 a bad address: $r"
r="$(send POST "$api/editions/$pay/partners" "{\"role\":\"sponsor\",\"name\":{\"uk\":\"Спонсор\"},\"paymentAccountId\":$account}")"
py "assert 'paymentAccountId' in d, d" <<<"$r" || fail "INS-04 an account only for a co-organiser: $r"
r="$(send POST "$api/editions/$pay/partners" '{"role":"sponsor","name":{"uk":"Фонд «Освіта»","en":"Education Fund"},"url":"https://fund.example.org"}')"
sponsor="$(py "print(d['saved'])" <<<"$r")"
r="$(send POST "$api/editions/$pay/partners" "{\"role\":\"co_organizer\",\"name\":{\"uk\":\"ГО «Наука»\"},\"url\":\"https://nauka.example.org\",\"paymentAccountId\":\"$account\"}")"
coorg="$(py "print(d['saved'])" <<<"$r")"
py "p=[p for p in d['partners'] if p['id'] == $coorg][0]; assert p['paymentAccountName'] == 'ГО «Наука»' and d['accounts'], d" <<<"$r" || fail "INS-04 the co-organiser's account: $r"
r="$(send POST "$api/editions/$pay/partners/$coorg/move" '{"direction":-1}')"
py "assert [p['id'] for p in d['partners']] == [$coorg, $sponsor], d" <<<"$r" || fail "INS-04 the order: $r"
echo "OK INS-04 partners with roles, order and the co-organiser's account"

# Logos: an SVG is refused, a PNG goes to the public files folder.
printf '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>' >"$svg"
python3 -c "
import struct, zlib, sys
def chunk(t, b): return struct.pack('>I', len(b)) + t + b + struct.pack('>I', zlib.crc32(t + b) & 0xffffffff)
raw = b''.join(b'\x00' + b'\x00\x67\x98' * 40 for _ in range(20))
open(sys.argv[1], 'wb').write(b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 40, 20, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))" "$png"
code="$(curl -s -b "$jar" -H "X-Csrf-Token: $csrf" -F "logo=@$svg;type=image/png" -o "$out" -w '%{http_code}' "$api/editions/$pay/partners/$coorg/logo")"
[ "$code" = 400 ] && grep -q '"logo"' "$out" || fail "INS-04 an SVG must be refused: $code $(cat "$out")"
r="$(curl -s -b "$jar" -H "X-Csrf-Token: $csrf" -F "logo=@$png" "$api/editions/$pay/partners/$coorg/logo")"
logo="$(py "print([p['logoUrl'] for p in d['partners'] if p['id'] == $coorg][0])" <<<"$r")" || fail "INS-04 the logo: $r"
h="$(curl -s -D - -o /dev/null "$logo")"
grep -qi '^content-type: image/png' <<<"$h" || fail "INS-04 the logo is served: $logo $h"
echo "OK INS-04 a PNG logo is public, an SVG is refused"

# The public page groups them, co-organisers first, and names them in JSON-LD.
page="$(curl -s -L "$B/confa/uk/conference/pay-2027")"
grep -q 'cmEdition__partners' <<<"$page" || fail "INS-04 the public section"
first="$(grep -o 'Співорганізатори\|Спонсори' <<<"$page" | head -1)"
[ "$first" = Співорганізатори ] || fail "INS-04 co-organisers first: $first"
grep -q "$logo" <<<"$page" || fail "INS-04 the logo on the page"
grep -q 'приймає оргвнески' <<<"$page" || fail "INS-04 who receives the fees"
ld="$(python3 -c "
import re, sys, json
m = re.search(r'<script type=\"application/ld\+json\">(.*?)</script>', sys.stdin.read(), re.S)
d = json.loads(m.group(1))
names = [o['name'] for o in d['organizer']] if isinstance(d['organizer'], list) else [d['organizer']['name']]
print('ok' if 'ГО «Наука»' in names and d['sponsor']['name'] == 'Фонд «Освіта»' else d)" <<<"$page")"
[ "$ld" = ok ] || fail "INS-04 JSON-LD: $ld"
echo "OK INS-04 the public page and JSON-LD"

# EDN-09: a copy takes the partners with their own logo files.
path="pay-copy-$RANDOM"
src="$(curl -s -b "$jar" "$api/editions/$pay")"
body="$(py "
local = d.get('local') or {}
for k in ('id', 'issueId', 'local', 'isbn', 'programPublished'): d.pop(k, None)
dates = dict(startsAt='2028-06-18T09:00', endsAt='2028-06-19T18:00', cfpOpensAt='2028-01-15T00:00', abstractDeadline='2028-03-01T00:00', paperDeadline='2028-04-01T00:00', registrationClosesAt='2028-06-10T00:00', paymentDeadline='2028-06-10T00:00')
d.update({k: (v if local.get(k) else '') for k, v in dates.items()})
d.update(path='$path', status='draft', copyParts=['partners'])
print(json.dumps(d))" <<<"$src")"
r="$(send POST "$api/editions/$pay/copy" "$body")"
py "assert d['copied']['partners'] == 2 and d['copied']['categories'] == 0, d" <<<"$r" || fail "INS-04 the copy: $r"
new="$(py "print(d['id'])" <<<"$r")"
r="$(curl -s -b "$jar" "$api/editions/$new/partners")"
newlogo="$(py "p=d['partners'][0]; assert p['localizedName'] == 'ГО «Наука»'; print(p['logoUrl'])" <<<"$r")" || fail "INS-04 the copied partners: $r"
[ "$newlogo" != "$logo" ] || fail "INS-04 the copy has its own logo file"
send DELETE "$api/editions/$new/partners/$(py "print(d['partners'][0]['id'])" <<<"$r")" '' >/dev/null
[ "$(curl -s -o /dev/null -w '%{http_code}' "$newlogo")" = 404 ] || fail "INS-04 the copy's logo is removed with it"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$logo")" = 200 ] || fail "INS-04 the source logo stays"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$api/editions/$pay/partners")" != 200 ] || fail "INS-04 needs sign-in"
echo "OK EDN-09 a copy with the partners and their logos"
