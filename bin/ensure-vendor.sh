#!/bin/bash
#
# Ensure migrated monorepo plugins and themes have their Composer vendor/ installed.
#
# A fresh env links the monorepo plugins and themes from /newspack-plugins/ and
# /newspack-themes/, whose composer vendor/ is never installed by `n env up` on
# its own — so activating a project that requires its autoloader (e.g.
# newspack-plugin, newspack-popups) fatals on a missing vendor/autoload.php.
# This runs a runtime-only Composer install
# (--no-dev -- all plugin activation needs) at env-up time, without dev
# dependencies (PHPUnit etc.) or the slow JS build, so a fresh env is never
# vendorless. Dev deps for running a plugin's tests come from `n build`/`n ci-build`.
#
# Idempotent: skips any project whose vendor/autoload.php is already present.
# get_all_project_dirs is monorepo-only, so standalone repos/ checkouts aren't
# touched here -- they install their own deps on demand via `n build <name>`.

source /var/scripts/resolve-project-path.sh

failed=()
installed=0

while IFS= read -r dir; do
	[ -d "$dir" ] || continue
	[ -f "$dir/composer.json" ] || continue
	name=$(basename "$dir")
	# Already provisioned — nothing to do (keeps warm starts instant).
	[ -f "$dir/vendor/autoload.php" ] && continue
	echo "[ensure-vendor] installing composer deps for $name"
	# Non-worktree projects bind-mount the single shared host tree, so every env
	# writes into the same <project>/vendor/. Two envs starting cold at once would
	# otherwise both see the missing autoload.php and run composer install into
	# that one directory, which can leave it corrupt. Serialize per directory; the
	# second waiter re-checks inside the lock and finds the work already done.
	#
	# The lock lives inside vendor/ deliberately: it has to sit on the shared host
	# mount to be visible to both containers (a /tmp lock is per-container and would
	# not serialize anything), and vendor/ is gitignored in every plugin and theme,
	# so it never shows up in a working tree.
	mkdir -p "$dir/vendor"
	if (
		exec 9>"$dir/vendor/.install.lock" || exit 1
		flock 9
		[ -f "$dir/vendor/autoload.php" ] && exit 0
		composer install --working-dir "$dir" --no-interaction --no-dev
	); then
		installed=$((installed + 1))
	else
		failed+=("$name")
	fi
done < <(get_all_project_dirs)

if [ "${#failed[@]}" -gt 0 ]; then
	echo "[ensure-vendor] ERROR: composer install failed for: ${failed[*]}" >&2
	echo "[ensure-vendor] those plugins/themes will fatal on activation; fix the cause and re-run 'n ci-build all'." >&2
	[ "$installed" -gt 0 ] && echo "[ensure-vendor] (provisioned vendor/ for $installed other project(s) before this)" >&2
	exit 1
fi

if [ "$installed" -eq 0 ]; then
	echo "[ensure-vendor] all monorepo plugin/theme vendor/ already present"
else
	echo "[ensure-vendor] provisioned vendor/ for $installed project(s)"
fi
