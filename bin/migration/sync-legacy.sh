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
# Run from any worktree whose HEAD is the integration target. CI uses main;
# locally you can test against any tip.
#
# Required tools: git, git-filter-repo, gh (gh is used on a real run to open
# and auto-merge the PR to main, and to escalate conflicts).

set -euo pipefail

DRY_RUN="${DRY_RUN:-0}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")"
SCRATCH_DIR="${SCRATCH_DIR:-/tmp/sync-legacy}"
PARALLEL_FILTER_JOBS="${PARALLEL_FILTER_JOBS:-5}"

# Shared helpers (manifest, structural overrides, dry-run wrappers, etc.)
# live in lib.sh.
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"

# In dry-run mode, transparently re-execute inside a fresh detached-HEAD
# worktree based off the integration target (origin/main by default; override
# with DRY_RUN_BASE) so the caller's working tree and HEAD are left untouched,
# and so dry-runs reproduce CI semantics regardless of the branch the user
# happens to be on.
#
# Trap cleans up the worktree and scratch dir on any exit, including SIGINT.
if [ "$DRY_RUN" = "1" ] && [ "${SYNC_LEGACY_DRY_RUN_ISOLATED:-0}" != "1" ]; then
  DRY_RUN_BASE="${DRY_RUN_BASE:-origin/main}"
  if ! git rev-parse --verify --quiet "$DRY_RUN_BASE" > /dev/null; then
    echo "ERROR: DRY_RUN_BASE '$DRY_RUN_BASE' not found." >&2
    echo "       Either fetch it (git fetch origin main) or" >&2
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

# ---------------------------------------------------------------------------
# Phase 1: filter
# ---------------------------------------------------------------------------
filter_all() {
  mkdir -p "$SCRATCH_DIR"
  echo "==> Phase 1: filtering ${#LEGACY_REPOS[@]} legacy repos (P=$PARALLEL_FILTER_JOBS)"

  # Render manifest as one entry per line for xargs.
  local manifest
  manifest=$(printf '%s\n' "${LEGACY_REPOS[@]}")
  local rc=0
  # xargs appends each stdin line as an extra argument after the fixed ones,
  # so positional args inside `bash -c` are: $0=script, $1=scratch, $2=entry.
  # LEGACY_SYNC_GH_TOKEN, if set, authenticates clones for private legacy
  # repos (e.g. newspack-story-budget). The default Actions GITHUB_TOKEN is
  # scoped to the running repo only, so a separate org-level token is needed.
  # Token stays in the environment — never on the command line — so it's not
  # visible to other processes via ps.
  echo "$manifest" | xargs -n1 -P"$PARALLEL_FILTER_JOBS" \
    bash -c '
      script="$0"
      scratch="$1"
      entry="$2"
      name="${entry%%:*}"
      target="${entry#*:}"
      if [ -n "${LEGACY_SYNC_GH_TOKEN:-}" ]; then
        url="https://x-access-token:${LEGACY_SYNC_GH_TOKEN}@github.com/Automattic/$name.git"
      else
        url="https://github.com/Automattic/$name.git"
      fi
      if "$script" \
           "$url" \
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
  for entry in "${LEGACY_REPOS[@]}"; do
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

# Push the conflicted state to a sync/conflicts/* branch and open a draft PR.
escalate() {
  local name=$1 saved=$2
  local branch="sync/conflicts/${name}-$(date -u +%Y%m%d-%H%M%S)"

  git add -A
  git commit --no-edit \
    -m "sync(conflict): unresolved merge of ${name} into main"

  local marker_files
  marker_files=$(git grep -lE '^<<<<<<< |^>>>>>>> ' HEAD -- 2>/dev/null | head -50 || true)

  git_push origin "HEAD:refs/heads/$branch"

  gh_pr_create \
    --base main \
    --head "$branch" \
    --draft \
    --reviewer adekbadek \
    --title "sync conflict: $name" \
    --body "$(printf 'Daily legacy-sync job hit unresolvable conflicts merging \`%s\` into \`main\`.\n\n**To resolve:**\n\n```\ngh pr checkout %s\ngit grep -lE %s | xargs -r $EDITOR   # fix conflict markers\ngit add -A\ngit commit --amend --no-edit\ngit push --force-with-lease\n```\n\nThen mark this PR ready for review and merge.\n\nFiles with conflict markers:\n\n```\n%s\n```\n' "$name" "$branch" "'^<<<<<<< |^>>>>>>> '" "${marker_files:-(none — only structural conflicts)}")"

  git reset --hard "$saved"
}

integrate_all() {
  echo "==> Phase 3: integrating into $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo HEAD)"
  local START
  START=$(git rev-parse HEAD)

  for entry in "${LEGACY_REPOS[@]}"; do
    local name="${entry%%:*}"
    local target="${entry#*:}"
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

    apply_structural_overrides "$target"

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

    normalize_package_repos
    restore_workspace_deps

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
    regenerate_lockfile
    land_on_main "$START"
  else
    echo "==> No clean merges this run; nothing to land on main"
  fi
}

# Land the integrated commits on main via an auto-merging PR. main is protected
# (no direct pushes), so the sync force-updates the standing sync/legacy-incoming
# branch, ensures a PR to main is open, and enables auto-merge with a MERGE
# commit. Squash is never used: it would collapse the per-plugin merge commits
# (and the individual legacy commits they carry), and semantic-release would then
# mis-compute version bumps. Auto-merge clears main's required review because the
# matticbot identity (whose token runs gh here) is on main's bypass list; it
# completes once the required CI check passes.
INCOMING_BRANCH="${INCOMING_BRANCH:-sync/legacy-incoming}"
land_on_main() {
  local start=$1
  local n
  n=$(git rev-list --count "$start..HEAD")
  echo "==> Landing $n new commits on main via PR (branch: $INCOMING_BRANCH)"
  if [ "$DRY_RUN" = "1" ]; then
    echo "    [dry-run] would force-push HEAD:$INCOMING_BRANCH, open/refresh a PR to main, wait for its CI, then admin-merge"
    return 0
  fi
  git_push origin --force "HEAD:refs/heads/$INCOMING_BRANCH"
  # Open the PR if one isn't already open for the branch (the force-push above
  # updates an existing one in place).
  if ! gh pr view "$INCOMING_BRANCH" --json state --jq '.state' 2>/dev/null | grep -q OPEN; then
    gh pr create \
      --base main \
      --head "$INCOMING_BRANCH" \
      --title "sync: land legacy trunk commits" \
      --body "$(printf 'Automated daily sync of commits that merged on the (frozen) legacy trunks into the monorepo.\n\nLanded automatically by the sync job (admin merge with a **merge commit** after CI passes — never squash, which would collapse the per-plugin commits and break semantic-release version computation).')" \
      || echo "WARN: gh pr create failed; branch is on origin for manual handling"
  fi
  # Wait for the PR's required checks, then admin-merge with a merge commit.
  # GitHub auto-merge can't be used: it ignores bypass allowances and would block
  # on main's required review. matticbot is a repo admin, so --admin merges past
  # the review once CI is green. On CI failure the PR is left open for review.
  echo "    waiting for CI on $INCOMING_BRANCH ..."
  if gh pr checks "$INCOMING_BRANCH" --watch --fail-fast > /dev/null 2>&1; then
    gh pr merge "$INCOMING_BRANCH" --merge --admin \
      && echo "    landed on main (admin merge after green CI)" \
      || echo "WARN: admin merge failed; PR is open on origin for manual merge"
  else
    echo "WARN: CI failed or incomplete on $INCOMING_BRANCH; leaving PR open for review"
  fi
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
filter_all
publish_all
integrate_all
