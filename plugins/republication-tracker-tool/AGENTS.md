# AI agent instructions for republication-tracker-tool

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules). This file covers only what is specific to this plugin.

The root file's `fix:js` remedy does not apply here: this plugin has no `fix:js` script (see "Missing scripts").

## Gotchas

- **No autoloader of any kind.** `composer.json` has no `autoload` block; every class is pulled in by a hand-maintained `require` list at the top of `republication-tracker-tool.php`. Adding a class means editing that list. Order is not load-bearing: every use of the `licenses.php` constants sits inside a function body, not at file scope.

- **The rewrite rule is never flushed.** `_activate()` is an empty stub (`republication-tracker-tool.php:201`) and `flush_rewrite_rules()` appears nowhere in the plugin. A fresh activation, or any change to the rule or to the `republication_tracker_tool_endpoint` filter, 404s until permalinks are re-saved by hand.

- **The tracking pixel is not the rewrite endpoint and not REST or admin-ajax.** It is a bare query string on the site root (`/?republication-pixel=true&post=ID`) intercepted by an anonymous closure on `template_include` at priority 99, which includes `includes/pixel.php` and exits. Because the closure `exit`s, only something that short-circuits **earlier** can break it: a `template_redirect` exit, a redirect, a full-page cache, or a `template_include` callback below priority 99 that exits.

- **With no GA4 ID configured, newly generated pixels record nothing.** `create_tracking_pixel_markup()` only appends `&ga4=` when `republication_tracker_tool_analytics_ga4_id` is set, and `pixel.php` only counts hits carrying that param (previously copied markup that still has one keeps incrementing). A "Total Views" column stuck at 0 is deliberate bot filtering, not a bug.

- **Republished content is built from raw `$post->post_content`; `the_content` is never filtered.** Shortcodes are stripped outright and all HTML comments are removed, which deletes every Gutenberg block delimiter. Server-rendered block output is therefore never generated; only whatever static inner HTML survives the comment strip goes out.

- **`republication_tracker_tool_post_types` only gates half the plugin.** It controls the `/republish/` page and the block, but `register_post_meta`, the REST fields, the meta boxes and the admin columns are all hardcoded to `'post'`. Adding a post type via the filter yields a working republish page with no editor UI and no registered meta.

- **There is no template-override mechanism.** The path `templates/republish-template.php` is hardcoded with no `locate_template()` or filter, and if the file is missing the code falls through and returns the theme's template. Renaming or moving it is a silent no-op, not a fatal.

- **There are three front doors and they assume different theme types.** The widget is the classic-theme path; the `/republish/` page template calls `get_header()`/`get_footer()`, also classic. The Republish Button block and its "Republish Section" pattern are registered on **both** theme types, but on classic themes `supports.inserter` / `inserter` is set to `false` (`includes/class-republish-button-block.php:70`, `includes/class-republish-pattern.php:70`), so they are hidden from the inserter while existing instances keep rendering. "The block is missing" on a classic theme is that gate, not a build failure. Do not replace those `$args['supports']` with a bare override: the code deep-merges deliberately, because `register_block_type_from_metadata()` shallow-merges and a flat override would drop the block's color/typography/spacing support and strip saved styles from existing instances.

- **The modal is guarded by a shared static, `Republication_Tracker_Tool::$modal_rendered`.** Whichever of the widget or the block renders first owns it, and the two ship different front-end scripts (`assets/widget.js` jQuery vs `dist/republish-button-view.js`) that both bind to the same `#republication-tracker-tool-modal` ID.

- **Per-image distribution control depends on newspack-plugin.** Without `\Newspack\Newspack_Image_Credits`, `can_distribute()` returns false for everything once "Distribute all media" is unchecked, so every image is stripped with no warning. Image removal also only matches `<img class="wp-image-N">` inside a `<figure>`; images without the core class always ship.

- **Naming is inconsistent in three specific places.** Post meta mixes separators (`republication-tracker-tool-hide-widget` with hyphens, `republication_tracker_tool_sharing` with underscores); one option drops `_tool` (`republication_tracker_additional_tracking_code`); and the hide filter is plain `hide_republication_widget` with no prefix. Grepping one convention finds half the code.

## Build: Grunt is not the build

- **`Gruntfile.js` builds nothing.** Its only tasks are `i18n` (addtextdomain + makepot) and `readme`. All JS and CSS is built by webpack through `newspack-scripts wp-scripts build`. `package.json` declares `"main": "Gruntfile.js"`, a standalone-era leftover that nothing consumes. Ignore it when investigating a build problem.

- **Never run the `readme` script.** `README.md` has deliberately diverged from `readme.txt` (Newspack maintainer credit, added contributor, different stable tag). `grunt readme` regenerates it from `readme.txt` and silently reverts all of that to the original INN Labs text.

- **Do not run `grunt i18n`.** CI regenerates this plugin's `.pot` (see the root `AGENTS.md`), so the `makepot` half is redundant. The `addtextdomain` half has no CI equivalent, and its glob excludes neither `vendor/` nor `tests/`, so it will rewrite text domains in any dependency or test file that carries a gettext call. No current dependency does, so a run today changes nothing there, but the exposure returns the moment one is added.

- **`assets/*.js` and `assets/*.css` are hand-written, git-tracked source served straight to the browser.** Webpack never reads or writes `assets/`. Only `src/` → `dist/` is built.

- **`dist/` is gitignored and the block registration bails without it.** On an unbuilt checkout the Republish Button block and the editor sidebar panel do not exist. On block themes this is completely silent; on classic themes you also get a `wp_json_file_decode` warning. Run `n build republication-tracker-tool` after any checkout or branch switch.

## Missing scripts

Of the standard build/lint/test script set, this plugin has only `build`, `lint:js` and `typescript:check` (it also carries `start`, `cm`, `i18n`, `readme` and the release scripts). Compared with the fully-tooled plugins here it is missing `watch`, `test`, `lint`, `clean`, `fix:js`, `format:js`, `lint:php`, `fix:php`, `lint:scss`, `format:scss` and the `*:staged` variants. `super-cool-ad-inserter` is the only other plugin missing `lint:php`. Consequences:

- `n watch` **fails** from this directory (`bin/watch-repo.sh` ends in `npm run watch`). Rebuild with `n build republication-tracker-tool`.
- `n test-js` **reports success while running nothing**, because pnpm special-cases a missing `test` script and exits 0. There are no JS tests here.
- To auto-fix JS, run `pnpm exec eslint --fix <path>` from the workspace root. It clears the formatting errors but not the structural ones (`no-undef` on `jQuery`/`$`, `no-var`, `no-unused-vars`), which need hand edits.

## Linting quirks

- **The pre-commit hook lints `assets/`; CI does not.** `lint:js` is scoped to `src`, but the plugin ships a root `.eslintrc.js`, so the hook lints every staged `.js` file, and `assets/widget.js`, `assets/republish-template.js` and `assets/clipboard-utils.js` carry well over a hundred pre-existing errors (legacy `var`, undeclared `jQuery`/`$`, wp-prettier spacing). Touching one line in any of them blocks your commit on errors you did not introduce.
- **Do not add a local `phpcs.xml`.** The root ruleset covers this plugin and carries a plugin-specific carve-out excluding `WordPress.Files.FileName.InvalidClassFileName` for this path, because the main file does not follow the `class-<name>.php` convention. CI and the pre-commit hook name the root standard explicitly so they would be unaffected, but a local config would silently change what anyone running PHPCS from this directory sees.

## Testing quirks

- PHP tests run with `n test-php`, but `tests/bootstrap.php` hard-requires the plugin's own `vendor/autoload.php` and exits 1 if absent. Run `n composer install` from this directory first on a fresh checkout.
- **The block test registers from `src/blocks/republish-button` while production registers from `dist/blocks/republish-button`.** Tests are green on an unbuilt checkout and on a stale `dist/`, so they can never catch a missing or outdated build.
- `$modal_rendered` leaks across tests. The two classes that render it (`test-widget.php`, `test-republish-button-block.php`) reset it in `tear_down()`; a new test that renders the widget or block without that reset sees empty modal markup.

## Monorepo-absorption leftovers

- **The standalone `Automattic/republication-tracker-tool` repo is still in the daily legacy-sync list**, mirrored in via `sync/republication-tracker-tool`. Open PRs for this plugin may live on that repo rather than here.
- `.distignore` (not `.gitignore`) defines the wp.org ZIP: it strips `src/`, `tests/`, `composer.json` and `bin/` but keeps `dist/`. Runtime code that reads a `src/` path will be missing in the released build.
- Edit `readme.txt` for wp.org, and edit `README.md` by hand when GitHub-facing content needs to change. Never regenerate README with Grunt (see above). Plugin version lives in the main file's `Version:` header; bumping `package.json` alone changes nothing.
