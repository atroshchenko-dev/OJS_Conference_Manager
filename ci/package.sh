#!/usr/bin/env bash
# Builds the installable package: conferenceManager-<release>.tar.gz with a
# single top-level folder "conferenceManager/" (what the OJS upload expects).
# Usage: ci/package.sh [output-dir]
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/dist}"
release="$(sed -n 's:.*<release>\(.*\)</release>.*:\1:p' "$root/version.xml")"
stage="$(mktemp -d)"
mkdir -p "$stage/conferenceManager" "$out"
tar -C "$root" \
  --exclude=./vendor --exclude=./tests --exclude=./ci --exclude=./tools --exclude=./.git --exclude=./.github \
  --exclude=./dist --exclude=./docs/uk/img --exclude=./docs/uk/walkthrough.md --exclude=./node_modules --exclude=./composer.lock --exclude=./composer.json --exclude=./phpunit.xml.dist \
  --exclude=./phpstan.neon.dist --exclude=./.php-cs-fixer.dist.php --exclude=./.phpunit.cache \
  -cf - . | tar -C "$stage/conferenceManager" -xf -
file="$out/conferenceManager-$release.tar.gz"
tar -C "$stage" -czf "$file" conferenceManager
(cd "$out" && md5sum "$(basename "$file")" > "$(basename "$file").md5")
rm -rf "$stage"
echo "$file"
