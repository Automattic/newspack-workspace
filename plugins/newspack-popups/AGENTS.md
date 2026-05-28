# Newspack Campaigns (newspack-popups): Agent Guidelines

Shared conventions (Docker, `n` script, coding standards, git rules) live in the workspace root `AGENTS.md`. This file covers only non-obvious hazards specific to this plugin.

## Naming

The same thing has three names depending on context:
- **Repository / code prefix**: `newspack-popups` / `Newspack_Popups_*`
- **Product name / UI brand**: "Newspack Campaigns"
- **CPT slug / user-facing label**: `newspack_popups_cpt` / "Prompts"

## Namespace split

Most PHP classes are in the global namespace with a `Newspack_Popups_` prefix. Newer code uses `Newspack\Campaigns`:
- `includes/cli/` → `Newspack\Campaigns\CLI`
- `includes/merge-tags/` → `Newspack\Campaigns`
- `includes/schemas/` → `Newspack\Campaigns` / `Newspack\Campaigns\Schemas`

## Autoloading is classmap, not PSR-4

Composer uses `classmap` autoloading. After adding or renaming a PHP file, run `composer dump-autoload` or autoloading will silently fail.

## Class init pattern

Newer classes use a static `init()` method called at file bottom (e.g. `Criteria`, `Expiry`, `Data_Api`). Follow this pattern for new classes. Older classes use singletons (`::instance()`) or bare `new` at file bottom.

## Criteria system spans PHP and JS

Display criteria require registration on both sides — this is the most common source of "it doesn't work" bugs:
1. **PHP** (`src/criteria/default/index.php`): defines criteria config (name, category, matching function).
2. **JS** (`src/criteria/default/*.js`): each file is a side-effect module that calls `setMatchingAttribute()` to wire up value-fetching from the Reader Data Library.
3. A new JS module must also be imported in `src/criteria/default/index.js`.

Missing either side produces no error — the criteria silently fails to match.

## Segmentation silently requires newspack-plugin

If `\Newspack\Reader_Data` doesn't exist (newspack-plugin not active), `Newspack_Popups::$segmentation_enabled` is set to `false` at boot. No error is thrown — segmentation features just silently stop working.

## data-frequency attribute is CSV, not JSON

The `data-frequency` attribute on `.newspack-popup-container` elements is CSV-encoded as `start,between,max,reset_period` (not JSON). Parsed in `src/view/utils/segments.js`.

## Two different capability checks

- **REST API** (`Newspack_Popups_API::permission_callback()`): checks `manage_options` directly.
- **Admin UI** (`Newspack_Popups::is_user_admin()`): defaults to `edit_others_pages`, filterable via `newspack_popups_admin_user_capability`.

A user can have admin UI access but fail REST calls (or vice versa).

## Lint commands

`npm run lint` runs JS + SCSS only. PHP linting requires a separate `npm run lint:php`.

## Test base class

PHP tests can extend `WP_UnitTestCase_PageWithPopups` (in `tests/wp-unittestcase-pagewithpopups.php`) which provides popup factory methods, segment setup helpers, and DOM assertion utilities.

## Inserter removes its own the_content filter during Homepage Posts rendering

The Inserter hooks `the_content` at priority 1 to inject prompts. During Homepage Posts block rendering, it temporarily removes this filter (via `newspack_blocks_homepage_posts_before_render` / `after_render` hooks) to prevent prompts inside post excerpts. If prompts are missing in a debugging scenario, check whether you're inside a Homepage Posts render cycle.

## Admin bar toggle lives in a separate webpack entry

The admin bar prompt visibility toggle is built from `src/view/admin.js` (the `admin` webpack entry), not from `src/editor/`. If modifying admin bar behavior, look in `src/view/`, not `src/editor/`.
