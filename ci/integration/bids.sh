#!/usr/bin/env bash
# DEC-10 in a real OJS, after review.sh (open-call in the two-phase mode,
# its first submission in review round 2 with one reviewer): reviewers of
# the journal mark which submissions they want, the manager gets the
# proposed distribution and assigns it; the assignment has due dates,
# the files of the round and the reviewer's task.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
txt() { sed 's/<[^>]*>/ /g' <<<"$1" | tr -s ' \n' | head -c 600; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

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
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3" "${@:4}"; }
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }

eid="$(curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == 'open-call'][0])")"
A="$api/editions/$eid"
page="$B/confa/uk/conference/open-call/bids"
for u in bid1 bid2; do run new-user confa "$u" "$u@example.org" reviewer >/dev/null || true; done
id1="$(curl -s -b "$jar" "$A/bids" | py "print([r['userId'] for r in d['reviewers'] if r['email'] == 'bid1@example.org'][0])")" || fail "DEC-10 reviewers of the journal"

# Off until the manager opens it.
login "$j1" bid1 reader12345
[ "$(curl -s -b "$j1" -o /dev/null -w '%{http_code}' "$page")" = 404 ] || fail "DEC-10 a page before the choice opens"
until="$(date -u -d '+7 days' +%F)"
[ "$(send PUT "$A/bids/settings" '{"biddingUntil":"2027-02-31"}' -o /dev/null -w '%{http_code}')" = 400 ] || fail "DEC-10 a bad date"
r="$(send PUT "$A/bids/settings" "{\"biddingUntil\":\"$until\",\"reviewersPerSubmission\":2}")"
py "assert d['open'] and d['settings'] == {'biddingUntil': '$until', 'reviewersPerSubmission': 2}, d['settings']" <<<"$r" || fail "DEC-10 settings: $(head -c 300 <<<"$r")"
curl -s -b "$jar" "$A/review-settings" | py "assert d['settings']['abstractFormId'], d" || fail "DEC-10 the forms of DEC-07 are kept"
s1="$(py "print([s['id'] for s in d['submissions'] if s['inReview']][0])" <<<"$r")" || fail "DEC-10 a submission in review: $(head -c 300 <<<"$r")"
echo "OK DEC-10 settings: off by default, a bad date refused, the review forms kept"

# Reviewers choose; a manager who is not a reviewer gets nothing.
[ "$(curl -s -b "$jar" -o /dev/null -w '%{http_code}' "$page")" = 404 ] || fail "DEC-10 the page for a non-reviewer"
form="$(curl -s -L -c "$j1" -b "$j1" "$page")"
grep -q "name=\"bid\[$s1\]\" value=\"eager\"" <<<"$form" || fail "DEC-10 the reviewer's page: $(txt "$form")"
if grep -q 'cmreviewer\|petro@example.org' <<<"$form"; then fail "DEC-10 authors or reviewers shown to a reviewer"; fi
loc="$(curl -s -c "$j1" -b "$j1" -o /dev/null -w '%{redirect_url}' "$page" --data "csrfToken=$(token "$form")&bid[$s1]=eager")"
[[ "$loc" == *saved=1* ]] || fail "DEC-10 a bid saved: $loc"
grep -q "value=\"eager\" checked" <<<"$(curl -s -b "$j1" "$page")" || fail "DEC-10 the bid is shown back"
login "$j2" bid2 reader12345
form="$(curl -s -L -c "$j2" -b "$j2" "$page")"
curl -s -c "$j2" -b "$j2" -o /dev/null "$page" --data "csrfToken=$(token "$form")&bid[$s1]=willing"
grep -q 'Вибір подань для рецензування' <<<"$(curl -s -b "$j2" "$B/confa/uk/conference/my")" || fail "DEC-10 the cabinet link"
echo "OK DEC-10 reviewers choose on their page without author names; the cabinet links to it"

# The distribution: one more reviewer is needed, the eager one goes first.
r="$(curl -s -b "$jar" "$A/bids")"
py "p=[x for x in d['proposal'] if x['submissionId'] == $s1]; assert [(x['userId'], x['bid']) for x in p] == [($id1, 'eager')], p" <<<"$r" || fail "DEC-10 the proposal: $(head -c 600 <<<"$r")"
r="$(send POST "$A/bids/assign" "{\"pairs\":[{\"submissionId\":$s1,\"userId\":$id1},{\"submissionId\":$s1,\"userId\":1}]}")"
py "assert d['assigned'] == 1 and d['skipped'] == 1, d; assert not [x for x in d['proposal'] if x['submissionId'] == $s1], d['proposal']" <<<"$r" || fail "DEC-10 assigned: $(head -c 400 <<<"$r")"
a="$(run review-of "$s1" bid1@example.org)"
[[ "$a" == round=2* && "$a" == *due=yes* && "$a" != *notified=0* ]] || fail "DEC-10 the OJS assignment: $a"
files="${a#*files=}"; files="${files%% *}"
[ "${files%/*}" = "${files#*/}" ] || fail "DEC-10 the files of the round: $a"
curl -s -b "$jar" "$A/bids" | py "s=[x for x in d['submissions'] if x['id'] == $s1][0]; assert len(s['assigned']) == 2, s" || fail "DEC-10 assigned shown"
echo "OK DEC-10 the proposal assigned in OJS with due dates, the round's files ($files) and the task"

r="$(send POST "$A/bids/invite" '{}')"
py "assert d['sent'] >= 2, d" <<<"$r" || fail "DEC-10 invitation: $r"
send PUT "$A/bids/settings" '{"biddingUntil":null}' | py "assert not d['open']" || fail "DEC-10 off again"
[ "$(curl -s -b "$j1" -o /dev/null -w '%{http_code}' "$page")" = 404 ] || fail "DEC-10 the page after it is off"
# Open again for the accessibility check of the reviewer's page.
send PUT "$A/bids/settings" "{\"biddingUntil\":\"$until\"}" >/dev/null
echo "OK reviewer bids"
