# AI agent instructions

See `../../AGENTS.md` for shared workspace conventions (Docker, `n` script, coding standards, git rules).

## Gotchas

- **Mixed autoloading with order dependency.** Composer classmap on `includes/` plus manual `require_once` in `newspack-newsletters.php`. Interfaces and base classes must be required FIRST (before provider implementations). After adding a new class file, you must both add a `require_once` to the main plugin file AND run `composer dump-autoload`.
- **`npm run lint` runs JS and SCSS only.** PHP linting requires separate `npm run lint:php`. Running only `npm run lint` will miss PHP violations.
- **The Newsletters wizard UI lives in `newspack-plugin`, not here.** This repo provides ESP APIs, editor, blocks, and rendering. The settings page (Engagement > Newsletters) is in newspack-plugin.
- **Publishing or making a post private triggers an ESP campaign send.** This is irreversible. The `transition_post_status` hook in the service provider base class sends the newsletter automatically. Be extremely careful with post status transitions, especially bulk operations.
- **Campaign Monitor is deprecated.** Code still exists in `service-providers/campaign_monitor/` but has zero client usage. Do not extend it. Active ESPs are Mailchimp, ActiveCampaign, and Constant Contact.
- **Three namespace styles coexist.** `Newspack\Newsletters` (backslash, newer: `Send_Lists`, `Subscription_Lists`), `Newspack_Newsletters` (underscore namespace: `Ads`, tracking classes), and no namespace with underscore prefix (legacy: `Newspack_Newsletters`, `Newspack_Newsletters_Contacts`). Use `Newspack\Newsletters` for new classes.
- **Inconsistent initialization patterns.** Some classes self-init at EOF (e.g., `Ads::init_hooks()` at bottom of `class-ads.php`), others are explicitly called in the main plugin file (e.g., `Subscription_Lists::init()`). Check both locations before adding initialization to avoid double-init.
- **`Subscription_List` uses a Mailchimp-specific trait.** The core class (`includes/class-subscription-list.php`) imports `Newspack_Newsletters_Mailchimp_Subscription_List_Trait`. Changing this trait affects all subscription lists, not just Mailchimp.
- **All integration checks must be defensive.** Use `class_exists()`/`function_exists()` guards for newspack-plugin dependencies. The plugin must work standalone.

## Dominant pattern for new PHP classes

```php
// includes/class-my-feature.php
namespace Newspack\Newsletters;

defined( 'ABSPATH' ) || exit;

class My_Feature {
	public static function init() {
		// Register hooks here.
	}
}
My_Feature::init();
```

Then in `newspack-newsletters.php`, add the `require_once` in the correct position (after interfaces/base classes, before dependent code):

```php
require_once NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'includes/class-my-feature.php';
```

Then run `composer dump-autoload`.

## Recipe: add a new ESP provider

Requires PHP, JS, and registration. See Constant Contact or ActiveCampaign for simpler examples (Mailchimp is the most complex).

1. Create `includes/service-providers/<name>/` with a main class extending `Newspack_Newsletters_Service_Provider` that implements `Newspack_Newsletters_ESP_API_Interface`, and a controller extending `Newspack_Newsletters_Service_Provider_Controller`.
2. Add `require_once` lines in `newspack-newsletters.php`. Order matters: the main class must come after the base class and interface requires.
3. Register via the `newspack_newsletters_registered_providers` filter in `includes/class-newspack-newsletters.php` `get_registered_providers()`.
4. Add provider-specific UI in `src/service-providers/<name>/`. Export an object matching the shape in `src/service-providers/index.js` (`ProviderSidebar`, `renderPreSendInfo`, `isCampaignSent`), and add it to the `SERVICE_PROVIDERS` map.
5. Run `composer dump-autoload`.
6. Rebuild: `n build`.
