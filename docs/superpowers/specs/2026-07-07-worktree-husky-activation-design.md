# Auto-activate husky in new workspace worktrees

**Status:** Design approved 2026-07-07; revised after multi-model (`magi`) review 2026-07-07
**Scope:** `bin/worktree.sh`, new `bin/worktree-tooling.sh`, new `tests/worktree-tooling.test.sh`, one doc line in `AGENTS.md`

## Problem

Since the husky pre-commit lint gate landed (upstream PR #299, NPPM-290), `core.hooksPath` is set to `.husky/_` in the shared `.git/config`. Git resolves that path **relative to each working tree**, and `.husky/_` is a generated, gitignored directory produced by husky's `prepare` script during `pnpm install`.

A freshly created `git worktree` has **no `node_modules` and no `.husky/_`**, so:

- Git finds no hook to run — the JS/SCSS/PHP lint gate silently does not fire in that worktree.
- Even if `.husky/_` were copied in, the tracked `.husky/pre-commit` body runs `pnpm exec lint-staged`, which errors without `node_modules`.

So the only faithful activation is running `pnpm install` in the new worktree, which regenerates `.husky/_` via `prepare` and provides `lint-staged`/`eslint`. Today the operator (and agents) must remember to do this by hand; they forget, and commits land unlinted locally (CI still re-lints, so nothing unsafe ships — but the early local check is lost).

## Goal

When a **tier-1 (workspace) worktree** of the monorepo is created through **`n worktree add`**, activate husky automatically by running `pnpm install`, unless `--no-install` is passed. `n env create --worktree` also activates (it delegates to the same code) but does **not** expose a skip flag — env worktrees need `node_modules` to build, so activation is always wanted there. Keep the change generic and upstreamable; keep the operator's personal shell-test gate out of it.

Non-goals: intercepting `git worktree add` / the Claude harness `EnterWorktree` (cannot be hooked); tier-2 standalone-repo worktrees; installing the personal `.husky/pre-commit.local` shell-test gate.

## Key facts that shape the design

- **Single chokepoint.** Both `n worktree add` and `n env create --worktree` create tier-1 worktrees through `bin/worktree.sh add` (env.sh delegates: `"$NABSPATH/bin/worktree.sh" add "$wt_branch"`). One insertion point covers both entry points; `env.sh` needs no change.
- **Two tier-1 add paths** in `worktree.sh` (existing branch vs. new `-b` branch) converge on a single `echo "Created worktree at ..."`, so one activation call placed after that line covers both.
- **`worktree.sh` exists upstream too** (the trunk carries local additions). The husky-activation behavior is a real upstream gap as well, so it is written as a clean, upstreamable slice.
- **Sourceable-lib pattern exists** in this codebase (`bin/worktree-mounts.sh` is a sourced helper; `link-repos.sh` was made sourceable for tests). The new logic follows it so it is unit-testable without a real install.
- **`pnpm install` exiting 0 does NOT guarantee husky activated.** husky's `prepare` step is a documented no-op under `HUSKY=0` (which `AGENTS.md` recommends for agents/automation), `NODE_ENV=production`, or the linked-worktree `.git`-as-file case — while `pnpm install` still exits 0. The activation must therefore verify the *outcome* (`.husky/_/pre-commit` exists), not just the install exit code.
- **pnpm is not always on PATH** in every shell (a known background-shell gap), so the helper must degrade gracefully.

## Design

### New file: `bin/worktree-tooling.sh`

A sourceable library defining one function. No top-level execution, so tests can source it safely.

```bash
# Activate husky + node tooling in a freshly created tier-1 workspace worktree.
# A new worktree has no node_modules, so husky's core.hooksPath=.husky/_ points
# at a directory that does not exist and the pre-commit lint gate never runs.
# `pnpm install` regenerates .husky/_ (via the `prepare` script) and provides
# lint-staged/eslint so the hook works.
#
# Best-effort: the worktree already exists, so every problem WARNS and the
# function ALWAYS returns 0 — it must never fail worktree creation.
#
# Args: <dir> = worktree path; <no_install> = "true" to skip (default "false").
activate_worktree_tooling() {
    local dir="$1" no_install="${2:-false}"

    if [[ "$no_install" == true ]]; then
        echo "[worktree] --no-install: husky hooks inactive until you run 'pnpm install' in $dir"
        return 0
    fi
    local pnpm_bin
    pnpm_bin="$(command -v pnpm 2>/dev/null)"
    if [[ -z "$pnpm_bin" || ! -x "$pnpm_bin" ]]; then
        # Explicit -x check: macOS's stock /bin/bash 3.2 `command -v` does not
        # verify the executable bit, so a present-but-non-executable pnpm must be
        # caught here to degrade gracefully rather than fail loudly at install.
        echo "[worktree] pnpm not available on PATH; skipping husky activation. Run 'pnpm install' in $dir to enable pre-commit hooks."
        return 0
    fi
    if [[ ! -d "$dir" ]]; then
        # Distinguish a missing/inaccessible worktree dir from a pnpm failure so
        # the warning does not misdirect debugging.
        echo "[worktree] Warning: worktree dir $dir not found; skipping husky activation."
        return 0
    fi

    echo "[worktree] Activating tooling (pnpm install) in $dir ..."
    # Frozen first: fast, and read-only w.r.t. pnpm-lock.yaml so a normal branch
    # is never dirtied. On a dependency-changing branch the lockfile is out of
    # sync and --frozen-lockfile fails; fall back to a full install so the hook
    # still activates (this may update pnpm-lock.yaml in the worktree — noted).
    if ! ( cd "$dir" && pnpm install --frozen-lockfile ); then
        echo "[worktree] Lockfile out of sync with manifests; retrying with a full 'pnpm install' (may update pnpm-lock.yaml in the worktree)..."
        ( cd "$dir" && pnpm install ) || echo "[worktree] Warning: pnpm install failed; husky hooks may be inactive. Run 'pnpm install' in $dir manually."
    fi

    # Verify the load-bearing outcome, not just the install exit code: `prepare`
    # can silently no-op (HUSKY=0, NODE_ENV=production, .git-as-file) while the
    # install still exits 0. Assert so a hookless worktree is discoverable.
    if [[ ! -e "$dir/.husky/_/pre-commit" ]]; then
        echo "[worktree] Warning: husky hook not present after install; pre-commit hooks are INACTIVE in $dir."
        echo "[worktree]   Likely HUSKY=0 or NODE_ENV=production in this shell — re-run 'pnpm install' in $dir without those to activate."
    fi
    return 0
}
```

Decisions embodied here:

- **Best-effort / non-fatal** (`return 0` on every path) — mirrors #299's "husky activation is non-fatal" and the project's non-destructive rule. The worktree is already created; do not abort on an install hiccup.
- **Outcome assertion** — the design's load-bearing claim is "the hook fires," not "pnpm ran," so the helper checks `.husky/_/pre-commit` exists and surfaces a clear, actionable warning when it does not. This closes the silent-failure gap (notably `HUSKY=0`, which this repo's own guidance recommends).
- **Frozen-first with a full-install fallback** — fast and churn-free on normal branches, but still activates the hook on dependency-changing branches (the case that most benefits from a local gate), with an explicit notice that the lockfile may change.
- **`cd`/pnpm/dir guards** — each degradation prints a distinct message so failures are not conflated.

### Change: `bin/worktree.sh`

1. Source the new lib next to the existing sources:
   ```bash
   source "$(dirname "${BASH_SOURCE[0]}")/worktree-tooling.sh"
   ```
2. In the `add` argument loop, parse a `--no-install` flag into `no_install` (default `false`).
3. After the tier-1 create block — a **single** call placed after the existing `echo "Created worktree at ..."` (both add paths converge there), so ordering reads create → activate:
   ```bash
   activate_worktree_tooling "$worktree_dir" "$no_install"
   ```
4. Update the `add` usage strings and the bottom-of-file help block to document `--no-install`, **explicitly stating it applies to tier-1 workspace worktrees and is ignored for tier-2 (`--repo`) standalone worktrees**.

Tier-2 (standalone-repo) worktrees are left unchanged: they are separate git repos, not the pnpm monorepo, so monorepo husky activation does not apply. `--no-install` is accepted there but inert (documented, so it is not a silent no-op).

### Unchanged: `bin/env.sh`

`n env create --worktree` delegates to `worktree.sh add` and therefore inherits auto-activation. Env worktrees need `node_modules` to build anyway, so this is the desired behavior; no skip flag is threaded through. The skip guarantee is intentionally scoped to `n worktree add` (see Goal).

### Testing: `tests/worktree-tooling.test.sh`

Host-runnable, no real install. Sources `bin/worktree-tooling.sh`, puts a **stub `pnpm`** first on `PATH` that appends its args and `$PWD` to a log file and exits with a controllable code. Asserts:

1. `no_install=true` → prints the skip message, **does not** invoke the stub, returns 0.
2. pnpm absent (PATH scrubbed to a dir without pnpm) → prints the note, returns 0.
3. pnpm present but **non-executable** (`chmod -x` the stub) → degrades to the same skip-with-note path, returns 0.
4. pnpm present, exits 0, and a fake `.husky/_/pre-commit` exists in the dir → invokes `pnpm install --frozen-lockfile` with `$PWD` == the target dir (exact flag asserted), no warning, returns 0.
5. pnpm present, `--frozen-lockfile` exits non-zero, plain install exits 0 → the fallback runs a second `pnpm install` (no `--frozen-lockfile`), returns 0.
6. pnpm succeeds but `.husky/_/pre-commit` is **absent** → prints the "hooks INACTIVE" warning, returns 0.
7. missing worktree dir → prints the dir-not-found warning, does not invoke the stub, returns 0.

Follows the existing `tests/*.test.sh` harness (`ok()` assert helper, `mktemp -d` fixtures, `set -u`). It joins the suite the shell-tooling gate runs, so the operator's `.husky/pre-commit.local` gate exercises it on any commit touching `bin/` or `tests/`.

### Docs: `AGENTS.md`

Add to the husky section: `n worktree add` and `n env create --worktree` auto-run `pnpm install` in new workspace worktrees to activate the hook (`n worktree add --no-install` to skip). Note plainly — as the common case, not a footnote — that worktrees created by **plain `git worktree add` or the Claude `EnterWorktree` tool bypass this and still need a manual `pnpm install`** to get hooks.

## Landing

Trunk tooling: implement on `feat/worktree-husky-activation` (branched from trunk `main`), and land on the trunk (→ `fork`) only on operator confirmation — no auto-push. Flag as a clean upstream-PR candidate, since upstream shares the same worktree/husky gap.

Verification before landing (do all):

1. Run the full `tests/*.test.sh` shell-tooling suite — all green, including the new `worktree-tooling.test.sh`.
2. **End-to-end**: `n worktree add <throwaway-branch>`, then in that worktree stage a lint-failing JS file and `git commit` — confirm husky **blocks** it (proves the hook actually fires, not just that pnpm ran). Then `--no-install` on another worktree and confirm the skip message + inactive hooks.
3. Remove the throwaway worktrees.

## Risks and mitigations

- **Install time on every worktree** (~10–15s warm) — mitigated by `--no-install` (for `n worktree add`) and by the fact that most tier-1 worktrees want `node_modules` anyway.
- **Silent non-activation** (`HUSKY=0` etc.) — mitigated by the post-install `.husky/_/pre-commit` assertion, which turns a silent failure into a visible, actionable warning.
- **`--frozen-lockfile` failure on dep-changing branches** — mitigated by the full-install fallback (hook still activates), with a notice that the lockfile may change.
- **Divergence from upstream `worktree.sh`** — additive and self-contained (a source line, a flag, one call site), so future upstream merges reconcile cleanly; the sliceable helper eases an upstream PR.

## Multi-model review record

Reviewed with `magi review` (design-review prompt, reviewers: claude/codex/mistral + synthesis) on 2026-07-07 — run `.magi/runs/20260707T142008Z.163`. Verdict: sound and well-scoped, all findings medium-or-below (nothing ship-blocking). Revisions folded in from that review: the post-install outcome assertion (silent-failure gap), the frozen→full-install fallback, narrowing the skip contract to `n worktree add`, the tier-2 help-text clarification and `cd`/pnpm failure distinction, the non-executable-pnpm and end-to-end coverage, and framing the `EnterWorktree`/bare-`git worktree add` gap as the common residual case in `AGENTS.md`.
