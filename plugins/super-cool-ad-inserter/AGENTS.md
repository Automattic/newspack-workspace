# AI agent instructions for super-cool-ad-inserter

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules). This file covers only what is specific to this plugin.

End-user documentation lives in [`docs/readme.md`](docs/readme.md). Read it rather than inferring behavior from settings names.

## Three names refer to this one plugin

- `super-cool-ad-inserter` is the directory, the pnpm filter, the `n build` target, the `wp plugin activate` slug and the wp.org slug.
- `super-cool-ad-inserter-plugin` is the main PHP file (plus `.php`), the block namespace, the `.pot` filename and the frozen legacy GitHub repo.
- `scaip` is every PHP function, option, hook and text-domain prefix.

**Grep for `scaip`**, not for the plugin name.

## Gotchas

- **There is no build step and no bundler config.** `blocks/scaip-sidebar/block.js` and `assets/js/scaip-document-panel.js` are hand-written ES5 IIFEs served raw from source with hand-maintained `wp-*` dependency arrays. Never introduce JSX, ESM `import`, or `@wordpress/*` package imports here; nothing will transpile them.

- **`n build super-cool-ad-inserter` and `n watch` both exit 1**, because `package.json` defines no `build` or `watch` script. They fail differently: `n build` runs `pnpm --filter ... run build` and reports `ERR_PNPM_RECURSIVE_RUN_NO_SCRIPT`, while `n watch` goes through `bin/watch-repo.sh`, which ends in `npm run watch`, and reports npm's `Missing script: "watch"`. That is expected, not a broken environment. Root `pnpm run build` silently skips the package instead, so bulk builds stay green while the single-package command is red.

- **This plugin's JavaScript is linted and type-checked by nothing.** It is the only plugin or theme with no `.eslintrc.js` (three `packages/*` are also config-less), and there is no root ESLint config to fall back on, so the pre-commit hook deliberately skips these files. There is no `lint:js` script, and the declared `typescript:check` is vacuous (zero `.ts` files, shared config sets `checkJs: false`). PHP is covered by the root `phpcs.xml` and currently passes clean.

- **Do not "fix" the block name to match the directory.** `super-cool-ad-inserter-plugin/scaip-sidebar` is hardcoded in three places that must move together, and it is baked into saved `post_content`. Renaming it orphans every existing block on every site.

- **`blocks/` is not `block.json`-registered.** It uses `register_block_type()` with a literal `attributes` array that has to be hand-synced with the JS, and the two are out of sync: PHP declares `class`, which the block never produces (its `supports.className` yields `className`). PHP's `align` is redundant rather than wrong, since `supports.align` supplies it. `scaip_shortcode()` defensively reads all four. The declared `editor_style`/`style` handles are never registered with `wp_register_style`, so block styles do nothing.

- **No autoloading of any kind.** `composer.json` has no `autoload` section and the code is prefix-namespaced functions, not classes. A new PHP file loads only if you manually add a `require_once` to `super-cool-ad-inserter-plugin.php`.

- **The insertion guard is registered at the same `the_content` priority as the inserter, and after it.** Ads insert at priority 5; the filter meant to suppress insertion when the post already contains a scaip-sidebar block runs too late to affect the current pass. Because `remove_filter` is global, once it fires it kills insertion for the rest of the request.

- **Every singular post that clears the gate is fully re-serialized, even when zero ads are inserted.** `scaip_insert_shortcode()` round-trips content through `parse_blocks`/`serialize_block`, and rewrites classic content through `wpautop` + `DOMDocument` into `core/html` blocks. Any edit here changes markup on posts that receive no ads at all.

- **Settings labelled "blocks" use two different denominators.** The minimum-blocks setting counts all parsed blocks; start and period count only allowed blocks (`core/paragraph` by default).

- **One callback backs both `[scaip]` and the generic `[ad]` shortcode tag**, which can collide with other ad plugins. It returns an empty string whenever the `scaip_shortcode` action produced no output, so a missing ad provider or empty widget area is a silent no-op.

- **Translations stay unavailable until the next regeneration.** The shipped `.pot` was generated against the `super-cool-ad-inserter-plugin` domain while runtime strings use `scaip`, so it holds only the plugin-header strings (5 entries against the 15 a correct extraction produces). The shared regeneration script (`.github/scripts/update-translations.sh`) reads the domain from the plugin header, so it extracts as `scaip` while keeping the shipped filename. The change affects nothing distributed through wp.org, which reports no language packs and 0% translated in every locale, and the plugin ships no `.po` of its own; a publisher holding a private translation keyed to the old domain would still need to rekey it. Separately, the text domain `scaip` does not match the wp.org slug `super-cool-ad-inserter`, which is what actually blocks official language packs from loading. Just-in-time loading covers the absent `load_plugin_textdomain()` call, so adding one would not help. That mismatch is a pre-existing gap and its own ticket.

## Cross-plugin coupling with newspack-ads

The integration is entirely one-way: this plugin contains **zero** references to newspack-ads. Most of it lives in `plugins/newspack-ads/includes/integrations/class-scaip.php`, with three more coupling sites in `class-sidebar-placements.php`, `providers/gam/class-gam-model.php` and `src/frontend/side-rail-placements.js`.

- **That file is included and self-initialises unconditionally**; only three of its methods check `defined( 'SCAIP_PLUGIN_FILE' )`. The hook wiring in `init()` does not, so `remove_action( 'scaip_shortcode', ... )` and `add_filter( 'scaip_disable_sidebars', '__return_true' )` run whether or not this plugin is installed.
- Renaming any `scaip_*` hook, the `scaip-N` sidebar IDs, or the `scaip-document-panel` script handle **breaks newspack-ads with nothing failing in this repo**. Enumerate the surface with `grep -rn scaip plugins/newspack-ads/`.
- The `<aside class="scaip scaip-N">` wrapper is also a contract, and a broader one than it looks: `.scaip` is targeted by newspack-theme SCSS and PHP *and* by newspack-ads frontend JS. Check with `grep -rn "scaip" themes/ plugins/newspack-ads/src/`.
- **With newspack-ads active, behavior changes invisibly.** Two UIs write the same `scaip_settings_*` options, sidebars are disabled via `scaip_disable_sidebars` (unless the publisher re-enables "Use legacy widgets for ad placement"), and this plugin's per-post editor panel is dequeued at `enqueue_block_editor_assets` priority 100. The panel "disappearing" is the integration working, not a bug.

## Local setup and testing

- **`n setup` does not activate this plugin.** Activate it by directory name: `n wp plugin activate super-cool-ad-inserter`, plus newspack-ads if you need the integration path.
- PHP tests run with `n test-php`, but `tests/bootstrap.php` unconditionally requires the plugin's own `vendor/autoload.php`. Run `n composer install` from this directory first on a fresh checkout, or you get a fatal rather than a helpful error.
- **A green test run proves almost nothing.** The tests covering the core insertion logic are `markTestIncomplete` stubs asserting only that empty input yields empty output, so `scaip_insert_shortcode()` has no real coverage. The substantive tests that do exist (`tests/inc/test-scaip-shortcode.php`, `tests/inc/test-scaip-metaboxes.php`) cover shortcode output and the meta auth callback, not insertion.
- `n test-js` exits 0 without running anything; there are no JS tests.

## Do not delete as standalone-era cruft

`.distignore`, `release.config.js` and the `release:archive` script are load-bearing for the live wp.org deploy. The workspace-root `.github/workflows/_release-wporg.yml` calls `release:archive` **without** `--if-present`, so removing it breaks the release rather than no-opping.

Genuinely dead: the `start`, `release` and `semantic-release` scripts in `package.json` (`start` runs `npm ci`, which is actively harmful in a pnpm workspace), and stale `github.com/Automattic/super-cool-ad-inserter-plugin` URLs across eight files, pointing at the retired standalone repo. Two of those are user-facing: the Settings → Ad Inserter page (`inc/scaip-settings.php:137`) and the block placeholder's documentation link (`blocks/scaip-sidebar/block.js:141`).

Unlike other absorbed plugins, `Automattic/super-cool-ad-inserter-plugin` is **frozen and removed from the legacy-sync mapping**. All work belongs in this copy; old PRs there are not being synced in.
