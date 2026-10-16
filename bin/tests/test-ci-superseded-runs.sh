#!/usr/bin/env bash
#
# test-ci-superseded-runs.sh
#
# Self-proving spec for how CI on main treats a push whose run GitHub cancelled
# because a newer push queued behind it.
#
# The two halves only work together. ci-push-base.sh makes the newer run diff
# from the last run that was not cancelled, so it tests the cancelled push's
# commits too. notify-ci-failure.sh relies on that to stay quiet about the
# cancelled run. Revert the first and a cancelled push's packages go untested
# while the second hides it; revert the second and every queued merge posts a
# false alarm to Slack.
#
# `gh` is stubbed with canned API answers; git runs for real on a scratch repo.
#
# Run: bash bin/tests/test-ci-superseded-runs.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PUSH_BASE="$ROOT/.github/scripts/ci-push-base.sh"
NOTIFY="$ROOT/.github/scripts/notify-ci-failure.sh"

WORK=$(mktemp -d -t ci-superseded-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

failures=0
check() { # <description> <expected> <actual>
  if [ "$2" = "$3" ]; then
    echo "ok   - $1"
  else
    echo "FAIL - $1"
    echo "       expected: $2"
    echo "       actual:   $3"
    failures=$((failures + 1))
  fi
}

# Three pushes, A <- B <- C. A's run passed, B's was cancelled, C's is running.
git init -q "$WORK/repo"
cd "$WORK/repo"
for c in A B C; do
  git -c user.name=t -c user.email=t@example.test commit -q --allow-empty -m "$c"
  eval "SHA_$c=$(git rev-parse HEAD)"
done

# The stub answers from GH_RUNS, a JSON list of runs, newest first, and fails
# outright when GH_FAIL is set.
mkdir "$WORK/bin"
cat > "$WORK/bin/gh" <<'STUB'
#!/usr/bin/env bash
[ -z "${GH_FAIL:-}" ] || exit 1
jq_filter=""
while [ $# -gt 0 ]; do
  case "$1" in
    --jq) jq_filter="$2"; shift 2 ;;
    api) shift ;;
    *) path="$1"; shift ;;
  esac
done
case "$path" in
  */actions/runs/*) body='{"workflow_id": 7}' ;;
  *per_page=1) body=$(jq -c '{workflow_runs: .[:1]}' <<<"$GH_RUNS") ;;
  *) body=$(jq -c '{workflow_runs: .}' <<<"$GH_RUNS") ;;
esac
jq -r "$jq_filter" <<<"$body"
STUB
chmod +x "$WORK/bin/gh"
export PATH="$WORK/bin:$PATH" GITHUB_REPOSITORY=o/r GITHUB_RUN_ID=3 BRANCH=main

runs() { # <run_number>:<sha>:<conclusion> ..., newest first
  local out="[" sep="" n sha conclusion
  for r in "$@"; do
    IFS=: read -r n sha conclusion <<<"$r"
    out+="$sep{\"run_number\":$n,\"head_sha\":\"$sha\",\"conclusion\":$conclusion}"
    sep=","
  done
  printf '%s]' "$out"
}

push_base() { # Runs as push C's run, number 3, whose before is B.
  GITHUB_RUN_NUMBER=3 EVENT_BEFORE=$SHA_B bash "$PUSH_BASE" 2>/dev/null
}

GH_RUNS=$(runs "3:$SHA_C:null" "2:$SHA_B:\"cancelled\"" "1:$SHA_A:\"success\"")
export GH_RUNS
check "a cancelled run's commits are tested by the next run" "$SHA_A" "$(push_base)"

GH_RUNS=$(runs "3:$SHA_C:null" "2:$SHA_B:\"failure\"" "1:$SHA_A:\"success\"")
check "a failed run counts as tested, since its failure was reported" "$SHA_B" "$(push_base)"

GH_RUNS=$(runs "3:$SHA_C:null" "2:$SHA_B:\"cancelled\"" "1:0000000000000000000000000000000000000001:\"success\"")
check "a last-tested commit missing from the branch falls back to before" "$SHA_B" "$(push_base)"

check "a failed API lookup falls back to before" "$SHA_B" "$(GH_FAIL=1 push_base)"

notify() { # <run_number> of the cancelled run being reported
  SLACK_AUTH_TOKEN=x SLACK_CHANNEL_ID=x SLACK_DRY_RUN=1 WORKFLOW_ID=7 RUN_ID=9 RUN_NUMBER="$1" \
    RUN_CONCLUSION=cancelled RUN_URL=u HEAD_BRANCH=main HEAD_SHA="$SHA_B" COMMIT_SUBJECT=s COMMIT_AUTHOR=a \
    bash "$NOTIFY" | head -1
}

GH_RUNS=$(runs "3:$SHA_C:null" "2:$SHA_B:\"cancelled\"")
check "a cancelled run with a newer run behind it posts nothing" \
  "[notify-ci-failure] Run 9 was cancelled for newer run #3, which tests its commits. Skipping." "$(notify 2)"

GH_RUNS=$(runs "2:$SHA_B:\"cancelled\"" "1:$SHA_A:\"success\"")
check "a cancelled run with nothing behind it still posts" \
  "[notify-ci-failure] DRY RUN -- would post to Slack:" "$(notify 2)"

if [ "$failures" -gt 0 ]; then
  echo "$failures check(s) failed."
  exit 1
fi
echo "All checks passed."
