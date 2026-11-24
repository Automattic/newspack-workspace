# newspack-ui-shadcn: agent instructions

Read `README.md` and `DECISIONS.md` before changing this package.

## The live site

https://newspack-ui-theme.space.fast/ is built from this package by a separate, internal repository, `newspack-ui-theme-site`, and published to Spacefast by hand. Merging a pull request here doesn't update it.

The site is built from everything this package reads, so republish it after a pull request merges that changes any of:

- `packages/ui-shadcn`;
- newspack-ui, in `plugins/newspack-plugin/src/newspack-ui`;
- `packages/colors` or `packages/motion`.

Follow "After a pull request merges through you" in the site repository's `AGENTS.md`. That file also says where the site and this monorepo need to be checked out.

When you open a pull request that changes any of those paths, add this line to its "What changes" section, before the issue reference:

> Merging won't update https://newspack-ui-theme.space.fast/. Republish it with the steps in the newspack-ui-theme-site repository's `AGENTS.md`.
