# AI agent instructions for newspack-story-budget

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules). This file covers only what is specific to this plugin.

There is no surviving Edit Flow legacy here despite the plugin's origins; the fork was rewritten. The only prefix archaeology is the `np_` / `_np_` / `newspack_` split below.

## Gotchas

- **An unbuilt checkout fatals every wp-admin page, not just the Story Budget screen.** `Admin::enqueue_assets()` does an unguarded `require` of `dist/story-budget-data.asset.php` on `admin_enqueue_scripts`, and `dist/` is gitignored. The plugin also `require_once`s its own `vendor/autoload.php` with no guard. Run `n build newspack-story-budget` (which does `composer install` too) before activating.

- **The Story Budget admin page destroys every other plugin's admin notices.** It calls `remove_all_actions( current_action() )` at priority `-PHP_INT_MAX` on `admin_notices`, `all_admin_notices` and `network_admin_notices`. If a notice or debug output "doesn't appear" on that screen, this is why.

- **Three naming prefixes coexist and are not interchangeable.** Hooks and filters use `newspack_story_budget_`; post meta uses `_np_story_budget_` (`Abstract_Field::FIELD_PREFIX`); the statuses bootstrap option uses `np_story_budget_`. The budget taxonomy is `newspack_story_budget` with no trailing underscore, and the status taxonomy is `newspack_story_status`, not `newspack_story_budget_status`.

- **The "modified" meta key has a real double underscore**: `_np_story_budget__modified`. Hand-writing a single underscore returns nothing, and this key is what the app's incremental `?since=` refresh depends on.

- **Do not "fix" the field-update route regex.** `(?P<slug>[\a-z]+)` in `includes/class-api.php:203` contains `\a` (PCRE BEL, 0x07), making it a 0x07 to 0x7A range that happens to match every real slug including `word_count`. Narrowing it to `[a-z]+` breaks every field with an underscore or digit. Slugs run through `sanitize_title()`, so they can contain hyphens too: any replacement must keep them, for example `[a-z0-9_-]+`.

- **`CLI::register_comands()` is misspelled and hooked by that spelling.** Rename both the definition and the `add_action`, or neither. Fixing only the definition silently kills all WP-CLI commands.

- **Default statuses are created exactly once, ever.** The gate option `np_story_budget_default_statuses_initialized` is written *before* the terms are created. Delete the status terms and they never come back; the Status dropdown is permanently empty. Recovery is `Statuses::dangerously_reset_statuses()` or deleting that option by hand. There is no activation hook that resets it.

- **Field registration order is load-bearing.** `Fields::register_fields()` runs on `init` at the default priority 10, and `Taxonomy_Field::__construct` calls `get_taxonomy()`. A taxonomy that does not exist by then produces a field that self-disables with a logged error and no visible symptom, so it must register earlier than `init:10` (the plugin's own use `init:5`). The `newspack_story_budget_fields` filter must likewise be added before `init:10`.

- **`Fields` and `CLI` are each simultaneously a class and a namespace.** Sibling files disagree on how to disambiguate: `class-abstract-field.php` imports the class as plain `Fields`, while `class-editable-field.php` and `class-taxonomy-field.php` alias it `Fields_Class`. Copying an import between these files silently changes which symbol resolves. There are also two `class-budget.php` and two `class-story.php` files (`includes/` and `includes/cli/`) defining different classes with identical short names.

- **`Fields::$all_fields` is a static registry that is never reset**, and `register_fields()` is idempotent-by-error: a second call logs "Field with slug X already exists" and discards that slug's config. A `newspack_story_budget_fields` filter added inside a test method has no effect, because `register_fields()` already ran on `init` and nothing re-runs it.

- **`Budgets::$stories_query` is static mutable state** that every `get_stories()` call overwrites, and the REST layer reads `found_posts` off it afterwards. Any second `get_stories()` in between silently corrupts the reported total.

- **Custom-field search in the wp-admin posts list is disabled unless `NEWSPACK_STORY_BUDGET_ENABLE_SEARCH_META` is defined and truthy.** The `posts_join`/`posts_where` filters register unconditionally and then no-op there, but they stay live for the app's own `story_budget_search` REST queries. Other undocumented constants exist: `grep -rn "NEWSPACK_STORY_BUDGET_" includes/ src/`.

- **Quick Edit posts `newspack_story_budget_budgets[]` (plural), Bulk Edit posts `newspack_story_budget_budget[]` (singular)**, handled by different methods on different hooks. They differ by one letter.

- **Integrations degrade silently when the other plugin is absent**, which is the normal state in a bare local env. Notably `Logger` routes to `\Newspack\Logger` or falls back to `error_log` with a different format, so log output moves rather than disappearing. Find the rest with `grep -rn "class_exists\|function_exists" includes/`.

## Required manual steps

- **After adding, renaming or moving any PHP class under `includes/`:** run `n composer dump-autoload` from this directory. Autoloading is a Composer classmap with no PSR-4 fallback. `n watch` only runs webpack.
- **After adding a webpack entry:** register it manually in `webpack.config.js` (no auto-discovery) *and* add a matching enqueue in `includes/class-admin.php`. Build output goes to `dist/`, not the wp-scripts default `build/`.
- **`composer update` inside this plugin exits 127.** `composer.lock` is out of sync with `composer.json`, `brainmaestro/composer-git-hooks` is orphaned in the lock but absent from `require-dev`, and `post-update-cmd` unconditionally runs `vendor/bin/cghooks`, which the update itself prunes. Use `composer install` (warns but succeeds), or fix `require-dev` first.

## The @wordpress/data store

The store lives in its own webpack entry (`src/store/index.js` → `story-budget-data`), separate from every consumer bundle.

- **Import `NAMESPACE` from `../store/constants`, never from `../store`.** Importing the index re-runs `register()` inside the consuming bundle and double-registers the store.
- **New API-touching actions must be generators.** Every action in `actions.js` that hits the API is a generator using the custom `STORY_BUDGET_FETCH` control, while every resolver in `resolvers.js` is a modern async thunk. Writing an async thunk in `actions.js` and awaiting `apiFetch()` gets you a plain action object, not a response. (The pure state-setting actions, `setView`, `clearErrors` and similar, are plain functions.)
- **Never call `@wordpress/api-fetch` directly from store code.** The local `apiFetch` from `./controls` prefixes the API namespace and routes through middleware that injects Basic auth and `X-Network-Site-Url` for remote-site mode. The middleware short-circuits unless the request carries `isStoryBudget`, which only the `STORY_BUDGET_FETCH` control sets, so a direct call loses the namespace prefix **everywhere** (404s locally too), not just the auth headers on remote sites.
- **A new cached state slice needs two edits:** a `STORAGE_KEYS` entry (drives rehydration) and a `store/utils/cached-actions.js` entry (drives writes). The root reducer hydrates generically, so add a `HYDRATE` case in the slice reducer only if the cached shape needs reshaping on restore, as `view.js` and `meta.js` do.
- **`getStories`/`getBudgets`/`getView` are memoized with `createSelector` from `@wordpress/data`**, whose second argument (`getDependants`) returns the array the cache keys on. Reading new state inside the selector body without adding it there returns stale data with no build or lint error. This is not React's `useEffect` dependency array.
- **The remote-site target is captured once at module-eval time**, so switching sites needs a full page load rather than a state update.

## JavaScript gotchas

- **Every webpack entry that needs the store must manually add the `newspack-story-budget-data` handle** to its PHP dependency array (`includes/class-admin.php` appends it by hand via `array_merge`; `story-budget-quick-edit` deliberately does not). Otherwise the store and the `newspackStoryBudget` global are both missing at runtime despite a green build. Everything else comes from the generated `.asset.php`.
- **Some `@wordpress/*` packages are bundled rather than externalized** (`dataviews`, `icons`, `ui`, `admin-ui` are on the dependency-extraction plugin's `BUNDLED_PACKAGES` list). They never appear in `.asset.php` deps, which is why a dependency you expect is missing, and adding more of them silently grows an already 2.2 MB bundle.
- **Import lodash by subpath** (`lodash/debounce`). The bare `import { debounce } from 'lodash'` form is externalized to the deprecated `window.lodash` global.
- **Do not add `@wordpress/*` packages to this plugin's `package.json`.** They resolve through the root `.npmrc` `public-hoist-pattern`. Add to the root devDependencies instead.
- **The `newspack-story-budget.*` JS filters are a live cross-repo contract**, consumed by `newspack-network` and by at least one licensed plugin that lives outside this monorepo under `repos/`. Renaming one or reordering its arguments breaks them with no compile-time signal, and the out-of-repo consumer will not show up in any grep of this workspace. Enumerate the filters with `grep -rn "applyFilters( 'newspack-story-budget" src/`.
- **Real circular imports exist** (`components/budgets.js` ↔ `components/budget-rows.js`, `hooks/index.js` ↔ `components/table-row-field.js`). They work only because nothing is dereferenced at module-eval time.
- **Drag-and-drop is hand-rolled HTML5 DnD**, gated on a hardcoded class string compared in JS (`components/budget-rows.js:63`) against the `className` set at `:204`. Changing one and not the other silently disables dragging. The SCSS nests the name as `&-drag-handle`, so grepping the full class will not find it there.
- **`src/utils/index.js` is a default-export namespace barrel** with no named exports, so `import { sites } from '../utils'` fails.
- **`react-hooks/exhaustive-deps` is off workspace-wide** and several effects deliberately rely on incomplete dep arrays. Adding "missing" deps to satisfy a lint habit changes behavior.

## Testing quirks

- **New PHPUnit tests must go in `tests/unit-tests/`.** `phpunit.xml`'s only testsuite directory is that path, so `tests/class-test-search.php` is not collected by the suite and has rotted (it asserts a meta prefix the code stopped using). That is pre-existing, not your regression.
- `tests/bootstrap.php` requires the plugin's own `vendor/`, so run `n composer install` from this directory on a fresh checkout.
- **`n test-js` is a real Jest run, but it only covers `src/utils/*` and `src/store/cache/*`.** Nothing exercises the store or any component, so a green run says little about changes there.

## Linting quirks

- `npm run lint` covers SCSS and JS only and silently skips PHP. Lint PHP from the workspace root (see the root guide).
- **`lint:js` is scoped to `src` and `includes`, so root-level JS is never linted by CI**, but the pre-commit hook lints every staged `.js` in this plugin (its `.eslintrc.js` sits at the plugin root, so it governs them all). A root config file can pass CI and still block your commit.

## Dead config

`.travis.yml` (PHP 5.6 to 7.4, `branches: only: trunk`) and `.hooks/pre-push` (blocks pushes to `trunk`; `composer.json` has an empty `extra: {}` so cghooks installs nothing, and the repo sets `core.hooksPath=.husky/_`) are both inert. The `lint-staged` block in `package.json` is also dead, because husky runs the root `.lintstagedrc.json`. The `start` script runs `npm ci`, which fails in this pnpm workspace; use `n watch`.

`bin/install-wp-tests.sh` and `release.config.js` look like standalone-era cruft but are **live**: `n test-php` invokes the former by relative path, and the latter delegates to the monorepo's `config/release.js`.
