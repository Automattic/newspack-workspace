# newspack-ui-shadcn: agent instructions

Read `README.md` and `DECISIONS.md` before changing this package.

## The live site

https://newspack-ui-theme.space.fast/ is built from this package by a separate, internal repository, `newspack-ui-theme-site`, and published to Spacefast by hand. Merging a pull request here doesn't update it.

After a pull request merges that changes this package, or the newspack-ui tokens, `packages/colors` or `packages/motion` it is built from, republish the site: follow "After a pull request merges through you" in that repository's `AGENTS.md`. It expects a checkout of the site next to this monorepo (`../newspack-ui-theme-site` from the repository root) and this checkout on the merged `main`.

When you open a pull request that changes this package, add this line to its body:

> Merging won't update https://newspack-ui-theme.space.fast/. Republish it with the steps in the newspack-ui-theme-site repository's `AGENTS.md`.
