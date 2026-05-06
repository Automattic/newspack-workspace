#!/usr/bin/env bash
#
# sync-legacy-repo.sh
#
# Deterministic filter-repo driver for importing or syncing a legacy Newspack
# repo into this monorepo. Given a source repo URL (or local path) and a
# target monorepo subdirectory, it produces a bare, filtered repo on disk
# whose commit SHAs are a pure function of the source commits + target path.
#
# This determinism is what makes continuous sync possible: running it again
# later against an advanced source branch produces the same SHAs for all
# previously-filtered commits plus new SHAs on top for new commits.
#
# Usage:
#   bin/sync-legacy-repo.sh <source> <target-subdir> <output-dir> [<branch>]
#
# Arguments:
#   source         Git URL or local path to the source repo.
#   target-subdir  Path inside the monorepo where files should end up
#                  (e.g. plugins/newspack-plugin, themes/newspack-theme,
#                  packages/scripts).
#   output-dir     Where to write the filtered bare repo (will be (re)created).
#   branch         Source branch to filter. Defaults to trunk.
#
# Examples:
#   bin/sync-legacy-repo.sh \
#     git@github.com:Automattic/newspack-plugin.git \
#     plugins/newspack-plugin \
#     /tmp/monorepo-import/newspack-plugin.git
#
#   bin/sync-legacy-repo.sh \
#     /path/to/local/newspack-plugin \
#     plugins/newspack-plugin \
#     /tmp/monorepo-import/newspack-plugin.git \
#     trunk
#

set -euo pipefail

if [ "$#" -lt 3 ] || [ "$#" -gt 4 ]; then
  echo "Usage: $0 <source> <target-subdir> <output-dir> [<branch>]" >&2
  exit 1
fi

SOURCE="$1"
TARGET_SUBDIR="$2"
OUTPUT_DIR="$3"
BRANCH="${4:-trunk}"

# Derive a tag-rename prefix from the last segment of the target subdir.
# e.g. plugins/newspack-plugin -> newspack-plugin-
TAG_PREFIX="$(basename "$TARGET_SUBDIR")-"

echo "==> sync-legacy-repo"
echo "    source : $SOURCE"
echo "    branch : $BRANCH"
echo "    target : $TARGET_SUBDIR"
echo "    output : $OUTPUT_DIR"
echo "    tags   : :$TAG_PREFIX"

# Fresh clone every time. filter-repo refuses to operate on a repo it has
# touched before unless --force is passed; starting clean is simpler and
# keeps the output deterministic.
rm -rf "$OUTPUT_DIR"
mkdir -p "$(dirname "$OUTPUT_DIR")"

# --mirror preserves all refs so we can pick trunk out reliably even when
# the source is a working copy with a different HEAD.
git clone --mirror --quiet "$SOURCE" "$OUTPUT_DIR"

cd "$OUTPUT_DIR"

# Resolve the target branch. Prefer a local ref named $BRANCH, fall back to
# a remote-tracking ref if that's what --mirror gave us (e.g. cloning from
# a non-bare working copy).
TARGET_REF=""
if git show-ref --verify --quiet "refs/heads/$BRANCH"; then
  TARGET_REF="refs/heads/$BRANCH"
elif git show-ref --verify --quiet "refs/remotes/origin/$BRANCH"; then
  TARGET_REF="refs/remotes/origin/$BRANCH"
else
  # Some older repos use master.
  if [ "$BRANCH" = "trunk" ]; then
    if git show-ref --verify --quiet refs/heads/master; then
      TARGET_REF=refs/heads/master
      BRANCH=master
    elif git show-ref --verify --quiet refs/remotes/origin/master; then
      TARGET_REF=refs/remotes/origin/master
      BRANCH=master
    fi
  fi
fi

if [ -z "$TARGET_REF" ]; then
  echo "ERROR: no ref named $BRANCH (or master) found in $SOURCE" >&2
  exit 2
fi

# Normalize: ensure a plain refs/heads/<branch> ref exists pointing at the
# tip we want to filter. Delete everything else so filter-repo only sees
# the history we care about and the output is deterministic.
TIP_SHA="$(git rev-parse "$TARGET_REF")"
git update-ref "refs/heads/$BRANCH" "$TIP_SHA"

# Drop every other ref (other branches, remote-tracking refs, stash, etc.)
# but keep tags - we want per-plugin release history preserved.
git for-each-ref --format='%(refname)' \
  refs/heads refs/remotes refs/pull refs/stash 2>/dev/null |
  while read -r ref; do
    if [ "$ref" != "refs/heads/$BRANCH" ]; then
      git update-ref -d "$ref"
    fi
  done

# Set HEAD so filter-repo has a sensible default.
git symbolic-ref HEAD "refs/heads/$BRANCH"

# Garbage-collect so filter-repo's initial analysis runs on the right set
# of reachable objects. Not strictly required, but keeps things tidy.
git reflog expire --expire=now --all
git gc --quiet --prune=now

# The deterministic rewrite:
#   --to-subdirectory-filter  moves every file in every historical commit
#                             into the target subdirectory.
#   --tag-rename              namespaces tags so they don't collide with
#                             other imported repos (v1.0.0 -> foo-v1.0.0).
git filter-repo \
  --force \
  --to-subdirectory-filter "$TARGET_SUBDIR" \
  --tag-rename ":$TAG_PREFIX"

echo "==> done: $OUTPUT_DIR"
echo "    tip  : $(git rev-parse HEAD)"
echo "    tags : $(git tag | wc -l | tr -d ' ')"
