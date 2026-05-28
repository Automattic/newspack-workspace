# Agent guidelines for newspack-network

This file covers what is specific to `newspack-plugin`. Shared conventions (Docker commands, `n` script, coding standards, git rules, etc.) are in the root `newspack-workspace/AGENTS.md`.

See DEV_NOTES.md for additional guidelines.

## Gotchas

- **Classmap autoloading, not PSR-4.** After adding, renaming, or moving any PHP class, you must run `composer dump-autoload` or the class will not be found at runtime.
- **Typo in class name: `Rest_Authenticaton`** (missing second 'i' in "Authentication"). File is `class-rest-authenticaton.php`. Do not "fix" the spelling without renaming everywhere including the autoload classmap.
- **Typo in method name: `processs_request_errors`** (triple 's') in `includes/node/class-webhook.php`. It is registered as a hook callback; renaming it breaks the hook binding.
- **CLI class namespaces don't match their directory.** Three classes in `includes/cli/` use namespace `Newspack_Network` (not `Newspack_Network\CLI`). A fourth (`Integrity_Check`) uses `Newspack_Network\CLI`. Follow the existing pattern for whichever file you're nearest to.
- **Two content distribution systems coexist.** `Distributor_Customizations` (old, wraps third-party Distributor plugin) and `Content_Distribution` (new, native). Both initialize unconditionally. New content distribution work should use `Content_Distribution`.
- **`dist/*.asset.php` files are generated but never consumed.** PHP enqueue calls hardcode dependency arrays (often empty `[]`). Adding a new `@wordpress/*` import that isn't already on the page will fail at runtime even though the build succeeds.
- **`distribute-panel` webpack entry exists only for CSS extraction.** Its JS bundle is never enqueued by PHP. The component is imported directly by `outgoing-post` and `incoming-post`.
- **`npm test` is a no-op** that always exits 0. There are no JS tests.
- **Event propagation requires the Newspack plugin.** Without `Newspack\Data_Events`, all webhook/event listeners silently skip registration. Local dev must have Newspack active or events will never fire.
- **Lowercase `constants` sub-namespace.** `includes/constants.php` uses `namespace Newspack_Network\constants` (lowercase). Import with `use const Newspack_Network\constants\CONSTANT_NAME` (note the `const` keyword).
- **Mutable static flags suppress events.** `User_Update_Watcher::$enabled` and `Woocommerce_Memberships\Events::$pause_events` are toggled during certain operations. If your code depends on these events firing, check these flags.
- **Hub-only vs unconditional initialization.** In `Initializer::init()`, lines 35-46 are hub/node-gated, but most `init()` calls below run on ALL sites. Place new hub-only or node-only code inside the appropriate guard.
- **`.travis.yml` is stale.** Real CI is GitHub Actions (`.github/workflows/`). Ignore the Travis config.
- **Two JS ecosystems.** Block editor features use webpack/React (`src/` to `dist/`). Admin pages use raw JS/jQuery enqueued directly from `includes/`. Match the existing pattern for the page type.
- **Shutdown-deferred processing.** `Content_Distribution::distribute_queued_posts` and `User_Update_Watcher::maybe_trigger_event` fire on the `shutdown` hook. Side effects are not immediate; they are lost if PHP terminates early.

## Adding a new event action

The `Accepted_Actions::ACTIONS` map in `includes/class-accepted-actions.php` drives dynamic class instantiation across the codebase. The string value (e.g., `'Reader_Registered'`) is used as a class name suffix in three different namespaces. All names must match exactly; a mismatch causes a runtime class-not-found error with no compile-time check.

1. Add the action to the `ACTIONS` constant in `includes/class-accepted-actions.php`.
2. Create `includes/incoming-events/class-your-action.php` (namespace `Newspack_Network\Incoming_Events`, extend `Abstract_Incoming_Event`).
3. Create `includes/hub/stores/event-log-items/class-your-action.php` (namespace `Newspack_Network\Hub\Stores\Event_Log_Items`, extend `Abstract_Event_Log_Item`).
4. If nodes should pull this event, add it to `ACTIONS_THAT_NODES_PULL` and create `includes/cli/backfillers/class-your-action.php` (namespace `Newspack_Network\Backfillers`, extend `Abstract_Backfiller`).
5. Run `composer dump-autoload`.

## Required manual steps

- **After adding/moving/renaming any PHP class:** `composer dump-autoload` (classmap autoloading).
- **After adding a new JS entry point:** add it to `webpack.config.js` manually (no auto-discovery), then `npm run build`. The entry key determines the `dist/` filename, not the source directory name.

## Testing quirks

- **PHP tests** require the WordPress test framework. Run `bin/install-wp-tests.sh` with DB credentials first, or use the Docker environment.
- **No JS tests exist.** `npm test` always exits 0.
