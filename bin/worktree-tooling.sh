#!/bin/bash
# Sourceable helper for activating husky/node tooling in a new workspace worktree.
# No top-level execution — safe to `source` from bin/worktree.sh and from tests.

# Activate husky + node tooling in a freshly created tier-1 workspace worktree.
# A new worktree has no node_modules, so husky's core.hooksPath=.husky/_ points
# at a directory that does not exist and the pre-commit lint gate never runs.
# `pnpm install` regenerates .husky/_ (via the `prepare` script) and provides
# lint-staged/eslint so the hook works.
#
# Best-effort: the worktree already exists, so every problem WARNS and this
# function ALWAYS returns 0 — it must never fail worktree creation.
#
# Args: $1 = worktree path; $2 = "true" to skip install (default "false").
activate_worktree_tooling() {
    local dir="$1" no_install="${2:-false}"

    if [[ "$no_install" == true ]]; then
        echo "[worktree] --no-install: husky hooks inactive until you run 'pnpm install' in $dir"
        return 0
    fi
    local pnpm_bin
    pnpm_bin="$(command -v pnpm 2>/dev/null)"
    if [[ -z "$pnpm_bin" || ! -x "$pnpm_bin" ]]; then
        # Explicit -x check (not just `command -v`'s exit status): some bash
        # builds' `command -v` does not verify the executable bit, so a
        # present-but-non-executable pnpm would otherwise slip past this guard
        # and fail loudly during install instead of degrading gracefully here.
        echo "[worktree] pnpm not available on PATH; skipping husky activation. Run 'pnpm install' in $dir to enable pre-commit hooks."
        return 0
    fi
    if [[ ! -d "$dir" ]]; then
        echo "[worktree] Warning: worktree dir $dir not found; skipping husky activation."
        return 0
    fi

    echo "[worktree] Activating tooling (pnpm install) in $dir ..."
    # Frozen first: fast and read-only w.r.t. pnpm-lock.yaml. On a dependency-
    # changing branch the lockfile is out of sync and --frozen-lockfile fails;
    # fall back to a full install so the hook still activates.
    if ! ( cd "$dir" && pnpm install --frozen-lockfile ); then
        echo "[worktree] Lockfile out of sync with manifests; retrying with a full 'pnpm install' (may update pnpm-lock.yaml in the worktree)..."
        ( cd "$dir" && pnpm install ) || echo "[worktree] Warning: pnpm install failed; husky hooks may be inactive. Run 'pnpm install' in $dir manually."
    fi

    # Verify the load-bearing outcome, not just the exit code: `prepare` can
    # silently no-op (HUSKY=0, NODE_ENV=production, .git-as-file) while install
    # still exits 0. Assert so a hookless worktree is discoverable.
    if [[ ! -e "$dir/.husky/_/pre-commit" ]]; then
        echo "[worktree] Warning: husky hook not present after install; pre-commit hooks are INACTIVE in $dir."
        echo "[worktree]   Likely HUSKY=0 or NODE_ENV=production in this shell — re-run 'pnpm install' in $dir without those to activate."
    fi
    return 0
}
