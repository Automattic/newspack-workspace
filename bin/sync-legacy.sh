#!/usr/bin/env bash
#
# sync-legacy.sh
#
# End-to-end driver for the legacy-Newspack → monorepo sync. Runs three phases:
#   1. Filter each legacy repo's trunk into the monorepo layout (parallel).
#   2. Publish per-repo sync/<name> branches (push to origin, or — in dry-run
#      mode — wire them locally as refs/remotes/origin/sync/<name>).
#   3. Integrate those branches into the current HEAD with auto-resolution
#      and per-repo escalation on unresolvable conflicts.
#
# Conflict policy is "legacy wins" (-Xtheirs), since this monorepo is
# pre-cutover — legacy trunks are the source of truth and any monorepo-side
# divergence is preparatory work that legacy supersedes.
#
# Three structural overrides run on top of -Xtheirs:
#   1. Per-plugin .github/ files are dropped (CI runs at the monorepo root).
#   2. plugins/*/package.json and themes/*/package.json take "ours" so the
#      workspace:* constraints survive any legacy version bumps to the
#      newspack-{scripts,components,colors,icons} packages.
#   3. For newspack-plugin only: paths under plugins/newspack-plugin/packages/
#      {colors,components,icons}/ get redirected to the workspace path
#      packages/<pkg>/<rest>. The package homes moved during extraction; legacy
#      updates should land at the new home, not the old in-plugin one.
#
# On unresolvable conflicts: the merge state is rolled back locally, a WIP
# commit with conflict markers is pushed to sync/conflicts/<name>-<timestamp>,
# and a draft PR is opened with @adekbadek requested as reviewer. Other repos
# in the same run continue independently.
#
# Usage:
#   bin/sync-legacy.sh                # full run (CI default)
#   DRY_RUN=1 bin/sync-legacy.sh      # local: no git push, no gh pr create
#
# Run from any worktree whose HEAD is the integration target. CI uses
# monorepo-integration; locally you can test against any tip.
#
# Required tools: git, git-filter-repo, gh (only when DRY_RUN!=1 and a
# conflict triggers escalation).

set -euo pipefail

DRY_RUN="${DRY_RUN:-0}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
SCRATCH_DIR="${SCRATCH_DIR:-/tmp/sync-legacy}"
PARALLEL_FILTER_JOBS="${PARALLEL_FILTER_JOBS:-5}"

# In dry-run mode, transparently re-execute inside a fresh detached-HEAD
# worktree based off the integration target (origin/monorepo-integration by
# default; override with DRY_RUN_BASE) so the caller's working tree and HEAD
# are left untouched, and so dry-runs reproduce CI semantics regardless of
# the branch the user happens to be on.
#
# Trap cleans up the worktree and scratch dir on any exit, including SIGINT.
if [ "$DRY_RUN" = "1" ] && [ "${SYNC_LEGACY_DRY_RUN_ISOLATED:-0}" != "1" ]; then
  DRY_RUN_BASE="${DRY_RUN_BASE:-origin/monorepo-integration}"
  if ! git rev-parse --verify --quiet "$DRY_RUN_BASE" > /dev/null; then
    echo "ERROR: DRY_RUN_BASE '$DRY_RUN_BASE' not found." >&2
    echo "       Either fetch it (git fetch origin monorepo-integration) or" >&2
    echo "       override: DRY_RUN_BASE=<ref> DRY_RUN=1 bin/sync-legacy.sh" >&2
    exit 2
  fi
  BASE_SHA=$(git rev-parse "$DRY_RUN_BASE")
  MAIN_REPO=$(git rev-parse --show-toplevel)
  TEMP_WORKTREE=$(mktemp -d -t sync-legacy-dry-run-XXXXXX)
  TEMP_SCRATCH=$(mktemp -d -t sync-legacy-scratch-XXXXXX)
  echo "==> Dry run: isolating in $TEMP_WORKTREE @ $DRY_RUN_BASE (${BASE_SHA:0:8})"
  git worktree add --quiet --detach "$TEMP_WORKTREE" "$BASE_SHA"

  cleanup() {
    local rc=$?
    cd "$MAIN_REPO" 2>/dev/null || cd /
    # Abort any in-flight merge so the worktree isn't blocked from removal.
    git -C "$TEMP_WORKTREE" merge --abort 2>/dev/null || true
    git -C "$TEMP_WORKTREE" reset --hard 2>/dev/null || true
    git -C "$MAIN_REPO" worktree remove --force "$TEMP_WORKTREE" 2>/dev/null || true
    git -C "$MAIN_REPO" worktree prune 2>/dev/null || true
    rm -rf "$TEMP_SCRATCH"
    return "$rc"
  }
  trap cleanup EXIT INT TERM

  cd "$TEMP_WORKTREE"
  SYNC_LEGACY_DRY_RUN_ISOLATED=1 \
    SCRATCH_DIR="$TEMP_SCRATCH" \
    DRY_RUN=1 \
    bash "$SCRIPT_PATH"
  exit $?
fi

# Manifest: name:target_subdir. Stable order (the integrate phase merges in
# this order, so changing it changes the merge commit shape).
REPOS=(
  newspack-plugin:plugins/newspack-plugin
  newspack-blocks:plugins/newspack-blocks
  newspack-popups:plugins/newspack-popups
  newspack-newsletters:plugins/newspack-newsletters
  newspack-ads:plugins/newspack-ads
  newspack-network:plugins/newspack-network
  newspack-multibranded-site:plugins/newspack-multibranded-site
  newspack-listings:plugins/newspack-listings
  newspack-sponsors:plugins/newspack-sponsors
  newspack-story-budget:plugins/newspack-story-budget
  super-cool-ad-inserter-plugin:plugins/super-cool-ad-inserter-plugin
  republication-tracker-tool:plugins/republication-tracker-tool
  newspack-theme:themes/newspack-theme
  newspack-block-theme:themes/newspack-block-theme
  newspack-scripts:packages/scripts
)

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

# ---------------------------------------------------------------------------
# Phase 1: filter
# ---------------------------------------------------------------------------
filter_all() {
  mkdir -p "$SCRATCH_DIR"
  echo "==> Phase 1: filtering ${#REPOS[@]} legacy repos (P=$PARALLEL_FILTER_JOBS)"

  # Render manifest as one entry per line for xargs.
  local manifest
  manifest=$(printf '%s\n' "${REPOS[@]}")
  local rc=0
  # xargs appends each stdin line as an extra argument after the fixed ones,
  # so positional args inside `bash -c` are: $0=script, $1=scratch, $2=entry.
  echo "$manifest" | xargs -n1 -P"$PARALLEL_FILTER_JOBS" \
    bash -c '
      script="$0"
      scratch="$1"
      entry="$2"
      name="${entry%%:*}"
      target="${entry#*:}"
      if "$script" \
           "https://github.com/Automattic/$name.git" \
           "$target" \
           "$scratch/$name.git" \
           trunk > "$scratch/$name.log" 2>&1; then
        echo "    filtered $name"
      else
        echo "    FAILED $name (see $scratch/$name.log)"
        exit 1
      fi
    ' \
    "$SCRIPT_DIR/sync-legacy-repo.sh" "$SCRATCH_DIR" \
    || rc=$?
  return $rc
}

# ---------------------------------------------------------------------------
# Phase 2: publish
# ---------------------------------------------------------------------------
publish_all() {
  echo "==> Phase 2: publishing sync/<name> branches"
  for entry in "${REPOS[@]}"; do
    local name="${entry%%:*}"
    if [ "$DRY_RUN" = "1" ]; then
      # Wire the filtered tip directly into refs/remotes/origin/sync/<name>
      # so the integrate phase finds it without any push to origin.
      git fetch --quiet --force "$SCRATCH_DIR/$name.git" \
        "refs/heads/trunk:refs/remotes/origin/sync/$name"
      echo "    wired origin/sync/$name (dry-run, no push)"
    else
      git fetch --quiet --force "$SCRATCH_DIR/$name.git" \
        "refs/heads/trunk:refs/heads/sync/$name"
      git push --force --quiet origin "refs/heads/sync/$name:refs/heads/sync/$name"
      echo "    pushed origin/sync/$name"
    fi
  done
}

# ---------------------------------------------------------------------------
# Phase 3: integrate
# ---------------------------------------------------------------------------

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
#   1. Path is conflicted: 3-way merge legacy's change into the workspace file.
#   2. Path is cleanly merged in (legacy added or modified, no monorepo-side
#      change): move/overwrite at the workspace path.
#   3. Path is deleted in legacy: drop it.
# Returns 1 if any routed file ends up with conflict markers, or if a modified
# file has no workspace target.
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

  # Sweep any remaining cleanly-merged files under the extracted dirs (the
  # conflict pass missed them because there was no conflict — just legacy's
  # content landing at the legacy path).
  while IFS= read -r path; do
    [ -z "$path" ] && continue
    local rel="${path#plugins/newspack-plugin/packages/}"
    local target="packages/$rel"
    mkdir -p "$(dirname "$target")"
    git show ":0:$path" > "$target"
    git add "$target"
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

  git add -A
  git commit --no-edit \
    -m "sync(conflict): unresolved merge of ${name} into monorepo-integration"

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

integrate_all() {
  echo "==> Phase 3: integrating into $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo HEAD)"
  local START
  START=$(git rev-parse HEAD)

  for entry in "${REPOS[@]}"; do
    local name="${entry%%:*}"
    local saved
    saved=$(git rev-parse HEAD)

    if ! git rev-parse --verify --quiet "origin/sync/$name" > /dev/null; then
      echo "SKIP $name (no sync branch — phase 2 hasn't published it yet)"
      continue
    fi
    local ahead
    ahead=$(git rev-list --count "HEAD..origin/sync/$name")
    if [ "$ahead" -eq 0 ]; then
      echo "SKIP $name (up to date)"
      continue
    fi
    echo "==> $name (+$ahead commits)"

    local merge_clean=0
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
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
filter_all
publish_all
integrate_all
