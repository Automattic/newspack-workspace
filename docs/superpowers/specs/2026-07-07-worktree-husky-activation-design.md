# Auto-activate husky in new workspace worktrees

**Status:** Design approved 2026-07-07
**Scope:** `bin/worktree.sh`, new `bin/worktree-tooling.sh`, new `tests/worktree-tooling.test.sh`, one doc line in `AGENTS.md`

## Problem

Since the husky pre-commit lint gate landed (upstream PR #299, NPPM-290), `core.hooksPath` is set to `.husky/_` in the shared `.git/config`. Git resolves that path **relative to each working tree**, and `.husky/_` is a generated, gitignored directory produced by husky's `prepare` script during `pnpm install`.

A freshly created `git worktree` has **no `node_modules` and no `.husky/_`**, so:

- Git finds no hook to run — the JS/SCSS/PHP lint gate silently does not fire in that worktree.
- Even if `.husky/_` were copied in, the tracked `.husky/pre-commit` body runs `pnpm exec lint-staged`, which errors without `node_modules`.

So the only faithful activation is running `pnpm install` in the new worktree, which regenerates `.husky/_` via `prepare` and provides `lint-staged`/`eslint`. Today the operator (and agents) must remember to do this by hand; they forget, and commits land unlinted locally (CI still re-lints, so nothing unsafe ships — but the early local check is lost).

## Goal

When a **tier-1 (workspace) worktree** of the monorepo is created through the project tooling, activate husky automatically by running `pnpm install`, unless explicitly skipped. Keep the change generic and upstreamable; keep the operator's personal shell-test gate out of it.

Non-goals: intercepting `git worktree add` / the Claude harness `EnterWorktree` (cannot be hooked); tier-2 standalone-repo worktrees; installing the personal `.husky/pre-commit.local` shell-test gate.

## Key facts that shape the design

- **Single chokepoint.** Both `n worktree add` and `n env create --worktree` create tier-1 worktrees through `bin/worktree.sh add` (env.sh delegates: `"$NABSPATH/bin/worktree.sh" add "$wt_branch"`). One insertion point covers both entry points; `env.sh` needs no change.
- **Two tier-1 add paths** in `worktree.sh` (existing branch vs. new `-b` branch) converge on a single `echo "Created worktree at ..."`, so one activation call placed after that line covers both.
- **`worktree.sh` exists upstream too** (the trunk carries local additions). The husky-activation behavior is a real upstream gap as well, so it is written as a clean, upstreamable slice.
- **Sourceable-lib pattern exists** in this codebase (`bin/worktree-mounts.sh` is a sourced helper; `link-repos.sh` was made sourceable for tests). The new logic follows it so it is unit-testable without a real install.
- **pnpm is not always on PATH** in every shell (a known background-shell gap), so the helper must degrade gracefully.

## Design

### New file: `bin/worktree-tooling.sh`

A sourceable library defining one function. No top-level execution, so tests can source it safely.

```bash
# Activate husky + node tooling in a freshly created tier-1 workspace worktree.
# A new worktree has no node_modules, so husky's core.hooksPath=.husky/_ points
# at a directory that does not exist and the pre-commit lint gate never runs.
# `pnpm install` regenerates .husky/_ (via the `prepare` script) and provides
# lint-staged/eslint so the hook works. Best-effort: the worktree already
# exists, so an install problem WARNS but never fails worktree creation.
#
# Args: <dir> = worktree path; <no_install> = "true" to skip (default "false").
# Always returns 0.
activate_worktree_tooling() {
    local dir="$1" no_install="${2:-false}"
    if [[ "$no_install" == true ]]; then
        echo "[worktree] --no-install: husky hooks inactive until you run 'pnpm install' in $dir"
        return 0
    fi
    if ! command -v pnpm >/dev/null 2>&1; then
        echo "[worktree] pnpm not on PATH; skipping husky activation. Run 'pnpm install' in $dir to enable pre-commit hooks."
        return 0
    fi
    echo "[worktree] Activating tooling (pnpm install) in $dir ..."
    if ! ( cd "$dir" && pnpm install --frozen-lockfile ); then
        echo "[worktree] Warning: pnpm install failed; husky hooks may be inactive. Run 'pnpm install' in $dir manually."
    fi
    return 0
}
```

Decisions embodied here:

- **Best-effort / non-fatal** (`return 0` on every path) — mirrors #299's "husky activation is non-fatal" and the project's non-destructive rule. The worktree is already created; do not abort on an install hiccup.
- **`--frozen-lockfile`** — fast, and read-only with respect to `pnpm-lock.yaml` so it never dirties the fresh worktree. On the rare dep-changing branch where the lockfile does not match, it fails and drops into the "run pnpm install manually" warning rather than silently rewriting the lockfile.
- **pnpm-missing guard** — prints a note and continues, so shells without pnpm on PATH still get a working (hook-less) worktree plus a clear next step.

### Change: `bin/worktree.sh`

1. Source the new lib next to the existing sources:
   ```bash
   source "$(dirname "${BASH_SOURCE[0]}")/worktree-tooling.sh"
   ```
2. In the `add` argument loop, parse a `--no-install` flag into `no_install` (default `false`). It is accepted for tier-1; for tier-2 it is parsed but inert (documented).
3. After the tier-1 create block — a **single** call placed after the existing `echo "Created worktree at ..."` (the existing-branch and `-b` new-branch paths converge there), so the ordering reads create → activate:
   ```bash
   activate_worktree_tooling "$worktree_dir" "$no_install"
   ```
4. Update the `add` usage strings and the bottom-of-file help block to document `--no-install`.

Tier-2 (standalone-repo) worktrees are left unchanged: they are separate git repos, not the pnpm monorepo, so monorepo husky activation does not apply.

### Unchanged: `bin/env.sh`

`n env create --worktree` delegates to `worktree.sh add` and therefore inherits auto-activation. Env worktrees need `node_modules` to build anyway, so this is the desired behavior; no flag is threaded through.

### Testing: `tests/worktree-tooling.test.sh`

Host-runnable, no real install. Sources `bin/worktree-tooling.sh`, puts a **stub `pnpm`** first on `PATH` that appends its args and `$PWD` to a log file and exits with a controllable code. Asserts:

1. `no_install=true` → prints the skip message, **does not** invoke the stub, returns 0.
2. pnpm absent (PATH scrubbed to a dir without pnpm) → prints the note, returns 0.
3. pnpm present, exits 0 → invokes `pnpm install --frozen-lockfile` with `$PWD` == the target dir, returns 0.
4. pnpm present, exits 1 → prints the warning, still returns 0.

Follows the existing `tests/*.test.sh` harness (`ok()` assert helper, `mktemp -d` fixtures, `set -u`). It joins the suite the shell-tooling gate runs, so the operator's `.husky/pre-commit.local` gate exercises it on any commit touching `bin/` or `tests/`.

### Docs: `AGENTS.md`

Add one sentence to the husky section noting that `n worktree add` and `n env create --worktree` auto-run `pnpm install` in new workspace worktrees to activate the hook (bypass with `--no-install`), and that plain `git worktree add` still needs a manual `pnpm install`.

## Landing

Trunk tooling: implement on `feat/worktree-husky-activation` (branched from trunk `main`), run the shell-tooling suite, and land on the trunk (→ `fork`) only on operator confirmation — no auto-push. Flag as a clean upstream-PR candidate, since upstream shares the same worktree/husky gap.

## Risks and mitigations

- **Install time on every worktree** (~10–15s warm) — mitigated by `--no-install` and by the fact that most tier-1 worktrees want `node_modules` anyway.
- **`--frozen-lockfile` failure on dep-changing branches** — surfaces a clear warning + manual step; does not block creation.
- **Divergence from upstream `worktree.sh`** — additive and self-contained (a source line, a flag, two call sites), so future upstream merges reconcile cleanly; the sliceable helper eases an upstream PR.
