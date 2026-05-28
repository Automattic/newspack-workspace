#!/usr/bin/env bash
#
# Deploy a freshly built plugin to the WordPress.org SVN repository.
#
# Monorepo-aware replacement for the legacy newspack-scripts release-wporg.sh:
#   - The version comes from WP_ORG_PLUGIN_VERSION (the plugin's package.json
#     version, set by the workflow) rather than a `vX.Y.Z` git tag. The legacy
#     script parsed `git tag --list 'v*'`, which never matches the monorepo's
#     `<pkgName>@<version>` tags.
#   - Runs from the plugin directory, where `release:archive` produced the
#     deployable `release/<plugin>/` folder.
#
# Required environment:
#   WP_ORG_PLUGIN_NAME      Name of the release/ subdir produced by release:archive.
#   WP_ORG_PLUGIN_VERSION   Version to deploy (e.g. 3.33.5).
#   WP_ORG_USERNAME         WordPress.org SVN username.
#   WP_ORG_PASSWORD         WordPress.org SVN password.
# Optional:
#   WP_ORG_PLUGIN_SLUG      WordPress.org slug, when it differs from the subdir
#                           name (e.g. super-cool-ad-inserter-plugin ships as the
#                           "super-cool-ad-inserter" slug). Defaults to the name.

set -euo pipefail

: "${WP_ORG_PLUGIN_NAME:?WP_ORG_PLUGIN_NAME is required}"
: "${WP_ORG_PLUGIN_VERSION:?WP_ORG_PLUGIN_VERSION is required}"
: "${WP_ORG_USERNAME:?WP_ORG_USERNAME is required}"
: "${WP_ORG_PASSWORD:?WP_ORG_PASSWORD is required}"

WP_ORG_PLUGIN_SLUG="${WP_ORG_PLUGIN_SLUG:-$WP_ORG_PLUGIN_NAME}"

SVN_PLUGINS_URL="https://plugins.svn.wordpress.org"
SVN_REPO_LOCAL_PATH="release/svn"
SVN_REPO_URL="$SVN_PLUGINS_URL/$WP_ORG_PLUGIN_SLUG"
SVN_TAG="$WP_ORG_PLUGIN_VERSION"

if [ ! -d "release/$WP_ORG_PLUGIN_NAME" ]; then
  echo "::error::release/$WP_ORG_PLUGIN_NAME not found. The build must run release:archive first."
  exit 1
fi

mkdir -p "$SVN_REPO_LOCAL_PATH" && cd "$SVN_REPO_LOCAL_PATH"

# Skip if this version is already published.
if svn ls "$SVN_REPO_URL/tags/$SVN_TAG" > /dev/null 2>&1; then
  echo "Tag $SVN_TAG already exists on WordPress.org. No deployment needed."
  exit 0
fi

# Brief pause to avoid a 429 from the WP.org SVN server.
sleep 3

svn checkout -q "$SVN_REPO_URL" .

rm -rf trunk
cp -r "../$WP_ORG_PLUGIN_NAME" ./trunk
cp -r ./trunk "./tags/$SVN_TAG"

# Stage adds and deletes. The greps are guarded with `|| true` because a clean
# state (e.g. a first deploy with no deletions) makes grep exit non-zero, which
# under `set -o pipefail` would abort the script before `svn ci`.
svn stat | { grep '^?' || true; } | awk '{print $2}' | xargs -r -I x svn add x@
svn stat | { grep '^!' || true; } | awk '{print $2}' | xargs -r -I x svn rm --force x@

svn ci --no-auth-cache --username "$WP_ORG_USERNAME" --password "$WP_ORG_PASSWORD" -m "Deploy version $SVN_TAG"
