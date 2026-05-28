# AI agent instructions

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules).

## Gotchas

- **No PHP autoloading.** Every new PHP file must be manually added as `include_once` in `Core::includes()` (`includes/class-core.php`). Missing this causes "class not found" errors at runtime. GAM API classes additionally need `require_once` in `includes/providers/gam/api/class-api.php`.
- **`npm run lint` runs JS and SCSS only.** PHP linting requires separate `npm run lint:php`. Running only `npm run lint` will miss PHP violations.
- **The advertising settings UI lives in `newspack-plugin`, not here.** This repo provides REST APIs, blocks, and frontend rendering. The wizard page that consumes them is `Newspack_Ads_Configuration_Manager` in newspack-plugin.
- **Settings are migrating.** Old settings live in `Newspack_Ads_Configuration_Manager` (in newspack-plugin). New settings should use the `Settings` class in this repo (`includes/class-settings.php`).
- **Bidding features are gated.** Header bidding (Prebid.js) only activates when `NEWSPACK_ADS_EXPERIMENTAL_BIDDERS` is defined as `true` in `wp-config.php`. Without it, bidding code exists but never runs.
- **Prebid.js has a custom Babel rule** in `webpack.config.js` that loads `.babelrc.js` from the `prebid.js` npm package. Upgrading prebid.js may break the build if the Babel config format changes.
- **New JS entry points must import setup first.** `__webpack_public_path__` is set dynamically via `src/setup/public-path.js`. Import `src/setup/editor.js` or `src/setup/view.js` at the top of new entry points.
- **Two block registration patterns coexist.** `ad-unit` uses manual `registerBlockType()`. `tabs`/`tabs-item` use `block.json` + `wp.domReady()` + `registerBlock()` helper. Use the `block.json` pattern for new blocks.
- **All provider and integration checks must be defensive.** Use `class_exists()`/`function_exists()` guards. The plugin must work standalone without any other Newspack plugin.

## Dominant pattern for new PHP classes

```php
// includes/class-my-feature.php
namespace Newspack_Ads;

defined( 'ABSPATH' ) || exit;

class My_Feature {
	public static function init() {
		// Register hooks here.
	}
}
My_Feature::init();
```

Then add to `includes/class-core.php`:

```php
include_once NEWSPACK_ADS_ABSPATH . '/includes/class-my-feature.php';
```

## Recipe: add a new provider

1. Create `includes/providers/<name>/class-<name>-provider.php` extending `Provider`. Set `$this->provider_id` and `$this->provider_name` in the constructor.
2. Add `include_once` in `includes/class-core.php`.
3. In `includes/class-providers.php`: add a `use` import and call `self::register_provider( new <Name>_Provider() );` in `init()`. Forgetting this step means the provider silently won't appear.
4. See `class-broadstreet-provider.php` for a minimal implementation.

## Recipe: register a new placement

1. Call `Placements::register_placement( $key, $config )` during the `init` hook. Config requires `name` and either `hook_name` (single hook) or `hooks` (array of hooks).
2. The placement auto-appears in the admin UI unless `show_ui => false`.
3. The ad renders when the theme calls `do_action( 'hook_name' )`. If the theme doesn't fire the hook, the placement exists in the UI but never renders on the frontend.
4. See `register_default_placements()` in `includes/class-placements.php` for examples.

## Recipe: add a new bidder

Requires both PHP and JS changes, plus a gated constant:

1. Create `includes/bidders/class-<name>.php`, call `Newspack_Ads\register_bidder( $id, $config )`.
2. Add `include_once` in `includes/class-core.php`.
3. Import the corresponding Prebid.js adapter module in `src/prebid/index.js`.
4. Rebuild: `n build` (Prebid.js bundle changes).
5. Test with `define( 'NEWSPACK_ADS_EXPERIMENTAL_BIDDERS', true );` in `wp-config.php`.
