# Worktree workspace-member build smoke (manual; needs Docker + a built workspace)

1. **Dual mount on create:** `n env create dbuild --worktree newspack-newsletters:<branch>`;
   `grep newspack-newsletters docker-compose.env-dbuild.yml` shows BOTH
   `:/newspack-plugins/newspack-newsletters` and `:/newspack-monorepo/plugins/newspack-newsletters`.
2. **In-place build at --build:** `n env up dbuild --build` → log shows
   "Building worktree plugin(s) in place: --filter <pkg-name>" and the worktree's
   `worktrees/<sb>/plugins/newspack-newsletters/dist/` is freshly built (timestamps newer
   than root's), served at `https://dbuild.test/`.
3. **Rebuild reflects worktree edits:** edit a source file in the worktree
   (`worktrees/<sb>/plugins/newspack-newsletters/src/...`), then from that worktree dir run
   `n build newspack-newsletters` → the rebuilt asset (worktree `dist/`) is what the env serves,
   with NO copy-to-root step.
4. **Migration heals an old env:** on an env created before this change, `n env up <name>` prints
   "added workspace-member mount(s)" and the compose now has the `:/newspack-monorepo/...` line;
   re-running `up` does not add it again (idempotent).
5. **Tier-2 unchanged:** a standalone-repo worktree env still shows only its single
   `/newspack-plugins/<repo>` mount and still gets the `cp -al` assets.
6. `bash -n bin/env.sh` clean (`bin/worktree-mounts.sh` was removed; its parsers now live inline in `env.sh`); `bash tests/worktree-mounts.test.sh` → 5 passed (sources `env.sh` and drives `parse_worktree_mount`/`each_worktree_in_env` directly).
