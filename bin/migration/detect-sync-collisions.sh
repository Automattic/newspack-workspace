#!/usr/bin/env bash
#
# detect-sync-collisions.sh
#
# Flags silent bad-merges in the legacy -> monorepo sync.
#
# The daily sync 3-way-merges each frozen legacy trunk (sync/<repo>) into the
# monorepo. When a file was edited on BOTH sides -- by monorepo-native work AND
# by an incoming legacy commit -- git usually merges it with no conflict marker,
# but the result can match NEITHER side: a fix dropped, a variable orphaned, two
# rewrites of one function spliced together. Conflicts already escalate to a
# draft PR; these silent blends do not. This script surfaces them.
#
# A file is flagged when ALL hold:
#   * it differs between the integration branch and main (it's in this batch),
#   * a legacy sync/<repo> branch and main BOTH changed it since their merge
#     base (a real 3-way merge happened), and
#   * the integrated blob equals NEITHER main's nor legacy's (a true blend, not
#     a clean pick of one side).
# Generated files (lockfiles, .pot, etc.) are tagged, not hidden -- they blend
# every run and are noise.
#
# A flag is a REVIEW candidate, not a proven bug: an orthogonal blend (e.g. a
# monorepo import-path codemod + an unrelated legacy logic change) merges
# correctly and matches neither side legitimately. A human confirms whether the
# two edits overlap. The real harm is when they touch the same lines.
#
# Output: one line per flagged file on stdout, plus a GitHub step-summary table
# when $GITHUB_STEP_SUMMARY is set. Exit code is always 0 -- this is an
# annotation, never a gate. Run from the repo root after `git fetch origin`.
# Portable to bash 3.2 (macOS) and the CI runner alike.
#
# Usage:
#   bin/migration/detect-sync-collisions.sh [INTEGRATION_REF] [MAIN_REF]
# Defaults: origin/sync/legacy-incoming, origin/main.

set -uo pipefail

INTEGRATION="${1:-origin/sync/legacy-incoming}"
MAIN="${2:-origin/main}"

TMP="$( mktemp -d )"
trap 'rm -rf "$TMP"' EXIT
BATCH="$TMP/batch"       # files in this integration delta (membership lookup)
HITS="$TMP/hits"         # accumulated "tag|repo|path" lines
: > "$HITS"

is_generated() {
  case "$1" in
    *.pot|*.po|*-lock.json|*/package-lock.json|pnpm-lock.yaml|*/package.json) return 0 ;;
    */readme.txt|*/README.md|*/.gitignore|*/.eslintrc.js) return 0 ;;
    *) return 1 ;;
  esac
}

git diff --name-only "$MAIN" "$INTEGRATION" | sort -u > "$BATCH"
if [ ! -s "$BATCH" ]; then
  echo "No integration delta vs $MAIN; nothing to check."
  exit 0
fi

for syncref in $( git for-each-ref --format='%(refname:short)' 'refs/remotes/origin/sync/*' ); do
  case "$syncref" in */sync/legacy-incoming) continue ;; esac
  # Only branches actually folded into this integration are relevant.
  git merge-base --is-ancestor "$syncref" "$INTEGRATION" 2>/dev/null || continue
  base=$( git merge-base "$MAIN" "$syncref" ) || continue
  repo="${syncref#origin/sync/}"

  # Files this legacy branch changed since the merge base (its incoming edits).
  git diff --name-only "$base" "$syncref" | while IFS= read -r f; do
    [ -n "$f" ] || continue
    grep -qxF "$f" "$BATCH" || continue                            # in this batch
    # Main must have changed it too, since the same base -> a real 3-way merge.
    git diff --quiet "$base" "$MAIN" -- "$f" 2>/dev/null && continue

    leg=$( git rev-parse "$syncref:$f"     2>/dev/null ) || continue
    mn=$(  git rev-parse "$MAIN:$f"        2>/dev/null ) || continue
    mrg=$( git rev-parse "$INTEGRATION:$f" 2>/dev/null ) || continue

    # Blend: integrated matches neither side.
    if [ "$mrg" != "$leg" ] && [ "$mrg" != "$mn" ]; then
      if is_generated "$f"; then echo "generated|$repo|$f"; else echo "REVIEW|$repo|$f"; fi
    fi
  done >> "$HITS"
done

# Dedupe; a REVIEW hit outranks a generated hit for the same path.
sort -u "$HITS" > "$TMP/uniq" && mv "$TMP/uniq" "$HITS"
grep '^REVIEW|'    "$HITS" | sed 's/^REVIEW|//'    | sort -u > "$TMP/review"    || true
grep '^generated|' "$HITS" | sed 's/^generated|//' | sort -u > "$TMP/generated" || true
# Drop generated entries that are also REVIEW (shouldn't happen, but be safe).
n_review=$(   awk 'END{print NR}' "$TMP/review"    )
n_generated=$( awk 'END{print NR}' "$TMP/generated" )

echo "Sync collision audit: ${n_review} file(s) to review, ${n_generated} generated/benign."
while IFS='|' read -r repo path; do
  [ -n "${repo:-}" ] || continue
  echo "  REVIEW  $repo  $path"
done < "$TMP/review"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### Sync collision audit"
    echo
    if [ "$n_review" -eq 0 ]; then
      echo "No silent blends detected in this batch. :white_check_mark:"
    else
      echo "These files were merged by combining monorepo-native and incoming legacy"
      echo "edits (the merged result matches neither side). Confirm the two edits do not"
      echo "overlap -- an orphaned variable or a dropped fix means a bad merge."
      echo
      echo "| repo | file |"
      echo "| --- | --- |"
      while IFS='|' read -r repo path; do
        [ -n "${repo:-}" ] || continue
        echo "| $repo | \`$path\` |"
      done < "$TMP/review"
    fi
    if [ "$n_generated" -gt 0 ]; then
      echo
      echo "<details><summary>${n_generated} generated/benign file(s) (blend every run)</summary>"
      echo
      while IFS='|' read -r repo path; do
        [ -n "${path:-}" ] || continue
        echo "- \`$path\`"
      done < "$TMP/generated"
      echo
      echo "</details>"
    fi
  } >> "$GITHUB_STEP_SUMMARY"
fi

exit 0
