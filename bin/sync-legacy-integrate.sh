#!/usr/bin/env bash
#
# sync-legacy-integrate.sh
#
# Iterates each origin/sync/<name> branch produced by the daily sync job and
# merges it into monorepo-integration.
#
# Default conflict policy is "legacy wins" (-Xtheirs), since this monorepo is
# pre-cutover — legacy trunks are the source of truth and any monorepo-side
# divergence is preparatory work that legacy supersedes.
#
# Three structural overrides run on top of -Xtheirs:
#   1. Per-plugin .github/ files are dropped (CI runs at the monorepo root,
#      legacy CI configs are dead code).
#   2. plugins/*/package.json and themes/*/package.json take "ours" so the
#      workspace:* constraints survive any legacy version bumps to the
#      newspack-{scripts,components,colors,icons} packages.
#   3. For newspack-plugin only: legacy modifications under
#      plugins/newspack-plugin/packages/{colors,components,icons}/ get
#      3-way-merged into the workspace path packages/<pkg>/<rest> (since the
#      package's home moved during extraction). Files that can't be cleanly
#      routed escalate the repo for human review.
#
# On unresolvable conflicts: the merge state is rolled back locally, a WIP
# commit with conflict markers is pushed to sync/conflicts/<name>-<timestamp>,
# and a draft PR is opened with @adekbadek requested as reviewer. Other repos
# in the same run continue independently.

set -euo pipefail

# DRY_RUN=1 skips destructive remote operations (push to origin, gh pr create)
# and prints what would happen instead. Useful for local iteration and CI
# debugging.
DRY_RUN="${DRY_RUN:-0}"

git_push() {
  if [ "$DRY_RUN" = "1" ]; then
    echo "    [dry-run] would push: git push $*"
  else
    git push "$@"
  fi
}

gh_pr_create() {
  if [ "$DRY_RUN" = "1" ]; then
    echo "    [dry-run] would create draft PR: gh pr create $*" | head -c 400
    echo
  else
    gh pr create "$@" \
      || echo "WARN: gh pr create failed; conflict branch is at origin"
  fi
}

REPOS=(
  newspack-plugin
  newspack-blocks
  newspack-popups
  newspack-newsletters
  newspack-ads
  newspack-network
  newspack-multibranded-site
  newspack-listings
  newspack-sponsors
  newspack-story-budget
  super-cool-ad-inserter-plugin
  republication-tracker-tool
  newspack-theme
  newspack-block-theme
  newspack-scripts
)

# Drop legacy per-plugin .github/ files and restore workspace:* in any
# conflicting plugin/theme package.json.
apply_structural_overrides() {
  local name=$1
  git rm -rf --ignore-unmatch \
    "plugins/$name/.github" "themes/$name/.github" \
    > /dev/null 2>&1 || true
  while IFS= read -r f; do
    case "$f" in
      plugins/*/package.json|themes/*/package.json)
        git checkout --ours -- "$f"
        git add "$f"
        ;;
    esac
  done < <(git diff --name-only --diff-filter=U)
}

# For newspack-plugin: redirect any path under
# plugins/newspack-plugin/packages/{colors,components,icons}/ to the workspace
# path packages/<pkg>/<rest>. Handles three cases:
#   1. Path is conflicted (modify/modify or modify/delete with -Xtheirs not
#      sufficient): 3-way merge legacy's change into the workspace file.
#   2. Path is cleanly merged in (legacy added or modified, no monorepo-side
#      change): move/overwrite at the workspace path.
#   3. Path is deleted in legacy: drop it.
#
# Returns 1 if any routed file ends up with conflict markers, or if a modified
# file has no workspace target (the workspace deleted/refactored it away).
route_extracted_packages() {
  local rc=0

  # Process conflicts first (the unresolved index entries hold the base/theirs
  # blobs we need for a real 3-way merge).
  while IFS= read -r path; do
    case "$path" in
      plugins/newspack-plugin/packages/colors/*|\
      plugins/newspack-plugin/packages/components/*|\
      plugins/newspack-plugin/packages/icons/*) ;;
      *) continue ;;
    esac

    local rel="${path#plugins/newspack-plugin/packages/}"
    local target="packages/$rel"
    local base_blob theirs_blob
    base_blob=$(git ls-files -s -- "$path" | awk '$3==1{print $2}')
    theirs_blob=$(git ls-files -s -- "$path" | awk '$3==3{print $2}')

    if [ -z "$theirs_blob" ]; then
      git rm -f -- "$path" > /dev/null
      continue
    fi

    if [ ! -e "$target" ]; then
      if [ -z "$base_blob" ]; then
        mkdir -p "$(dirname "$target")"
        git show "$theirs_blob" > "$target"
        git add "$target"
        git rm -f -- "$path" > /dev/null
      else
        rc=1
      fi
      continue
    fi

    local base_src=/tmp/sync-base-$$
    local theirs_src=/tmp/sync-theirs-$$
    local merged=/tmp/sync-merged-$$
    if [ -n "$base_blob" ]; then
      git show "$base_blob" > "$base_src"
    else
      : > "$base_src"
    fi
    git show "$theirs_blob" > "$theirs_src"

    if git merge-file -p "$theirs_src" "$base_src" "$target" > "$merged" 2>/dev/null; then
      cp "$merged" "$target"
      git add "$target"
      git rm -f -- "$path" > /dev/null
    else
      cp "$merged" "$target"
      git rm -f -- "$path" > /dev/null
      rc=1
    fi
    rm -f "$base_src" "$theirs_src" "$merged"
  done < <(git diff --name-only --diff-filter=U)

  # Now sweep any remaining files under the extracted dirs that came through
  # as clean adds/modifies (the conflict pass missed them because there was no
  # conflict — just legacy's content landing at the legacy path).
  while IFS= read -r path; do
    [ -z "$path" ] && continue
    local rel="${path#plugins/newspack-plugin/packages/}"
    local target="packages/$rel"
    if [ ! -e "$target" ]; then
      mkdir -p "$(dirname "$target")"
      git show ":0:$path" > "$target"
      git add "$target"
    else
      # Workspace already has a file at this path. Under "legacy wins"
      # policy, overwrite with legacy content. (Rare in practice — workspace
      # paths that were extracted are typically a strict subset of the
      # in-plugin tree, so collisions only happen if both sides added a
      # new same-name file.)
      git show ":0:$path" > "$target"
      git add "$target"
    fi
    git rm -f -- "$path" > /dev/null
  done < <(git ls-files -- \
    'plugins/newspack-plugin/packages/colors/*' \
    'plugins/newspack-plugin/packages/components/*' \
    'plugins/newspack-plugin/packages/icons/*')

  return "$rc"
}

# Push the conflicted state to a sync/conflicts/* branch and open a draft PR.
escalate() {
  local name=$1 saved=$2
  local branch="sync/conflicts/${name}-$(date -u +%Y%m%d-%H%M%S)"

  # Stage everything (resolved + unresolved) and commit so we can push.
  git add -A
  git commit --no-edit \
    -m "sync(conflict): unresolved merge of ${name} into monorepo-integration"

  # Capture the list of files that still carry conflict markers, before we
  # push and reset.
  local marker_files
  marker_files=$(git grep -lE '^<<<<<<< |^>>>>>>> ' HEAD -- 2>/dev/null | head -50 || true)

  git_push origin "HEAD:refs/heads/$branch"

  gh_pr_create \
    --base monorepo-integration \
    --head "$branch" \
    --draft \
    --reviewer adekbadek \
    --title "sync conflict: $name" \
    --body "$(printf 'Daily legacy-sync job hit unresolvable conflicts merging \`%s\` into \`monorepo-integration\`.\n\n**To resolve:**\n\n```\ngh pr checkout %s\ngit grep -lE %s | xargs -r $EDITOR   # fix conflict markers\ngit add -A\ngit commit --amend --no-edit\ngit push --force-with-lease\n```\n\nThen mark this PR ready for review and merge.\n\nFiles with conflict markers:\n\n```\n%s\n```\n' "$name" "$branch" "'^<<<<<<< |^>>>>>>> '" "${marker_files:-(none — only structural conflicts)}")"

  git reset --hard "$saved"
}

if [ "$DRY_RUN" = "1" ]; then
  echo "[dry-run] skipping 'git fetch origin --prune' so locally-supplied origin/sync/* refs survive"
else
  git fetch origin --prune --quiet
fi
START=$(git rev-parse HEAD)

for name in "${REPOS[@]}"; do
  saved=$(git rev-parse HEAD)
  if ! git rev-parse --verify --quiet "origin/sync/$name" > /dev/null; then
    echo "SKIP $name (no sync branch — first run hasn't produced it yet)"
    continue
  fi
  ahead=$(git rev-list --count "HEAD..origin/sync/$name")
  if [ "$ahead" -eq 0 ]; then
    echo "SKIP $name (up to date)"
    continue
  fi
  echo "==> $name (+$ahead commits)"

  # -Xtheirs lets legacy win all content conflicts, -Xno-renames disables
  # git's cross-plugin rename heuristic.
  merge_clean=0
  if git merge --no-edit --no-commit -Xno-renames -Xtheirs "origin/sync/$name" > /dev/null 2>&1; then
    merge_clean=1
  fi

  apply_structural_overrides "$name"

  # newspack-plugin always runs the extracted-package routing — files under
  # plugins/newspack-plugin/packages/{colors,components,icons}/ leak in even
  # without conflicts (legacy added them, monorepo just doesn't have them),
  # so the routing has to sweep clean adds in addition to conflicts.
  if [ "$name" = "newspack-plugin" ]; then
    if ! route_extracted_packages; then
      echo "    ESCALATE (extracted-package routing left conflict markers)"
      escalate "$name" "$saved"
      continue
    fi
  fi

  if [ -z "$(git diff --name-only --diff-filter=U)" ]; then
    git commit --no-edit > /dev/null
    if [ "$merge_clean" = "1" ] && [ "$name" != "newspack-plugin" ]; then
      echo "    OK"
    else
      echo "    OK (auto-resolved)"
    fi
  else
    echo "    ESCALATE"
    escalate "$name" "$saved"
  fi
done

if [ "$(git rev-parse HEAD)" != "$START" ]; then
  echo "==> Pushing $(git rev-list --count "$START..HEAD") new commits to monorepo-integration"
  git_push origin HEAD:monorepo-integration
else
  echo "==> No clean merges this run; nothing to push to monorepo-integration"
fi
