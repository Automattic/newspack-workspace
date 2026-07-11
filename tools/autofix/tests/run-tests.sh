#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"
pattern="${1:-test-*.sh}"
rc=0
for t in $pattern; do
  [ -f "$t" ] || { echo "no tests match: $pattern"; exit 1; }
  echo "== $t"
  bash "$t" || rc=1
done
exit "$rc"
