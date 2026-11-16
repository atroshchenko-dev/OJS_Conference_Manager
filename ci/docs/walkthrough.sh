#!/usr/bin/env bash
# Retakes the screenshots of the step-by-step guide (docs/uk/walkthrough.md)
# in a real OJS with the plugin: creates a fresh journal, walks the whole
# cycle of an event through the interface (ci/docs/walkthrough.js) and
# renders the invoice, certificate, invitation letter and abstract book
# PDFs to images. Run it on a test stand only: it adds a journal and users.
#
#   ci/docs/walkthrough.sh [journal-path]
#
# OJS_PORT (8080) and OJS_CONTAINER (ojs) as in ci/integration; CHROME may
# point to an installed Chrome instead of the Playwright browser, PYTHON to
# a Python with PyMuPDF.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
B="http://localhost:${OJS_PORT:-8080}"
ojs="${OJS_CONTAINER:-ojs}"
path="${1:-guide$(date +%m%d%H%M)}"
out="$root/docs/uk/img/walkthrough"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
run() { MSYS_NO_PATHCONV=1 docker exec -w /var/www/html "$ojs" php cm-lifecycle.php "$@" 2>&1 | grep -v "PHP Deprecated"; }
( cd "$root" && MSYS_NO_PATHCONV=1 docker cp ci/integration/lifecycle.php "$ojs:/var/www/html/cm-lifecycle.php" )
run context "$path" uk
run enable "$path"

tools="${DOCS_TOOLS:-$work/tools}"
mkdir -p "$tools"
if ! node -e "require('playwright')" 2>/dev/null; then
  ( cd "$tools" && { [ -f package.json ] || npm init -y >/dev/null; } && npm i --silent --no-audit --no-fund playwright@1.49.1 >/dev/null )
  [ -n "${CHROME:-}" ] || ( cd "$tools" && npx playwright install chromium >/dev/null )
  export NODE_PATH="$tools/node_modules"
fi
node "$root/ci/docs/walkthrough.js" "$B" "$path" "$out" "$work"

# The documents: first page of each, three pages of the abstract book.
"${PYTHON:-python3}" - "$work" "$out" <<'PY' || echo "PDF pages not rendered: pip install pymupdf"
import os, sys
import fitz
work, out = sys.argv[1:3]
jobs = [('invoice.pdf', 'd01-invoice', [0]), ('certificate.pdf', 'd02-certificate', [0]),
        ('invitation.pdf', 'd03-invitation', [0]), ('abstract-book.pdf', 'd04-abstract-book-p{}', [0, 2, 3])]
for src, name, pages in jobs:
    f = os.path.join(work, src)
    if not os.path.exists(f):
        print('missing', src)
        continue
    doc = fitz.open(f)
    for i in pages:
        if i < doc.page_count:
            doc[i].get_pixmap(dpi=110).save(os.path.join(out, name.format(i + 1) + '.png'))
PY

# 256 colours keep the screenshots sharp at less than half the size.
"${PYTHON:-python3}" - "$out" <<'PY' || echo "PNG files not reduced: pip install pillow"
import os, sys
from PIL import Image
out = sys.argv[1]
for f in os.listdir(out):
    if f.endswith('.png'):
        p = os.path.join(out, f)
        Image.open(p).convert('RGB').quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(p, optimize=True)
PY
echo "Screenshots in $out (journal $path)"
