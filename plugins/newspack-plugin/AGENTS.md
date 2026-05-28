# Newspack Plugin: Agent Instructions

This file covers what is specific to `newspack-plugin`. Shared conventions (Docker commands, `n` script, coding standards, git rules, etc.) are in the root `newspack-workspace/AGENTS.md`.

## Linting Commands

```bash
npm run lint             # JS + SCSS only (see gotchas)
npm run lint:js          # JavaScript/TypeScript linting
npm run lint:scss        # SCSS linting
npm run lint:php         # PHP linting (PHPCS)
npm run fix:js           # Auto-fix JS issues
npm run fix:php          # Auto-fix PHP issues (PHPCBF)
```

## Common Gotchas

- `npm run lint` runs JS + SCSS only. PHP linting requires a separate `npm run lint:php`.
- After adding a new PHP file, run `composer dump-autoload` to update the classmap (Composer uses `classmap`, not PSR-4).
- Individual JS test files cannot run independently. Always run `npm test` for the full suite.
- Never import `react-router-dom` directly in source code. Use the proxy: `import Router from '../../packages/components/src/proxied-imports/router'`. Tests may import `react-router-dom` directly.
- New standalone webpack entry points must import `src/shared/js/public-path.js` first.
- Plugin integration classes in `includes/plugins/` use the root `Newspack` namespace despite living in subdirectories.

## PHP Backend

### Bootstrap & Autoloading

- **`newspack.php`**: Main plugin file, defines constants (`NEWSPACK_ABSPATH`, `NEWSPACK_PLUGIN_FILE`, etc.), requires Composer autoloader and Action Scheduler.
- **`includes/class-newspack.php`**: Singleton main class. Manually `include_once`s files in a specific order via `includes()`, then hooks `init()` methods.
- **Autoloading**: Composer `classmap` strategy (not PSR-4). After adding a new PHP file, run `composer dump-autoload` to update the classmap.

### Class Initialization Patterns

New classes should follow the **static `init()` pattern** (dominant, used by 100+ classes):

```php
namespace Newspack;

class My_Feature {
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_things' ] );
    }
    // ... static methods ...
}
My_Feature::init();
```

Two other patterns exist but are legacy or special-purpose:
- `new ClassName()` at file bottom (rare, ~7 classes like `API`, `Profile`)
- `Newspack::instance()` singleton (only the main class)

Classes that should not be extended are marked `final`.

### Namespace Map

| Namespace | Directory |
|-----------|-----------|
| `Newspack` | `includes/` (root, most classes) |
| `Newspack\API` | `includes/api/` |
| `Newspack\CLI` | `includes/cli/` |
| `Newspack\Data_Events` | `includes/data-events/` |
| `Newspack\Data_Events\Connectors` | `includes/data-events/connectors/` |
| `Newspack\Reader_Activation` | `includes/reader-activation/` |
| `Newspack\Reader_Activation\Sync` | `includes/reader-activation/sync/` |
| `Newspack\Reader_Activation\Integrations` | `includes/reader-activation/integrations/` |
| `Newspack\Wizards` | `includes/wizards/` |
| `Newspack\Wizards\Newspack` | `includes/wizards/newspack/` |
| `Newspack\Wizards\Traits` | `includes/wizards/traits/` |
| `Newspack\Optional_Modules` | `includes/optional-modules/` |
| `Newspack\Content_Gate` | `includes/content-gate/` |
| `Newspack\Collections` | `includes/collections/` |

### REST API

Two namespace constants, defined in `includes/util.php`:
- `NEWSPACK_API_NAMESPACE` = `newspack/v1` (primary)
- `NEWSPACK_API_NAMESPACE_V2` = `newspack/v2`

Three patterns for registering routes (in order of prevalence):

1. **Wizard Section routes** (most common): Extend `Wizard_Section`, implement `register_rest_routes()` (auto-hooked to `rest_api_init`). Route pattern: `wizard/{wizard_slug}/{section}`. Permission: `$this->api_permissions_check()`.

2. **Wizard routes**: Extend `Wizard`, implement `register_api_endpoints()`. Route pattern: `wizard/{slug}/...`. Permission: `$this->api_permissions_check()`.

3. **Standalone controllers**: In `includes/api/`, extend `WP_REST_Controller`. Only 2 exist (`Plugins_Controller`, `Wizards_Controller`).

Common sanitize callbacks from `util.php`: `Newspack\newspack_clean()`, `Newspack\newspack_string_to_bool()`.

### Wizard System (PHP side)

Two levels of abstraction:

- **`Wizard`** (abstract, `includes/wizards/class-wizard.php`): Override `$slug`, `$capability`, `get_name()`. Renders an empty `<div>` for React hydration. Provides `api_permissions_check()`, completion tracking, admin menu registration.

- **`Wizard_Section`** (abstract, `includes/wizards/class-wizard-section.php`): Modular sections within a wizard. Set `$wizard_slug`, implement `register_rest_routes()`. Used by 9+ section classes under `includes/wizards/newspack/` (Emails, Pixels, Social, etc.).

For the frontend counterpart, see **Frontend > Wizard System** below.

### Plugin Integrations

Simple integrations live in single files: `includes/plugins/class-{plugin}.php`. Complex integrations span subdirectories: `includes/plugins/woocommerce/`, `includes/plugins/co-authors-plus/`, `includes/plugins/woocommerce-subscriptions/`, etc.

Some integrations have corresponding Configuration Managers in `includes/configuration_managers/`.

### Optional Modules

Feature flags managed by `includes/optional-modules/class-optional-modules.php`. Check the class for the current module list. Enable/disable via `wp newspack optional-modules enable|disable|list`.

### Settings & Data Storage

| Mechanism | Convention | Examples |
|-----------|-----------|----------|
| `wp_options` | Prefixed per subsystem | `newspack_reader_activation_*`, `newspack_donation_*` |
| Custom Post Types | Short prefix | `newspack_rr_email`, `np_content_gate` |
| User meta | `np_` prefix | `np_reader`, `np_reader_email_verified` |
| Feature flag constants | In `wp-config.php` | `NEWSPACK_CONTENT_GATES`, `NEWSPACK_LOG_LEVEL` |

### Logging

`Newspack\Logger` provides:
- `Logger::log( $payload, $header, $type )`: gated by `NEWSPACK_LOG_LEVEL` constant (0 = off, 1 = basic, 2 = verbose).
- `Logger::newspack_log( $code, $message, $data, $type )`: fires `newspack_log` action (consumed by Newspack Manager).

Subsystems use a `LOGGER_HEADER` constant (e.g., `Data_Events::LOGGER_HEADER = 'NEWSPACK-DATA-EVENTS'`).

### WP-CLI Commands

All under the `newspack` namespace, defined in `includes/cli/`. Run `wp newspack --help` to list available subcommands.

### PHP Testing

```bash
npm run lint:php         # PHP linting (PHPCS)
npm run fix:php          # Auto-fix PHP issues (PHPCBF)
```

- Tests live in `tests/unit-tests/`, extend `WP_UnitTestCase`.
- Bootstrap: `tests/class-newspack-unit-tests-bootstrap.php`. Mocks in `tests/mocks/`.
- Available `@group` annotations: `byline-block`, `corrections`, `Access_Rules`, `WooCommerce_Subscriptions_Integration`.
- Run tests via `n test-php` from the repo directory (see parent AGENTS.md for flags).

## Frontend (JS/React/TypeScript)

### Wizard System (two coexisting architectures)

For the PHP backend counterpart, see **PHP Backend > Wizard System** above.

**Modern pattern (use for new code):**
- Single entry point: `src/wizards/index.tsx` uses `React.lazy()` + `Suspense` to load views by `?page=` URL param.
- Views are function components (`.tsx` preferred).
- Data fetching: `useWizardApiFetch(slug)` hook from `src/wizards/hooks/`.
- Composition helpers: `WizardsTab`, `WizardSection`, `WizardsActionCard` from `src/wizards/`.
- Used by: Dashboard, Settings, Audience pages.

**Legacy pattern (found in older wizards):**
- Standalone webpack entry per wizard: `src/wizards/{name}/index.js`.
- Uses `withWizard(Component, requiredPlugins)` HOC.
- Data fetching via `wizardApiFetch` prop (from HOC).
- Mounts via `createRoot()` + `render()`.
- Used by: Setup, Newsletters, Advertising.

### State Management

`@wordpress/data` custom store, namespace `newspack/wizards` (`WIZARD_STORE_NAMESPACE`):
- Key selectors: `getWizardAPIData(slug)`, `getWizardData(slug)`, `isLoading()`.
- Key actions: `wizardApiFetch`, `saveWizardSettings`.
- Auto-fetches data from `/newspack/v1/wizard/{slug}` via resolver.
- Helper: `useWizardData(wizardName)` from `packages/components/src/wizard/store/utils.js`.

Reader activation frontend uses a separate localStorage-based store (`src/reader-activation/store.js`), not `@wordpress/data`.

### TypeScript Conventions

Mixed JS/TS codebase (~30% TypeScript). Newer wizard views are `.tsx`; older wizards, blocks, and most components remain `.js`.

- Config extends `newspack-scripts/config/tsconfig.json` (strict mode).
- Type declarations: ambient `.d.ts` files with global types (no imports needed). Located at `src/wizards/types/` and feature-level `types.d.ts`.
- Window globals typed via `declare global { interface Window { ... } }`.

### Import Conventions

**Order** (each group separated by a blank line with a JSDoc comment header):
1. `/** External dependencies */` (classnames, lodash, etc.)
2. `/** WordPress dependencies */` (@wordpress/\*)
3. `/** Internal dependencies */` (relative paths)

**Component library**: Import from `packages/components/src` via relative paths (no webpack alias):
```js
import { Button, ActionCard } from '../../packages/components/src';
```

**Router**: In source code, always import through the proxy, never directly from `react-router-dom` (tests may import directly):
```js
import Router from '../../packages/components/src/proxied-imports/router';
const { HashRouter, Route, Switch } = Router;
```

**Colors in JS**: `import colors from '../../packages/colors/colors.module.scss';`

### Webpack Entry Points

Base config from `newspack-scripts`, which extends `@wordpress/scripts`.

**Auto-discovered entries:**
- Wizards: `src/wizards/*/index.{js,tsx}` (~7 standalone entries)
- Other scripts: `src/other-scripts/*/index.js` (~11 entries)

**Hardcoded entries (~30)**: reader-activation scripts, content-gate scripts, my-account variants, admin/editor scripts, blocks, collections, newspack-ui, bylines, and more. All declared in `webpack.config.js`.

**Code splitting**: Hashed chunk filenames, commons split chunk. Public path set dynamically via `src/shared/js/public-path.js` from `window.newspack_urls.public_path`. Any standalone entry point must import this file first.

**Ad blocker workaround**: `advertising/` wizard bundled as `billboard.js`.

### Gutenberg Blocks

Blocks in `src/blocks/` with `block.json` metadata. Central registration in `src/blocks/index.js`.

- Each block exports `{ metadata, name, settings }`.
- Conditional registration based on `window.newspack_blocks` feature flags (`has_reader_activation`, `corrections_enabled`, `collections_enabled`, `has_memberships`, `is_block_theme`).
- Icons from `packages/icons/` with foreground color from `packages/colors/`. See `packages/icons/DEVELOPMENT.md` for the icon selection hierarchy (prefer `@wordpress/icons` first, then Newspack icons) and React/PHP usage patterns.
- Some blocks have separate webpack entries for frontend `view.js` scripts.

### SCSS & Color System

- Design tokens in `packages/colors/colors.module.scss` (primary, secondary, tertiary, quaternary, neutral + semantic colors, each with 000-1000 scale).
- See `packages/colors/DEVELOPMENT.md` for the color usage decision tree: backend admin uses WordPress colors (with `primary-600` accent override), block icons must use `primary-400`, frontend Newspack UI uses `newspack-colors`, theme elements use the theme palette.
- BEM-ish naming with `newspack-` prefix (e.g., `.newspack-wizard__header`, `.newspack-card`).
- Tachyons CSS utility library available for utility classes.
- Shared mixins in `src/shared/scss/_mixins.scss`.

### JS Testing

```bash
npm test                 # IMPORTANT: Always run the full suite. Individual test files cannot run independently.
npm run tsc              # TypeScript type checking (watch mode, no emit)
```

- Jest via `newspack-scripts test` (wraps `@wordpress/scripts`).
- Test files colocated with source using `.test.js` suffix.
- Libraries: `@testing-library/react` (`render`, `fireEvent`, `waitFor`, `screen`).
- Mocking: `jest.mock()` for `@wordpress/data`, `@wordpress/api-fetch`; direct manipulation of `window.*` globals.

## Recipes

### Add a new wizard section

1. Create a PHP class in `includes/wizards/newspack/` extending `Wizard_Section`.
2. Set `$wizard_slug` to match the parent wizard, implement `register_rest_routes()`.
3. `include_once` the file in `includes/class-newspack.php` (order matters).
4. Run `composer dump-autoload`.
5. Create a React component in `src/wizards/` (use modern pattern: function component, `.tsx`, `useWizardApiFetch`).
6. Add a `React.lazy()` import in `src/wizards/index.tsx` mapped to the `?page=` param.

### Add a new block

1. Create a directory in `src/blocks/<block-name>/` with `block.json`, `edit.js`, `index.js`.
2. Export `{ metadata, name, settings }` from `index.js`.
3. Register the block in `src/blocks/index.js` (add feature flag condition if needed via `window.newspack_blocks`).
4. If the block needs a PHP render callback, register it in `includes/class-blocks.php`.
5. If the block needs a frontend script, add a `view.js` and a hardcoded webpack entry in `webpack.config.js`.

### Add a new plugin integration

1. Create `includes/plugins/class-{plugin-name}.php` using the root `Newspack` namespace.
2. Follow the static `init()` pattern (see Class Initialization Patterns above).
3. `include_once` the file in `includes/class-newspack.php`.
4. Run `composer dump-autoload`.
5. Optionally add a Configuration Manager in `includes/configuration_managers/` for setup UI.
