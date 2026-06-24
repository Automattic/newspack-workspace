#!/bin/bash
#
# Entry point for the global watch dispatcher (`n watch` with no project arg).
# Runs at the monorepo root so the Node dispatcher resolves chokidar from the
# root node_modules and pnpm path filters resolve against the workspace.
#

# pnpm is provided by Node 20's bundled corepack. Enable on first use; the
# version is pinned via the `packageManager` field in package.json.
if ! command -v pnpm >/dev/null 2>&1; then
    corepack enable pnpm >/dev/null
fi

cd /newspack-monorepo
exec pnpm run watch
