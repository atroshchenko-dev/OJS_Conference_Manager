#!/usr/bin/env bash
# DEC-07 – DEC-09 in a real OJS, after api-smoke.sh (a complete submission
# of open-call in the submission stage): committee votes and their totals,
# a conflict of interest by affiliation, the decision "abstract accepted,
# send the full text" refused outside the two-phase mode, and in that mode
# the review forms of the abstract and paper rounds.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"
csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/confa/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
[ -n "$csrf" ] || fail "no CSRF token"
api="$B/confa/api/v1/conference"
send() { curl -s -b "$jar" -X "$1" -H "X-Csrf-Token: $csrf" -H 'Content-Type: application/json' "$2" -d "$3" "${@:4}"; }
py() { python3 -c "import json,sys; d=json.load(sys.stdin); $1"; }

eid="$(curl -s -b "$jar" "$api/editions?count=50" | py "print([e['id'] for e in d['items'] if e['path'] == 'open-call'][0])")"
s1="$(curl -s -b "$jar" "$api/editions/$eid/decision-candidates" | py "print(sorted(i['submissionId'] for i in d['items'])[0])")" || fail "a submission of open-call"
[ "$(curl -s -b "$jar" "$api/editions/$eid" | py "print(d['reviewMode'])")" = light ] || fail "open-call is not in the light mode"

# DEC-09: votes of the committee.
r="$(send PUT "$api/submissions/$s1/vote" '{"vote":"for","comment":"Актуальна тема"}')"
py "assert d['review']['counts'] == {'for': 1, 'against': 0, 'abstain': 0}, d['review']; assert d['review']['myVote']['comment'] == 'Актуальна тема'" <<<"$r" || fail "DEC-09 vote: $r"
[ "$(send PUT "$api/submissions/$s1/vote" '{"vote":"maybe"}' -o /dev/null -w '%{http_code}')" = 400 ] || fail "DEC-09 a bad vote accepted"
r="$(send PUT "$api/submissions/$s1/vote" '{"vote":"abstain"}')"
py "assert d['review']['counts'] == {'for': 0, 'against': 0, 'abstain': 1}, d['review']" <<<"$r" || fail "DEC-09 a vote replaced: $r"
curl -s -b "$jar" "$api/editions/$eid/decision-candidates" | py "v=[i['votes'] for i in d['items'] if i['submissionId'] == $s1][0]; assert v == {'for': 0, 'against': 0, 'abstain': 1}, v" || fail "DEC-09 totals on the bulk decision page"
echo "OK DEC-09 a vote with a comment, replaced, a bad one refused, totals for the chair"

# DEC-08: the same affiliation as the author.
curl -s -b "$jar" "$api/submissions/$s1/conference" | py "assert d['review']['conflicts'] == [], d['review']['conflicts']" || fail "DEC-08 no conflict before the affiliations match"
run set-affiliation "$s1" 1 'Університет «Приклад»' >/dev/null
curl -s -b "$jar" "$api/submissions/$s1/conference" | py "c=d['review']['conflicts']; assert len(c) == 1 and c[0]['isViewer'] and 'Приклад' in c[0]['affiliation'], c" || fail "DEC-08 conflict of interest"
echo "OK DEC-08 a warning for an editor with the affiliation of an author"

# DEC-07: the decision is refused outside the two-phase mode.
decide() { send POST "$B/confa/api/v1/submissions/$1/decisions" "$2" "${@:3}"; }
[ "$(decide "$s1" '{"decision":3}' | py "print(d['decision'])")" = 3 ] || fail "DEC-07 send to review"
round1="$(curl -s -b "$jar" "$B/confa/api/v1/submissions/$s1" | py "print(d['reviewRounds'][-1]['id'])")"
code="$(decide "$s1" "{\"decision\":905,\"reviewRoundId\":$round1}" -o /dev/null -w '%{http_code}')"
[ "$code" = 400 ] || fail "DEC-07 the decision in the light mode: $code"

curl -s -b "$jar" "$api/editions/$eid" | py "d['reviewMode'] = 'two_phase'; print(json.dumps(d))" > "$jar.edition"
[ "$(send PUT "$api/editions/$eid" "@$jar.edition" | py "print(d['reviewMode'])")" = two_phase ] || fail "DEC-07 two-phase mode"
rm -f "$jar.edition"
f1="$(run review-form confa 'Abstract review')"
f2="$(run review-form confa 'Paper review')"
[ "$(send PUT "$api/editions/$eid/review-settings" '{"abstractFormId":999999}' -o /dev/null -w '%{http_code}')" = 400 ] || fail "DEC-07 a form of nobody"
send PUT "$api/editions/$eid/review-settings" "{\"abstractFormId\":$f1,\"paperFormId\":$f2}" | py "assert d['settings']['abstractFormId'] == $f1 and d['settings']['paperFormId'] == $f2, d" || fail "DEC-07 forms saved"
[ "$(run assign-reviewer "$s1" 1)" = "round=1 form=$f1" ] || fail "DEC-07 the abstract form in round 1"
r="$(decide "$s1" "{\"decision\":905,\"reviewRoundId\":$round1}")"
[ "$(py "print(d['decision'])" <<<"$r")" = 905 ] || fail "DEC-07 abstract accepted: $r"
curl -s -b "$jar" "$B/confa/api/v1/submissions/$s1" | py "r=d['reviewRounds'][-1]; assert r['statusId'] == 1, r" || fail "DEC-07 the round waits for the full text"
[ "$(decide "$s1" "{\"decision\":14,\"reviewRoundId\":$round1}" | py "print(d['decision'])")" = 14 ] || fail "DEC-07 a new round"
[ "$(run assign-reviewer "$s1" 2)" = "round=2 form=$f2" ] || fail "DEC-07 the paper form in round 2"
curl -s -b "$jar" "$api/submissions/$s1/conference" | py "r=d['review']; assert r['twoPhase'] and not r['voting'], r" || fail "DEC-07 panel"
echo "OK DEC-07 refused in the light mode; in the two-phase mode the abstract form, abstract accepted, a new round with the paper form"
