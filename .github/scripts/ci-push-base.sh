#!/usr/bin/env bash
#
# Prints the commit a push run of CI should diff against to pick the packages
# it tests.
#
# A push to main waits while an earlier run is in progress, and GitHub cancels
# that waiting run when a newer push queues behind it. Diffing against the
# push's own `before` would then never test the cancelled push's changes: the
# newer run covers only its own commits. Diffing against the last run that was
# not cancelled covers every commit since a run last looked at the branch, so a
# cancelled run loses nothing and the failure notifier can stay quiet about it.
#
# Falls back to EVENT_BEFORE whenever the lookup fails or its answer is not an
# ancestor of HEAD (a reset branch), which is exactly the old behaviour.
#
# Env: GH_TOKEN, GITHUB_REPOSITORY, GITHUB_RUN_ID, GITHUB_RUN_NUMBER, BRANCH,
# EVENT_BEFORE.

set -uo pipefail

fallback() {
  echo "[ci-push-base] $1; using the push's before commit." >&2
  printf '%s\n' "$EVENT_BEFORE"
  exit 0
}

WORKFLOW_ID=$(gh api "repos/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}" --jq '.workflow_id' 2>/dev/null) ||
  fallback "Could not read this run"
[ -n "$WORKFLOW_ID" ] || fallback "Could not read this run"

# Newest first. An in-progress run has no conclusion yet and counts: it is
# testing its commits. A failed one counts too, because its failure was reported.
LAST_TESTED=$(
  gh api "repos/${GITHUB_REPOSITORY}/actions/workflows/${WORKFLOW_ID}/runs?branch=${BRANCH}&event=push&per_page=50" \
    --jq "[.workflow_runs[] | select(.run_number < ${GITHUB_RUN_NUMBER} and .conclusion != \"cancelled\")][0].head_sha // empty" \
    2>/dev/null
) || fallback "Could not list earlier runs"
[ -n "$LAST_TESTED" ] || fallback "No earlier run that was not cancelled"

git cat-file -e "${LAST_TESTED}^{commit}" 2>/dev/null || fallback "Commit ${LAST_TESTED} is not in the checkout"
git merge-base --is-ancestor "$LAST_TESTED" HEAD || fallback "Commit ${LAST_TESTED} is not an ancestor of HEAD"

if [ "$LAST_TESTED" != "$EVENT_BEFORE" ]; then
  echo "[ci-push-base] The runs after ${LAST_TESTED} were cancelled; testing their commits too." >&2
fi
printf '%s\n' "$LAST_TESTED"
