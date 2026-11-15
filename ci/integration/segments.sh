#!/usr/bin/env bash
# Plan 17.5 in a real OJS, after api-smoke.sh: the configuration matrix.
# For each typical institution in tests/fixtures/segments/ (S1–S5 and the
# generic profile of S6) a new journal gets its institution profile and
# runs the first setup wizard (INS-08) with its preset (DST-07); the
# edition, categories, tariffs and payment rules must match the fixture.
# A broken preset file on the site is reported on the site overview.
#
# DEMO=1 keeps the journals with their editions for a demo site
# (see ci/demo/README.md).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
fail() { echo "FAIL: $*" >&2; exit 1; }
run() { docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
docker cp "$root/ci/integration/lifecycle.php" "$ojs:/var/www/html/cm-lifecycle.php"

jar="$(mktemp)"
tok="$(curl -s -L -c "$jar" -b "$jar" "$B/index/en/login" | sed -n 's/.*name="csrfToken" value="\([^"]*\)".*/\1/p' | head -1)"
curl -s -c "$jar" -b "$jar" -o /dev/null "$B/index/en/login/signIn" --data "csrfToken=$tok&username=admin&password=admin12345"

suffix="${SEGMENT_SUFFIX:-}"
checked=0
for spec in S1:s1:uk S2:s2:uk S3:s3:uk S4:s4:uk S5:s5:uk S6:sgen:en; do
  IFS=: read -r code path locale <<<"$spec"
  run context "$path" "$locale" >/dev/null
  run enable "$path" >/dev/null
  csrf="$(curl -s -L -c "$jar" -b "$jar" "$B/$path/en/management/settings/context" | { grep -o '"csrfToken":"[^"]*"' || true; } | head -1 | cut -d'"' -f4)"
  [ -n "$csrf" ] || fail "$code: no CSRF token in $path"
  api="$B/$path/api/v1/conference"
  python3 - "$root/tests/fixtures/segments/$code.json" "$api" "$csrf" "$jar" "$suffix" "$code" <<'PY' || fail "$code"
import json, os, sys, urllib.error, urllib.request

fixture_file, api, csrf, jar_file, suffix, code = sys.argv[1:7]
f = json.load(open(fixture_file))
if code == 'S6':
    f = f['generic']
# curl writes HttpOnly cookies as "#HttpOnly_host" lines, which the Python
# cookie jar skips as comments: read the jar by hand.
cookies = []
for line in open(jar_file):
    parts = line.rstrip('\n').split('\t')
    if len(parts) == 7 and (not line.startswith('#') or line.startswith('#HttpOnly_')):
        cookies.append(parts[5] + '=' + parts[6])
opener = urllib.request.build_opener()
opener.addheaders = [('Cookie', '; '.join(cookies))]

def call(method, url, body=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(url, data=data, method=method, headers={'X-Csrf-Token': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'})
    try:
        with opener.open(req) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'{}')

def check(ok, what):
    if not ok:
        print(f'{code}: {what}', file=sys.stderr)
        sys.exit(1)

status, _ = call('PUT', api + '/institution', f['institution'])
check(status == 200, f'institution profile ({status})')
status, state = call('GET', api + '/setup')
check(status == 200, 'GET setup')
codes = [p['code'] for p in state['presets']]
check(f['preset'] in codes, f'preset offered: {codes}')
check(all(p['jurisdiction'] in (None, state['jurisdiction']) for p in state['presets']), 'only presets of the jurisdiction')
check('labels' in state and state['labels']['paymentModes'], 'labels for the wizard')

edition = dict(f['edition'])
if suffix:
    edition['path'] = edition['path'] + '-' + suffix
status, result = call('POST', api + '/setup', dict(preset=f['preset'], **edition))
check(status == 201, f'POST setup ({status}): {result}')
expect = f['expect']
e = result['edition']
for k, v in expect['edition'].items():
    check(e[k] == v, f'edition.{k} = {e[k]}')
check(e['status'] == 'draft', 'the edition is a draft')
check(len(result['warnings']) == expect['warnings'], f"warnings {result['warnings']}")
check(all(w['message'] and not w['message'].startswith('plugins.') for w in result['warnings']), 'translated warnings')

status, fees = call('GET', f"{api}/editions/{e['id']}/fees")
check(status == 200, 'fees')
check(len(fees['categories']) == expect['categories'], f"categories {len(fees['categories'])}")
check(all(c['name'] and not any(str(n).startswith('plugins.') for n in c['name'].values()) for c in fees['categories']), 'category names translated')
check(len(fees['tariffs']) == expect['tariffs'], f"tariffs {len(fees['tariffs'])}")
by_id = {c['id']: c['code'] for c in fees['categories']}
status, rules = call('GET', f"{api}/editions/{e['id']}/payment-rules")
check(status == 200, 'payment rules')
items = rules['items'] if isinstance(rules, dict) and 'items' in rules else rules.get('rules', rules)
got = {('*' if r['categoryId'] is None else by_id[r['categoryId']]): r['mode'] for r in items}
check(got == expect['rules'], f'rules {got}')

if os.environ.get('DEMO') == '1':
    # Demo site: open the call so that the public pages show the edition.
    body = {k: v for k, v in e.items() if k not in ('id', 'contextId', 'issueId', 'local', 'allowedStatuses', 'submissionsOpen')}
    body.update(e.get('local') or {})
    body['status'] = 'cfp_open'
    status, opened = call('PUT', f"{api}/editions/{e['id']}", body)
    check(status == 200, f'opening the demo edition ({status}): {opened}')

status, again = call('GET', api + '/setup')
check(again['done'] and again['last']['preset'] == f['preset'], 'the wizard remembers the preset')
PY
  checked=$((checked + 1))
done

# A broken preset file of the site is listed on the site overview.
files="$(docker exec "$ojs" sh -c "sed -n 's/^files_dir *= *//p' /var/www/html/config.inc.php")"
docker exec "$ojs" sh -c "mkdir -p '$files/conferenceManager/presets' && printf '{\"schema\": 1, \"code\": \"Broken\"' > '$files/conferenceManager/presets/broken-example.json' && chown -R www-data '$files/conferenceManager' 2>/dev/null || true"
page="$(curl -s -b "$jar" "$B/index/en/conferenceSite")"
grep -q 'broken-example.json' <<<"$page" || fail "DST-07 the site overview lists a broken preset file"
grep -q 'Presets available: 6' <<<"$page" || fail "DST-07 six presets of the package"
docker exec "$ojs" rm -f "$files/conferenceManager/presets/broken-example.json"

# The editions page loads the wizard.
page="$(curl -s -b "$jar" "$B/s1/en/manageConference")"
grep -q 'js/setupWizard.js' <<<"$page" || fail "INS-08 the wizard script on the editions page"

echo "OK 17.5 configuration matrix: $checked typical institutions set up by the first setup wizard (INS-08) from presets (DST-07); broken site preset reported"
