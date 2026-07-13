# Auto-activate husky in new workspace worktrees — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `n worktree add` (and `n env create --worktree`) run `pnpm install` in a freshly created tier-1 workspace worktree so husky's pre-commit lint gate is actually active there, and warn clearly when activation did not take.

**Architecture:** Add one sourceable helper (`bin/worktree-tooling.sh`) holding `activate_worktree_tooling`, unit-tested in isolation with a stub `pnpm`. Wire it into the single worktree-creation chokepoint (`bin/worktree.sh add`, tier-1 branch) behind a new `--no-install` flag. `bin/env.sh` is unchanged — it delegates to `worktree.sh add`, so it inherits activation.

**Tech Stack:** Bash (bin/ tooling), the project's `tests/*.test.sh` host-runnable harness, pnpm 10.x, husky v9.

## Global Constraints

- Helper is **best-effort / non-fatal**: `activate_worktree_tooling` ALWAYS returns 0; a problem WARNS, never aborts worktree creation. (Verbatim from spec.)
- Verify the **outcome** (`.husky/_/pre-commit` exists), not just the `pnpm install` exit code — `prepare` can no-op under `HUSKY=0` / `NODE_ENV=production` / `.git`-as-file while install still exits 0.
- `--no-install` is a **`n worktree add`** guarantee only; `n env create --worktree` always activates. It is accepted but **inert for tier-2 (`--repo`)** worktrees, and the help text must say so.
- Install command: **`pnpm install --frozen-lockfile` first**, then fall back to a plain `pnpm install` if frozen fails (dependency-changing branch), printing a notice.
- Follow existing tooling conventions: source siblings via `"$(dirname "${BASH_SOURCE[0]}")/<lib>.sh"`; tests use the `ok()` helper + `mktemp -d` fixtures + `set -u`.
- No changes to tracked `.husky/*`, `.lintstagedrc.json`, or upstream lint wrappers.

---

## File Structure

- **Create `bin/worktree-tooling.sh`** — sourceable lib; defines `activate_worktree_tooling <dir> <no_install>`. No top-level execution.
- **Create `tests/worktree-tooling.test.sh`** — host-runnable unit tests for the helper, using a stub `pnpm`.
- **Modify `bin/worktree.sh`** — source the lib; init + parse `--no-install`; call the helper after the tier-1 create; update usage/help text.
- **Modify `AGENTS.md`** — one note in the husky section about auto-activation and the `EnterWorktree`/bare-`git worktree add` residual gap.

---

## Task 1: `activate_worktree_tooling` helper + unit tests

**Files:**
- Create: `bin/worktree-tooling.sh`
- Test: `tests/worktree-tooling.test.sh`

**Interfaces:**
- Consumes: nothing (leaf helper).
- Produces: `activate_worktree_tooling <dir> <no_install>` — runs `pnpm install` in `<dir>` (frozen-first, plain fallback), asserts `.husky/_/pre-commit` afterward, prints `[worktree] ...` progress/warnings to stdout, **always returns 0**. `<no_install>` defaults to `"false"`.

- [ ] **Step 1: Write the failing test**

Create `tests/worktree-tooling.test.sh`:

```bash
#!/bin/bash
# Unit tests for bin/worktree-tooling.sh:activate_worktree_tooling. Host-runnable.
# Uses a stub `pnpm` on PATH so no real install runs; asserts the helper's
# frozen-first/plain-fallback, the .husky/_ outcome check, and its graceful
# degradation (best-effort, always returns 0).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
has(){ if printf '%s' "$2" | grep -qF -- "$3"; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (missing [$3] in: $2)"; fail=$((fail+1)); fi; }
hasnt(){ if printf '%s' "$2" | grep -qF -- "$3"; then echo "  FAIL  $1 (unexpected [$3])"; fail=$((fail+1)); else echo "  PASS  $1"; pass=$((pass+1)); fi; }

source "$BIN/worktree-tooling.sh"

# Build a stub `pnpm` in $1/bin that logs "<cwd> | <args>" to $PNPM_LOG and exits
# with PNPM_FROZEN_EXIT for a --frozen-lockfile call, PNPM_PLAIN_EXIT otherwise.
make_stub_dir(){
    mkdir -p "$1/bin"
    cat > "$1/bin/pnpm" <<'STUB'
#!/bin/bash
echo "$PWD | $*" >> "$PNPM_LOG"
case "$*" in
    *--frozen-lockfile*) exit "${PNPM_FROZEN_EXIT:-0}" ;;
    *) exit "${PNPM_PLAIN_EXIT:-0}" ;;
esac
STUB
    chmod +x "$1/bin/pnpm"
}

# Run the helper with PATH pointed only at $stub_bin (so system pnpm can't leak);
# builtins cover everything else the helper needs. Captures combined output in $OUT
# and return code in $RC without polluting the test shell's PATH.
run_helper(){ # $1=stub_bin (or "" for none), $2=dir, $3=no_install
    local sp="$1" dir="$2" ni="$3" oldpath="$PATH"
    PATH="${sp:-$FIX/emptybin}"
    OUT="$(activate_worktree_tooling "$dir" "$ni" 2>&1)"; RC=$?
    PATH="$oldpath"
}

mkdir -p "$FIX/emptybin"   # a PATH dir guaranteed to lack pnpm

# --- Case 1: no_install=true -> skip, do not invoke pnpm ---
S1="$FIX/c1"; make_stub_dir "$S1"; export PNPM_LOG="$S1/log"; : > "$PNPM_LOG"
D1="$FIX/wt1"; mkdir -p "$D1/.husky/_"; : > "$D1/.husky/_/pre-commit"
run_helper "$S1/bin" "$D1" true
has  "case1 prints --no-install skip"        "$OUT" "--no-install"
ok   "case1 pnpm NOT invoked (empty log)"    "$(cat "$PNPM_LOG")" ""
ok   "case1 returns 0"                        "$RC" "0"

# --- Case 2: pnpm absent on PATH -> note, return 0 ---
D2="$FIX/wt2"; mkdir -p "$D2"
run_helper "$FIX/emptybin" "$D2" false
has  "case2 prints pnpm-not-available note"  "$OUT" "pnpm not available"
ok   "case2 returns 0"                        "$RC" "0"

# --- Case 3: pnpm present but non-executable -> degrades to the same note ---
S3="$FIX/c3"; make_stub_dir "$S3"; chmod -x "$S3/bin/pnpm"
export PNPM_LOG="$S3/log"; : > "$PNPM_LOG"
D3="$FIX/wt3"; mkdir -p "$D3"
run_helper "$S3/bin" "$D3" false
has  "case3 non-exec pnpm -> not-available"  "$OUT" "pnpm not available"
ok   "case3 pnpm NOT invoked"                 "$(cat "$PNPM_LOG")" ""
ok   "case3 returns 0"                         "$RC" "0"

# --- Case 4: frozen install succeeds, hook present -> no fallback, no warning ---
S4="$FIX/c4"; make_stub_dir "$S4"; export PNPM_LOG="$S4/log"; : > "$PNPM_LOG"
export PNPM_FROZEN_EXIT=0 PNPM_PLAIN_EXIT=0
D4="$FIX/wt4"; mkdir -p "$D4/.husky/_"; : > "$D4/.husky/_/pre-commit"
run_helper "$S4/bin" "$D4" false
has  "case4 invoked with --frozen-lockfile in dir" "$(cat "$PNPM_LOG")" "$D4 | install --frozen-lockfile"
ok   "case4 exactly one pnpm invocation"      "$(wc -l < "$PNPM_LOG" | tr -d ' ')" "1"
hasnt "case4 no INACTIVE warning"             "$OUT" "INACTIVE"
ok   "case4 returns 0"                         "$RC" "0"

# --- Case 5: frozen fails -> plain fallback runs; hook present -> no warning ---
S5="$FIX/c5"; make_stub_dir "$S5"; export PNPM_LOG="$S5/log"; : > "$PNPM_LOG"
export PNPM_FROZEN_EXIT=1 PNPM_PLAIN_EXIT=0
D5="$FIX/wt5"; mkdir -p "$D5/.husky/_"; : > "$D5/.husky/_/pre-commit"
run_helper "$S5/bin" "$D5" false
has  "case5 frozen attempted"                 "$(cat "$PNPM_LOG")" "install --frozen-lockfile"
ok   "case5 two invocations (frozen+plain)"   "$(wc -l < "$PNPM_LOG" | tr -d ' ')" "2"
has  "case5 prints fallback notice"           "$OUT" "retrying with a full"
ok   "case5 returns 0"                         "$RC" "0"
unset PNPM_FROZEN_EXIT PNPM_PLAIN_EXIT

# --- Case 6: install ok but hook absent -> INACTIVE warning ---
S6="$FIX/c6"; make_stub_dir "$S6"; export PNPM_LOG="$S6/log"; : > "$PNPM_LOG"
D6="$FIX/wt6"; mkdir -p "$D6"   # no .husky/_/pre-commit
run_helper "$S6/bin" "$D6" false
has  "case6 warns hooks INACTIVE"             "$OUT" "INACTIVE"
ok   "case6 returns 0"                         "$RC" "0"

# --- Case 7: missing worktree dir -> dir-not-found warning, no pnpm ---
S7="$FIX/c7"; make_stub_dir "$S7"; export PNPM_LOG="$S7/log"; : > "$PNPM_LOG"
run_helper "$S7/bin" "$FIX/does-not-exist" false
has  "case7 warns dir not found"              "$OUT" "not found"
ok   "case7 pnpm NOT invoked"                 "$(cat "$PNPM_LOG")" ""
ok   "case7 returns 0"                         "$RC" "0"

echo ""
echo "worktree-tooling: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash tests/worktree-tooling.test.sh`
Expected: FAIL — `activate_worktree_tooling: command not found` / non-zero exit (the lib does not exist yet).

- [ ] **Step 3: Write the helper**

Create `bin/worktree-tooling.sh`:

```bash
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
        # builds (notably macOS's stock /bin/bash 3.2) do not verify the
        # executable bit, so a present-but-non-executable pnpm would slip past
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash tests/worktree-tooling.test.sh`
Expected: PASS — `worktree-tooling: 21 passed, 0 failed` and exit 0.

- [ ] **Step 5: Commit**

```bash
git add bin/worktree-tooling.sh tests/worktree-tooling.test.sh
git commit -m "feat(worktree): add husky-activation helper for new worktrees"
```
(This worktree has no active husky, so no hook runs; the test was already run in Step 4.)

---

## Task 2: Wire into `bin/worktree.sh` + document, with a real end-to-end check

**Files:**
- Modify: `bin/worktree.sh` (source lib; init/parse `--no-install`; call helper after tier-1 create; usage/help text)
- Modify: `AGENTS.md` (husky section note)

**Interfaces:**
- Consumes: `activate_worktree_tooling <dir> <no_install>` from Task 1.
- Produces: `n worktree add <branch> [--no-install]` (tier-1 auto-activates unless skipped); `n env create --worktree` inherits activation unchanged.

- [ ] **Step 1: Source the helper**

In `bin/worktree.sh`, after the existing `source` lines (currently lines 3–4), add a third:

```bash
source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"
source "$(dirname "${BASH_SOURCE[0]}")/worktree-tooling.sh"
```

- [ ] **Step 2: Init and parse `--no-install` in the `add` case**

In the `add)` branch, find the flag-init + arg-parse loop (currently around lines 41–48). Add `no_install=false` next to `repo=""`, and a `--no-install` case:

```bash
        shift  # consume "add"
        repo=""
        no_install=false
        positionals=()
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --repo) repo="$2"; shift 2 ;;
                --no-install) no_install=true; shift ;;
                *) positionals+=("$1"); shift ;;
            esac
        done
```

- [ ] **Step 3: Call the helper after the tier-1 create**

In the tier-1 (workspace) branch, find the line `echo "Created worktree at worktrees/$safe_branch"` (currently line 104, immediately before the `;;` that ends the `add` case). Add the activation call right after it:

```bash
        echo "Created worktree at worktrees/$safe_branch"
        activate_worktree_tooling "$worktree_dir" "$no_install"
        ;;
```

(The tier-2 `--repo` branch returns earlier via its own `exit 0`, so it is untouched — `--no-install` is inert there by design.)

- [ ] **Step 4: Update usage + help text**

In the two `add` usage strings (currently lines 56–57) add the flag:

```bash
            echo "Usage: n worktree add <branch> [--repo <name>] [--no-install]"
            echo "   or: n worktree add <repo> <branch>  (legacy; repo arg ignored for tier 1)"
```

In the bottom-of-file help block (currently the `add` line ~360), document it, including the tier-2 caveat:

```bash
        echo "  add <branch> [--repo <name>] [--no-install]  Create a worktree at the given branch"
        echo "                                              (--repo: a standalone repos/{plugins,themes}/<name> checkout)"
        echo "                                              (--no-install: skip auto 'pnpm install'; ignored for --repo/tier-2)"
```

- [ ] **Step 5: Syntax-check the modified script**

Run: `bash -n bin/worktree.sh`
Expected: no output, exit 0.

- [ ] **Step 6: Add the AGENTS.md note**

In `AGENTS.md`, in the husky bullet/section, add this sentence (place it near the existing pre-commit-hook description):

```markdown
- `n worktree add` and `n env create --worktree` automatically run `pnpm install` in a new workspace worktree so the husky pre-commit hook is active there (`n worktree add --no-install` to skip; it warns if activation did not take, e.g. under `HUSKY=0`). Worktrees created by plain `git worktree add` or the Claude `EnterWorktree` tool bypass this — the common case for ad-hoc worktrees — and still need a manual `pnpm install` before hooks work.
```

- [ ] **Step 7: Real end-to-end verification (proves the hook fires)**

This exercises the *modified* `worktree.sh` directly (the root `n` still runs the root's copy until this lands). Run from this feature worktree; it creates and removes a throwaway workspace worktree under the root.

Run:
```bash
ROOT=/Users/jason10lee/Repositories/A8C/newspack-workspace.git
BR=__wt_husky_e2e__
# Use the MODIFIED worktree.sh against the root workspace:
NABSPATH="$ROOT" bash bin/worktree.sh add "$BR"
# Confirm husky is armed in the new worktree:
test -e "$ROOT/worktrees/$BR/.husky/_/pre-commit" && echo "HOOK ARMED" || echo "HOOK MISSING"
# Prove it blocks a lint-failing commit:
printf 'const x =1\n' > "$ROOT/worktrees/$BR/plugins/newspack-plugin/src/__e2e_bad__.js"
( cd "$ROOT/worktrees/$BR" && git add plugins/newspack-plugin/src/__e2e_bad__.js \
    && git commit -m "e2e: should be blocked" ) ; echo "commit exit: $?  (expect non-zero)"
```
Expected: `HOOK ARMED`, and the commit exit is non-zero (husky/lint-staged blocks it).

- [ ] **Step 8: Verify `--no-install` path + clean up**

Run:
```bash
ROOT=/Users/jason10lee/Repositories/A8C/newspack-workspace.git
NABSPATH="$ROOT" bash bin/worktree.sh add __wt_husky_e2e2__ --no-install
# Expect the "--no-install: husky hooks inactive" line and NO node_modules install.
test -e "$ROOT/worktrees/__wt_husky_e2e2__/.husky/_/pre-commit" && echo "ARMED (unexpected)" || echo "INACTIVE (expected)"
# Clean up both throwaway worktrees + branches:
NABSPATH="$ROOT" bash bin/worktree.sh remove __wt_husky_e2e__ --yes
NABSPATH="$ROOT" bash bin/worktree.sh remove __wt_husky_e2e2__ --yes
cd "$ROOT" && git worktree prune
```
Expected: first worktree printed `INACTIVE (expected)`; both worktrees removed; `git worktree list` no longer shows them.

- [ ] **Step 9: Run the full shell-tooling suite**

Run:
```bash
for t in tests/*.test.sh; do echo "== $t =="; bash "$t" || echo "FAILED: $t"; done
```
Expected: every suite prints its passing summary; no `FAILED:` line.

- [ ] **Step 10: Commit**

```bash
git add bin/worktree.sh AGENTS.md
git commit -m "feat(worktree): auto-activate husky on 'n worktree add' (NPPM-290)"
```

---

## Self-Review

**1. Spec coverage:**
- New `bin/worktree-tooling.sh` helper w/ best-effort, frozen-first fallback, outcome assertion → Task 1. ✓
- `worktree.sh` source + `--no-install` + single tier-1 call + help text (incl. tier-2 caveat) → Task 2 Steps 1–4. ✓
- `env.sh` unchanged (inherits) → no task needed (verified conceptually; delegation confirmed in spec). ✓
- Unit tests incl. non-executable-pnpm (case 3), exact `--frozen-lockfile` assertion (case 4), fallback (case 5), INACTIVE warning (case 6) → Task 1. ✓
- AGENTS.md note incl. `EnterWorktree`/bare-add residual gap → Task 2 Step 6. ✓
- End-to-end "hook fires" verification → Task 2 Steps 7–8. ✓

**2. Placeholder scan:** none — all code and commands are literal.

**3. Type/name consistency:** `activate_worktree_tooling "$worktree_dir" "$no_install"` (Task 2 Step 3) matches the helper signature and the `worktree_dir`/`no_install` variable names in `worktree.sh` (Task 2 Steps 2–3) and Task 1's definition. Stub env vars (`PNPM_LOG`, `PNPM_FROZEN_EXIT`, `PNPM_PLAIN_EXIT`) are consistent between `make_stub_dir` and the cases.

> Note on test count: Step 4 expects "21 passed" — the sum of the per-case `ok`/`has`/`hasnt` assertions above (3+2+3+4+4+2+3). If assertions are added/removed, update that number to match.
