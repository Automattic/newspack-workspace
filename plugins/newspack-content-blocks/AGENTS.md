# AI agent instructions for newspack-content-blocks

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules). This file covers only what is specific to this plugin.

## Gotchas

- **The plugin is dormant while newspack-blocks is active.** `Newspack_Content_Blocks\Guard::is_legacy_plugin_active()` runs once, when the main file loads, and `Plugin::load()` only runs when it returns false. Don't register anything outside `Plugin::load()` (the main file's text domain hook included).
- **newspack-plugin's bundled copy of newspack-blocks defines `NEWSPACK_BLOCKS__VERSION` too.** The guard ignores that constant when `Newspack\Bundled_Blocks::is_loaded()`, so the bundled copy doesn't keep this plugin dormant.
- **Never define a `NEWSPACK_BLOCKS__*` constant, a `Newspack_Blocks*` class or a `newspack_blocks_*` / `newspack_articles_*` function here.** They would collide with newspack-blocks when both load in one request, such as the request that activates it. Public hook, block, route and CSS names are unchanged by design.
- **New PHP files go in `Plugin::FILES`** (class name => path), and need `n composer dump-autoload` from this directory.
