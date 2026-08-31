# AI agent instructions for newspack-multibranded-site

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules). This file covers only what is specific to this plugin.

## Gotchas

- **`src/admin/` is dead code on any site running newspack-plugin.** `Admin::add_admin_menu()` returns early whenever `Newspack\Newspack` exists, and redirects to `newspack-settings#/additional-brands` if you are on that page (`includes/class-admin.php:50`). The Brands UI publishers actually see is newspack-plugin's TSX at `plugins/newspack-plugin/src/wizards/newspack/views/settings/additional-brands/`. Editing `src/admin/` changes nothing on a normal site. The other two entry points (`src/post-primary-brand/`, `src/prompt-brands/`) are live.

- **The Brands UI talks to this plugin over core REST, not custom routes.** newspack-plugin's wizard hits `/wp/v2/brand`; the surface is `show_in_rest` on the taxonomy (`includes/class-taxonomy.php:110`) and on registered meta (`includes/class-meta.php:39`). Changing a meta key, its `show_in_rest` schema, or the taxonomy's `rest_base` breaks the publisher-facing UI with nothing failing in this plugin or its tests.

- **Renaming `Customizations\Theme_Colors` breaks two consumers in different ways.** newspack-theme drops its entire multibrand integration silently, gating on `class_exists( 'Newspack_Multibranded_Site\Customizations\Theme_Colors' )` (`themes/newspack-theme/newspack-theme/functions.php:1477`). newspack-plugin **fatals** instead: its call to `get_registered_theme_colors()` is guarded only by `defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE' )`, not by `class_exists` (`plugins/newspack-plugin/includes/wizards/newspack/class-newspack-settings.php:116,124`).

- **Brand colors and brand site-title links do nothing on `newspack-block-theme`.** Only the classic `newspack-theme` registers `newspack_multibranded_site_theme_colors` and fires `newspack_site_title_url`. An empty color picker on the FSE theme is expected, not a bug.

- **`Taxonomy::get_current()` is always null in wp-admin, REST, WP-CLI and cron**, because the current brand is resolved on the `wp` action. Most `Customizations\*` filters silently no-op there: every one that calls `get_current()`. `Url` and `Show_Page_On_Front` are the exceptions and work normally. Brand-aware code tested via `wp eval` or an admin screen looks broken when it is not.

- **`Taxonomy::POST_TYPES` and `Taxonomy::get_post_types()` are not interchangeable.** The method adds the Newspack Popups CPT when that plugin is active; the constant does not. They are already used inconsistently across the codebase, so pick deliberately.

- **New classes are registered in one of three places, not one.** `Initializer::init()` covers only `Customizations\*`, `Integrations\*`, `Admin` and `Taxonomy`. `Meta\*` classes are init'd inside `Taxonomy::register_taxonomy()` (`includes/class-taxonomy.php:121`). `Admin\*` classes are a third list inside `Admin::init()`.

- **A new `Meta` subclass has two silent-failure traps.** `Meta::$type` defaults to `'term'`, so a post- or user-meta class that forgets to redeclare `public static $type` registers as brand term meta instead. And with `$type = 'post'`, `Meta::register_option()` iterates `static::get_post_types()`, which the base class returns as `[]`, so a subclass that does not also override `get_post_types()` registers **nothing**. See `includes/meta/class-post-primary-brand.php` for the working shape. `Meta` declares three abstract methods (`get_key()`, `get_description()`, `get_schema()`); implementing only `get_key()` is a fatal.

- **`Meta::init()` calls `self::register_option()`, not `static::`.** Overriding `register_option()` in a subclass has no effect. Override `init()` instead.

- **Several class names exist in more than one namespace**, `Show_Page_On_Front` three times (`Customizations\`, `Meta\`, `Admin\`) with identically named files. The same holds for the `_primary_brand` meta key, which is registered against four object types (post, user, category term, tag term). Always check the `use` alias or the object type before assuming what a symbol refers to. Full list: `find includes -name 'class-*.php' -exec basename {} \; | sort | uniq -d`.

- **Docblocks are unreliable here.** Nearly every file in `includes/customizations/` and `includes/meta/` (plus one in `includes/admin/`) carries the copy-pasted file comment "Newspack Multibranded site taxonomy.", and several class docblocks describe a different class entirely (`Popups_Should_Display_Prompt` says "Blog Name Customization"; `Admin\Cat_Primary_Brand` and `Admin\Filter_Posts` both say "Newspack Authors Primary Brand"). `includes/integrations/` is the one directory whose comments are correct. Read the code, not the comment.

- **PHP hardcodes script dependencies and ignores the generated `*.asset.php` for two of the three entry points.** `admin` declares two handles where `dist/admin.asset.php` lists fifteen; `postPrimaryBrand` omits `react-jsx-runtime`, `wp-core-data` and `wp-data`. They get away with it for different reasons: `postPrimaryBrand` because the block editor has already loaded those, `admin` because `wp-components` pulls most of the fifteen in transitively. Adding a new `@wordpress/*` import gives you a green build and a runtime `wp.x is undefined`. Update the enqueue by hand, or switch the handle to read its `.asset.php` as `promptBrands` does.

- **`getWebpackConfig` shallow-spreads what you pass over the wp-scripts default**, so supplying `entry` replaces it wholesale rather than merging. Entries are a hardcoded object with no auto-discovery.

- **A checkout without `composer install` fatals on every request** (`newspack-multibranded-site.php:28`, an unguarded `require_once` of `vendor/autoload.php`). With vendor present but `dist/` missing, the Campaigns admin screen fatals on a bare `require` of `dist/promptBrands.asset.php` (`includes/admin/class-prompt-popups.php:34`); the other enqueues only emit `filemtime()` warnings. Run `n build newspack-multibranded-site`.

- **Cross-plugin integrations fail silently, not loudly.** This plugin hooks filters owned by newspack-plugin (`newspack_ga4_custom_parameters`, `newspack_content_gate_supported_taxonomies`), newspack-popups (`newspack_popups_should_display_prompt`), newspack-theme (`newspack_site_title_url`) and Yoast (`wpseo_primary_term_taxonomies`, in `includes/admin/class-post-primary-brand.php`). `Admin\Prompt_Popups` additionally string-matches the hardcoded screen base `audience_page_newspack-audience-campaigns`. In a bare local env "nothing happened" is the normal failure mode.

- **The plugin self-registers a GitHub-releases auto-updater against the standalone `Automattic/newspack-multibranded-site` repo** when newspack-manager is present (`newspack-multibranded-site.php:40`). It is the only plugin in the monorepo doing this, and it is independent of the monorepo release pipeline.

## Required manual steps

- **After adding, renaming or moving any PHP class under `includes/`:** run `n composer dump-autoload` from this directory. Autoloading is a Composer classmap, not PSR-4, so the class is invisible until the map is regenerated. `n build` regenerates the map via `composer install`; `n watch` does not.
- **New `Meta\*` classes register in `Taxonomy::register_taxonomy()`**, not `Initializer::init()`.

## Testing quirks

- **`phpunit.xml` and `phpunit.xml.dist` are both tracked and they disagree.** Local `n test-php` runs bare `phpunit`, which prefers `phpunit.xml` (testsuite: everything under `./tests/unit-tests`). CI prefers `phpunit.xml.dist` (testsuite: `./tests/` recursive, but only files matching `test-*`). A test file named anything else passes locally and is silently skipped in CI. Edit both, and name new test files `test-*.php`.
- **Tests that need a current brand must use `$this->go_to( ... )`.** There is no setter for `Taxonomy::$current_brand` and `do_action( 'init' )` is not enough, because the brand resolves on `wp`.
- **The theme-colors tests depend on a fixture filter registered in `tests/bootstrap.php`** returning a `primary_color` mod, where newspack-theme actually registers `primary_color_hex`. Tests that look self-contained break if you change the bootstrap.

## Linting quirks

- **This plugin is one of the two that ship a local `.phpcs.xml.dist`.** It is a stale plain-`WordPress` ruleset pinned to `7.2-` and WP 4.6, and it flags things the root ruleset explicitly allows, such as short array syntax. It also scans generated `dist/*.asset.php`, which CI does not.
- **`npm run lint` covers SCSS and JS only and silently skips PHP.** Lint PHP from the workspace root (see the root guide).
