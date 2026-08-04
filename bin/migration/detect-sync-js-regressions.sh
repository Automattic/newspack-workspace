#!/usr/bin/env bash
#
# detect-sync-js-regressions.sh
#
# Flags incoming legacy commits that (re)introduce JavaScript where the
# monorepo has moved to TypeScript.
#
# The daily sync merges frozen legacy trunks into the monorepo. Once a file
# has been renamed .js -> .ts on the monorepo side, a legacy edit to the old
# .js path can survive the merge as a RE-ADDED .js file sitting next to its
# .ts twin. That is a duplicate-module hazard: webpack resolves one of the two
# and silently ships whichever wins, so the twin case is dangerous in ANY
# unit, mid-sweep or not. Separately, a brand-new legacy .js file landing in a
# unit whose migration is complete (its tsconfig sets "allowJs": false) must
# be converted on the sync branch instead of merged as-is.
#
# A file is flagged when it is added by the integration batch (vs main) and:
#   * a .ts/.tsx twin of it exists in the integrated tree ("ts-twin"), or
#   * it lives in a unit whose tsconfig.json has "allowJs": false
#     ("ts-only-unit").
#
# Output mirrors detect-sync-collisions.sh: one "  REVIEW" line per finding on
# stdout, plus a GitHub step-summary table when $GITHUB_STEP_SUMMARY is set.
# Exit code is always 0 -- the caller decides whether to hold the merge. Run
# from the repo root after `git fetch origin`. Portable to bash 3.2.
#
# Usage:
#   bin/migration/detect-sync-js-regressions.sh [INTEGRATION_REF] [MAIN_REF]
# Defaults: origin/sync/legacy-incoming, origin/main.

set -uo pipefail

INTEGRATION="${1:-origin/sync/legacy-incoming}"
MAIN="${2:-origin/main}"

TMP="$( mktemp -d )"
trap 'rm -rf "$TMP"' EXIT
HITS="$TMP/hits" # "reason|unit|path" lines
: > "$HITS"

# The unit (workspace package) a path belongs to, e.g. plugins/newspack-ads.
unit_of() {
  case "$1" in
    plugins/*/*|themes/*/*|packages/*/*) echo "$1" | cut -d/ -f1-2 ;;
    *) echo "" ;;
  esac
}

# Whether the unit's tsconfig in the integrated tree disables allowJs,
# marking the unit as fully migrated (JS is no longer accepted there).
unit_is_ts_only() {
  local unit=$1 tsconfig
  [ -n "$unit" ] || return 1
  tsconfig=$( git show "$INTEGRATION:$unit/tsconfig.json" 2>/dev/null ) || return 1
  printf '%s' "$tsconfig" | grep -Eq '"allowJs"[[:space:]]*:[[:space:]]*false'
}

git diff --name-status --diff-filter=A "$MAIN" "$INTEGRATION" | cut -f2- | while IFS= read -r f; do
  case "$f" in
    *.js|*.jsx) ;;
    *) continue ;;
  esac
  base="${f%.*}"
  if git cat-file -e "$INTEGRATION:$base.ts" 2>/dev/null || git cat-file -e "$INTEGRATION:$base.tsx" 2>/dev/null; then
    echo "ts-twin|$( unit_of "$f" )|$f"
    continue
  fi
  unit=$( unit_of "$f" )
  if unit_is_ts_only "$unit"; then
    echo "ts-only-unit|$unit|$f"
  fi
done >> "$HITS"

sort -u "$HITS" > "$TMP/uniq" && mv "$TMP/uniq" "$HITS"
n_hits=$( awk 'END{print NR}' "$HITS" )

echo "Sync JS-regression audit: ${n_hits} file(s) to review."
while IFS='|' read -r reason unit path; do
  [ -n "${path:-}" ] || continue
  echo "  REVIEW  $unit  $path  ($reason)"
done < "$HITS"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### Sync JS-regression audit"
    echo
    if [ "$n_hits" -eq 0 ]; then
      echo "No JavaScript reintroduced into TypeScript territory. :white_check_mark:"
    else
      echo "The sync added JavaScript files that collide with the TypeScript migration."
      echo "A \`ts-twin\` file duplicates a module that was renamed to TS (webpack will"
      echo "silently pick one of the two); a \`ts-only-unit\` file landed in a fully"
      echo "migrated unit. Convert the file to TS on the sync branch (porting the legacy"
      echo "edit into the .ts twin where one exists), then merge."
      echo
      echo "| unit | file | reason |"
      echo "| --- | --- | --- |"
      while IFS='|' read -r reason unit path; do
        [ -n "${path:-}" ] || continue
        # A literal backtick in the path would break the code-span below; drop any.
        safe_path=$(printf '%s' "$path" | tr -d '`')
        echo "| $unit | \`$safe_path\` | $reason |"
      done < "$HITS"
    fi
  } >> "$GITHUB_STEP_SUMMARY"
fi

exit 0
