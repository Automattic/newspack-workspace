#!/usr/bin/env bash

# Release the latest version on wordpress.org plugin repository.
# To be run as part of CI workflow.
# Assumptions:
# - repository name matches the wordpress.org plugin name or WP_ORG_PLUGIN_NAME is set,
# - there is a `release` directory with the folder containing the files and named as the plugin in it
# Partially adapted from https://carlalexander.ca/continuous-deployment-wordpress-directory-circleci/

SVN_PLUGINS_URL="https://plugins.svn.wordpress.org"
SVN_REPO_LOCAL_PATH="release/svn"
SVN_REPO_URL="$SVN_PLUGINS_URL/$WP_ORG_PLUGIN_NAME"

LATEST_GIT_TAG=$(git tag --list 'v*' --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1)
if [ -z "$LATEST_GIT_TAG" ]; then
  echo "No stable version tag found. Aborting deployment."
  exit 1
fi
# Remove the "v" at the beginning of the git tag
LATEST_SVN_TAG=${LATEST_GIT_TAG:1}

mkdir -p $SVN_REPO_LOCAL_PATH && cd $SVN_REPO_LOCAL_PATH
sudo apt-get update
sudo apt-get install subversion

# Check if the latest SVN tag exists already
TAG=$(svn ls "$SVN_REPO_URL/tags/$LATEST_SVN_TAG")
error=$?
if [ $error == 0 ]; then
  # Tag exists, don't deploy
  echo "Latest tag ($LATEST_SVN_TAG) already exists on the WordPress directory. No deployment needed!"
  exit 0
fi

# Wait a moment to avoid a 429 by WPORG's server.
sleep 3

svn checkout -q "$SVN_REPO_URL" .

rm -rf trunk

cp -r "../$WP_ORG_PLUGIN_NAME" ./trunk
cp -r ./trunk "./tags/$LATEST_SVN_TAG"

# Add new files to SVN
svn stat | grep '^?' | awk '{print $2}' | xargs -I x svn add x@

# Remove deleted files from SVN
svn stat | grep '^!' | awk '{print $2}' | xargs -I x svn rm --force x@

# Commit to SVN
svn ci --no-auth-cache --username $WP_ORG_USERNAME --password $WP_ORG_PASSWORD -m "Deploy version $LATEST_SVN_TAG"
