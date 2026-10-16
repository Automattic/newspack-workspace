#!/usr/bin/env bash
#
# Prints the commit a push run of CI should diff against to pick the packages
# it tests.
#
# A push waits while an earlier run on the same branch is in progress, and
# GitHub cancels that waiting run when a newer push queues behind it. Diffing
# against the push's own `before` would then never test the cancelled push's
# changes: the newer run covers only its own commits. Diffing against the last
# run that was not cancelled covers every commit since a run last looked at the
# branch, so a cancelled run loses nothing and the failure notifier can stay
# quiet about it.
#
# Falls back to EVENT_BEFORE, so the diff covers this push's own commits only,
# whenever the lookup fails or its answer is not an ancestor of HEAD (a reset
# branch). The fallback raises a warning on the run, because the notifier has
# already assumed this run covers any cancelled run before it.
#
# Env: GH_TOKEN, GITHUB_REPOSITORY, GITHUB_RUN_ID, GITHUB_RUN_NUMBER, BRANCH,
# EVENT_BEFORE.

set -uo pipefail

# How far back to look. Each cancelled run needs a newer push behind it, so a
# streak this long means something other than a merge queue is going on.
MAX_PAGES=5

fallback() {
  # stderr, because stdout is the answer. The runner reads workflow commands from both.
  echo "::warning title=CI base commit::$1; testing only this push's own commits, so commits of cancelled runs before it may be untested." >&2
  printf '%s\n' "$EVENT_BEFORE"
  exit 0
}

WORKFLOW_ID=$(gh api "repos/${GITHUB_REPOSITORY}/actions/runs/${GITHUB_RUN_ID}" --jq '.workflow_id' 2>/dev/null) ||
  fallback "Could not read this run"
[ -n "$WORKFLOW_ID" ] || fallback "Could not read this run"

# An in-progress run has no conclusion yet and counts: it is testing its
# commits. A failed one counts too, since its failure shows on the run. A run
# that was cancelled or never started its jobs tested nothing.
LAST_TESTED=""
for page in $(seq 1 "$MAX_PAGES"); do
  PAGE=$(
    gh api "repos/${GITHUB_REPOSITORY}/actions/workflows/${WORKFLOW_ID}/runs?branch=${BRANCH}&event=push&per_page=100&page=${page}" 2>/dev/null
  ) || fallback "Could not list earlier runs"
  LAST_TESTED=$(
    jq -r --argjson current "$GITHUB_RUN_NUMBER" '
      [.workflow_runs[]
        | select(.run_number < $current and .conclusion != "cancelled" and .conclusion != "startup_failure")]
      | max_by(.run_number) // {} | .head_sha // empty' <<<"$PAGE"
  ) || fallback "Could not read the list of earlier runs"
  [ -z "$LAST_TESTED" ] || break
  [ "$(jq '.workflow_runs | length' <<<"$PAGE")" -eq 100 ] || break
done
[ -n "$LAST_TESTED" ] || fallback "No earlier run that was not cancelled"

git merge-base --is-ancestor "$LAST_TESTED" HEAD 2>/dev/null ||
  fallback "Commit ${LAST_TESTED} is not an ancestor of HEAD"

if [ "$LAST_TESTED" != "$EVENT_BEFORE" ]; then
  echo "[ci-push-base] The runs after ${LAST_TESTED} were cancelled; testing their commits too." >&2
fi
printf '%s\n' "$LAST_TESTED"
