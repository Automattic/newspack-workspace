# Converge the fork's standalone-repos tooling onto upstream's design

**Status:** Design approved 2026-07-15; revised after multi-model (`magi`) review 2026-07-15
**Branch:** `refactor/converge-standalone-repos-tooling` (off trunk `main` `fd6ec9ab8`)
**Scope:** `bin/worktree.sh`, `bin/repos.sh`, `bin/env.sh`, `n`, `clone-repos.sh` + `bin/worktree-mounts.sh` (both **delete**), `.gitignore`, `AGENTS.md`, and the shell tests (rewrite `repos-host-path`, replace `worktree-mounts` with a parse test, add interaction coverage). (`bin/link-repos.sh` and `migrate-standalone-repos.sh` explicitly stay.)

## Problem

The fork-as-trunk `main` carries a large local implementation of "standalone repos as git worktrees" (developing customer/private plugins outside the monorepo). Upstream (`origin/main`) has since **independently reimplemented the same feature with a different, cleaner design**, so the fork's version is now parallel and — in places — inferior:

| Concern | Fork (local) | Upstream |
|---|---|---|
| Entry point | `--repo <name>` flag on `n worktree add`/`remove` | separate `add-repos`/`remove-repos` subcommands |
| Worktree location | `worktrees/standalone/<repo>/<branch>` | `worktrees-repos/<name>/<branch>` |
| Host-path resolver | inline tier appended to `get_repo_host_path` | dedicated `get_standalone_repo_host_path` + `is_standalone_git_repo` (validates a real independent git repo) |
| Fetch | `git fetch origin <branch>` | forced refspec `+<branch>:refs/remotes/origin/<branch>` (the trunk just adopted this fix standalone) |
| env-create failure | trap-based rollback (`EXIT` trap → `cleanup_partial_env_state`) | inline `wt_rollback` called per failure path (different style; both roll back) |
| Branch on removal | always deleted | `remove-repos` keeps the branch |

Because both sides keep editing the same tooling files, every fork-catches-up merge collides here and re-resolving by hand perpetuates the drift. Upstream's design is a strict improvement, so the sane path is to **converge onto it** and delete the fork's parallel version.

## Goal

Land a trunk branch that replaces the fork's standalone-repos implementation with upstream's *current* design, **re-grafting the fork-only capabilities that live in the same files**, so the next `origin/main`→trunk catch-up collides minimally on this tooling. This is the "converge" half of a **converge-then-catch-up** strategy, decoupled from pulling upstream's ~26 product commits.

Non-goals: pulling upstream's product/release commits; upstreaming the fork's other features; migrating/cleaning the 20 old-layout `repos/<name>` checkouts.

## Key facts that shape the design

- **Upstream's tooling design is currently static.** As of `origin/main` = `a74b7c0f8`, upstream has not touched `worktree.sh`/`repos.sh`/`env.sh` since the analyzed snapshot. This makes the convergence target a *presently* fixed point — see the softened conflict claim below.
- **The husky change is folded in.** The trunk should reflect the future-merged state (upstream design + husky auto-activation). `feat/worktree-husky-autoactivate` already ports husky onto upstream's `worktree.sh`, so it is the base for the converged file. **Verified: that branch's `_worktree_create` carries the forced-refspec fetch fix** (line 28), so adopting it does not revert the trunk's standalone fetch-fix commit.
- **Nothing new to import.** `origin/main` has zero `bin/` files the trunk lacks. Convergence *modifies shared files*; all other fork-only files stay.
- **`migrate-standalone-repos.sh` stays.** 20 real old-layout `repos/<name>` checkouts remain; it is the only tool that migrates them. Not superseded.
- **Tests are fork-only** (upstream ships its tooling untested), so preserve coverage by rewriting the affected tests and adding interaction coverage.

## Design — per-file

### `bin/worktree.sh` — start from the husky branch
Take `feat/worktree-husky-autoactivate`'s `worktree.sh` as the base: already upstream's `add`/`add-repos`/`remove-repos` + `_worktree_create` (with the fetch fix) + the husky `activate_worktree_tooling` call on tier-1 `add`. Drop the fork's `--repo`/`worktrees/standalone/` code. **Add a compatibility guard** (finding #8): the removed `n worktree add --repo X` / `remove --repo X` shapes must emit a clear error pointing to `add-repos` / `remove-repos`, not a confusing generic failure — this is tooling wired into personal scripts.

### `bin/repos.sh` — adopt upstream's helpers
Adopt upstream's `get_standalone_repo_host_path` + `is_standalone_git_repo`; delete the fork's inline standalone tier in `get_repo_host_path`. Keep every other fork-only function. **Precedence (finding #5):** the "tracked monorepo copy wins over a `repos/` duplicate" guarantee (e.g. `newspack-network`, which exists both as a monorepo extension and a standalone repo) is preserved by caller order — callers try `get_repo_host_path` (monorepo) first, fall back to `get_standalone_repo_host_path`. This must be asserted by a test (see below), not assumed.

### `bin/env.sh` — the careful re-graft (and delete `bin/worktree-mounts.sh`)
`env.sh` mixes three concerns: (a) worktree-mount emission/parsing for env compose files, (b) the fork-only `--isolated-db` sidecar (NEWS-2286), (c) sourcing the fork-only refactor helpers. Convergence:
- **(a) Adopt upstream's mount handling wholesale.** Upstream **inlines** mount emission in the `--worktree` parser and provides `parse_worktree_mount` / `each_worktree_in_env` / `resolve_unsanitized_branch` as functions in `env.sh`; it **deletes** `bin/worktree-mounts.sh`. So the convergence **deletes `bin/worktree-mounts.sh`** and adopts upstream's inline emission + parse functions (true convergence — this region then matches upstream and won't reconflict at catch-up). The `--worktree` branch dispatches on `get_repo_host_path` (monorepo, mounts under `worktrees/<safe>/…`) vs `get_standalone_repo_host_path` (standalone, mounts `worktrees-repos/<repo>/<safe>:/newspack-repos/<kind>/<repo>`).
- **(b) Re-graft `--isolated-db`** onto upstream's create/destroy/list blocks — the highest-risk seam. The fork's sidecar variables (`sidecar_block`/`db_service`/`mysql_host_line`/`suffix_log`) slot into upstream's otherwise-identical YAML skeleton; the destroy block's sidecar-vs-shared conditional replaces upstream's unconditional `db` drop; the `list` block gains the `isolated_marker`/`db_kind`/porcelain column (new surface upstream lacks). The supporting helpers `sidecar_service_for_env` + `env_safe_name` live in `bin/_common.sh` (also fork-only) — carry them forward.
- **(c) Keep** the `ssl-trust.sh`/`env-hosts.sh` sourcing. Both fork and upstream roll back created worktrees on failure — reconcile onto upstream's inline `wt_rollback` style (drop the fork's `EXIT`-trap version).
- **Testability:** upstream's `parse_worktree_mount`/`each_worktree_in_env` are functions defined before `env.sh`'s dispatch. To keep them host-testable, **make `env.sh` sourceable** (guard the top-level dispatch behind a `main`-style guard, the fork's established pattern from `link-repos.sh`) so a test can source it and call the parse functions directly.

### `n` — adopt upstream's
Adopt upstream's `n` (routes `worktrees-repos/` container paths + `docker-compose.override.yml` support). Drop the fork's trivial one-line difference.

### `clone-repos.sh` — delete
Upstream deleted it (#617). **Verified: no other references** across `n`/`bin/`/`tests/`/`docs/`/`AGENTS.md` (finding #10), so deletion is dangling-reference-free; keep a `grep -rn clone-repos` guard in the plan regardless.

### `.gitignore`, `AGENTS.md` — reconcile
`.gitignore`: add `worktrees-repos` + `docker-compose.override.yml`; keep fork entries. `AGENTS.md`: replace `--repo`/`worktrees/standalone` docs with `add-repos`/`worktrees-repos`; keep fork-only docs (`--isolated-db`, husky note, hosts-marker, Xdebug, `setup-networking.sh`). **Document the branch-retention asymmetry (finding #12):** `remove-repos` keeps the branch, while `n env destroy` still `git branch -D`s the bound branch — two removal paths, two outcomes; state it so it is intentional, not a surprise.

### Kept untouched (fork-only, unaffected)
`link-repos.sh` (**verified**: references neither `get_repo_host_path` nor retired paths — finding #11), `migrate-standalone-repos.sh`, `ssl-trust.sh`, `env-hosts.sh`, `worktree-tooling.sh`, `composer-recovery.sh`, `ensure-vendor.sh`, `debug-gateways.php`. (`bin/_common.sh` is touched only to carry forward `sidecar_service_for_env`/`env_safe_name` if upstream's `_common.sh` lacks them — verify during the env.sh task.)

## Tests

**Rewrite (retarget to upstream's design):**
- **`tests/repos-host-path.test.sh`** — from the inline `get_repo_host_path` tier to `get_standalone_repo_host_path` + `is_standalone_git_repo` (incl. the "unzipped dir that isn't its own git repo" case), **plus a `newspack-network` case** asserting the monorepo copy wins (precedence, finding #5).
- **`tests/worktree-mounts.test.sh`** — the fork's helper is deleted, so **replace** this with a test of upstream's `parse_worktree_mount` / `each_worktree_in_env` (source the now-sourceable `env.sh`): assert the monorepo shape (`worktrees/<safe>/plugins|themes/<name>` → `repo|branch|monorepo`), the standalone shape (`worktrees-repos/<repo>/<safe>` → `repo|branch|repos`), and non-matching lines return non-zero.

**Add (interaction coverage the current plan lacked — findings #1, #3):**
- **Combined `--isolated-db` × standalone-mount path** — a host-runnable test asserting `env create --isolated-db --worktree <plugin>:<branch>` generates a compose file carrying *both* the isolated-db sidecar (with the fork's DB-naming convention) *and* the `worktrees-repos/` mount. This is the seam the design calls fragile; it must not be left to a one-off manual run.
- **env-create rollback** — a test inducing a multi-worktree `env create` failure and asserting all created worktrees/config are cleaned up (exercises upstream's rollback, a stated convergence rationale).
- *(Optional, finding #9)* a `docker-compose.override.yml` case for the converged `n`.

**Keep as-is** (test unchanged features): `link-repos`, `ssl-trust`, `env-hosts`, `worktree-tooling` (husky), `migrate-standalone-repos`, `composer-recovery`, `newspack-manage-host`, and the `*.smoke.*` files.

## Sequencing

1. `bin/repos.sh` (adopt helpers) + rewrite `repos-host-path.test.sh` (incl. `newspack-network` precedence).
2. `bin/worktree.sh` (from husky branch; assert fetch-fix survives) + `--repo` compat error.
3. `n` (adopt upstream) + `clone-repos.sh` delete (after the grep guard).
4. `bin/env.sh`: adopt upstream's inline mount parsing + **delete `bin/worktree-mounts.sh`**, make `env.sh` sourceable, re-graft `--isolated-db` + reconcile rollback style, carry `_common.sh` helpers; replace `worktree-mounts.test.sh` with the parse test + add the combined-path and rollback tests — the careful step, last.
5. `.gitignore` + `AGENTS.md` reconcile (incl. branch-retention note).
6. Full-suite + real e2e verification.

## Risks & verification

- **`env.sh` × `--isolated-db` re-graft (highest risk).** Now covered by an *automated* combined-path test (above) plus `tests/env-isolated-db.smoke.sh` and a real `n env create --isolated-db --worktree` smoke (env up; DB is the isolated sidecar with correct naming; worktree mounts; husky arms) before landing.
- **Rollback regression.** Covered by the new rollback test.
- **Behavioral change for users.** `n worktree add --repo X` / `worktrees/standalone/` go away (→ `add-repos` / `worktrees-repos/`), now softened by the `--repo` compat error. Call out in the PR/commit body; personal env compose files referencing old shapes need updating. (The 20 old-layout `repos/<name>` checkouts are already unresolved today — no regression.)

Verification before landing (all): full `tests/*.test.sh` green; `bash -n` on every changed script; the combined `--isolated-db --worktree` e2e; a `n worktree add-repos <name> <branch>` create→mount→remove round-trip against a real standalone checkout; and confirm `n worktree add --repo` emits the compat error.

## Landing & revert

Lands as **one reviewed branch** off known-good trunk `fd6ec9ab8`, on operator confirmation — no auto-push. **Revert path (finding #4):** because it lands atomically, recovery pre-merge is simply not merging; post-merge it is `git revert` of the single merge commit (or reset trunk to `fd6ec9ab8` and re-apply any unrelated commits landed since). Pin `fd6ec9ab8` in the PR/commit body as the known-good restore point.

**Conflict-claim, softened (finding #7):** the next catch-up is conflict-free on this tooling *only while upstream's `worktree.sh`/`repos.sh`/`env.sh` stay static* (true as of `a74b7c0f8`). If upstream edits them before the catch-up, convergence still reduces the collision to a **shared-design 3-way merge** rather than reconciling two different implementations — the real, durable win.

## Multi-model review record

Reviewed with `magi review` (design-review prompt; reviewers claude/codex/mistral + synthesis) 2026-07-15 — run `.magi/runs/20260715T073937Z.320`. Verdict: strategy sound, close to ready; gaps were under-specified seams, not design objections. Folded in: the combined isolated-db×mount test and rollback test (top risk), the `worktree-mounts.sh` edited-not-kept reconciliation, the `newspack-network` precedence test, the `--repo` compat error, the softened conflict claim, the revert procedure, the branch-retention note, and direct verification of the fetch-fix survival / `link-repos.sh` safety / `clone-repos` reference-freeness / precedence-by-caller-order. Test-count normalized to 2 rewrites + named additions.
