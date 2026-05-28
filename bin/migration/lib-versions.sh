#!/usr/bin/env bash
#
# lib-versions.sh — shared version-resolution helpers for the migration release
# tooling. Sourced by:
#
#   • bin/migration/seed-baseline-tags.sh — seeds `<pkgName>@<version>` git tags
#     so multi-semantic-release bumps from the true last-released version.
#   • .github/scripts/publish-baseline-releases.sh — publishes each package's
#     current version as a real GitHub release (tag + zip) at cutover.
#
# Both need the same answer to "what is this package's current version, and is
# it releasable?", so the logic lives here once.
#
# Part of the migration tooling — intended to be deleted after cutover.

# Read a top-level field from a package.json with node (avoids a jq dependency).
# Objects are returned as JSON; missing fields as an empty string. Never fails
# the caller (trailing `|| true`), so a missing field doesn't trip `set -e`.
pkg_field() {
  node -e "const o=JSON.parse(require('fs').readFileSync('$1','utf8')); const v=o['$2']; process.stdout.write(v==null?'':(typeof v==='object'?JSON.stringify(v):String(v)))" 2>/dev/null || true
}

# Raw latest non-prerelease GitHub release tag name for a legacy repo (e.g.
# "v6.41.3"), as published. Empty if the repo has no releases. Uses --json so it
# reads the tag name, not the (possibly different) release title.
gh_latest_tag() {
  gh release list --repo "Automattic/$1" --exclude-pre-releases --limit 1 \
    --json tagName --jq '.[0].tagName // ""' 2>/dev/null || true
}

# Latest non-prerelease GitHub release version for a legacy repo, stripped of a
# leading "v". Empty if the repo has no releases.
gh_latest() {
  gh_latest_tag "$1" | sed 's/^v//'
}

# Latest version published to npm for a package name. Empty if unpublished.
npm_latest() { npm view "$1" version 2>/dev/null || true; }

# Print the higher of two semver strings (tolerates empty arguments).
highest_version() {
  local a="$1" b="$2"
  [ -z "$a" ] && { echo "$b"; return; }
  [ -z "$b" ] && { echo "$a"; return; }
  printf '%s\n%s\n' "$a" "$b" | sort -V | tail -1
}

# A package is releasable if it carries a release config that semantic-release
# (via cosmiconfig) will honour: a release.config.{js,cjs} file, any
# .releaserc* file (e.g. newspack-theme uses .releaserc.js), or a "release"
# field in package.json. Keep this in sync with cosmiconfig's search places.
is_releasable() {
  local dir="$1"
  [ -f "$dir/release.config.js" ] && return 0
  [ -f "$dir/release.config.cjs" ] && return 0
  ls "$dir"/.releaserc* > /dev/null 2>&1 && return 0
  [ -n "$(pkg_field "$dir/package.json" 'release')" ] && return 0
  return 1
}

# Map a workspace directory to the Automattic GitHub repo that publishes its
# automated releases. Most directories match their basename; the extracted
# shared package at packages/scripts is the standalone newspack-scripts repo.
# packages/{colors,components,icons} have no standalone repo (they were split
# out of newspack-plugin), so they resolve to a non-existent repo and the GH
# lookup simply returns empty — npm is their source of truth.
legacy_repo() {
  case "$1" in
    packages/scripts) echo "newspack-scripts" ;;
    *) basename "$1" ;;
  esac
}

# Resolve a package's baseline version = the highest across every source: the
# legacy repo's latest GitHub release (authoritative for automated-release
# packages), the latest version on npm, and the in-repo package.json. Taking the
# max guards all directions at once: the monorepo manifest routinely LAGS the
# GitHub release (the sync brings code, not the release commits), while
# hand-released shared packages can have a package.json that LEADS the registry.
# The max is always strictly >= anything already shipped or claimed. Prints the
# resolved version (may be empty if nothing is resolvable).
resolve_version() {
  local dir="$1" pkg_name="$2"
  local gh npmv pjv
  gh=$(gh_latest "$(legacy_repo "$dir")")
  npmv=$(npm_latest "$pkg_name")
  pjv=$(pkg_field "$dir/package.json" 'version')
  highest_version "$(highest_version "$gh" "$npmv")" "$pjv"
}
