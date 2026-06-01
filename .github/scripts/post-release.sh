#!/usr/bin/env bash
#
# Post-release branch maintenance for the monorepo, run after the `release` job.
#
# After a release on the `release` branch:
#   - reset the single-serving `alpha` branch onto `release` (when alpha's
#     history is fully released), or merge `release` into `alpha` otherwise;
#   - merge `release` back into the repository's default branch so they stay in
#     sync, notifying Slack on conflict.
#
# workspace:* preservation is handled by .github/scripts/finalize-package-versions.cjs,
# which runs after multi-semantic-release (the "Sync package.json versions" step
# in release.yml) and reverts msr's dependency concretization in its own commit,
# so no dependency restoration is needed here.
#
# This lives in the monorepo (not packages/scripts, which mirrors the legacy
# newspack-scripts repo and is overwritten by the daily sync) and targets the
# repo's actual default branch rather than the hard-coded `trunk` the legacy
# script used.

set -euo pipefail

# The default branch the legacy script called "trunk"; resolved dynamically so
# this works whether the repo's default is `main` or `trunk`.
DEFAULT_BRANCH=$(git remote show origin | sed -n 's/.*HEAD branch: //p')
DEFAULT_BRANCH=${DEFAULT_BRANCH:-main}

LATEST_VERSION_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "release")

# Notify Slack about a failed post-release merge into $1, if Slack is configured.
notify_slack() {
  local target="$1"
  if [ -z "${SLACK_CHANNEL_ID:-}" ] || [ -z "${SLACK_AUTH_TOKEN:-}" ]; then
    echo "[post-release] Missing Slack channel ID and/or token. Cannot notify."
    return
  fi
  echo "[post-release] Notifying the team on Slack."
  curl \
    --data "{\"channel\":\"$SLACK_CHANNEL_ID\",\"blocks\":[{\"type\":\"section\",\"text\":{\"type\":\"mrkdwn\",\"text\":\"⚠️ Post-release merge to \`$target\` failed for: \`$GITHUB_REPOSITORY\`. Check <$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID|the build> for details.\"}}]}" \
    -H "Content-type: application/json" \
    -H "Authorization: Bearer $SLACK_AUTH_TOKEN" \
    -X POST https://slack.com/api/chat.postMessage \
    -s > /dev/null
}

git pull origin release
git fetch origin alpha

git checkout -B alpha origin/alpha

# Decide alpha-branch maintenance by whether alpha holds any commit that release
# does not:
#   - alpha fully contained in release  => the release came from an alpha
#     promotion (alpha's history is now released), so reset alpha onto release;
#   - alpha has its own commits          => a hotfix landed on release, or alpha
#     moved on, so merge release into alpha to preserve those commits.
#
# This ancestry test is the correct condition — reset only when no alpha work
# would be lost — and is robust to however many per-package version commits a
# release stacks, unlike a fixed HEAD~N offset (which silently misclassifies
# multi-package releases).
if git merge-base --is-ancestor origin/alpha release; then
  echo "[post-release] alpha is fully contained in release; resetting alpha onto release."
  git reset --hard release --
  git push --force-with-lease=alpha:origin/alpha origin alpha
else
  echo "[post-release] alpha has unreleased commits; merging release into alpha."
  if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
    git push origin alpha
  else
    git merge --abort
    echo "[post-release] Post-release merge to alpha failed."
    notify_slack alpha
  fi
fi

echo "[post-release] Merging release into $DEFAULT_BRANCH."
git checkout "$DEFAULT_BRANCH"
if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
  git push origin "$DEFAULT_BRANCH"
else
  git merge --abort
  echo "[post-release] Post-release merge to $DEFAULT_BRANCH failed."
  notify_slack "$DEFAULT_BRANCH"
fi
