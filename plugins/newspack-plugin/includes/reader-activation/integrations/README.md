# Newspack Integrations

## A framework for syncing reader data with third-party services.

A uniform contract for syncing Newspack reader data with external systems — ESPs, CRMs, donation platforms — through bidirectional contact synchronization.

Each integration:

- Pushes contact data (Newspack → external) on reader activity events.
- Pulls contact data (external → Newspack) on a recurring schedule.
- Declares its own settings, metadata prefix, and outgoing fields.
- Exposes external fields back to Newspack for use as segmentation criteria and content gate access rules.

The framework is built on top of [Data Events](../../data-events/README.md) and uses WooCommerce [ActionScheduler](https://actionscheduler.org/) for retries and per-integration job grouping.

---

## File Map

| File | Purpose |
| --- | --- |
| `../class-integrations.php` | Registry and orchestrator. Owns the integrations registry, enable/disable state, data event handler map, ActionScheduler group lookup, My Account endpoint registration, and the hourly health check. |
| `class-integration.php` | Abstract base class. Implements settings storage, metadata prefix, outgoing/incoming field selection, contact preparation, the health-check shell, and the data-event handler dispatcher. |
| `class-esp.php` | Built-in Mailchimp integration (id `esp`). Syncs through the Newspack Newsletters Mailchimp provider. |
| `class-form-capture.php` | Built-in `form-capture` integration. Registers readers from publisher-designated frontend forms. |
| `class-gravity-forms.php` | Built-in `gravity-forms` integration. Registers readers from Gravity Forms submissions; extends `form-capture`. |
| `class-incoming-field.php` | Value object describing an external field returned by an integration. Carries display metadata plus flags for access rules and segmentation criteria. |
| `class-date-value.php` | Date value helpers shared by the pull pipeline and the access-rule evaluator: source-format normalization to ISO and calendar-date validation. |
| `class-contact-pull.php` | Pull pipeline. Per-integration synchronous loopback requests plus ActionScheduler-backed retries with exponential backoff. |
| `class-contact-cron.php` | Recurring cron orchestration. Stages users for pull/push and processes both queues every 5 minutes. |
| `class-push-log.php` | Push log. The record of what was sent to each integration: one row per reader, integration and triggering push, written by `Contact_Sync`. |

The registry class `Newspack\Reader_Activation\Integrations` and the abstract base `Newspack\Reader_Activation\Integration` live in the parent namespace. The other classes in this folder live in `Newspack\Reader_Activation\Integrations\*`.

---

## Built-in Integrations

### `esp`

Syncs contacts and metadata fields with Mailchimp through the Newspack Newsletters Mailchimp provider. It keeps the `esp` id from when it served every provider, so no stored settings moved. On any other provider it reports itself unsupported and `is_set_up()` returns `false`, so it never syncs; that provider needs an integration of its own. Auto-registers and is enabled by default on new installs (and on legacy upgrades unless the legacy `newspack_reader_activation_sync_esp` option was explicitly disabled). Pulls custom merge fields back into Newspack for segmentation and access rules.

### `form-capture`

Form Capture. Registers readers from publisher-designated frontend forms built with any tool but Gravity Forms, such as an ActiveCampaign embed (opt-in via the `newspack-form-capture` CSS class or configured selectors). Registered while `NEWSPACK_FORM_CAPTURE_ENABLED` is set or where a site already enabled it, and disabled by default. It is the first capture-only built-in — neither a sync destination nor a pull source — which makes it the working reference for two patterns:

- **Capability declarations over failing gates.** `supports_push()`/`supports_pull()` return `false` (no dead outbound/inbound controls, no bearing on `has_one_syncable_integration()`), while `can_sync()` succeeds — capture-only is a declared capability, not an error state.
- **One switch for the frontend registration surface.** `supports_frontend_registration()` returns the integration's *enabled* state **and** its `get_unsupported_reason()` being null, so the page-emitted key, the endpoint acceptance, and the capture script share a single off switch — one that also closes when the site's configuration changes after enabling.

It reports itself unsupported on reCAPTCHA v2, which cannot be pre-acquired for a page-navigating form submit, and sizes its own registration rate-limit bucket via the `newspack_frontend_registration_rate_limit` filter.

### `gravity-forms`

Registers readers from Gravity Forms submissions. It extends `form-capture` and shares its capture script, which sends each Gravity Forms form to this integration and every other form to `form-capture`, so a reader is registered once and each integration's switch covers its own forms. A "Register readers" toggle on the Gravity Forms block opts a placement in, and the block's render filter carries that attribute to the page as the `newspack-form-capture` class on the form tag; a form placed without the block opts in by carrying the class through its CSS Class Name setting, a route the help docs cover. It has no settings, so its card offers no settings page. It declares Gravity Forms as a required plugin, which `supports_frontend_registration()` also checks on every request, and its how-to through `get_guide()`, which the card's How it works menu item opens as a guide whose last step links the help docs. Registered on every site and disabled by default, except that a site upgrading with `form-capture` enabled and Gravity Forms active gets it enabled once (`maybe_enable_on_upgrade()`), since that site's Gravity Forms forms move here.

---

## Registering an Integration

Integrations are registered during the `newspack_reader_activation_register_integrations` action, which fires on `init` priority 5:

```php
add_action(
    'newspack_reader_activation_register_integrations',
    function () {
        Newspack\Reader_Activation\Integrations::register( new My_Integration() );
    }
);
```

`Integrations::register()` keeps the instance in a static registry. Enabled/disabled state is persisted to the `newspack_reader_activation_enabled_integrations` option.

After registration, the integration gets a card in the publisher-facing Integrations UI if it declares settings fields or a guide (`get_guide()`). It starts receiving sync requests once it is enabled and `is_set_up()` returns `true`.

---

## Building an Integration

An integration is a class extending `\Newspack\Reader_Activation\Integration`. The base class provides settings storage, metadata prefix, outgoing/incoming field selection, and a contact preparation pipeline. You only need to provide the parts unique to your third-party.

### Minimum implementation

```php
namespace Newspack\My_Plugin;

use Newspack\Reader_Activation\Integration;

class My_Integration extends Integration {
    public function __construct() {
        parent::__construct(
            'my_integration',
            __( 'My Integration', 'my-plugin' ),
            __( 'Syncs reader data with My Service.', 'my-plugin' )
        );
    }

    public function register_settings_fields() {
        return [
            [
                'key'     => 'api_key',
                'type'    => 'password',
                'label'   => __( 'API Key', 'my-plugin' ),
                'default' => '',
            ],
        ];
    }

    public function is_set_up() {
        return (bool) $this->get_settings_field_value( 'api_key' );
    }

    public function can_sync( $return_errors = false ) {
        $errors = new \WP_Error();
        if ( ! $this->get_settings_field_value( 'api_key' ) ) {
            $errors->add( 'no_api_key', __( 'API key is missing.', 'my-plugin' ) );
        }
        if ( $return_errors ) {
            return $errors;
        }
        return ! $errors->has_errors();
    }

    public function push_contact_data( $contact, $context = '', $existing_contact = null ) {
        // $contact arrives prepared: filtered to this integration's enabled
        // fields and prefixed. Don't call prepare_contact() here.
        // ... push $contact to your API.
        return true;
    }
}
```

`is_set_up()` is optional, but it is what keeps sync away from an integration that is not configured. Pushes, pulls, account deletions, retries and health checks only reach enabled integrations where it returns `true`, and the default is `true`. Without the override above, this integration would receive pushes before an API key is saved. Those checks cover the framework's own dispatch only: handlers an integration registers for itself run whatever its state (see [Data Event Handlers](#data-event-handlers)).

### Required methods

| Method | Purpose |
| --- | --- |
| `register_settings_fields()` | Return static field declarations (key, type, default at minimum). Called from the constructor. No API calls, no conditional logic based on external state. |
| `can_sync( $return_errors = false )` | Validate the integration's configuration. Return `bool` or `WP_Error` depending on `$return_errors`. The hourly health check reports its errors, and the backfill CLI refuses a push (or `both`) run scoped to an integration that fails it. `Sync::has_one_syncable_integration()` passes while at least one push-enabled integration passes it; it decides, among other things, whether the reader-activity sync handlers register at all. The framework does not call it before each push or pull: `is_set_up()` decides which integrations those reach, though an implementation may call it itself, as `esp` does. |
| `push_contact_data( $contact, $context, $existing_contact )` | Send contact data to the external system. Return `true` on success or `WP_Error` on failure. Some failures are retried with backoff (see [Retries](#retries)). The framework reaches it only through the final `push_contact()` wrapper, which records the outcome and passes a fourth `$options` argument (see Push below). |

### Optional overrides

| Method | Purpose |
| --- | --- |
| `supports_push()` | Whether the integration can send contact data outbound. Defaults to `true`. Return `false` for an inbound-only integration — see [Direction capabilities and toggles](#direction-capabilities-and-toggles). |
| `supports_pull()` | Whether the integration can fetch contact data inbound. Defaults to `true`. Return `false` if `pull_contact_data()`/`get_available_incoming_fields()` are not implemented. |
| `is_set_up()` | Whether the integration is fully configured (external prerequisites **and** the integration's own settings). Defaults to `true`. Pushes, pulls, account deletions, retries and health checks only reach enabled integrations where it returns `true` (`Integrations::get_active_configured_integrations()`). Answer from stored configuration, not a live API call, so a provider outage does not drop sync traffic. |
| `is_connected()` | Whether the external service prerequisite alone (provider chosen, key entered) is configured at its source. Defaults to `true`. While it is `false`, the REST layer refuses to enable the integration, and when `get_setup_url()` is set the card's primary action is Connect, which opens that URL. Once connected, Enable opens a modal collecting any empty `required` settings fields, or enables the integration directly. |
| `get_unsupported_reason()` | Non-null string marks the integration as unsupported with the site's current configuration (e.g. the ESP integration while the newsletters provider is anything but Mailchimp). The Integrations UI shows the string verbatim as the card's error badge and, when `get_setup_url()` is set, points the primary action there; the REST layer refuses to enable. Defaults to `null`. |
| `get_unsupported_action_label()` | Label for the card's primary action while `get_unsupported_reason()` is non-null, naming the remedy. Defaults to "Open settings". |
| `get_setup_url()` | Admin URL where the integration's prerequisites are configured. Defaults to empty string. |
| `get_provider_slug()` | Slug of the connected vendor, which the `newspack_sync_push_contact` log entry records. The Integrations UI uses it for the card's brand icon only when it is a Newspack Newsletters provider slug, such as `mailchimp` or `active_campaign`; other cards keep the generic icon unless the UI brands them by integration id. Defaults to `null`. |
| `get_required_plugins()` | Plugins the integration needs, as arrays with `slug`, `name`, `is_active` and `is_installed`. The card shows a "Requires …" state, or an Activate action when every missing plugin is installed. Include all four keys: a missing `is_installed` reads as not installed. Defaults to `[]`. |
| `get_guide()` | How-to steps the card's How it works menu item opens as a guide, one step per page. Each step has a `title` and a `description`, and may have a `link` with a `label` and a `url`. Use it when the way in is a workflow elsewhere in the admin, such as a block setting. The menu item shows only once the integration is enabled. Defaults to `[]`. |
| `test_connection()` | Lightweight live API call to verify credentials and reachability. Called as part of `health_check()`. Defaults to `true`. |
| `pull_contact_data( $user_id )` | Fetch contact data from the external system. Return `array` of `field_key => value` or `WP_Error`. Defaults to `[]`. |
| `get_available_incoming_fields()` | Return an array of `Incoming_Field` objects representing the schema available to pull. Required for any integration that supports pulling. |
| `configure_incoming_field( $field )` | `protected`. Enrich an `Incoming_Field` with display metadata and promotion flags (access rule / segment criteria). The framework calls it on each stored field when it rebuilds the enabled selection. Call it yourself on each field `get_available_incoming_fields()` returns, as `ESP` does, or the live list is never configured. |
| `register_handlers()` | Register data event handlers (see [Data Event Handlers](#data-event-handlers)) and any WordPress hooks the integration needs. Called once per *accepted* instance after all integrations have been registered — hook here, not in the constructor, so a rejected duplicate registration never leaves live callbacks behind. |
| `supports_frontend_registration()` | Return `true` to expose this integration's registration key to the page and accept it on the frontend registration endpoint. Defaults to `false`. The capture built-ins (`form-capture`, `gravity-forms`) gate this on their enabled state so key, endpoint and script share one switch. |
| `get_registration_key()` / `validate_registration_request()` | Override to implement custom registration key schemes. Default is timing-safe HMAC-SHA256 of the integration ID and a stored per-integration seed with the site's auth salt; `rotate_registration_key()` regenerates the seed without touching `AUTH_SALT`. The default validator still accepts the key from before the seed existed, so rotation does not fully revoke a key yet (see [Frontend Reader Registration](#frontend-reader-registration)). |
| `handle_logged_in_user_registration( $user, $request )` | Called when a logged-in user attempts to register again via the frontend. Use to update user data, link the account, record a new event, etc. Default is a no-op. Runs only after every gate ahead of the logged-in branch has passed, including the integration's own `validate_registration_request()`. |
| `get_my_account_menu_item()` | Return `[ 'slug' => ..., 'label' => ..., 'position' => ... ]` to add a tab to the My Account page: WooCommerce's, or Newspack's own when WooCommerce is inactive. Default returns `null` (no tab). See [My Account Endpoints](#my-account-endpoints). |
| `render_my_account_page( $value )` | Echo markup for the integration's My Account page. Under WooCommerce it is called inside the account template with the endpoint value; on Newspack's own page `$value` is `''`. |
| `supports_hard_delete()` | Return `true` alongside a `delete_contact()` implementation. Only then does the account-deletion setting offer `delete` mode (and default to it). Defaults to `false`. |
| `delete_contact( $email )` | Remove the contact identified by `$email` from the external system. Default returns a `not_implemented` WP_Error. Required only if `supports_hard_delete()` returns `true`. When the system has no contact for `$email`, return a `WP_Error` whose only code is `Integration::CONTACT_NOT_FOUND_ERROR_CODE` (`ras_contact_not_found`): the reader is already gone there, so the deletion path ends as a benign `success` with no retry. The built-in ESP integration maps its providers' not-found errors onto this code. |
| `flag_deletion_cleanup( $email )` | Stop outreach to a deleted reader after the flag-mode deletion push, e.g. by removing the contact from every list. Default is a no-op, and an integration that keeps it gets no flag-mode deletion at all (see [Built-in account-deletion fields](#built-in-account-deletion-fields)). |
| `contact_exists( $email )` | Whether pushing this contact would update a live record the external system holds. Consulted by `Contact_Sync` only on an update-only push (the backfill CLI's default; `--create-missing` turns it off), before `push_contact_data()`: return `false` to have the reader skipped (tallied as skipped), a `WP_Error` if the lookup failed (handled as a failed push), `true` to let the push proceed. Answer by what the push would do: `true` only when it would update a live record, `false` when it would create one or restore an archived or deleted one. Anything other than a boolean or a `WP_Error` is handled as a failed lookup. The default returns a `WP_Error`, and `supports_contact_lookup()` is false until this method is overridden: on an update-only push the framework withholds the push from an integration that cannot ask (reported as an error, never retried) and the CLI pre-flight refuses the run, rather than let an upsert create the contact. The built-in Mailchimp integration (`esp`) reads the contact once and requires current (not archived) membership of the configured audience. |
| `supports_contact_lookup()` | Whether `contact_exists()` is implemented. True as soon as a subclass overrides that method, so implementing it is the opt-in; override this only to opt out explicitly. Read by the sync on an update-only push and by the CLI pre-flight. |

---

## Settings Fields

Settings fields are declared statically in `register_settings_fields()` and stored individually as WordPress options keyed `newspack_integration_settings_{id}_{key}`.

### Field declaration

```php
[
    'key'         => 'master_list',
    'type'        => 'select',
    'default'     => '',
    'required'    => true, // Optional. See below.
    'label'       => __( 'Master List', 'my-plugin' ),
    'description' => __( '...', 'my-plugin' ),
    'options'     => [ ... ], // Required for 'select'.
]
```

Supported `type` values: `text`, `password`, `textarea`, `number`, `checkbox`, `select`, `metadata`, `oauth`, `hidden`. The base class sanitizes values per type before persisting.

`required => true` marks a field that must have a value before the integration can be enabled from the Integrations UI. When the card's Enable action runs while a required field is empty, the UI opens a modal that collects the missing required fields, saves them, and then enables the integration in one step. That modal is the only enforcement: the server does not check `required`, so a direct REST call or `Integrations::enable()` can enable the integration with the field empty. Do not combine `required` with the managed field types (`oauth`, `hidden`) — the Integrations UI cannot collect those, and the settings endpoint drops client writes for them.

`oauth` and `hidden` are **managed field types**: `Integrations::update_integration_settings()` calls `is_managed_settings_field()` and skips them without an error, so admin clients can't overwrite them by POSTing to the settings REST endpoint. They're writable only from trusted PHP via `update_settings_field_value()`. See `Integration::MANAGED_FIELD_TYPES`.

### `oauth` field type

`oauth` renders as a Connect / Disconnect button on the integration's settings card. The stored value is the human-readable identity of the connection (account email, workspace name, etc.) — it's shown next to the Disconnect button when present, and its presence toggles the card between "Connect" and "connected" states.

Two render-time properties drive the buttons; declare them in `get_settings_config()` (not the static `register_settings_fields()`) since they typically depend on a nonce or the current user:

| Key | Purpose |
| --- | --- |
| `oauth_url` | Target of the **Connect** button. Usually a remote authorize URL with `redirect_uri` pointing back to your callback. |
| `disconnect_url` | Target of the **Disconnect** button. Usually a local admin URL that revokes the remote grant, clears stored tokens, and redirects back to the integrations screen. |

Typical wiring:

1. Declare the field statically with `'type' => 'oauth'` (and any siblings — `'hidden'` token fields for the access/refresh tokens, scopes, etc.).
2. Override `get_settings_config()` to attach `oauth_url` and `disconnect_url` for the current request.
3. Register an OAuth callback endpoint that exchanges the code, writes the tokens and the display identity via `update_settings_field_value()`, and redirects back to the integrations screen.
4. Implement `is_set_up()` to return `true` only when the identity field is populated, so sync waits until the connection exists.

Because the field skips the REST save path, no admin client can spoof a connection by POSTing a value — every write goes through your callback.

### Reading and writing

```php
$value = $this->get_settings_field_value( 'api_key' );
$this->update_settings_field_value( 'api_key', $new_value );
```

`get_settings_field_value()` returns the field's declared `default` when no value is stored. A small legacy option map provides lazy migration for fields that were renamed from older site-wide options.

### Enriching at render time

`get_settings_fields()` is the static declaration. `get_settings_config()` is what the REST API serves to the UI — override it to add expensive data (e.g. live list options from an API) or to filter fields by the current provider/state. See `ESP::get_settings_config()` for the canonical example.

### Built-in metadata fields

Each integration automatically gets the fields for the directions it declares (see [Direction capabilities and toggles](#direction-capabilities-and-toggles) — a push-less integration gets none of the outbound rows, a pull-less one none of the inbound rows):

| Field key | Direction | Type | Purpose |
| --- | --- | --- | --- |
| `metadata_prefix` | outbound | `text` | String prepended to every outgoing metadata field name (default `NP_`; an empty value reads and saves as `NP_`). Stored at `newspack_integration_metadata_prefix_{id}`, copied on first read from the legacy site-wide `Sync\Metadata::PREFIX_OPTION` when that is set. Required so outgoing field names are unique on the external system. |
| `outgoing_sync_enabled` | outbound | `checkbox` | Whether outbound sync currently runs. Default `true`. Pausing it stops pushes (including account-deletion propagation) while preserving the outgoing field selection. Stored at `newspack_integration_settings_{id}_outgoing_sync_enabled`. |
| `outgoing_metadata_fields` | outbound | `metadata` | Subset of Newspack metadata fields to push, referenced by canonical display name rather than a raw key. Stored at `newspack_integration_outgoing_fields_{id}`. See Push (Outgoing Sync) below for how an integration without a saved selection resolves one. |
| `incoming_sync_enabled` | inbound | `checkbox` | Whether inbound sync currently runs. Default `true`. Pausing it stops pulls while preserving the incoming field selection. Stored at `newspack_integration_settings_{id}_incoming_sync_enabled`. |
| `incoming_metadata_fields` | inbound | `metadata` | Subset of external fields to pull and store on the Newspack user. Stored at `newspack_integration_incoming_fields_{id}` as a `key => raw_data` map. |

**Field grouping.** `get_settings_config()` also attaches `grouped_options` to `outgoing_metadata_fields` — the same catalog as `options`, organized into labeled sections by originating class (`Metadata::get_grouped_default_fields()`) for the configure UI's picker. A section made entirely of fields marked `'status' => 'legacy'` in their class's field config sorts after every other section; adjacent sections sharing a label are folded into one, so `Legacy_Basic` and `Legacy_Payment` — both named "Legacy" — render as a single trailing panel instead of two identical ones. A field name that appears in more than one section is kept only in the first, so names both schemas declare (Account, Registration Date and the like) show in their modern section, not under Legacy. Fields added by filter that no class declares go in an "Additional" section.

### Built-in account-deletion fields

Deletion propagates through the push pipeline, so push-capable integrations also get two account-deletion settings (a push-less integration gets neither):

| Field key | Type | Purpose |
| --- | --- | --- |
| `sync_account_deletion` | `checkbox` | Whether to propagate WordPress reader-account deletions to this integration. Default `true`. Stored at `newspack_integration_settings_{id}_sync_account_deletion`. Migrated lazily from the legacy `newspack_reader_activation_sync_esp_delete` option (see migration note below). |
| `account_deletion_handling` | `select` | When sync is on, choose between `delete` (call `$integration->delete_contact()`) and `flag` (push the contact with a `{prefix}Account_Deleted` metadata field — a UTC `Y-m-d H:i:s` timestamp matching peer datetime fields — plus a `{prefix}Membership_Status` field set to `user-deleted` for backward compatibility). `delete` is offered, and is the default, only when `supports_hard_delete()` returns `true`; otherwise `flag` is the only option. Stored at `newspack_integration_settings_{id}_account_deletion_handling`. Declares a `condition` on `sync_account_deletion` so the configure UI hides it when sync is off. |

**Legacy migration.** Both fields derive from the single legacy `sync_esp_delete` boolean, which was effectively three-way in behavior: `true` hard-deleted the contact, while `false` kept the contact but removed it from every list (still a deletion signal). Because *both* states propagated a deletion, a migrated site always keeps `sync_account_deletion = true`; the legacy boolean only picks the handling mode — `true → delete` (or `flag` for an integration that cannot hard-delete), `false → flag`. Mapping legacy `false` to `flag` (rather than disabling sync) preserves the old "don't hard-delete, but still signal the deletion" posture. Every push-capable integration reads the same legacy option. Sites that never set the legacy option fall through to the field defaults. See `Integration::migrate_account_deletion_setting()`.

The dispatcher lives at `Contact_Sync::handle_account_deletion()` and is called from the `reader_delete_sync` data event handler, which is registered while at least one integration can sync (`Contact_Sync::has_one_syncable_integration()`). It walks the enabled, set-up integrations with outbound sync on and `sync_account_deletion` set.

Flag mode is two steps: the metadata push, then the integration's `flag_deletion_cleanup( $email )`, which ends further outreach. The flag push passes `skip_lists` to ask for an upsert that adds the reader to no list. That only holds for an integration whose `push_contact_data()` reads `$options`, so an integration that implements the cleanup, and whose push can add a contact to a list, must honor `skip_lists`. Otherwise the deletion adds the reader back to a list until the cleanup runs, and leaves them there when the cleanup fails. The dispatcher **skips flag mode entirely for an integration that keeps the base no-op cleanup**, because the push alone would leave the deleted reader reachable at the provider. An integration with neither `supports_hard_delete()` nor a cleanup override therefore propagates no deletions, whatever its settings say. The built-in Mailchimp integration overrides the cleanup to clear the contact from every list (`Newspack_Newsletters_Contacts::update_lists()`). Mailchimp keeps every contact in an audience, so that integration honors `skip_lists` by updating only the reader's existing member of the configured audience, leaving the member's status as it is. When the audience has no live member for the reader, it pushes nothing and answers `ras_contact_not_found`. The deletion path treats that code as benign, from any integration, when it is the error's only code: the push log row ends as a `success` with no retry, and the cleanup still runs.

The cleanup runs whether or not the push succeeded, since the reader must stop receiving outreach either way. Its failures fold into `handle_account_deletion()`'s aggregated error, and mark the push log row `flag_cleanup_failed` when the push landed or came back benign (see [Push Log](#push-log)), but are **not** scheduled for retry on their own; that gap mirrors the legacy deletion path this replaced and is tracked separately as hardening work. When a failed flag push is retried, the retry that ends the chain (one that lands, comes back benign, hits a permanent error, or is the last attempt) runs the cleanup again, whatever its own push answered.

### Conditional fields

A settings field can declare an optional `condition` so the frontend hides it when another field's current value doesn't match:

```php
[
    'key'       => 'dependent_field',
    'type'      => 'text',
    'condition' => [ 'field' => 'controlling_field', 'equals' => true ],
],
```

The configure-view in `src/wizards/audience/views/integrations/` honors this predicate. Conditions are single-level only (no nesting, no array of conditions).

---

## Direction capabilities and toggles

Each direction is gated twice: by a **capability** the integration declares in code, and by a **toggle** the publisher controls in the wizard.

| Method | Answers |
| --- | --- |
| `supports_push()` / `supports_pull()` | *Can* this integration ever sync in that direction? Override to `false` for a direction you don't implement. |
| `is_push_enabled()` / `is_pull_enabled()` | *Should* it sync right now? `final` — capability AND toggle. Every dispatch site calls these. |

Declaring `supports_push() === false` removes the entire Outbound settings group (metadata prefix, outbound toggle, outgoing fields, both account-deletion fields), keeps the integration out of `Sync::has_one_syncable_integration()`, and skips the push path — so an inbound-only integration renders no dead outbound controls. `supports_pull() === false` does the same for the Inbound group and `Contact_Pull`.

The toggles pause a direction without discarding configuration: the stored field selections survive, so re-enabling restores them. Pausing is not retroactive — changes and deletions that occur while a direction is paused are not replayed on re-enable.

An **undeclared** toggle field reads as enabled. An integration that overrides `get_settings_fields()` without the base metadata group has no toggle to store, so that direction cannot be paused at all; only `supports_push()`/`supports_pull()` returning `false` stops it. Pre-toggle third-party integrations keep syncing. This matches the configure-view, which treats a missing toggle field as enabled.

**Scope caveat.** These toggles govern *this framework's* dispatch only. Newsletter-signup contact upserts reach the ESP through the Newspack Newsletters plugin's own channel and are unaffected by `outgoing_sync_enabled` — pausing outbound sync stops reader-data syncing, not newsletter subscriptions.

---

## Push (Outgoing Sync)

When a contact needs to be synced, the framework calls `push_contact()` on every enabled, set-up integration with outbound sync on. That method is `final` on the base class: it delegates to your `push_contact_data()` and records one `newspack_sync_push_contact` entry through `newspack_log` — type `debug` on success, `error` on a `WP_Error` — carrying the integration id, provider slug, context, the contact exactly as handed to the integration, the options in effect, and any error messages and codes. That entry is the manager-log record of a sync for every integration, including ones that never go through Newspack Newsletters (whose upsert writes its own `newspack_esp_sync_upsert_contact` entry, so a Mailchimp push shows both). It reflects what the framework handed over: an implementation may still reshape the payload internally, and a non-error result only means the integration reported success. The contact array is the Newspack canonical form (email, name, metadata, etc.). The framework runs it through `prepare_contact()` before calling `push_contact()`, so `push_contact_data()` receives the prepared contact and must not call `prepare_contact()` itself, or any custom preparation logic in an override runs twice. `prepare_contact()`:

1. Filters `$contact['metadata']` to the fields enabled on this integration.
2. Renames raw keys to `prefix . field name`, using the integration's metadata prefix.
3. Preserves keys already in prefixed form if the underlying field is enabled, or if the merged catalog doesn't know the name at all (custom fields injected by site snippets).

Raw keys resolve through the merged `raw_key => name` catalog, so both members of a value-equivalent pair (legacy `account`, new `Account`) land on the same ESP key no matter which raw key the caller supplies. Only the raw keys in `Metadata::UTM_RAW_KEYS` match by suffix — an enabled `Signup UTM: ` carries `signup_page_utm_source` and its siblings, but never a bare `signup_page_utm`, which carries no value of its own. Every other field matches exactly, so a label registered through `newspack_ras_metadata_keys` that happens to end in `': '` can never carry a *different* field past the selection. A key naming a field the catalog knows but hasn't enabled here is dropped either way, whether it arrived raw or already prefixed — checked against every registered field, not only the currently-available ones, so a name left over from when its class briefly had a feature flag on still filters instead of passing through as if it were an unrecognized custom field.

Unprefixed sync-control keys (`Metadata::SYNC_CONTROL_KEYS`: `status`, `status_if_new`) always pass through; any other unregistered unprefixed key is dropped. An already-prefixed key supplied directly in the input is never overwritten by a raw key that resolves to the same output — that protection is one-directional, though: two raw keys sharing a label (legacy `registration_page` and `current_page_url` both resolve to "Registration Page") still follow last-write-wins against each other.

An integration that has **never saved** an Outbound selection inherits the ESP integration's effective selection, so un-migrated sites keep their pre-existing payloads; an explicitly saved selection always wins, and saving with nothing checked genuinely means "push no metadata fields". A corrupt (non-array) stored value is treated the same as no selection, so it inherits rather than failing closed to empty. Override `get_inherited_outgoing_fields()` to inherit a different set, or return `[]` from it to opt out of inheritance entirely. Once a selection is saved, inheritance only returns if the integration's `newspack_integration_outgoing_fields_*` option is deleted (NPPD-2107).

**ESP resolution order.** No flag gates this, and it's what every other integration's inheritance ultimately reaches too, since `get_inherited_outgoing_fields()` calls into the ESP integration for it. `Esp::get_enabled_outgoing_fields()` checks three tiers in order: its own saved outgoing-fields option; failing that, the legacy global `_newspack_metadata_fields` option (`Metadata::FIELDS_OPTION`), copied into that per-integration option **verbatim** on first read rather than through the validating setter — validating here would permanently strip a currently-unavailable name (e.g. a payment field while WooCommerce is inactive) from a publisher's existing selection; failing that, the era-scoped default described next.

**Default posture.** Inheritance preserves behavior rather than tightening it. The final tier, `Metadata::get_default_enabled_fields()`, returns every available field of the schema era the site comes from — including Membership Status, Total Paid and Recurring Payment on a legacy site. The era is derived once and stamped into `newspack_sync_schema_origin` (`Metadata::SCHEMA_ORIGIN_OPTION`), and the stamp wins on every later read; only the field list is recomputed per read, so a field whose class becomes available later (WooCommerce activated, say) joins the default without a new stamp. Content Gate fields and fields added by filter that no class declares are part of the default in either era. Correcting a wrong era means changing that stored option. Two constants override the stamp while they are defined, without changing it: `NEWSPACK_SYNC_METADATA_VERSION` selects the legacy era when set to `'legacy'` and the current one for any other value, and a truthy `NEWSPACK_SYNC_METADATA_VERSION_1` selects the current era despite its name (the first constant wins when both are defined). Per-integration selection is what closes the exposure, and it takes an explicit save to do so.

### Optional `$options` parameter

`Contact_Sync` may pass a fourth `$options` array to `push_contact_data()` carrying sync scoping. The `wp newspack integrations backfill` CLI and its legacy alias `wp newspack esp sync` set it, and flag-mode account deletion passes `skip_lists`:

- `skip_lists` (bool) — upsert the contact without adding it to any list, so an unsubscribed contact isn't resubscribed.
- `fields` (string[]|null) — the canonical field labels the sync is scoped to (already applied to the metadata before your method is called). The sync-control keys are not fields and stay in the metadata. The contact's `name` is dropped too, so a field-scoped run cannot rewrite names.
- `existing_only` (bool) — update-only: the framework asks `contact_exists()` before calling `push_contact_data()` and skips integrations that answer `false`, so an implementation never sees a contact it reported missing. An integration whose `supports_contact_lookup()` is false gets no push (withheld and reported as an error), and the CLI refuses such a run before any batch. The backfill CLI sets it unless `--create-missing` is passed; real-time syncs never do. Integrations do not need to read this key.
- `integration_id` (string|null) — restricts the push fan-out to a single active integration. The framework acts on this key in `Contact_Sync::push_to_integrations()` before any integration is called; like the rest of `$options`, it is still visible to `push_contact_data()` overrides that declare the fourth parameter, but integrations don't need to (and shouldn't) act on it.

The abstract signature intentionally stays three-parameter (`push_contact_data( $contact, $context, $existing_contact )`). `Contact_Sync::push_to_integrations()` calls every integration with the fourth `$options` argument; PHP discards surplus positional arguments to a method that declares fewer parameters (they remain available via `func_get_args()`) — there is no warning or error, so a three-parameter implementation keeps working unchanged. Adding the fourth parameter to the *abstract* instead would be a fatal "declaration must be compatible" error for every existing three-parameter override, which is why the parameter lives only on the concrete overrides that use it. Add `$options = []` to your override only if the integration needs to react to these flags (the built-in `esp` integration reads `skip_lists`). An integration that implements `flag_deletion_cleanup()` and can add a contact to a list on push needs it (see [Built-in account-deletion fields](#built-in-account-deletion-fields)). Integrations that ignore `$options` behave exactly as before.

### When pushes are triggered

- Reader activity, through `Contact_Sync_Connector`: registration, login, completed orders, subscription changes and renewal attempts, and newsletter subscription changes, among others. Each syncs the contact through `Contact_Sync`, which pushes to every integration as above. Handlers an integration registers itself via `register_handler()` (see below) push only if the integration's own method does.
- Recurring cron via `Contact_Cron`: a logged-in reader's pageview stages them for a push at most once every 5 minutes, and the next 5-minute batch sends it.
- Direct calls from other Newspack subsystems via `Contact_Sync::sync_contact()`.

### Retries

`Contact_Sync` retries a failed push through ActionScheduler with a `30s → 2min → 8min → 30min → 2h` backoff, up to 5 retries after the first attempt. Only a full, unscoped push (default `$options`) of a reader with a WordPress account to rebuild the contact from is retried, and only when the error is not classified benign (the provider already holds the contact) or permanent. Scoped CLI pushes, guest contacts and permanent failures end on their first attempt. Each integration's retries are grouped under `newspack-integration-{id}` so they can be inspected and managed independently in the Activity Logs UI. A retry chain is a single row in the [Push Log](#push-log). Retries go through `push_contact()` too, so every attempt leaves its own `newspack_sync_push_contact` entry.

---

## Pull (Incoming Sync)

Integrations that override `pull_contact_data( $user_id )` can fetch external state back into Newspack. The returned associative array is intersected with the enabled incoming fields and persisted via `Reader_Data::update_item()` for each key.

When the provider has no contact for the reader at all, return a `WP_Error` with the code `Integration::CONTACT_NOT_FOUND_ERROR_CODE` (`ras_contact_not_found`) — it lets batch drivers count the reader as skipped rather than failed, since no re-run can make an absent contact appear. The built-in ESP integration normalizes its providers' not-found errors onto this code.

**Pulled values are additive.** A pull writes every enabled incoming field present in the payload and never deletes reader data, so a field missing from the next payload keeps its previously stored value — including for access rules and segmentation criteria built on promoted fields. The framework does not filter empty values: an integration that returns `''` or `null` for a cleared field overwrites the stored value with it, so leave empty fields out of the payload. The built-in Mailchimp integration does (Newspack Newsletters drops them), which means clearing a provider field there has no local effect; remove the reader's stored item instead, or gate on a field whose value changes rather than disappears.

### When pulls are triggered

The pull pipeline (`Contact_Pull` + `Contact_Cron`) runs on every logged-in pageview, throttled per user:

- If the user's last enqueue was more than 24 hours ago (`PULL_SYNC_THRESHOLD`), all enabled integrations are pulled synchronously via per-integration loopback `admin-ajax.php` requests. The request timeout defaults to 1 second per integration (filter `newspack_pull_integration_request_timeout`). If any loopback fails or overruns, the user is staged for a full pull on the next batch.
- Otherwise, the user is staged for pull on the next 5-minute batch (`Contact_Cron::CRON_INTERVAL`).

### Retries

The batch pull (`Contact_Pull::pull_all()`) schedules per-integration retries with a `30s → 2min → 8min → 30min → 2h` backoff, up to 5 retries after the first attempt. The synchronous loopback pull schedules none itself: its failures re-queue the user for the batch, as above. Retries run under the integration's ActionScheduler group (`newspack-integration-{id}`) using the hook `newspack_contact_pull_retry`.

Every failure is retried except the permanent ones in `Contact_Pull::PERMANENT_PULL_ERRORS`, which no retry can fix: a rejected reader-data write (`reader_data_write_failed`, refused by validation), no selected incoming fields (`no_selected_incoming_fields`), and a payload that is not an array (`invalid_pull_payload`). Permanent failures surface as errors without scheduling a retry, and one surfacing mid-chain fails its action immediately instead of burning the remaining attempts. A provider with no contact for the reader (`ras_contact_not_found`) is not a failure at all: the batch pull skips the reader with no retry and no error, a retry chain that meets it ends, and the loopback pull answers success, so it queues no batch pull. A contact created later is found by the pull the reader's next visit queues.

### Incoming fields

`get_available_incoming_fields()` returns the schema offered by the external system. Each field is an `Incoming_Field`:

```php
$field = new Incoming_Field( 'membership_level', $raw );
$field
    ->set_name( __( 'Membership Level', 'my-plugin' ) )
    ->set_value_type( 'string' )                    // 'string', 'boolean', 'number', 'date', 'datetime', 'select', or 'multiselect'.
    ->set_matching_function( 'list__in' )           // 'default', 'list__in', 'list__not_in', 'range', 'date_range'.
    ->set_date_format( '' )                         // PHP date format (e.g. 'm/d/Y'); empty for ISO 8601 / Y-m-d. Also put it in $raw (see below).
    ->set_options( [ [ 'value' => 'gold', 'label' => 'Gold' ], ... ] )
    ->set_description( __( 'Member tier from the CRM.', 'my-plugin' ) )
    ->set_is_access_rule( true )                    // Register as a content gate access rule.
    ->set_is_segment_criteria( true )               // Register as a popups segmentation criterion.
    ->set_access_rule_callback( function ( $user_id, $args ) { /* ... */ } );
```

**The `$raw` array is what the framework stores.** When a publisher enables a field, the framework saves `$field->get_raw_data()`, not the values set through setters, and rebuilds the field from it on later reads. Anything the framework reads back from storage, such as `date_format`, must be a key in `$raw`; a setter alone never reaches the stored copy. A stored entry with none of the schema keys (`name`, `value_type`, `matching_function`, `options`, `description`, `is_access_rule`, `is_segment_criteria`) is treated as predating the schema and refreshed from the live schema on every read.

**Declare `date_format` in `$raw` on every `date` / `datetime` field, even when it is empty.** The key's presence is how the framework tells "the provider says its dates are ISO 8601" from "this entry predates source formats, so the format is unknown". An entry set to `date_range` with the key *absent* is refreshed from the live schema on the next read; a format the live schema declares is persisted back — a one-time repair. When the live schema declares nothing either, the entry is treated as ISO for that read only and re-examined on the next one, so a declaration that arrives later (a provider update, a cache that was empty) still lands — which is also why an integration that never declares the key keeps re-fetching the live schema on every read. One that declares `''` is taken at its word and never refetched.

A date value the framework cannot confidently parse is stored **untouched** rather than guessed at — the matcher then rejects it, so the criterion matches nobody rather than matching wrongly. When that happens the pull writes a line through `Logger::log()` naming the field and the source format it used. That is the only signal that a declared format is missing or wrong, and it reaches the PHP error log only when `NEWSPACK_LOG_LEVEL` is set.

Use `configure_incoming_field()` to enrich a field after construction. The framework calls it whenever stored fields are re-hydrated; call it yourself on each field `get_available_incoming_fields()` returns, as `ESP::get_available_incoming_fields()` does. This is where you set `is_access_rule`, `is_segment_criteria`, and any custom callback. Reading those values from `$raw`, as `ESP::configure_incoming_field()` does, keeps the live and stored fields in agreement.

The base class also offers `get_filtered_incoming_fields()`, so publishers don't re-select fields Newspack already pushes. It hides a field whose name starts with any prefixed name in the site-wide outgoing catalog (`Sync\Metadata::get_all_prefixed_keys()`). Those names carry the site-wide prefix, not this integration's own.

---

## Backfill CLI

`wp newspack integrations backfill` runs an operator-driven bulk sync in either
direction, optionally scoped to a single integration:

```sh
# Update every reader's contact at every active integration, creating none (same as the legacy `esp sync`).
wp newspack integrations backfill

# Also create contacts for the readers an integration does not have yet.
wp newspack integrations backfill --create-missing

# Pull enabled incoming fields for all readers from one integration.
wp newspack integrations backfill --direction=pull --integration=esp

# Fully catch up one integration, 500 readers per batch.
wp newspack integrations backfill --direction=both --integration=esp --create-missing --batch-size=500
```

- `--direction=push|pull|both` (default `push`). Push-only flags
  (`--subscription-ids`, `--order-ids`, `--migrated-subscriptions`,
  `--skip-lists`, `--fields`) hard-error when the direction includes pull.
  `--create-missing` is refused only with `--direction=pull`: only a push can
  create a contact, so it means the same under `--direction=both`.
- Both legs honor the per-direction toggles: the push leg skips integrations
  where `is_push_enabled()` is false, and the pull leg skips those where
  `is_pull_enabled()` is false, matching every other sync dispatch site.
- A direction that includes `pull` requires at least one in-scope integration
  with inbound sync enabled *and* incoming fields selected — validated in the
  pre-flight, so a `--direction=both` run fails fast instead of completing the
  push leg first.
- `--user-ids` limits either direction to a list of readers. `--batch-size`,
  `--max-batches` and `--offset` split an all-readers or
  `--migrated-subscriptions` run across processes; they do not window a
  `--user-ids` list.
- `--integration=<id>` restricts the run to one active, configured integration.
  On the push side this also scopes the `--fields` pre-flight validation, checks
  that integration's own `can_sync()` and `is_push_enabled()` (rather than only
  the global "at least one syncable integration" gate), and skips ESP-specific
  guards such as the Mailchimp `--skip-lists` rejection when the target is not
  the ESP.
- A pull `--dry-run` still performs the external API reads — it only skips
  writing reader data. It does evaluate the deterministic reader-data write
  rejections, so its error tally previews what a real run would report.
- Pull failures are **not** retried via ActionScheduler (a bulk run against a
  flaky API would flood the queue): errors are tallied and logged, and the
  affected `--offset` window can be re-run.
- Readers no pull target has a contact for (`ras_contact_not_found`) are tallied
  as skipped, not as errors: a pull cannot create the missing contact, so
  re-running could never clear them and a partially-synced site would never
  exit 0. A reader found at one integration and missing at another counts as
  processed; an error at any of them counts as an error. The push side tallies
  the same way.
- The push updates only the contacts an integration already has, unless
  `--create-missing` is passed: readers it reports no contact for
  (`contact_exists()` returning `false`) are tallied as skipped and never
  created; a read that fails for any other reason withholds the push and is
  tallied as an error. Failed pushes are auto-retried only on a
  `--create-missing` run without `--skip-lists`/`--fields`. Without
  `--create-missing`, the run refuses to start if any integration taking part
  cannot check (`supports_contact_lookup()` false). Scope the run with
  `--integration` to one that can, and run each of the others on its own with
  `--integration=<id> --create-missing` if creating contacts there is
  intended; an unscoped `--create-missing` creates contacts at every
  integration taking part. A `--dry-run` without `--create-missing` still
  performs the existence read and previews the skips in its tally; because it
  reaches the provider it is gated like a wet run (the staging guard applies).
  A `--dry-run --create-missing` reads no contacts at the provider, so it runs
  even when the configuration check fails (on staging, for one), then exits 1
  with every reason on both commands; under `--direction=both` the pull leg
  does not run.
- A run that tallies any error prints its summary as a warning and **exits 1**,
  so unattended runbooks can detect partial failure from the exit status. A
  clean run exits 0.
- Where a run fetches readers in batches, batch boundaries free the object
  cache and pause one second for every 100 contacts that reached an
  integration, carrying the remainder forward, so pacing follows provider
  traffic rather than `--batch-size`. That covers the all-readers run in either
  direction, `--migrated-subscriptions`, and a pull's `--user-ids` list (in
  `--batch-size` chunks). A push given `--user-ids`, `--order-ids` or a plain
  `--subscription-ids` list runs without pauses.
- `wp newspack esp sync` remains as a backward-compatible alias frozen to the
  push direction and its historical flag surface, plus `--create-missing`:
  like `backfill`, it only updates existing contacts unless that flag is
  passed. It warns on STDERR on every run that it is a legacy alias. It still
  exits 0 even when errors are tallied, and warns on STDERR when any non-dry
  run but a plain `--create-missing` one tallies errors; a pre-flight failure
  exits 1 on both commands.

See `wp help newspack integrations backfill` for the full option reference.

---

## Data Event Handlers

Integrations subscribe to [Data Events](../../data-events/README.md) by overriding `register_handlers()` and calling `$this->register_handler()`:

```php
public function register_handlers() {
    $this->register_handler( 'reader_registered', 'on_reader_registered' );
    $this->register_handler( 'woo_order_updated', 'on_woo_order_updated' );
}

public function on_reader_registered( $timestamp, $data, $client_id ) {
    if ( ! \Newspack\Reader_Activation\Integrations::is_enabled( $this->get_id() ) || ! $this->is_set_up() ) {
        return;
    }
    // Push or transform contact data.
}
```

Handlers run for every registered integration, enabled or not: the framework registers them all and dispatches without checking either state. Guard any handler that reaches a provider, as above, so that disabling the integration stops it.

Each `register_handler( $action_name, $method )` call:

1. Stores the integration ID and method name in the registry's handler map, keyed by `static::class . '::' . $action_name`. A method that is not callable is logged as an error and not registered, so that handler never runs.
2. Registers a static callable `[ static::class, 'dispatch_data_event_handler' ]` with Data Events. This is intentionally serializable so ActionScheduler can persist it across requests.

The registry filters `newspack_data_events_handler_action_group` once, resolving each handler's group from the handler map, so a handler's retries land in its integration's own group. The first attempt runs in Data Events' shared dispatch, outside any integration's group; only retries are scoped per integration.

When the event fires, Data Events calls the static dispatcher, which resolves the live integration instance from the registry and invokes the original instance method. Each handler is wrapped in its own retry context — a thrown exception from a handler triggers Data Events' standard ActionScheduler retry for just that handler, without affecting other integrations.

**Constraint:** at most one instance per concrete subclass can register a handler for a given action. Late registrations for the same `(class, action)` pair overwrite earlier ones.

---

## ActionScheduler Groups

Every integration owns an ActionScheduler group named `newspack-integration-{id}`. A human-readable label is registered for it via `newspack_action_scheduler_group_labels`, for active integrations only. Scheduled actions (data event handler retries, pull retries, push retries) are placed in their integration's group, which makes per-integration filtering possible in the Activity Logs UI.

Programmatic access:

```php
// All groups across integrations.
Integrations::get_all_action_groups();

// Scheduled actions for one integration.
$integration->get_scheduled_actions( [
    'status'   => 'failed',
    'per_page' => 50,
] );

// Scheduled actions for all integrations, filtered.
Integrations::get_scheduled_actions( [
    'integration_id' => 'esp',
    'status'         => 'pending',
] );

Integrations::count_scheduled_actions( [ 'integration_id' => 'esp' ] );
```

An empty `integration_id` queries every `newspack-integration-*` group in ActionScheduler's groups table, not the registry. That includes groups left by disabled or removed integrations, and leaves out integrations that have never scheduled an action.

---

## Push Log

`Push_Log` records every outbound operation `Contact_Sync` performs against an integration — contact upserts, deletion flags and hard deletes — in the `{prefix}newspack_integrations_push_log` table. It answers "what did we send this reader's CRM record, when, and did it arrive?", which ActionScheduler cannot: most pushes are not actions of their own, upsert retry args carry a user ID rather than an email, and an intermediate retry completes normally while the sync is still failing.

Integrations do not write to it. `Contact_Sync` does, because only it knows which attempt of a chain a push was, whether another follows, and when a chain gives up. Pulls, dry runs and pushes that never ran are not recorded. That covers sync disabled, outbound paused, deletion sync off, flag-mode deletion skipped for an integration without a cleanup, and pushes an update-only backfill skipped or withheld, including when its existence read failed.

### What a row is

One reader, one integration, one triggering push. A fan-out to three integrations writes three rows.

- **Retries update the row.** The row ID rides in the retry's ActionScheduler args as `log_id`. `attempts` counts pushes made so far; `max_attempts` is the ceiling when the row was written (`MAX_RETRIES + 1`, or 1 when nothing will retry: a CLI push without `--create-missing` or scoped with `--skip-lists`/`--fields`, or a contact with no account to rebuild from). It is a ceiling, not a promise: a permanent or benign result ends a row on its first attempt. Read `status` to know whether another attempt is coming.
- **`status` describes the sync, not an action**: `success`, `retrying` or `failed`. An error row is written as `failed` and becomes `retrying` only when a retry is actually scheduled: if Action Scheduler stores nothing, the row stays `failed`. A retry that gives up before pushing ends the row as `failed` with `error_code = retry_aborted`. A benign result is a `success` that keeps `error_class = benign`.
- **`payload`** is the prepared contact as handed to the integration: as close to the wire as the framework sees. An integration may still reshape it internally. Hard deletes have none. On an email change it also carries `previous_email`, the address the contact was matched on: that is log context, not data sent. Because it is part of the payload, the first routine push after an email change adds a row of its own rather than collapsing, which leaves the email-change row intact as the record of the change.
- **Identical pushes collapse.** A clean successful first-attempt upsert whose payload matches the reader's latest row for that integration bumps `repeat_count` and `updated_at` (and rewrites `payload`) on that row instead of adding one, so the recurring sync does not grow the table. The latest row must itself be a `success` upsert with no `error_class`. A row that succeeded on a retry keeps the error class of its earlier attempts, so the next identical push starts a new row, and later ones collapse into that. The comparison ignores key order and volatile fields (`Last Active` by default; filter `newspack_integrations_push_log_volatile_fields`, whose names are field names as sent to the integration, without its prefix). Deletion rows never collapse.
- **Flag-mode deletion is two steps.** When the flag push lands but `flag_deletion_cleanup()` fails, the row ends as `failed` with `error_code = flag_cleanup_failed`: the deleted reader is still on the lists. The same holds when the first push came back benign (the contact was already deleted at the integration): no retry follows a benign result, so the failed cleanup is the only record that a list still holds the reader. When the first push failed transiently, a failed cleanup is not written to the row, because the retry that ends the chain runs the cleanup again. A failure there is recorded the same way when that retry's push landed or came back benign; when the push failed too, the row keeps the push's error, since a `flag_cleanup_failed` row reads as a push that was delivered.
- **Error fields are never cleared**, so a row that succeeds on a later attempt still says what the earlier ones hit.

### Reading the log

The Logs page of an integration (Audience → Integrations → an integration's menu → Logs) opens on a "Sync Activity" tab backed by read-only methods on `Push_Log`. None writes, and every lookup leads with an equality on the leading column of an index the table already has.

- **`Push_Log::query( $args )`** lists one integration's rows by last update, without payloads or error messages: a provider's message can run to thousands of characters, and a list does not show it. `search` takes a full email, which also matches rows of the account that address belongs to and of the accounts its own rows name (a reader who changed address keeps one history, and the address they left still finds the email-change push, which is logged under the new one), or the start of an address. There is no match in the middle of an address: it could not use the `email` index. `needs_attention` keeps `retrying` and `failed` rows with no later `success` for the same reader and integration. An upsert normally sends the full contact, so any later success supersedes a failed or retrying one (a retry that still runs writes its row again, so a new failure puts it back, while a row whose retry is gone is never rewritten); a failed deletion (`flag` or `delete`) is only made up for by a later successful deletion, so a reader who signs up again does not hide an erasure that never reached the provider. "Same reader" means the address or the account, asked as two lookups per open failure rather than one `OR` over both, because a dependent subquery cannot serve an `OR` from either index. It is worked out when reading; nothing is stored.
- **Every read returns a `WP_Error`** when the table cannot be read (`query()`, `get()`, `get_predecessor()`). Reads run with the database layer's error output suppressed, the account lookup behind a full-email search included, so without it a broken or missing table would read as a log with nothing in it, or as an entry that is not there. The routes answer 500 and the screen reports a failed load instead. The message carries neither the statement nor the database's own text, either of which can quote the reader's address.
- **`Push_Log::get( $id, $integration_id )`** returns one row with its payload and error message. A row of another integration reads as missing (`null`).
- **`Push_Log::get_predecessor( $row )`** returns the reader's previous `success` row for that integration, and **`Push_Log::compare_payloads( $row, $predecessor, $prefix )`** compares the two field by field. It compares with the last *successful* push because a failed one never reached the provider, and it skips a benign one, which changed nothing there; on a failed row the comparison reads as what did not arrive, except a `flag_cleanup_failed` row whose push itself reached the provider. A row whose push came back benign reads as not delivered whatever its status, for the same reason. The comparison is against what Newspack last sent, so edits made at the provider are invisible to it. Volatile fields are flagged, from the same `newspack_integrations_push_log_volatile_fields` filter the collapse check uses.
- **An operation other than an upsert is compared on its own fields.** A `flag` row carries the address and a few deletion fields by design, so only the fields that row sent take part: the rest were never cleared at the provider, and listing them as emptied would describe an erasure that did not happen. An upsert normally sends the whole contact, so a field it stopped sending stays on the list. A CLI push scoped with `--fields` is the exception: it sends only the named fields, so the rest are not really dropped. It is logged as an ordinary upsert, which carries further: it becomes the predecessor of the reader's next full push, whose comparison then shows every field the scoped push left out as changed from nothing, and as a later success it takes a failed full update off "needs attention" without having sent what failed. That is why an empty "needs attention" list reads as what the log holds, not as an all-clear. Telling scoped pushes apart needs the write side to record the scoping.
- **The two questions use different clocks.** The predecessor is the previous row by `id`, while "needs attention" asks which push finished later by `updated_at`. When a retry chain overlaps a newer push for the same reader, a row can be compared with a push that finished after it.

The wizard exposes them as `GET /newspack/v1/wizard/newspack-audience-integrations/settings/{integration_id}/push-log` and `…/push-log/{id}`. The list carries `retention_days`, so the screen names the windows the site keeps rather than repeating the defaults. A `retrying` row's `retry` says whether its Action Scheduler action is still pending or running now (the row points at a running retry until its push returns); "Run retry now" goes through the existing `…/logs/{action_id}/run` route. The second tab, "Scheduled Actions", is the Action Scheduler list: it says a job ran, not that a sync worked.

### Retention and privacy

The hourly `newspack_integrations_push_log_cleanup` cron deletes `success` rows 30 days after their last update and `failed` or `retrying` rows after 90, in batches of 1,000. Each run stops after 5 batches that deleted rows (looking at an integration with nothing to prune does not count). A run that stops there with expired rows still left logs `newspack_integrations_push_log_cleanup_capped`, so a backlog that outgrows the cron is visible; the rest goes on the next run. Tune the windows with `newspack_integrations_push_log_retention_days`; add the hook name to `NEWSPACK_CRON_DISABLE` to turn the cron off. A collapsed row keeps refreshing `updated_at`, so an active reader whose data has not changed holds one live row per integration.

Rows hold reader emails and pushed field values. A personal-data eraser (`newspack-integrations-push-log`) deletes a reader's rows by email and by account, so rows under a previous address go too. The account comes from the WP user the address resolves to and from the rows stored under that address, so a request that arrives after the account was deleted still reaches them. Guest and deletion rows name no account (`user_id` 0) and are matched by email alone.

Writing the log never breaks a sync: a database failure returns 0 to the caller and is reported once per request as `newspack_integrations_push_log_write_failed`. Statements that carry reader data run with `$wpdb` error output suppressed, because a failed statement would otherwise reach the PHP error log whole, email and payload included; that report is the signal instead. It includes the database's own error text (`db_error`) with everything from its first quote to its last replaced by `'…'`. The database quotes what a statement failed on, which can be the reader's address; the words around the quotes still name the kind of failure, though not the column or key it hit. The report also names no user: during a sync, the user whose request hit the table is often the reader.

### Changing the schema

Edit the `CREATE TABLE` in `Push_Log::maybe_create_table()` and bump `TABLE_VERSION`. The stored version no longer matches, so `dbDelta` runs on the next request and applies the change. The version is recorded only once the table exists and, when `dbDelta` changed something, a dry run of the same definition finds nothing left to apply, so a failed creation or `ALTER` is retried rather than leaving every write to fail. The retry is hourly, not per request, for a host that keeps refusing the change. Write each column the way MariaDB reports it back: integer types with their display width (`bigint(20)`, not `bigint`) and no aliases (`int(10)`, not `integer`). A definition `dbDelta` misreads looks like a pending change on every run, so the version would never be recorded and the change would run every hour. `test_the_table_definition_reads_back_as_applied` checks the read-back on the database it runs on, and the display widths on the definition itself, since `dbDelta` ignores a missing width on MySQL 8.0, which CI runs, but not on MariaDB. `dbDelta` adds columns and indexes and alters column definitions, but never drops or renames anything: a removed or renamed column needs its own `ALTER TABLE` alongside the version bump. A table that disappears after its version was recorded (a restore, a manual drop) is created again: the first failed write finds it gone and forgets the version.

---

## Health Checks

The framework schedules an hourly cron hook `newspack_integration_health_check` that walks every enabled integration whose `is_set_up()` returns `true`, and runs:

1. `can_sync( true )` — settings validation as a `WP_Error`.
2. `test_connection()` — live API check.

`Integration::health_check()` is `final` and returns `true` or the first failure as a `WP_Error`: errors from `can_sync( true )` stop it before `test_connection()` runs, and an exception thrown by `test_connection()` becomes a `newspack_integration_connection_error`. Each run fires one of two actions per integration, then one for the run:

```php
// On failure, after logging the error under `NEWSPACK-INTEGRATION`:
do_action(
    'newspack_integration_health_check_failed',
    [
        'integration_id'   => $integration->get_id(),
        'integration_name' => $integration->get_name(),
        'error'            => $result, // WP_Error
    ]
);

// On success:
do_action(
    'newspack_integration_health_check_passed',
    [
        'integration_id'   => $integration->get_id(),
        'integration_name' => $integration->get_name(),
    ]
);

// After the run, with the IDs of the integrations it checked:
do_action( 'newspack_integration_health_checks_completed', [ 'esp' ] );
```

`Newspack\Alert_Manager` consumes all three and keeps one health record per integration in the `newspack_integration_health_state` option. Slack is paged once, on the `Alert_Manager::HEALTH_BROKEN_THRESHOLD`-th consecutive failure, and every other failure reaches the log at warning severity; a gap longer than `Alert_Manager::HEALTH_STREAK_MAX_GAP` between failures restarts a count that has not paged yet. That transition, the pass that ends it, and a run that stops checking a broken integration (disabled, or no longer set up) fire:

```php
do_action(
    'newspack_integration_health_changed',
    [
        'integration_id'   => 'esp',
        'integration_name' => 'Mailchimp',
        'state'            => 'broken', // or 'recovered', or 'disconnected' when a run no longer checks it
        'error_class'      => 'publisher', // the ESP account is the problem; 'other' for outages and unknowns
        'error'            => '403: API Access has been disabled for this account.',
        'first_failed_at'  => 1790000000,
        'failures'         => 3,
    ]
);
```

One event per outage in each direction, so a consumer can open and close a ticket without deduplicating hourly repeats itself. Staging hosts (`*.newspackstaging.com`) fire it too; only their alerts are capped at Watch, so a consumer that should skip staging has to check the host itself.

While an integration is broken, its contact-sync failures don't page either: retry exhaustion reaches the log at warning severity, and its failures stay out of the failure log the pattern alerts read. A record whose last failure is older than `Alert_Manager::HEALTH_STREAK_MAX_GAP` no longer counts, so a check that stops running cannot keep an integration muted.

To disable the schedule on a specific site, add `'newspack_integration_health_check'` to the `NEWSPACK_CRON_DISABLE` constant array. A disabled or unpaid ESP account is reported from contact sync at most once an hour per integration, at warning severity while the check runs. Without the check, that hourly report pages instead, as it also does when the check's cron has stopped running for longer than `Alert_Manager::HEALTH_STREAK_MAX_GAP`.

---

## My Account Endpoints

Integrations can add their own tab to the My Account page (WooCommerce's, or Newspack's own when WooCommerce is inactive) by returning a menu item from `get_my_account_menu_item()`:

```php
public function get_my_account_menu_item() {
    return [
        'slug'     => 'my-rewards',
        'label'    => __( 'My Rewards', 'my-plugin' ),
        'position' => 2, // Optional zero-based index into WooCommerce's menu; appended if omitted.
    ];
}

public function render_my_account_page( $value ) {
    echo '<h2>' . esc_html__( 'My Rewards', 'my-plugin' ) . '</h2>';
    // ...
}
```

With WooCommerce, the registry handles rewrite endpoint registration, query var registration, menu item insertion, and a one-off `flush_rewrite_rules()` whenever the set of declared slugs changes. `position` is a zero-based index into WooCommerce's menu as it stands before Newspack's own My Account filters drop and reorder tabs. An item without one is appended before `customer-logout`; a position at or past the end lands after it on WooCommerce's own account page. Newspack's default account UI moves Log out to its footer, so there such an item is last. Without WooCommerce, Newspack's own My Account page registers and flushes the endpoints itself, appends the tabs (ignoring `position`), and calls `render_my_account_page( '' )`.

Slug collisions between integrations resolve to first-wins in the order integrations were enabled; one enabled later silently skips. Pick a slug that no core WooCommerce endpoint or other plugin's My Account tab uses (`newsletters` belongs to Newspack Newsletters): the menu shows a single item, but the integration's page still renders under that endpoint, so two tab bodies show.

Endpoints are only registered for integrations that are currently enabled.

---

## Frontend Reader Registration

To allow an integration to drive frontend reader registration (e.g. a third-party signup form posting to Newspack):

1. Override `supports_frontend_registration()` to return `true`. The framework will output the integration's registration key on the page and accept it on the registration endpoint. Prefer returning the integration's *enabled* state, plus any runtime prerequisite the integration has (as the built-in `form-capture` does, which also refuses when the site is on reCAPTCHA v2), so the key, the endpoint and any frontend script share a single off switch. Checking a prerequisite only when the integration is enabled leaves a site that changes the prerequisite afterwards emitting a key nothing can use.
2. Optionally override `get_registration_key()` and `validate_registration_request()` to implement a custom key scheme (asymmetric keys, time-bounded tokens, etc.). The default is HMAC-SHA256 of the integration ID and a stored per-integration seed with the site's auth salt, compared in constant time; `rotate_registration_key()` regenerates the seed to revoke a key that is being abused (PHP-only — gate any admin surface for it on a capability check and a nonce). While an integration stays on this default scheme, its validator also accepts the key from before the seed was added, so pages cached before the upgrade keep validating. That allowance has no cutoff yet (its removal is a `@todo` on `get_legacy_registration_key()`), and while it lasts, rotation stops the seeded key but not that one. An integration with a custom key scheme never gets the allowance.
3. Optionally override `handle_logged_in_user_registration( $user, $request )` to react when an already-logged-in reader submits a registration request — record a new donation, link an account, fire an analytics event, etc. It runs only after the request has passed every gate ahead of the logged-in branch in `Reader_Registration::api_frontend_register_reader()`, including the integration's own `validate_registration_request()`. That validator also guards anonymous registration, so keep it as strict as that path needs, and make it accept what the integration's client sends for a logged-in reader. None of those gates ties the request to `$user`: `npe` and `metadata` are caller-supplied on this path, not read from the session, and the email check comes after this branch, so `npe` can even be empty. Anything the handler attaches to `$user` needs its own check that it belongs to that account.

Each integration's registration traffic is rate-limited per IP in its own bucket (see `Reader_Registration::get_rate_limit_bucket_for()`); integrations expecting more than the default 10/hour should size their bucket via the `newspack_frontend_registration_rate_limit` filter, as `form-capture` does.

A new reader's first contact sync reaches Sync Activity as `RAS Reader registration` unless an integration names its own sign-ups: filter `newspack_reader_registered_sync_context` and match the event data's `metadata.registration_method`, as `form-capture` does.

The built-in JS client (`newspackReaderActivation.register()`) always sends the value returned by `get_registration_key()`. Custom key schemes that diverge from this default need their own client-side code to compute and submit the key.

The built-in `form-capture` integration ([class-form-capture.php](class-form-capture.php) and `src/reader-activation-form-capture/`) is the reference implementation of this section end to end: enabled-gated key emission, a capture script driving `register()`, magic-link suppression for repeat captures, existing-reader sync scheduling, and new-reader sync naming.

### Example: registering readers from a third-party widget

The example below registers a reader when a third-party widget (a donation form, a signup embed) reports a completed action in the browser.

Here's how a Javascript integration that captures user action and registers them look like. The script listens for the widget's completion event and passes the reader's details to `register()`. The second argument is the integration ID, which selects the key the framework emitted for it:

```js
/* global MyService */

function onComplete( detail ) {
    const { contact } = detail;
    if ( ! contact?.email || ! window.newspackReaderActivation?.register ) {
        return;
    }

    window.newspackReaderActivation
        .register( contact.email, 'my_integration', {
            first_name: contact.firstName || '',
            last_name: contact.lastName || '',
            metadata: {
                my_integration_supporter_id: contact.id || '',
            },
        } )
        .catch( error => {
            // A returning reader already has an account; the endpoint still
            // records the registration, so this is not a failure.
            if ( 'reader_already_exists' === error?.code ) {
                return;
            }
            // The widget's own action already succeeded, so don't surface this
            // to the reader. Log it for error monitoring and QA.
            // eslint-disable-next-line no-console
            console.warn( '[My Integration] Reader registration failed.', error );
        } );
}

// The widget's library may still be loading, or may be blocked by an ad
// blocker. Check with `typeof` and retry once on window load.
function listen() {
    if ( typeof MyService === 'undefined' ) {
        return false;
    }
    MyService.on( 'complete', onComplete );
    return true;
}

if ( ! listen() ) {
    window.addEventListener( 'load', listen );
}
```

On the server side, it's important to implement `validate_registration_request` and `handle_logged_in_user_registration`. In these methods, you need to make sure that the information that comes in the request matches what actually exists in your platform database. The javascript is the triger, but the actual data should not be trusted as is to avoid malicious actors from spamming the registration endpoint.

```php
namespace Newspack\My_Plugin;

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;

class My_Integration extends Integration {
    // Constructor, settings fields, etc. as in "Minimum implementation".

    /**
     * One switch: when the publisher disables the integration, the page stops
     * emitting its key and the endpoint stops accepting it.
     */
    public function supports_frontend_registration(): bool {
        return Integrations::is_enabled( $this->get_id() );
    }

    /**
     * Extend the default key check rather than replace it, so a client without
     * the page's key never reaches an API lookup and rotate_registration_key()
     * still applies.
     */
    public function validate_registration_request( string $key, $request ): bool {
        if ( ! $this->supports_frontend_registration() ) {
            return false;
        }
        if ( ! parent::validate_registration_request( $key, $request ) ) {
            return false;
        }

        $metadata = $request->get_param( 'metadata' );
        $email    = $request->get_param( 'npe' );
        if ( ! is_array( $metadata ) || empty( $metadata['my_integration_supporter_id'] ) || empty( $email ) || ! is_email( $email ) ) {
            return false;
        }

        // An ID that exists is not enough: it must belong to this email.
        return self::supporter_belongs_to( $metadata['my_integration_supporter_id'], $email );
    }

    /**
     * A logged-in reader completed the widget again. Bind against the session's
     * account, never `npe`: on this path `npe` is caller-supplied.
     */
    public function handle_logged_in_user_registration( $user, $request ) {
        if ( ! $this->supports_frontend_registration() ) {
            return;
        }

        $metadata = $request->get_param( 'metadata' );
        if ( ! is_array( $metadata ) || empty( $metadata['my_integration_supporter_id'] ) ) {
            return;
        }

        $supporter_id = $metadata['my_integration_supporter_id'];
        if ( ! self::supporter_belongs_to( $supporter_id, $user->user_email ) ) {
            return;
        }

        update_user_meta( $user->ID, 'my_integration_supporter_id', $supporter_id );
    }

    /**
     * Fails closed: an API error counts as "does not belong".
     */
    private static function supporter_belongs_to( string $supporter_id, string $email ): bool {
        $supporter = My_Service_API::get_supporter( $supporter_id );
        if ( is_wp_error( $supporter ) || empty( $supporter['email'] ) ) {
            return false;
        }
        return strtolower( $supporter['email'] ) === strtolower( $email );
    }
}
```

The client script depends on the `newspack-reader-activation` handle, so `newspackReaderActivation.register()` is available when it runs. Enqueue it only while the integration is enabled:

```php
add_action(
    'wp_enqueue_scripts',
    function () {
        if ( ! Integrations::is_enabled( 'my_integration' ) ) {
            return;
        }
        wp_enqueue_script(
            'my-integration-registration',
            plugins_url( 'dist/registration.js', MY_PLUGIN_FILE ),
            [ 'newspack-reader-activation' ],
            MY_PLUGIN_VERSION,
            [
                'strategy'  => 'defer',
                'in_footer' => true,
            ]
        );
    }
);
```

To act on readers this integration creates, match its registration method, `integration-registration-<integration ID>`, in the `newspack_registered_reader` action. Fundraise Up uses this to mark new readers as donors:

```php
add_action(
    'newspack_registered_reader',
    function ( $email, $authenticate, $user_id, $existing_user, $metadata ) {
        if ( 'integration-registration-my_integration' !== ( $metadata['registration_method'] ?? '' ) ) {
            return;
        }
        $target_id = $user_id ? (int) $user_id : ( $existing_user ? (int) $existing_user->ID : 0 );
        if ( ! $target_id ) {
            return;
        }
        \Newspack\Reader_Data::update_item( $target_id, 'is_donor', true );
    },
    10,
    5
);
```

### Alternative: registering readers on a landing page

Some services can send the reader to a page on the site once they finish, with an identifier in the URL. The integration can then register or log in the reader in PHP when that page loads. This path skips the frontend registration endpoint and its JS client, so the integration does not need `supports_frontend_registration()`. It also works when a script blocker stops the widget's browser events.

The URL is the only input, and anyone can copy or forge it. Logging someone in from it is only safe when the server re-checks everything through the service's API and limits how long and how often a URL works. Here are a few best practices:

- **Read identity from the API, never from the URL.** The URL only carries a lookup key. The email, the name and the IDs saved on the reader all come from the service's response.
- **Expire the link.** Refuse records older than a short window, and treat a missing or unreadable timestamp as expired. A URL found later in browser history or a shared link then logs no one in.
- **Use each identifier once.** Claim it in the object cache before calling the API (`wp_cache_add()` succeeds for the first caller only), so a reload or a second tab can't register or log in twice. Release the claim when the API call or the registration fails, so the reader can retry by reloading.
- **Never switch sessions.** If someone else is logged in, leave the session alone and log the mismatch.
- **Log in readers only.** `Reader_Activation::set_current_reader()` checks no credentials, so check `Reader_Activation::is_user_reader()` first. That keeps a URL from logging anyone in as an editor or administrator.
- **Keep the response out of the page cache.** Call `nocache_headers()` before setting the login cookie.

```php
namespace Newspack\My_Plugin;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;

add_action( 'template_redirect', __NAMESPACE__ . '\maybe_register_from_landing_page', 1 );

function maybe_register_from_landing_page() {
    // This runs on every page view, so bail before any lookup when the request
    // isn't a landing-page visit.
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }
    if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_page() ) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Identity comes from the service's API, not from this parameter.
    $record_id = isset( $_GET['my_service_id'] ) ? sanitize_text_field( wp_unslash( $_GET['my_service_id'] ) ) : '';
    if ( empty( $record_id ) || ! Integrations::is_enabled( 'my_integration' ) ) {
        return;
    }

    // Use each identifier once.
    $claim_key = 'my_integration_landing_' . md5( $record_id );
    if ( false === wp_cache_add( $claim_key, 1, 'my_integration', DAY_IN_SECONDS ) ) {
        return;
    }

    $record = My_Service_API::get_record( $record_id );
    if ( is_wp_error( $record ) ) {
        wp_cache_delete( $claim_key, 'my_integration' ); // Let a reload retry.
        return;
    }

    // Read identity from the API.
    $email = $record['contact']['email'] ?? '';
    if ( ! is_email( $email ) ) {
        return;
    }

    // Expire the link. A missing timestamp counts as expired.
    $created = empty( $record['created_at'] ) ? false : strtotime( $record['created_at'] );
    if ( ! $created || $created < time() - DAY_IN_SECONDS ) {
        return;
    }

    nocache_headers();

    $metadata = [
        // Same method as the frontend endpoint uses, so one
        // `newspack_registered_reader` handler covers both paths.
        'registration_method'         => 'integration-registration-my_integration',
        'current_page_url'            => home_url( remove_query_arg( 'my_service_id' ) ),
        'my_integration_contact_id' => $record['contact']['id'] ?? '',
    ];

    // Someone is already logged in: record it only if it's the same person.
    if ( is_user_logged_in() ) {
        $current_user = wp_get_current_user();
        if ( strtolower( $current_user->user_email ) === strtolower( $email ) ) {
            // do something
        }
        return;
    }

    // A returning reader: log them in. Never log in a non-reader account.
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        if ( ! Reader_Activation::is_user_reader( $user ) ) {
            return;
        }
        if ( is_wp_error( Reader_Activation::set_current_reader( $user->ID ) ) ) {
            return;
        }
        // do something.
        return;
    }

    // A new reader: register_reader() creates the account, logs them in
    // (third argument), and passes $metadata to `newspack_registered_reader`.
    $name    = trim( ( $record['contact']['first_name'] ?? '' ) . ' ' . ( $record['contact']['last_name'] ?? '' ) );
    $user_id = Reader_Activation::register_reader( $email, $name, true, $metadata );
    if ( is_wp_error( $user_id ) || ! $user_id ) {
        wp_cache_delete( $claim_key, 'my_integration' ); // Let a reload retry.
    }
}
```

The handler runs on `template_redirect`, before any output, so it can still set the login cookie and the page renders for the logged-in reader.
