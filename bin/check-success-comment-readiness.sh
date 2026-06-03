#!/usr/bin/env bash
# Are we ready to re-enable @semantic-release/github successComment/releasedLabels?
#
# During the legacy->monorepo migration those options are disabled in the
# release configs (config/release.js, themes/*/release config): the success
# step resolves every issue/PR ref in a released commit to comment on and label
# it, and migrated commits carry legacy-repo PR numbers that 404 in the
# monorepo, which fails the release job.
#
# Re-enabling is safe when BOTH hold:
#   1. the sync producer is off (nothing can refill the queue), and
#   2. no commit queued for the next release references an unresolvable number.
#
# Temporary tooling for NPPM-2752 -- remove in the cleanup phase once the
# success comment/label is re-enabled.
set -euo pipefail

REPO=Automattic/newspack-workspace
RANGE="${1:-origin/release..origin/main}"   # publish queue for the next stable release

git fetch -q origin release main alpha

# (1) Can the queue still refill?
sync_state=$(gh workflow list --repo "$REPO" --json name,state \
  --jq '.[] | select(.name|test("Sync legacy")) | .state')
echo "Sync legacy repos: ${sync_state:-not found}"

# (2) Scan the actual commit range for unresolvable refs.
stale=0
for n in $(git log "$RANGE" --format='%s%n%b' | grep -oE '#[0-9]+' | tr -d '#' | sort -un); do
  if ! gh api "repos/$REPO/issues/$n" >/dev/null 2>&1; then   # PRs are issues; 404 = stale
    echo "  STALE #$n"
    stale=$((stale + 1))
  fi
done
echo "stale refs in $RANGE: $stale"

if [[ "$sync_state" == "disabled" && "$stale" -eq 0 ]]; then
  echo "READY: safe to re-enable successComment/releasedLabels."
else
  echo "NOT READY."
  exit 1
fi
