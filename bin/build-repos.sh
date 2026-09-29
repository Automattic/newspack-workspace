#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

MONOREPO_ROOT="/newspack-monorepo"

# pnpm is provided by Node 20's bundled corepack. Enable on first use; the
# pnpm version is pinned via the `packageManager` field in package.json.
if ! command -v pnpm >/dev/null 2>&1; then
    corepack enable pnpm >/dev/null
fi

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

# Translate a container path under /newspack-{plugins,themes}/<name> to the
# pnpm filter selector for a workspace package: the package's actual `name`
# read from its package.json. We can't derive this from the folder, because a
# package's name often differs from its directory (plugins/newspack-plugin is
# named "newspack", plugins/newspack-blocks is "@automattic/newspack-blocks").
# A bare `--filter <dirname>` matches no projects there, so pnpm silently builds
# nothing and exits 0. Falls back to the basename if no name is found.
package_filter_for_dir() {
    local name
    name=$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/package.json" 2>/dev/null | head -n1)
    if [ -n "$name" ]; then echo "$name"; else basename "$1"; fi
}

# Build a standalone repos/ checkout with its own toolchain. These live outside
# the pnpm workspace, so `pnpm --filter` can't see them. Composer + JS deps are
# installed in-place; in CI mode the JS install is frozen/clean (reproducible)
# when a lockfile is present. Failures propagate so a broken standalone build
# can't silently pass as green.
build_standalone_repo() {
    local dir="$1"
    echo "Building standalone repo $dir"

    # PHP deps if present. composer.lock pins exact versions, so the install is
    # reproducible and identical in CI and dev. Dev deps are kept (a standalone
    # repo's build step may need them) -- matching the monorepo composer paths.
    if [ -f "$dir/composer.json" ]; then
        composer install --working-dir "$dir" --no-interaction || return 1
    fi

    # JS deps + build: no-op if there's no package.json.
    if [ ! -f "$dir/package.json" ]; then
        return 0
    fi

    # Detect package manager from lockfile; default npm for a lockfile-less repo
    # (npm's lenient hoisting is the safest default, and matches the prior code).
    local pm="npm"
    if [ -f "$dir/pnpm-lock.yaml" ]; then
        pm="pnpm"
    elif [ -f "$dir/yarn.lock" ]; then
        pm="yarn"
    fi

    # Install JS deps. In CI mode use a reproducible install where the package
    # manager + lockfile support it (pnpm is only selected when its lockfile
    # exists, so --frozen-lockfile is always satisfiable; npm ci needs a
    # package-lock.json). Everything else -- Yarn (avoids the Classic-vs-Berry
    # frozen-flag split), a lockfile-less repo, or dev mode -- gets a plain
    # install so it still builds. CI=true is scoped to the pnpm frozen install
    # (non-interactive in a TTY-less CI shell) and is deliberately NOT exported:
    # a global CI=true also flips pnpm's frozen-lockfile default onto the
    # dev-mode and workspace installs, which would break them on a stale lockfile.
    if [ "$MODE" = "ci" ] && [ "$pm" = "pnpm" ]; then
        ( cd "$dir" && CI=true pnpm install --frozen-lockfile ) || return 1
    elif [ "$MODE" = "ci" ] && [ "$pm" = "npm" ] && [ -f "$dir/package-lock.json" ]; then
        ( cd "$dir" && npm ci ) || return 1
    else
        ( cd "$dir" && "$pm" install ) || return 1
    fi

    # Run build only if a build script is declared.
    if grep -q '"build"[[:space:]]*:' "$dir/package.json" 2>/dev/null; then
        ( cd "$dir" && "$pm" run build ) || return 1
    fi
}

if [ $# -eq 0 ]; then
    echo "No arguments provided"
    echo "Possible arguments: all, theme, block-theme, or any plugin slug"
    exit 1
fi

WHAT_TO_BUILD="$1"
MODE="$2"

cd "$MONOREPO_ROOT"

# Workspace install: pnpm resolves `workspace:*` deps (e.g. newspack-scripts)
# and links shared bins into each package's node_modules/.bin.
if [ "$MODE" = "ci" ]; then
    # CI=true (scoped, NOT exported) keeps pnpm non-interactive so a one-time
    # node_modules re-layout (e.g. after a hoist-pattern change in .npmrc)
    # auto-purges instead of blocking on a TTY-less confirmation prompt --
    # `n` runs pnpm via `docker exec` without a TTY, where that prompt aborts.
    CI=true pnpm install --frozen-lockfile
else
    pnpm install
fi

case $WHAT_TO_BUILD in
    all)
        # Root composer install provisions the shared PHP dev tooling
        # (PHP_CodeSniffer + the WP coding standards in ./vendor) that the
        # pre-commit hook's PHP check and `composer phpcs` depend on -- the
        # counterpart to the root `pnpm install` above that provisions the JS
        # linters. The monorepo root is bind-mounted (.:/newspack-monorepo), so
        # this lands on the host ./vendor where the git hook runs.
        composer install --working-dir "$MONOREPO_ROOT"
        # Composer install per monorepo project (each plugin still has its own
        # composer.json for production deps; dev deps are hoisted to the root).
        # Standalone repos/ checkouts are not built here -- they're external and
        # often distributed/pre-built; build one on demand with `n build <name>`.
        while IFS= read -r dir; do
            [ -d "$dir" ] && composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        pnpm run build
        ;;
    *)
        dir="$(find_project "$WHAT_TO_BUILD")"
        if [[ "$dir" == "$REPOS_PATH"/* ]]; then
            build_standalone_repo "$dir"
        else
            pkg=$(package_filter_for_dir "$dir")
            echo "Building $dir (package: $pkg)"
            composer install --working-dir "$dir"
            pnpm --filter "$pkg" run build
        fi
        ;;
esac
