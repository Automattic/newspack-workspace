# Converge Standalone-Repos Tooling — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fork's parallel standalone-repos tooling (`--repo` flag, `worktrees/standalone/`, inline `get_repo_host_path` tier) with upstream's design (`add-repos`/`remove-repos`, `worktrees-repos/`, `get_standalone_repo_host_path`), re-grafting the fork-only `--isolated-db` and husky-activation features, so the next catch-up merge is conflict-free on this tooling.

**Architecture:** Per-file — adopt upstream's version by `git checkout` for the files where the fork adds nothing standalone-specific (`repos.sh`, `n`) or where a ported branch exists (`worktree.sh` from `feat/worktree-husky-autoactivate`); **surgically** convert `env.sh`'s standalone-worktree region to upstream's shape while preserving its fork-only `--isolated-db`/ssl/hosts integrations; delete `clone-repos.sh` and `worktree-mounts.sh`; rewrite the affected shell tests. Everything lands as one branch.

**Tech Stack:** Bash tooling, the `tests/*.test.sh` host-runnable harness, git worktrees, Docker Compose (env layer).

## Global Constraints

- Refs: fork/trunk = `main`; upstream = `origin/main`; husky base = `feat/worktree-husky-autoactivate`. Land on `refactor/converge-standalone-repos-tooling`.
- Upstream's two-helper API is authoritative: callers try `get_repo_host_path` (monorepo) first, fall back to `get_standalone_repo_host_path` (standalone). Preserve this precedence (`newspack-network` = dual-existing name → monorepo wins).
- Standalone worktree location becomes `worktrees-repos/<repo>/<safe_branch>`; the `--repo` flag and `worktrees/standalone/` are removed. `n worktree add --repo X` must emit a compat error pointing to `add-repos`.
- Preserve verbatim (fork-only, upstream lacks): `--isolated-db` sidecar logic in `env.sh` (sidecar naming `db_lowercase_<env_safe_name>`, `env_safe_name` folds `-` and `.` to `_`), the `_common.sh` helpers `sidecar_service_for_env`/`env_safe_name`, and the ssl-trust/env-hosts sourcing+calls.
- Commit with `-c core.hooksPath=/dev/null` (this worktree has no husky active) and a `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>` trailer.
- Tests are host-runnable (`bash tests/<name>.test.sh`), use the `ok()` helper + `mktemp -d` fixtures + `set -u`.

---

## Task 1: `bin/repos.sh` — adopt upstream's two-helper split + rewrite host-path test

**Files:**
- Modify: `bin/repos.sh`
- Test: `tests/repos-host-path.test.sh`

**Interfaces produced:** `get_repo_host_path <name>` → `plugins/<n>`|`themes/<n>`|`""` (monorepo only); `get_standalone_repo_host_path <name>` → `repos/plugins/<n>`|`repos/themes/<n>`|`""`; `is_standalone_git_repo <host_path>` → exit 0 if the checkout is its own git repo.

- [ ] **Step 1: Adopt upstream's `bin/repos.sh`, then confirm no fork data lost**

```bash
git checkout origin/main -- bin/repos.sh
git diff main -- bin/repos.sh
```
Expected: the diff shows the fork's inline standalone tier removed from `get_repo_host_path` and the two new helpers added. **Verify the `newspack_plugins`/`newspack_themes` arrays did not lose any fork-only entries** (compare against `git show main:bin/repos.sh`). If the fork's arrays had an entry upstream lacks, re-add it. (Per extraction, the fork has no functions upstream lacks and the arrays match — but confirm.)

- [ ] **Step 2: Rewrite the failing test**

Overwrite `tests/repos-host-path.test.sh` with:

```bash
#!/bin/bash
# Unit tests for bin/repos.sh host-path resolution. Host-runnable.
# Covers upstream's two-function split: get_repo_host_path (monorepo only) +
# get_standalone_repo_host_path (repos/{plugins,themes}) + is_standalone_git_repo,
# and the caller-order precedence (monorepo copy wins over a repos/ duplicate).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

source "$BIN/repos.sh"

# A real caller resolves a name by trying monorepo first, then standalone:
resolve(){ local p; p=$(get_repo_host_path "$1"); [[ -n "$p" ]] && { echo "$p"; return; }; get_standalone_repo_host_path "$1"; }

# Fixtures: a standalone plugin + theme (real git repos so is_standalone_git_repo
# works), a name colliding with a monorepo plugin, and an unzipped (non-git) dir.
export NABSPATH="$FIX"
git init -q "$FIX/repos/plugins/acme-standalone"
git init -q "$FIX/repos/themes/acme-theme"
mkdir -p "$FIX/repos/plugins/newspack-network"; git init -q "$FIX/repos/plugins/newspack-network"  # dual-existing name
mkdir -p "$FIX/repos/plugins/unzipped-plugin"   # NOT its own git repo

# get_repo_host_path: monorepo only
ok "monorepo plugin -> plugins/<name>"  "$(get_repo_host_path newspack-plugin)"  "plugins/newspack-plugin"
ok "monorepo theme -> themes/<name>"    "$(get_repo_host_path newspack-theme)"   "themes/newspack-theme"
ok "get_repo_host_path ignores standalone" "$(get_repo_host_path acme-standalone)" ""

# get_standalone_repo_host_path
ok "standalone plugin"  "$(get_standalone_repo_host_path acme-standalone)"  "repos/plugins/acme-standalone"
ok "standalone theme"   "$(get_standalone_repo_host_path acme-theme)"       "repos/themes/acme-theme"
ok "standalone unknown -> empty" "$(get_standalone_repo_host_path nope)"    ""

# Precedence (finding #5): newspack-network exists in the monorepo AND as a
# standalone checkout -> the monorepo copy wins via caller order.
ok "dual-existing name: monorepo wins" "$(resolve newspack-network)" "plugins/newspack-network"

# is_standalone_git_repo: true for a real repo, false for an unzipped dir
if is_standalone_git_repo "repos/plugins/acme-standalone"; then r=yes; else r=no; fi
ok "real git repo IS standalone" "$r" "yes"
if is_standalone_git_repo "repos/plugins/unzipped-plugin"; then r=yes; else r=no; fi
ok "unzipped dir is NOT standalone" "$r" "no"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
```

- [ ] **Step 3: Run — expect PASS** (repos.sh already adopted in Step 1)

Run: `bash tests/repos-host-path.test.sh`
Expected: `RESULT: 9 passed, 0 failed`, exit 0.
(Note `newspack-plugin`/`newspack-theme` must be in the `newspack_plugins`/`newspack_themes` arrays for the monorepo cases — they are, in the adopted `repos.sh`.)

- [ ] **Step 4: Commit**
```bash
git add bin/repos.sh tests/repos-host-path.test.sh
git commit -m "refactor(repos): adopt upstream get_standalone_repo_host_path split"
```

---

## Task 2: `bin/worktree.sh` — adopt the husky-ported upstream version + `--repo` compat error

**Files:**
- Modify: `bin/worktree.sh`

**Interfaces consumed:** `get_standalone_repo_host_path`, `is_standalone_git_repo` (Task 1). **Produced:** `n worktree add-repos <name> <branch>` / `remove-repos <name> <safe_branch>`; `add`/`remove` are monorepo-only; `--repo` errors with a migration hint.

- [ ] **Step 1: Adopt the husky branch's `worktree.sh`** (already upstream's `add`/`add-repos`/`remove-repos` + `_worktree_create` with the forced-refspec fetch fix + the husky `activate_worktree_tooling` call)

```bash
git checkout feat/worktree-husky-autoactivate -- bin/worktree.sh
```

- [ ] **Step 2: Verify the fetch fix and husky call survived**
```bash
grep -n 'fetch origin "+\$branch:refs/remotes/origin/\$branch"' bin/worktree.sh   # expect 1 (in _worktree_create)
grep -n 'activate_worktree_tooling' bin/worktree.sh                               # expect >=1 (tier-1 add)
grep -n 'get_standalone_repo_host_path\|is_standalone_git_repo' bin/worktree.sh   # expect the add-repos/remove-repos cases
bash -n bin/worktree.sh && echo OK
```
Expected: fetch fix present, husky call present, add-repos uses the upstream helpers, syntax OK.

- [ ] **Step 3: Add the `--repo` backward-compat error** in the `add)` arg-stripping loop

Find (in the `add)` case):
```bash
        _args=()
        for _a in "$@"; do
            if [[ "$_a" == "--no-install" ]]; then no_install=true; else _args+=("$_a"); fi
        done
```
Replace with (add a `--repo` guard that errors with a migration hint):
```bash
        _args=()
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --no-install) no_install=true; shift ;;
                --repo)
                    echo "Error: 'n worktree add --repo <name>' was removed; standalone repos now use a dedicated subcommand." >&2
                    echo "  Use: n worktree add-repos <name> <branch>" >&2
                    exit 1 ;;
                *) _args+=("$1"); shift ;;
            esac
        done
```
(Do the same for the `remove)` case if it has a matching arg loop — add a `--repo)` arm pointing to `remove-repos`. If `remove)` uses positional parsing, add an early `[[ "$*" == *--repo* ]]` guard printing the same hint.)

- [ ] **Step 4: Verify syntax + the compat error fires**
```bash
bash -n bin/worktree.sh && echo OK
NABSPATH=/tmp bash bin/worktree.sh add somebranch --repo newspack-manager 2>&1 | grep -q "add-repos" && echo "compat error OK"
```
Expected: `OK` and `compat error OK` (and no worktree created).

- [ ] **Step 5: Commit**
```bash
git add bin/worktree.sh
git commit -m "refactor(worktree): adopt upstream add-repos design; error on legacy --repo"
```

---

## Task 3: `n` (adopt upstream) + delete `clone-repos.sh`

**Files:**
- Modify: `n`
- Delete: `clone-repos.sh`

- [ ] **Step 1: Adopt upstream's `n`**
```bash
git checkout origin/main -- n
git diff main -- n --stat
```
Expected: upstream's `n` adds `worktrees-repos/` container routing + cwd detection + `docker-compose.override.yml` support. It is a strict superset except one line: upstream hardcodes the theme list as `projects=("${newspack_plugins[@]}" "newspack-theme" "newspack-block-theme")`.

- [ ] **Step 2: De-drift the theme list** (avoid the latent bug where a new theme isn't recognized)

In `n`, find:
```bash
projects=("${newspack_plugins[@]}" "newspack-theme" "newspack-block-theme") # create a new list with the appended value
```
Replace with:
```bash
projects=("${newspack_plugins[@]}" "${newspack_themes[@]}")
```
(Keeps `n`'s cwd-detection list sourced from `bin/repos.sh`'s arrays instead of a hardcoded pair.)

- [ ] **Step 3: Confirm no lingering `clone-repos` references, then delete**
```bash
grep -rn "clone-repos" n bin/ tests/ docs/ AGENTS.md 2>/dev/null | grep -v "clone-repos.sh:" || echo "no external refs"
git rm clone-repos.sh
bash -n n && echo "n syntax OK"
```
Expected: `no external refs`, file removed, `n` parses.

- [ ] **Step 4: Commit**
```bash
git add n && git rm --cached clone-repos.sh 2>/dev/null; git add -A n clone-repos.sh
git commit -m "refactor(n): adopt upstream worktrees-repos routing + override.yml; drop clone-repos.sh"
```

---

## Task 4: `bin/env.sh` — surgically convert the standalone-worktree region to upstream's design (preserve `--isolated-db`)

This is the careful task. **Do not** `git checkout origin/main -- bin/env.sh` (that drops `--isolated-db` and the ssl/hosts refactor). Instead, edit the fork's `env.sh` in place, replacing only the standalone-worktree-mount region with upstream's shape.

**Files:**
- Modify: `bin/env.sh`, `bin/_common.sh` (only if it lacks the two helpers)
- Delete: `bin/worktree-mounts.sh`
- Test: replace `tests/worktree-mounts.test.sh`; add `tests/env-worktree-combo.test.sh`

**Interfaces consumed:** `get_repo_host_path`, `get_standalone_repo_host_path` (Task 1); `worktree.sh add`/`add-repos`/`remove`/`remove-repos` (Task 2).

### Preserve verbatim (fork-only — must remain after this task)
- The `--isolated-db` blocks: the `--isolated-db)` arg arm; the `sidecar_block`/`db_service`/`mysql_host_line`/`suffix_log` assembly and its splice into the compose heredoc; the `env up` sidecar startup + LCTN check; the destroy-block sidecar-vs-shared conditional + data-dir removal; the `list` `isolated_marker`/`db_kind`/porcelain column.
- `bin/_common.sh`: `sidecar_service_for_env`, `env_safe_name`.
- The `source .../ssl-trust.sh` and `source .../env-hosts.sh` lines and all their call sites.

### Replace (fork standalone shape → upstream shape)

- [ ] **Step 1: Drop the `worktree-mounts.sh` sourcing + delete the file**

In `bin/env.sh` remove:
```bash
source "$(dirname "${BASH_SOURCE[0]}")/worktree-mounts.sh"
```
Then:
```bash
git rm bin/worktree-mounts.sh
```

- [ ] **Step 2: Add upstream's parse/emit functions to `env.sh`** (near the top, after the other `source` lines / helper defs), copied verbatim from `origin/main:bin/env.sh`:

```bash
git show origin/main:bin/env.sh | sed -n '/^parse_worktree_mount()/,/^}/p'
git show origin/main:bin/env.sh | sed -n '/^resolve_unsanitized_branch()/,/^}/p'
git show origin/main:bin/env.sh | sed -n '/^each_worktree_in_env()/,/^}/p'
```
Paste all three functions into `env.sh`. (These replace the fork's `worktree_volume_lines` usage and the fork's `parse_env_worktrees`. Remove the fork's `parse_env_worktrees` definition wherever it lives — `grep -n parse_env_worktrees bin/env.sh bin/_common.sh`.)

- [ ] **Step 3: Replace the `--worktree` arg arm's standalone branch** with upstream's (monorepo → `worktrees/<safe>/…` double-mount; standalone → `worktrees-repos/<repo>/<safe>:/newspack-repos/<kind>/<repo>`, recorded in `wt_specs` for post-parse creation). Use upstream's `create` `--worktree` block verbatim:
```bash
git show origin/main:bin/env.sh | sed -n '/case \$1 in/,/^        done$/p'   # the create arg loop
```
Splice it in, **keeping the fork's `--isolated-db)` arm** inside the same `case`. Replace the fork's inline `worktree_volume_lines`/`worktrees/standalone/` emission with upstream's inline `worktree_volumes+=...` lines and its `wt_specs`/`wt_rollback` post-parse creation loop (which calls `worktree.sh add` / `add-repos`). Delete the fork's `_create_cleanup_on_error`/`cleanup_partial_env_state` EXIT-trap machinery and its `trap` lines — upstream's inline `wt_rollback` supersedes it.

- [ ] **Step 4: Keep the `--isolated-db` compose splice.** Upstream's create heredoc is byte-identical to the fork's non-isolated skeleton except for `${sidecar_block}`, `depends_on: - ${db_service}`, `${mysql_host_line}`. Ensure those three fork variables remain in the heredoc and are still assembled above it (the `if [[ "$isolated_db" == true ]]; then …` block). Result: upstream's mount handling + the fork's sidecar.

- [ ] **Step 5: Replace the destroy worktree loop** with upstream's `each_worktree_in_env` + `remove`/`remove-repos` loop:
```bash
git show origin/main:bin/env.sh | sed -n '/worktree_entries=()/,/^        done$/p'
```
Keep the fork's sidecar-vs-shared DB-drop conditional and sidecar data-dir removal around it.

- [ ] **Step 6: Make `env.sh` sourceable** so the parse functions are testable. Wrap the top-level `case $1 in … esac` dispatch in a guard (fork pattern from `link-repos.sh`):
```bash
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    <the existing `case "$1" in … esac` dispatch>
fi
```
Move only the dispatch inside the guard; leave function/`source` definitions above it.

- [ ] **Step 7: Verify `_common.sh` helpers exist**
```bash
grep -n 'sidecar_service_for_env\|env_safe_name' bin/_common.sh || echo "MISSING — re-add from main:bin/_common.sh"
```
If missing (upstream's `_common.sh` was adopted anywhere), re-add both from `git show main:bin/_common.sh`.

- [ ] **Step 8: `bash -n`**
```bash
bash -n bin/env.sh && bash -n bin/_common.sh && echo OK
```

- [ ] **Step 9: Write the parse test** (`tests/worktree-mounts.test.sh` → replace with this; it now tests upstream's functions by sourcing the sourceable `env.sh`):

```bash
#!/bin/bash
# Unit tests for env.sh worktree-mount parsing (upstream's parse_worktree_mount /
# each_worktree_in_env). Host-runnable; sources env.sh (sourceable-guarded).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
source "$BIN/env.sh"

ok "monorepo plugin mount"  "$(parse_worktree_mount '      - ./worktrees/feat-x/plugins/np-news:/newspack-plugins/np-news')" "np-news|feat-x|monorepo"
ok "monorepo theme mount"   "$(parse_worktree_mount '      - ./worktrees/feat-y/themes/np-theme:/newspack-themes/np-theme')" "np-theme|feat-y|monorepo"
ok "standalone repos mount" "$(parse_worktree_mount '      - ./worktrees-repos/np-manager/feat-z:/newspack-repos/plugins/np-manager')" "np-manager|feat-z|repos"
if parse_worktree_mount '      - ./html:/var/www/html' >/dev/null 2>&1; then r=matched; else r=nomatch; fi
ok "non-worktree mount -> non-zero" "$r" "nomatch"

# each_worktree_in_env over a compose fixture with one of each shape:
C="$FIX/c.yml"; cat > "$C" <<'EOF'
    volumes:
      - ./worktrees/feat-x/plugins/np-news:/newspack-plugins/np-news
      - ./worktrees-repos/np-manager/feat-z:/newspack-repos/plugins/np-manager
      - ./html:/var/www/html
EOF
ok "each_worktree_in_env yields 2 triples" "$(each_worktree_in_env "$C" | grep -c '|')" "2"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
```
Run: `bash tests/worktree-mounts.test.sh` → `RESULT: 5 passed, 0 failed`.

- [ ] **Step 10: Write the combined `--isolated-db` × standalone-mount test** (`tests/env-worktree-combo.test.sh`) — asserts compose generation carries both. Since full `n env create` needs Docker, this test drives only the host-side compose-file generation is not trivially isolatable; instead assert the naming contract at the unit level and defer the full wiring to the smoke test:

```bash
#!/bin/bash
# Asserts the isolated-db sidecar naming contract that a combined
# --isolated-db + --worktree env must satisfy (host-runnable, no Docker).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"; pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
source "$BIN/_common.sh"
ok "env_safe_name folds dashes" "$(env_safe_name combo-env)" "combo_env"
ok "env_safe_name folds dots"   "$(env_safe_name foo.bar)"   "foo_bar"
# sidecar_service_for_env recovers the service name from a generated compose file:
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
printf 'services:\n  db_lowercase_combo_env:\n    image: mariadb\n  env-combo-env:\n' > "$FIX/dc.yml"
ok "sidecar detected from compose" "$(sidecar_service_for_env "$FIX/dc.yml")" "db_lowercase_combo_env"
echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
```
Run: `bash tests/env-worktree-combo.test.sh` → `RESULT: 4 passed, 0 failed`. (The *full* combined create→mount→destroy with a live sidecar is covered by the smoke test in Task 6.)

- [ ] **Step 11: Run the full host-side suite**
```bash
for t in tests/*.test.sh; do echo "== $t =="; bash "$t" || echo "FAILED: $t"; done
```
Expected: every suite green; no `FAILED:`.

- [ ] **Step 12: Commit**
```bash
git add -A bin/env.sh bin/_common.sh tests/worktree-mounts.test.sh tests/env-worktree-combo.test.sh
git rm --cached bin/worktree-mounts.sh 2>/dev/null; git add -A
git commit -m "refactor(env): converge standalone-worktree mounts to upstream; keep --isolated-db"
```

---

## Task 5: `.gitignore` + `AGENTS.md` reconcile

**Files:** Modify `.gitignore`, `AGENTS.md`

- [ ] **Step 1: `.gitignore`** — ensure `worktrees-repos` and `docker-compose.override.yml` are ignored (add if absent), keep the fork's entries:
```bash
grep -q '^worktrees-repos' .gitignore || echo 'worktrees-repos/' >> .gitignore
grep -q 'docker-compose.override.yml' .gitignore || echo 'docker-compose.override.yml' >> .gitignore
```

- [ ] **Step 2: `AGENTS.md`** — replace `--repo`/`worktrees/standalone` documentation with `add-repos`/`worktrees-repos`, keep fork-only docs (`--isolated-db`, husky note, hosts-marker, Xdebug). Find the standalone-repos doc block (`grep -n 'worktrees/standalone\|--worktree <repo>\|standalone' AGENTS.md`) and update the wording to `n worktree add-repos <name> <branch>` and `worktrees-repos/<name>/<branch>`. Add one sentence documenting the branch-retention asymmetry: *"`n worktree remove-repos` keeps the standalone branch; `n env destroy` deletes the bound branch."*

- [ ] **Step 3: Commit**
```bash
git add .gitignore AGENTS.md
git commit -m "docs(converge): document add-repos/worktrees-repos; note branch-retention asymmetry"
```

---

## Task 6: Full verification (e2e)

**No file changes — a verification gate.** Run against a real env; requires Docker up.

- [ ] **Step 1: Full host suite green** — `for t in tests/*.test.sh; do bash "$t" || echo FAIL $t; done`
- [ ] **Step 2: `bash -n` every changed script** — `for f in bin/repos.sh bin/worktree.sh bin/env.sh bin/_common.sh n; do bash -n "$f" && echo "$f OK"; done`
- [ ] **Step 3: `--repo` compat error** — `NABSPATH=<root> bash bin/worktree.sh add x --repo y 2>&1 | grep add-repos`
- [ ] **Step 4: standalone worktree round-trip** — against a real `repos/plugins/<name>` checkout: `n worktree add-repos <name> <branch>` → confirm `worktrees-repos/<name>/<branch>` created → `n worktree remove-repos <name> <safe_branch> --yes` → confirm branch preserved.
- [ ] **Step 5: combined isolated-db + standalone env smoke** — `n env create combo --isolated-db --worktree <standalone>:<branch> --up`; assert the compose file has both the `db_lowercase_combo` sidecar and the `worktrees-repos/…:/newspack-repos/…` mount; assert the sidecar reports `lower_case_table_names=1`; `n env destroy combo` tears down both. Also run `tests/env-isolated-db.smoke.sh`.
- [ ] **Step 6: monorepo worktree unaffected** — `n worktree add <branch>` still works (husky arms, no regression).

---

## Self-Review

**1. Spec coverage:** repos.sh split (T1); worktree.sh + `--repo` compat (T2); n + clone-repos delete (T3); env.sh surgical converge + delete worktree-mounts.sh + sourceable + isolated-db preserved + rollback reconcile + parse/combined tests (T4); .gitignore/AGENTS.md + branch-retention note (T5); e2e incl. combined isolated-db (T6). newspack-network precedence (T1 test), fetch-fix survival (T2 verify), clone-repos ref check (T3). ✓

**2. Placeholder scan:** the env.sh steps reference `git show origin/main:bin/env.sh | sed …` to pull exact upstream blocks rather than pasting 200+ lines — this is a concrete extraction command, not a placeholder; the *decisions* (what to preserve/replace/delete) are explicit. The combined-path test is intentionally unit-level (naming contract) with the full live wiring deferred to the smoke test (T6 Step 5) — stated, not hidden.

**3. Type/name consistency:** `get_repo_host_path`/`get_standalone_repo_host_path`/`is_standalone_git_repo` (T1) match T2/T4 call sites; `parse_worktree_mount`/`each_worktree_in_env`/`resolve_unsanitized_branch` (T4) match the test (T4 S9); `env_safe_name`/`sidecar_service_for_env` (T4/_common) match the combo test (T4 S10). `--repo` compat wording consistent (T2 S3 ↔ T6 S3).

> **Reviewer note:** Task 4 is the load-bearing one and the least mechanically pre-scriptable (a 649-line surgical divergence). Give it extra review scrutiny — especially that every `--isolated-db` block from the Global Constraints list survives, and that the destroy block's sidecar conditional wraps upstream's worktree-removal loop rather than being dropped.
