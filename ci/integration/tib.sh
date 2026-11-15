#!/usr/bin/env bash
# EDN-10 in a real OJS, last in the chain: TIB Conference (stable-3_5_0,
# commit 88eb249) next to the plugin. An edition with its own issue fills
# the TIB fields of the issue; the issue form of OJS changes the edition;
# without TIB nothing is synced. TIB_DIR may point to a local copy of TIB.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
TIB_COMMIT=88eb2492d46a385330ec224e76be798b812cd107
fail() { echo "FAIL: $*" >&2; exit 1; }
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

tmp="$(mktemp -d)"; jar="$(mktemp)"
trap 'rm -rf "$tmp" "$jar"; run plugin-enabled confa conference 0 >/dev/null 2>&1 || true' EXIT
if [ -n "${TIB_DIR:-}" ]; then
  cp -r "$TIB_DIR" "$tmp/conference"
else
  curl -sSfL "https://codeload.github.com/withanage/conference/tar.gz/$TIB_COMMIT" | tar xz -C "$tmp"
  mv "$tmp/conference-$TIB_COMMIT" "$tmp/conference"
fi
rm -rf "$tmp/conference/.git"
docker exec -u 0 "$ojs" rm -rf /var/www/html/plugins/generic/conference
docker cp "$tmp/conference" "$ojs:/var/www/html/plugins/generic/conference"
docker exec -u 0 "$ojs" chown -R www-data:www-data /var/www/html/plugins/generic/conference 2>/dev/null || true
# Installed as from the plugin gallery: the version is registered.
docker exec -w /var/www/html "$ojs" php lib/pkp/tools/installPluginVersion.php plugins/generic/conference/version.xml >/dev/null 2>&1 || fail "TIB version not registered"

login() {
  local t
  t="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
  curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$t&username=admin&password=admin12345"
}
login
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3"; }
edition() {
  cat <<EOF
{"path":"$1","eventType":"conference","submissionMode":"abstracts_and_papers","publicationMode":"ojs_issue","format":"in_person",
 "timezone":"Europe/Kyiv","status":"draft","name":{"uk":"Конференція TIB $1","en":"TIB conference $1"},
 "venue":{"uk":"Київ, вул. Хрещатик, 1","en":"Kyiv"},"startsAt":"2027-10-14 10:00","endsAt":"2027-10-15 16:30","cfpOpensAt":"2027-05-01","abstractDeadline":"2027-09-01"}
EOF
}

drop() {
  local id
  for p in tib-off tib-2027; do
    id="$(curl -s -b "$jar" "$api/editions?count=100" | py "print(next((e['id'] for e in d['items'] if e['path'] == '$p'), ''))")"
    [ -z "$id" ] || send DELETE "$api/editions/$id" '' >/dev/null
  done
}
drop

# Without TIB: an edition with its own issue, no TIB fields.
r="$(send POST "$api/editions" "$(edition tib-off)")"
off="$(py "print(d['issueId'])" <<<"$r")" || fail "EDN-10 an edition without TIB: $(head -c 300 <<<"$r")"
[ "$(run issue-data "$off")" = '[]' ] || fail "EDN-10 fields written without TIB: $(run issue-data "$off")"
grep -q '"tibSync":false' <<<"$(curl -s -b "$jar" "$B/confa/en/manageConference")" || fail "EDN-10 no note without TIB"

# With TIB: the edition fills the fields of its issue.
run plugin-enabled confa conference 1 | grep -q 'conferenceplugin on' || fail "EDN-10 TIB not enabled"
r="$(send POST "$api/editions" "$(edition tib-2027)")"
eid="$(py "print(d['id'])" <<<"$r")"; iid="$(py "print(d['issueId'])" <<<"$r")"
got="$(run issue-data "$iid")"
py "d.setdefault('conferenceOnline', ''); assert d == {'conferenceDateBegin': '2027-10-14', 'conferenceDateEnd': '2027-10-15', 'conferenceOnline': '', 'conferencePlaceStreet': 'Київ, вул. Хрещатик, 1'}, d" <<<"$got" || fail "EDN-10 the TIB fields of a new edition: $got"
page="$(curl -s -b "$jar" "$B/confa/en/manageConference")"
grep -q '"tibSync":true' <<<"$page" || fail "EDN-10 the note in the edition form"
echo "OK EDN-10 an edition fills the TIB fields of its issue; nothing without TIB"

# The issue form of OJS (with TIB's fields) changes the edition.
form="$B/confa/\$\$\$call\$\$\$/grid/issues/future-issue-grid/update-issue?issueId=$iid"
out="$(curl -s -b "$jar" "$form" --data-urlencode "csrfToken=$csrf" --data-urlencode "title[en]=TIB conference" --data-urlencode "showTitle=1" \
  --data-urlencode "conferenceDateBegin=2027-10-20" --data-urlencode "conferenceDateEnd=2027-10-21" \
  --data-urlencode "conferencePlaceStreet=Львів, пл. Ринок, 1" --data-urlencode "conferencePlaceCity=Львів" \
  --data-urlencode "conferencePlaceCountry=Україна" --data-urlencode "conferenceOnline=1")"
grep -q '"status":true' <<<"$out" || fail "EDN-10 the issue form: $(head -c 300 <<<"$out")"
e="$(curl -s -b "$jar" "$api/editions/$eid")"
py "l = d['local']; assert (l['startsAt'], l['endsAt']) == ('2027-10-20T10:00', '2027-10-21T16:30'), l; assert d['format'] == 'online', d['format']; assert d['venue']['uk'] == 'Львів, пл. Ринок, 1' and d['venue']['en'] == 'Kyiv', d['venue']" <<<"$e" || fail "EDN-10 the edition after the issue form: $(head -c 600 <<<"$e")"
got="$(run issue-data "$iid")"
py "assert d['conferencePlaceCity'] == 'Львів' and d['conferenceDateBegin'] == '2027-10-20' and d['conferenceOnline'] == '1', d" <<<"$got" || fail "EDN-10 the issue keeps TIB's values: $got"
curl -s -b "$jar" "$api/audit-log?entity=edition&action=tib_sync" | py "assert any(str(i.get('entityId')) == '$eid' for i in d['items']), d['items'][:2]" || fail "EDN-10 the audit log"
echo "OK EDN-10 the issue form changes the edition: dates with their times, format and venue; city and country stay with TIB"

# An end before the start in the issue form is not taken by the edition.
curl -s -b "$jar" -o /dev/null "$form" --data-urlencode "csrfToken=$csrf" --data-urlencode "title[en]=TIB conference" --data-urlencode "showTitle=1" \
  --data-urlencode "conferenceDateBegin=2027-10-20" --data-urlencode "conferenceDateEnd=2027-10-01" --data-urlencode "conferencePlaceStreet=Львів, пл. Ринок, 1" \
  --data-urlencode "conferencePlaceCity=Львів" --data-urlencode "conferencePlaceCountry=Україна" --data-urlencode "conferenceOnline=1"
curl -s -b "$jar" "$api/editions/$eid" | py "assert d['local']['endsAt'] == '2027-10-21T16:30', d['local']" || fail "EDN-10 a bad end date taken"

# Editing the edition in the plugin brings the issue back in step.
e="$(curl -s -b "$jar" "$api/editions/$eid")"
body="$(py "d.pop('local', None); d['startsAt'] = '2027-11-03 09:30'; d['endsAt'] = '2027-11-04 15:00'; d['format'] = 'hybrid'; print(json.dumps(d))" <<<"$e")"
send PUT "$api/editions/$eid" "$body" | py "assert d['id']" || fail "EDN-10 the edition saved"
got="$(run issue-data "$iid")"
py "assert (d['conferenceDateBegin'], d['conferenceDateEnd'], d.get('conferenceOnline', ''), d['conferencePlaceCity']) == ('2027-11-03', '2027-11-04', '', 'Львів'), d" <<<"$got" || fail "EDN-10 the issue after the edition: $got"
echo "OK EDN-10 an edit of the edition is written to the issue; a bad date from the issue form is not taken"

drop
echo "OK TIB Conference"
