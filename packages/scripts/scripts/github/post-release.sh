#!/usr/bin/env bash

# This script should be ran on CI after a new regular (not pre-release) version is released.

# The last commit message at this point is the automated release commit. The second-to-last
# commit message should contain data about the merge.
SECOND_TO_LAST_COMMIT_MSG=$(git log -n 1 --skip 1 --pretty=format:"%s")

LATEST_VERSION_TAG=$(git describe --tags --abbrev=0)

git pull origin release
git checkout alpha

# If the merge was from alpha branch (the basic flow), alpha branch should be reset.
if [[ $(echo $SECOND_TO_LAST_COMMIT_MSG | grep '^Merge .*alpha') ]]; then
  echo '[newspack-scripts] Release was created from the alpha branch. Alpha branch will now be reset.'

  # Reset the tip of alpha branch to the release branch.
  # The alpha branch is single-serving, just for alpha releases. After a release,
  # we don't care about any alpha changes.
  git reset --hard release --
  # Force-push the alpha branch.
  git push --force
else
  echo '[newspack-scripts] Release was created from a different branch than the alpha branch (e.g. a hotfix branch).'
  echo '[newspack-scripts] Alpha branch will now be updated with the lastest changes from release.'
  git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"
  if [[ $? == 0 ]]; then
    git push
  else
    git merge --abort
    echo '[newspack-scripts] Post-release merge to alpha failed.'
    if [ -z "$SLACK_CHANNEL_ID" ] || [ -z "$SLACK_AUTH_TOKEN" ]; then
      echo '[newspack-scripts] Missing Slack channel ID and/or token. Cannot notify.'
    else
      echo '[newspack-scripts] Notifying the team on Slack.'
      curl \
        --data "{\"channel\":\"$SLACK_CHANNEL_ID\",\"blocks\":[{\"type\":\"section\",\"text\":{\"type\":\"mrkdwn\",\"text\":\"⚠️ Post-release merge to alpha failed for: \`$GITHUB_REPOSITORY\`. Check <$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID|the build> for details.\"}}]}" \
        -H "Content-type: application/json" \
        -H "Authorization: Bearer $SLACK_AUTH_TOKEN" \
        -X POST https://slack.com/api/chat.postMessage \
        -s > /dev/null
    fi
  fi
fi

# Update trunk branch with latest changes from the release branch, so they are in sync.
echo '[newspack-scripts] Merging the release branch into trunk.'
git checkout trunk

# Merge release branch into trunk branch, and notify the team if any conflicts arise.
git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"
if [[ $? == 0 ]]; then
  echo '[newspack-scripts] Pushing updated trunk to origin.'
  git push
else
  git merge --abort
  echo '[newspack-scripts] Post-release merge to trunk failed.'
  if [ -z "$SLACK_CHANNEL_ID" ] || [ -z "$SLACK_AUTH_TOKEN" ]; then
    echo '[newspack-scripts] Missing Slack channel ID and/or token. Cannot notify.'
  else
    echo '[newspack-scripts] Notifying the team on Slack.'
    curl \
      --data "{\"channel\":\"$SLACK_CHANNEL_ID\",\"blocks\":[{\"type\":\"section\",\"text\":{\"type\":\"mrkdwn\",\"text\":\"⚠️ Post-release merge to \`trunk\` failed for: \`$GITHUB_REPOSITORY\`. Check <$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID|the build> for details.\"}}]}" \
      -H "Content-type: application/json" \
      -H "Authorization: Bearer $SLACK_AUTH_TOKEN" \
      -X POST https://slack.com/api/chat.postMessage \
      -s > /dev/null
  fi
fi
