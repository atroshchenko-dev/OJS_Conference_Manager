#!/usr/bin/env bash
# NFR-07 in a real OJS, after reports.sh: axe-core on the plugin's public
# pages and the manager's screens (ci/integration/a11y.js).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
tools="${A11Y_TOOLS:-$(mktemp -d)}"
( cd "$tools" && [ -f package.json ] || npm init -y >/dev/null )
( cd "$tools" && npm i --silent --no-audit --no-fund axe-core@4.10.2 >/dev/null )
if ! node -e "require('playwright')" 2>/dev/null; then
  ( cd "$tools" && npm i --silent --no-audit --no-fund playwright@1.49.1 >/dev/null && npx playwright install --with-deps chromium >/dev/null )
  export NODE_PATH="$tools/node_modules"
fi
node "$root/ci/integration/a11y.js" "$B" "$tools/node_modules/axe-core/axe.min.js"
