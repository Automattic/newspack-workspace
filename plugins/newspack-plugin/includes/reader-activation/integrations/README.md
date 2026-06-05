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
| `class-esp.php` | Built-in ESP integration. Generic adapter for Newspack Newsletters (Mailchimp, ActiveCampaign, Constant Contact). |
| `class-incoming-field.php` | Value object describing an external field returned by an integration. Carries display metadata plus flags for access rules and segmentation criteria. |
| `class-contact-pull.php` | Pull pipeline. Per-integration synchronous loopback requests plus ActionScheduler-backed retries with exponential backoff. |
| `class-contact-cron.php` | Recurring cron orchestration. Stages users for pull/push and processes both queues every 5 minutes. |

The registry class is `Newspack\Reader_Activation\Integrations` (parent namespace). Classes under this folder live in `Newspack\Reader_Activation\Integrations\*`.

---

## Built-in Integrations

### `esp`

Syncs contacts and metadata fields with the active Newspack Newsletters service provider. Auto-registers and is enabled by default on new installs (and on legacy upgrades unless the legacy `newspack_reader_activation_sync_esp` option was explicitly disabled). Pulls custom merge fields back into Newspack for segmentation and access rules.

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

After registration, the integration becomes available in the publisher-facing Integrations UI and (if enabled) starts receiving sync requests.

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
        $contact = $this->prepare_contact( $contact );
        // ... push $contact to your API.
        return true;
    }
}
```

### Required methods

| Method | Purpose |
| --- | --- |
| `register_settings_fields()` | Return static field declarations (key, type, default at minimum). Called from the constructor. No API calls, no conditional logic based on external state. |
| `can_sync( $return_errors = false )` | Check whether the integration is configured and ready to sync. Return `bool` or `WP_Error` depending on `$return_errors`. Called before every push and pull. |
| `push_contact_data( $contact, $context, $existing_contact )` | Send contact data to the external system. Return `true` on success or `WP_Error` on failure. Failed pushes are retried via Contact Sync's ActionScheduler-backed retry mechanism. |

### Optional overrides

| Method | Purpose |
| --- | --- |
| `is_set_up()` | Whether external prerequisites (provider chosen, key entered, etc.) are configured. Defaults to `true`. Used by the Integrations UI to mark cards as ready. |
| `get_setup_url()` | Admin URL where the integration's prerequisites are configured. Defaults to empty string. |
| `test_connection()` | Lightweight live API call to verify credentials and reachability. Called as part of `health_check()`. Defaults to `true`. |
| `pull_contact_data( $user_id )` | Fetch contact data from the external system. Return `array` of `field_key => value` or `WP_Error`. Defaults to `[]`. |
| `get_available_incoming_fields()` | Return an array of `Incoming_Field` objects representing the schema available to pull. Required for any integration that supports pulling. |
| `configure_incoming_field( $field )` | Enrich an `Incoming_Field` with display metadata and promotion flags (access rule / segment criteria). Called by the framework on every constructed field. |
| `register_handlers()` | Register data event handlers (see [Data Event Handlers](#data-event-handlers)). Called once after all integrations have been registered. |
| `supports_frontend_registration()` | Return `true` to expose this integration's registration key to the page and accept it on the frontend registration endpoint. |
| `get_registration_key()` / `validate_registration_request()` | Override to implement custom registration key schemes. Default is timing-safe HMAC-SHA256 of the integration ID with the site's auth salt. |
| `handle_logged_in_user_registration( $user, $request )` | Called when a logged-in user attempts to register again via the frontend. Use to update user data, link the account, record a new event, etc. Default is a no-op. |
| `get_my_account_menu_item()` | Return `[ 'slug' => ..., 'label' => ..., 'position' => ... ]` to add a tab to the WooCommerce My Account page. Default returns `null` (no tab). |
| `render_my_account_page( $value )` | Echo markup for the integration's My Account page. Called inside the WooCommerce account template. |
| `get_required_plugins()` | Declare third-party plugins this integration depends on. Return an array of `[ 'slug' => ..., 'name' => ..., 'is_active' => ..., 'is_installed' => ... ]` entries. The Integrations UI uses this to gate the card: when every missing dependency is at least installed, it offers an **Activate** action; when any dependency is uninstalled, the card stays disabled with a "Requires …" affordance. Default `[]`. |

---

## Settings Fields

Settings fields are declared statically in `register_settings_fields()` and stored individually as WordPress options keyed `newspack_integration_settings_{id}_{key}`.

### Field declaration

```php
[
    'key'         => 'master_list',
    'type'        => 'select',
    'default'     => '',
    'label'       => __( 'Master List', 'my-plugin' ),
    'description' => __( '...', 'my-plugin' ),
    'options'     => [ ... ], // Required for 'select'.
]
```

Supported `type` values: `text`, `password`, `textarea`, `number`, `checkbox`, `select`, `metadata`, `oauth`, `hidden`. The base class sanitizes values per type before persisting.

`oauth` and `hidden` are **managed field types**: `Integrations::update_integration_settings()` calls `is_managed_settings_field()` and skips them, so admin clients can't overwrite them by POSTing to the settings REST endpoint. They're writable only from trusted PHP via `update_settings_field_value()`. See `Integration::MANAGED_FIELD_TYPES`.

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
4. Implement `is_set_up()` to return `true` only when the identity field is populated, so the Integrations UI marks the card ready.

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

Every integration automatically gets three additional fields appended to its settings:

| Field key | Type | Purpose |
| --- | --- | --- |
| `metadata_prefix` | `text` | String prepended to every outgoing metadata field name (default `NP_`). Stored at `newspack_integration_metadata_prefix_{id}`. Required so outgoing field names are unique on the external system. |
| `outgoing_metadata_fields` | `metadata` | Subset of Newspack metadata fields to push. Stored at `newspack_integration_outgoing_fields_{id}`. |
| `incoming_metadata_fields` | `metadata` | Subset of external fields to pull and store on the Newspack user. Stored at `newspack_integration_incoming_fields_{id}` as a `key => raw_data` map. |

---

## Push (Outgoing Sync)

When a contact needs to be synced, the framework calls `push_contact_data()` on every active integration. The contact array is the Newspack canonical form (email, name, metadata, etc.). Implementations should call `$this->prepare_contact( $contact )` first, which:

1. Filters `$contact['metadata']` to the keys enabled on this integration.
2. Renames keys using the integration's metadata prefix.
3. Preserves keys already in prefixed form if the underlying field is enabled.

`prepare_contact()` is a no-op when the site is still on the legacy metadata schema (where the metadata classes pre-filter), which keeps newly-built integrations compatible with un-migrated sites.

### When pushes are triggered

- Data event handlers registered via `register_handler()` (see below).
- Recurring cron via `Contact_Cron` (every 5 minutes for logged-in users).
- Direct calls from other Newspack subsystems via `Contact_Sync::sync_contact()`.

### Retries

Failed pushes are scheduled for retry by the upstream `Contact_Sync` class with exponential backoff via ActionScheduler. Each integration's retries are grouped under `newspack-integration-{id}` so they can be inspected and managed independently in the Activity Logs UI.

---

## Pull (Incoming Sync)

Integrations that override `pull_contact_data( $user_id )` can fetch external state back into Newspack. The returned associative array is intersected with the enabled incoming fields and persisted via `Reader_Data::update_item()` for each key.

### When pulls are triggered

The pull pipeline (`Contact_Pull` + `Contact_Cron`) runs on every logged-in pageview, throttled per user:

- If the user's last enqueue was more than 24 hours ago (`PULL_SYNC_THRESHOLD`), all enabled integrations are pulled synchronously via per-integration loopback `admin-ajax.php` requests. The request timeout defaults to 1 second per integration — anything that overruns falls back to the cron queue.
- Otherwise, the user is staged for pull on the next 5-minute batch (`Contact_Cron::CRON_INTERVAL`).

### Retries

`Contact_Pull` schedules per-integration retries with a `30s → 2min → 8min → 30min → 2h` backoff for up to 5 attempts. Retries run under the integration's ActionScheduler group (`newspack-integration-{id}`) using the hook `newspack_contact_pull_retry`.

### Incoming fields

`get_available_incoming_fields()` returns the schema offered by the external system. Each field is an `Incoming_Field`:

```php
$field = new Incoming_Field( 'membership_level', $raw );
$field
    ->set_name( __( 'Membership Level', 'my-plugin' ) )
    ->set_value_type( 'string' )                    // 'string' or 'boolean'.
    ->set_matching_function( 'list__in' )           // 'default', 'list__in', 'list__not_in', 'range'.
    ->set_options( [ [ 'value' => 'gold', 'label' => 'Gold' ], ... ] )
    ->set_description( __( 'Member tier from the CRM.', 'my-plugin' ) )
    ->set_is_access_rule( true )                    // Register as a content gate access rule.
    ->set_is_segment_criteria( true )               // Register as a popups segmentation criterion.
    ->set_access_rule_callback( function ( $user_id, $args ) { /* ... */ } );
```

Use `configure_incoming_field()` to enrich a field after construction — it's called on every field returned by `get_available_incoming_fields()` and again whenever stored fields are re-hydrated. This is where you set `is_access_rule`, `is_segment_criteria`, and any custom callback.

The base class also offers `get_filtered_incoming_fields()`, which hides fields whose name matches one of the integration's own outgoing prefixed keys, so publishers don't re-select fields they're already pushing.

---

## Data Event Handlers

Integrations subscribe to [Data Events](../../data-events/README.md) by overriding `register_handlers()` and calling `$this->register_handler()`:

```php
public function register_handlers() {
    $this->register_handler( 'reader_registered', 'on_reader_registered' );
    $this->register_handler( 'woo_order_updated', 'on_woo_order_updated' );
}

public function on_reader_registered( $timestamp, $data, $client_id ) {
    // Push or transform contact data.
}
```

Each `register_handler( $action_name, $method )` call:

1. Stores the (integration, method) tuple in the registry's handler map, keyed by `static::class . '::' . $action_name`.
2. Registers a static callable `[ static::class, 'dispatch_data_event_handler' ]` with Data Events. This is intentionally serializable so ActionScheduler can persist it across requests.
3. Filters the handler's ActionScheduler group to the integration's own group via `newspack_data_events_handler_action_group`, so failures and retries are scoped per integration.

When the event fires, Data Events calls the static dispatcher, which resolves the live integration instance from the registry and invokes the original instance method. Each handler is wrapped in its own retry context — a thrown exception from a handler triggers Data Events' standard ActionScheduler retry for just that handler, without affecting other integrations.

**Constraint:** at most one instance per concrete subclass can register a handler for a given action. Late registrations for the same `(class, action)` pair overwrite earlier ones.

---

## ActionScheduler Groups

Every integration owns an ActionScheduler group named `newspack-integration-{id}`, registered with a human-readable label via `newspack_action_scheduler_group_labels`. Scheduled actions (data event handler retries, pull retries, push retries) are placed in their integration's group, which makes per-integration filtering possible in the Activity Logs UI.

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

An empty `integration_id` queries every group registered by the framework.

---

## Health Checks

The framework schedules an hourly cron hook `newspack_integration_health_check` that walks every active integration and runs:

1. `can_sync( true )` — settings validation as a `WP_Error`.
2. `test_connection()` — live API check.

`Integration::health_check()` is `final` and returns either `true` or a `WP_Error` aggregating failures. When a failure is detected, the framework logs the error under `NEWSPACK-INTEGRATION` and fires:

```php
do_action(
    'newspack_integration_health_check_failed',
    [
        'integration_id'   => $integration->get_id(),
        'integration_name' => $integration->get_name(),
        'error'            => $result, // WP_Error
    ]
);
```

This hook is consumed by Newspack Manager to surface alerts. To disable the schedule on a specific site, add `'newspack_integration_health_check'` to the `NEWSPACK_CRON_DISABLE` constant array.

---

## My Account Endpoints

Integrations can add their own tab to the WooCommerce My Account page by returning a menu item from `get_my_account_menu_item()`:

```php
public function get_my_account_menu_item() {
    return [
        'slug'     => 'my-rewards',
        'label'    => __( 'My Rewards', 'my-plugin' ),
        'position' => 25, // Optional; appended if omitted.
    ];
}

public function render_my_account_page( $value ) {
    echo '<h2>' . esc_html__( 'My Rewards', 'my-plugin' ) . '</h2>';
    // ...
}
```

The registry handles rewrite endpoint registration, query var registration, menu item insertion (positional or appended, always keeping `customer-logout` last), and a one-off `flush_rewrite_rules()` whenever the set of declared slugs changes. Slug collisions resolve to first-wins; integrations registered later silently skip.

Endpoints are only registered for integrations that are currently enabled.

---

## Frontend Reader Registration

To allow an integration to drive frontend reader registration (e.g. a third-party signup form posting to Newspack):

1. Override `supports_frontend_registration()` to return `true`. The framework will output the integration's registration key on the page and accept it on the registration endpoint.
2. Optionally override `get_registration_key()` and `validate_registration_request()` to implement a custom key scheme (asymmetric keys, time-bounded tokens, etc.). The default is HMAC-SHA256 of the integration ID with the site's auth salt, compared in constant time.
3. Optionally override `handle_logged_in_user_registration( $user, $request )` to react when an already-logged-in reader submits a registration request — record a new donation, link an account, fire an analytics event, etc.

The built-in JS client (`newspackReaderActivation.register()`) always sends the value returned by `get_registration_key()`. Custom key schemes that diverge from this default need their own client-side code to compute and submit the key.
