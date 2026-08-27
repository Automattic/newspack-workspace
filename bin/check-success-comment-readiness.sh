#!/usr/bin/env bash
# Are we ready to re-enable @semantic-release/github successComment/releasedLabels?
#
# During the legacy->monorepo migration those options are disabled in the
# release configs (config/release.js, themes/*/release config): the success
# step resolves every issue/PR ref in a released commit to comment on and label
# it, and migrated commits carry legacy-repo PR numbers that 404 in the
# monorepo, which fails the release job.
#
# No producer adds legacy commits to the monorepo any more, so the only open
# question is whether the commits already queued for the next release carry an
# unresolvable number. Re-enabling is safe when none do.
#
# Temporary tooling for NPPM-2752 -- remove in the cleanup phase once the
# success comment/label is re-enabled.
set -euo pipefail

REPO=Automattic/newspack-workspace
RANGE="${1:-origin/release..origin/main}"   # publish queue for the next stable release

git fetch -q origin release main alpha

# Scan the actual commit range for unresolvable refs.
stale=0
# `&#8217;`-style HTML entities also match `#<digits>`; semantic-release does not
# read those as issue refs, so neither does this.
for n in $(git log "$RANGE" --format='%s%n%b' \
  | grep -oE '(^|[^&])#[0-9]+' | grep -oE '[0-9]+' | sort -un); do
  if ! gh api "repos/$REPO/issues/$n" >/dev/null 2>&1; then   # PRs are issues; 404 = stale
    echo "  STALE #$n"
    stale=$((stale + 1))
  fi
done
echo "stale refs in $RANGE: $stale"

if [[ "$stale" -eq 0 ]]; then
  echo "READY: safe to re-enable successComment/releasedLabels."
else
  echo "NOT READY."
  exit 1
fi
