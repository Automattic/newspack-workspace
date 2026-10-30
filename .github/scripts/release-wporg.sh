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
#                           name. Defaults to the name.

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

# The version WordPress.org serves comes from the main plugin file's `Version:`
# header: these plugins ship `Stable tag: trunk`, so readme.txt carries no
# version at all and the header is the only marker an update check reads.
# WP_ORG_PLUGIN_VERSION comes from package.json instead, and the two are
# written by different halves of the release (semantic-release stamps the
# header, finalize-package-versions.cjs commits package.json). Assert they
# agree on the built artifact, so the value that decides what publishes is
# checked rather than assumed.
main_file=$( { grep -ilE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "release/$WP_ORG_PLUGIN_NAME"/*.php || true; } | head -n 1 )
if [ -z "$main_file" ]; then
  echo "::error::No plugin header found in release/$WP_ORG_PLUGIN_NAME. Cannot confirm the version WordPress.org would serve."
  exit 1
fi
header_line=$( grep -m1 -iE '^[[:space:]]*\*?[[:space:]]*Version:' "$main_file" || true )
header_version=$( printf '%s' "$header_line" | sed -E 's/.*[Vv]ersion:[[:space:]]*//; s/[[:space:]].*$//' )
if [ "$header_version" != "$WP_ORG_PLUGIN_VERSION" ]; then
  echo "::error::$main_file declares version '${header_version:-none}' but this deploy is publishing $WP_ORG_PLUGIN_VERSION. WordPress.org reads the header, so the artifact would go out mislabelled."
  exit 1
fi

mkdir -p "$SVN_REPO_LOCAL_PATH" && cd "$SVN_REPO_LOCAL_PATH"

# Nothing is published when this version is already tagged on WordPress.org.
# That is expected when re-running a deploy, and it is also the last guard
# behind the workflow's version gate -- so it annotates the run rather than
# passing quietly, because on any other run it means the build carried the
# wrong version and the release never reached wordpress.org.
if svn ls "$SVN_REPO_URL/tags/$SVN_TAG" > /dev/null 2>&1; then
  echo "::warning::Tag $SVN_TAG already exists on WordPress.org; nothing deployed. Expected on a re-run -- otherwise check that $SVN_TAG is the version this run released."
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
