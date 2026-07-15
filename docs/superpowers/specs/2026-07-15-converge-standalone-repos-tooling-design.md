# Converge the fork's standalone-repos tooling onto upstream's design

**Status:** Design approved 2026-07-15
**Branch:** `refactor/converge-standalone-repos-tooling` (off trunk `main`)
**Scope:** `bin/worktree.sh`, `bin/repos.sh`, `bin/env.sh`, `n`, `clone-repos.sh` (delete), `.gitignore`, `AGENTS.md`, and the 2 affected shell tests. (`bin/link-repos.sh` and `migrate-standalone-repos.sh` explicitly stay.)

## Problem

The fork-as-trunk `main` carries a large local implementation of "standalone repos as git worktrees" (developing customer/private plugins that live outside the monorepo). Upstream (`origin/main`) has since **independently reimplemented the same feature with a different, cleaner design**, so the fork's version is now parallel and — in places — inferior:

| Concern | Fork (local) | Upstream |
|---|---|---|
| Entry point | `--repo <name>` flag on `n worktree add`/`remove` | separate `add-repos`/`remove-repos` subcommands |
| Worktree location | `worktrees/standalone/<repo>/<branch>` | `worktrees-repos/<name>/<branch>` |
| Host-path resolver | inline tier appended to `get_repo_host_path` | dedicated `get_standalone_repo_host_path` + `is_standalone_git_repo` (validates a real independent git repo) |
| Fetch | `git fetch origin <branch>` | forced refspec `+<branch>:refs/remotes/origin/<branch>` (the trunk just adopted this fix standalone) |
| env-create failure | no rollback | atomic multi-worktree rollback |
| Branch on removal | always deleted | `remove-repos` keeps the branch |

Because both sides keep editing the same tooling files (`n`, `bin/env.sh`, `bin/repos.sh`, `bin/worktree.sh`), **every fork-catches-up-with-upstream merge collides here** and re-resolving by hand perpetuates the drift. Upstream's design is a strict improvement (subcommand split, real repo validation, fetch fix, rollback), so the sane path is to **converge onto it** and delete the fork's parallel version, rather than hand-merge two implementations forever.

## Goal

Land a trunk branch that replaces the fork's standalone-repos implementation with upstream's *current, stable* design, **re-grafting the fork-only capabilities that live in the same files**, so the next `origin/main`→trunk catch-up merge is conflict-free on this tooling. This is the "converge" half of a **converge-then-catch-up** strategy: it is decoupled from pulling upstream's ~26 product commits (those come at the later, now-clean catch-up).

Non-goals: pulling upstream's product/release commits (later catch-up); upstreaming the fork's other features (separate PRs); migrating or cleaning up the 20 old-layout `repos/<name>` checkouts (independent, operator's call).

## Key facts that shape the design

- **Upstream's tooling design is stable.** As of `origin/main` = `a74b7c0f8`, upstream has not touched `bin/worktree.sh`/`bin/repos.sh`/`bin/env.sh` since the analyzed snapshot — the convergence target is a fixed point, not a moving one. (`n` gained `docker-compose.override.yml` support #182 — a freebie; `clone-repos.sh` was deleted #617.)
- **The husky change is folded in.** The trunk should reflect the *future-merged* state (upstream design + husky auto-activation). The existing `feat/worktree-husky-autoactivate` branch already ports husky onto upstream's `worktree.sh`, so it is the starting point for the converged `worktree.sh`. That upstream PR still stands separately from upstream's perspective; this just brings the same shape into the trunk.
- **Nothing new to import.** `origin/main` has zero `bin/` files the trunk lacks. Convergence *modifies shared files*; all fork-only files stay.
- **`migrate-standalone-repos.sh` stays.** 20 real old-layout `repos/<name>` checkouts remain on this machine; it is the only tool that migrates them to the `repos/{plugins,themes}/` layout both designs require. Not superseded — kept, with its test and docs, untouched by this work.
- **Tests are fork-only** (upstream ships its tooling untested), so the fork's suite is the only safety net — preserve coverage by rewriting the ~3 affected tests onto upstream's design.

## Design — per-file

### `bin/worktree.sh` — start from the husky branch
Take `feat/worktree-husky-autoactivate`'s `worktree.sh` as the base: it is already upstream's `add`/`add-repos`/`remove-repos` structure + `_worktree_create` (with the fetch fix) + the husky `activate_worktree_tooling` call on the tier-1 `add`. Drop the fork's `--repo`/`worktrees/standalone/` code entirely. Net: the converged `worktree.sh` ≈ that branch's file, re-based on current trunk.

### `bin/repos.sh` — adopt upstream's helpers
Adopt upstream's `get_standalone_repo_host_path` + `is_standalone_git_repo`; delete the fork's inline standalone tier appended to `get_repo_host_path`. Keep every other fork-only function in `repos.sh` untouched (only the standalone tier is retired).

### `bin/env.sh` — the careful one (re-graft `--isolated-db`)
`env.sh` mixes three concerns: (a) worktree-mount parsing for envs (fork uses `worktrees/standalone/`; upstream uses `worktrees-repos/` with a `repo|branch|kind` triple + create rollback), (b) the fork-only `--isolated-db` sidecar (NEWS-2286), (c) sourcing the fork-only refactor helpers (`ssl-trust.sh`, `env-hosts.sh`, `worktree-mounts.sh`). Convergence: **take upstream's (a)**, **re-graft (b) `--isolated-db` onto it** (it is non-overlapping per the divergence analysis), **keep (c) the sourcing/refactor**. This is the highest-risk file — a re-graft, not a copy.

### `n` — adopt upstream's
Adopt upstream's `n` (routes `worktrees-repos/` container paths + `docker-compose.override.yml` support). Drop the fork's trivial one-line `projects=(…)` difference (functionally identical).

### `clone-repos.sh` — delete
Upstream deleted it (#617, obsolete once plugins/themes live in-tree). Accept the deletion; the fork's small consistency tweak becomes moot.

### `bin/link-repos.sh` — KEEP the fork's (out of scope)
Confirmed: this symlinks `repos/{plugins,themes}/<name>` checkouts into the active site (`wp-content/plugins|themes/`) — a concern **shared by both designs** and independent of the retired `--repo`/`worktrees/standalone/` worktree code (it references neither `worktrees/standalone` nor `worktrees-repos`). The fork's version adds tested value upstream lacks (sourceable `main()`, `link_standalone()` extraction, stale-symlink repoint after migration). It is fork-only enhancement, only the fork changed it since the merge-base, so it neither needs converging nor conflicts at the next catch-up. Keep it and `link-repos.test.sh` as-is.

### `.gitignore`, `AGENTS.md` — reconcile prose/entries
`.gitignore`: add upstream's `worktrees-repos` + `docker-compose.override.yml`; keep the fork's entries. `AGENTS.md`: replace the fork's `--repo`/`worktrees/standalone` documentation with upstream's `add-repos`/`worktrees-repos` wording; keep the fork-only docs (`--isolated-db`, husky-activation note, hosts-marker, Xdebug mapping, `setup-networking.sh`).

### Fork-only files — keep untouched
`ssl-trust.sh`, `env-hosts.sh`, `worktree-mounts.sh`, `worktree-tooling.sh`, `migrate-standalone-repos.sh`, `composer-recovery.sh`, `ensure-vendor.sh`, `debug-gateways.php`.

## Test rewrites (bounded)

Rewrite onto upstream's design (coverage preserved):
- **`tests/repos-host-path.test.sh`** — retarget from the inline `get_repo_host_path` standalone tier to `get_standalone_repo_host_path` + `is_standalone_git_repo` (incl. the "unzipped dir that isn't its own git repo" case upstream validates).
- **`tests/worktree-mounts.test.sh`** — retarget worktree-mount parsing from `worktrees/standalone/` to upstream's `worktrees-repos/` + `repo|branch|kind` triple.

So the rewrite is **2 files**. Unchanged (test fork-only, unaffected features), keep as-is: `link-repos`, `ssl-trust`, `env-hosts`, `worktree-tooling` (husky), `migrate-standalone-repos`, `composer-recovery`, `newspack-manage-host`, and the `*.smoke.*` files.

## Sequencing

1. `bin/repos.sh` (adopt helpers) + rewrite `repos-host-path.test.sh`.
2. `bin/worktree.sh` (from husky branch) — depends on repos.sh helpers.
3. `n` (adopt upstream) + `clone-repos.sh` delete.
4. `bin/env.sh` re-graft `--isolated-db` onto upstream's mount parsing + rewrite `worktree-mounts.test.sh` — the careful step, done after the simpler ones.
5. `.gitignore` + `AGENTS.md` reconcile.
6. Full-suite + real e2e verification.

## Risks & verification

- **`env.sh` `--isolated-db` re-graft** — the one place a regression can hide. Mitigation: rewritten `worktree-mounts.test.sh` + the existing `tests/env-isolated-db.smoke.sh`, plus a real `n env create <name> --isolated-db --worktree <plugin>:<branch>` smoke (env up, DB is the isolated sidecar, worktree mounts, husky arms) before landing.
- **Behavioral change for users** — `n worktree add --repo X` and `worktrees/standalone/` paths disappear (replaced by `n worktree add-repos X` / `worktrees-repos/`). Any personal env compose files or scripts referencing the old shapes need updating; call this out in the PR/commit body. (The 20 old-layout `repos/<name>` checkouts are already unresolved today, so no regression there.)

Verification before landing (all): full `tests/*.test.sh` green; `bash -n` on every changed script; the `--isolated-db --worktree` e2e above; and a `n worktree add-repos <name> <branch>` round-trip against a real standalone checkout confirming create → mount → remove.

## Landing

Trunk tooling: land on trunk (→ `fork`) only on operator confirmation — no auto-push. This is a large, self-contained refactor; it merits its own review pass. After it lands, the next `origin/main`→trunk catch-up is conflict-free on this tooling.
