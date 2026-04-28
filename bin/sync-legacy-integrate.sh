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

# For newspack-plugin: redirect conflicting paths under
# plugins/newspack-plugin/packages/{colors,components,icons}/ to the workspace
# path packages/<pkg>/<rest>, doing a 3-way merge against the workspace's
# current content. Returns 1 if any routed file ends up with conflict markers
# (or has no workspace target for a modified-but-not-new file) so the caller
# can escalate the repo.
route_extracted_packages() {
  local rc=0
  while IFS= read -r path; do
    case "$path" in
      plugins/newspack-plugin/packages/colors/*|\
      plugins/newspack-plugin/packages/components/*|\
      plugins/newspack-plugin/packages/icons/*)
        local rel="${path#plugins/newspack-plugin/packages/}"
        local target="packages/$rel"
        local base_blob theirs_blob
        base_blob=$(git ls-files -s -- "$path" | awk '$3==1{print $2}')
        theirs_blob=$(git ls-files -s -- "$path" | awk '$3==3{print $2}')

        # Legacy deleted the file — drop it on our side too.
        if [ -z "$theirs_blob" ]; then
          git rm -f -- "$path" > /dev/null
          continue
        fi

        # No workspace target.
        if [ ! -e "$target" ]; then
          if [ -z "$base_blob" ]; then
            # Brand new file in legacy — place it at the workspace path.
            mkdir -p "$(dirname "$target")"
            git show "$theirs_blob" > "$target"
            git add "$target"
            git rm -f -- "$path" > /dev/null
          else
            # Workspace removed/renamed it — needs a human.
            rc=1
          fi
          continue
        fi

        # 3-way merge legacy's change into the workspace file.
        local base_src=/tmp/sync-base-$$
        local theirs_src=/tmp/sync-theirs-$$
        local merged=/tmp/sync-merged-$$
        if [ -n "$base_blob" ]; then
          git show "$base_blob" > "$base_src"
        else
          # No common ancestor — fall back to empty base.
          : > "$base_src"
        fi
        git show "$theirs_blob" > "$theirs_src"

        if git merge-file -p "$theirs_src" "$base_src" "$target" > "$merged" 2>/dev/null; then
          cp "$merged" "$target"
          git add "$target"
          git rm -f -- "$path" > /dev/null
        else
          # Conflict markers in $merged — leave them in the workspace file
          # for a human to resolve, but still drop the in-plugin path.
          cp "$merged" "$target"
          git rm -f -- "$path" > /dev/null
          rc=1
        fi
        rm -f "$base_src" "$theirs_src" "$merged"
        ;;
    esac
  done < <(git diff --name-only --diff-filter=U)
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

  git push origin "HEAD:refs/heads/$branch"

  gh pr create \
    --base monorepo-integration \
    --head "$branch" \
    --draft \
    --reviewer adekbadek \
    --title "sync conflict: $name" \
    --body "$(printf 'Daily legacy-sync job hit unresolvable conflicts merging \`%s\` into \`monorepo-integration\`.\n\n**To resolve:**\n\n```\ngh pr checkout %s\ngit grep -lE %s | xargs -r $EDITOR   # fix conflict markers\ngit add -A\ngit commit --amend --no-edit\ngit push --force-with-lease\n```\n\nThen mark this PR ready for review and merge.\n\nFiles with conflict markers:\n\n```\n%s\n```\n' "$name" "$branch" "'^<<<<<<< |^>>>>>>> '" "${marker_files:-(none — only structural conflicts)}")" \
    || echo "WARN: gh pr create failed; conflict branch is at origin/$branch"

  git reset --hard "$saved"
}

git fetch origin --prune --quiet
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

  # Try the easy path first: -Xtheirs lets legacy win all content conflicts,
  # and -Xno-renames disables git's cross-plugin rename heuristic.
  if git merge --no-edit --no-commit -Xno-renames -Xtheirs "origin/sync/$name" > /dev/null 2>&1; then
    git commit --no-edit > /dev/null
    echo "    OK"
    continue
  fi

  apply_structural_overrides "$name"

  if [ "$name" = "newspack-plugin" ]; then
    if ! route_extracted_packages; then
      echo "    ESCALATE (extracted-package routing left conflict markers)"
      escalate "$name" "$saved"
      continue
    fi
  fi

  if [ -z "$(git diff --name-only --diff-filter=U)" ]; then
    git commit --no-edit > /dev/null
    echo "    OK (auto-resolved)"
  else
    echo "    ESCALATE"
    escalate "$name" "$saved"
  fi
done

if [ "$(git rev-parse HEAD)" != "$START" ]; then
  echo "==> Pushing $(git rev-list --count "$START..HEAD") new commits to monorepo-integration"
  git push origin HEAD:monorepo-integration
else
  echo "==> No clean merges this run; nothing to push to monorepo-integration"
fi
