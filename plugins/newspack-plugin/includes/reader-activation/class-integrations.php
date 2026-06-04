<?php
/**
 * Integrations management class
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Data_Events;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations Management Class.
 *
 * Manages registration, enabling/disabling, and retrieval of integrations.
 * Also owns the data event handler map and dispatch logic.
 */
class Integrations {
	/**
	 * Logger header for integration-related messages.
	 */
	const LOGGER_HEADER = 'NEWSPACK-INTEGRATION';

	/**
	 * Cron hook name for integration health checks.
	 */
	const HEALTH_CHECK_CRON_HOOK = 'newspack_integration_health_check';

	/**
	 * Registered integrations.
	 *
	 * @var Integration[]
	 */
	private static $integrations = [];

	/**
	 * Whether integrations have been registered.
	 *
	 * @var bool
	 */
	private static $integrations_registered = false;

	/**
	 * Maps registered data event handlers to their integration and method.
	 *
	 * Keyed by "ClassName::action_name" to allow per-integration dispatch.
	 * Only one instance per concrete subclass can register a handler for a
	 * given action. If multiple instances of the same subclass register for
	 * the same action, the last registration wins.
	 *
	 * @var array<string, array{integration_id: string, method: string}>
	 */
	private static $handler_map = [];

	/**
	 * Map of My Account endpoint slug to integration ID.
	 *
	 * Populated during `register_my_account_endpoints()` for integrations
	 * whose `get_my_account_menu_item()` returns a menu item declaration.
	 *
	 * @var array<string, string>
	 */
	private static $my_account_endpoints = [];

	/**
	 * Option storing the last set of registered My Account endpoint slugs,
	 * used to trigger a rewrite rules flush when the set changes.
	 */
	const MY_ACCOUNT_ENDPOINTS_OPTION = 'newspack_integration_my_account_endpoints';

	/**
	 * Option name for storing enabled integrations.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'newspack_reader_activation_enabled_integrations';

	/**
	 * Initialize integrations system.
	 */
	public static function init() {
		// Include required files.
		require_once __DIR__ . '/integrations/class-integration.php';
		require_once __DIR__ . '/integrations/class-contact-pull.php';
		require_once __DIR__ . '/integrations/class-contact-cron.php';

		add_action( 'init', [ __CLASS__, 'register_integrations' ], 5 );
		add_action( 'init', [ __CLASS__, 'register_my_account_endpoints' ], 6 );
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'filter_my_account_menu_items' ] );
		add_filter( 'query_vars', [ __CLASS__, 'filter_my_account_query_vars' ] );
		add_action( 'newspack_frontend_registration_existing_user', [ __CLASS__, 'handle_existing_user_registration' ], 10, 3 );
		add_action( 'init', [ __CLASS__, 'schedule_health_check' ] );
		add_action( self::HEALTH_CHECK_CRON_HOOK, [ __CLASS__, 'run_health_checks' ] );
		add_filter( 'newspack_data_events_handler_action_group', [ __CLASS__, 'filter_handler_action_group' ], 10, 3 );
		add_filter( 'newspack_action_scheduler_group_labels', [ __CLASS__, 'register_group_labels' ] );

		Integrations\Contact_Cron::init();
	}

	/**
	 * Register group labels for integration ActionScheduler groups.
	 *
	 * @param array $labels Existing labels.
	 * @return array
	 */
	public static function register_group_labels( $labels ) {
		foreach ( self::get_active_integrations() as $integration ) {
			$labels[ self::get_action_group( $integration->get_id() ) ] = $integration->get_name();
		}
		return $labels;
	}

	/**
	 * Get the ActionScheduler group name for a specific integration.
	 *
	 * @param string $integration_id The integration ID.
	 *
	 * @return string The group name (e.g., 'newspack-integration-esp').
	 */
	public static function get_action_group( $integration_id ) {
		return \Newspack\Action_Scheduler::GROUP_PREFIX . 'integration-' . $integration_id;
	}

	/**
	 * Resolve the ActionScheduler group for a data event handler.
	 *
	 * Looks up the handler in the internal handler map and returns the
	 * per-integration group, or null if the handler is not registered
	 * through an integration.
	 *
	 * @param string $class       The handler class name.
	 * @param string $action_name The data event action name.
	 *
	 * @return string|null The group name or null if the handler is not registered through an integration.
	 */
	public static function get_action_group_for_handler( $class, $action_name ) {
		$key = $class . '::' . $action_name;
		if ( isset( self::$handler_map[ $key ] ) ) {
			return self::get_action_group( self::$handler_map[ $key ]['integration_id'] );
		}
		return null;
	}

	/**
	 * Filter the ActionScheduler group for a data event handler.
	 *
	 * Hooked to 'newspack_data_events_handler_action_group' to assign
	 * integration-specific groups to handlers registered through integrations.
	 *
	 * @param string $group       The default group name.
	 * @param string $class       The handler class name.
	 * @param string $action_name The data event action name.
	 *
	 * @return string The filtered group name.
	 */
	public static function filter_handler_action_group( $group, $class, $action_name ) {
		return self::get_action_group_for_handler( $class, $action_name ) ?? $group;
	}

	/**
	 * Get all ActionScheduler group slugs for Newspack integrations.
	 *
	 * @return string[] Array of group slug strings.
	 */
	public static function get_all_action_groups() {
		return \Newspack\Action_Scheduler::get_groups_by_prefix( \Newspack\Action_Scheduler::GROUP_PREFIX . 'integration-' );
	}

	/**
	 * Get ActionScheduler actions for Newspack integrations.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $integration_id Filter by a single integration ID.
	 *     @type string $status         ActionScheduler status (pending, complete, failed, canceled).
	 *     @type int    $per_page       Number of actions to return. Default 20.
	 *     @type int    $offset         Offset for pagination. Default 0.
	 *     @type string $orderby        Column to order by. Default 'scheduled_date_gmt'.
	 *     @type string $order          ASC or DESC. Default 'DESC'.
	 * }
	 *
	 * @return array Array of action row objects.
	 */
	public static function get_scheduled_actions( $args = [] ) {
		$defaults = [
			'integration_id' => '',
		];
		$args = wp_parse_args( $args, $defaults );

		// Resolve integration_id to group slugs.
		if ( ! empty( $args['integration_id'] ) ) {
			$args['groups'] = [ self::get_action_group( $args['integration_id'] ) ];
		} else {
			$args['groups'] = self::get_all_action_groups();
		}
		unset( $args['integration_id'] );

		// No groups to query, return empty array.
		if ( empty( $args['groups'] ) ) {
			return [];
		}

		return \Newspack\Action_Scheduler::get_scheduled_actions( $args );
	}

	/**
	 * Count ActionScheduler actions for Newspack integrations.
	 *
	 * @param array $args {
	 *     Optional. Query arguments. Same as get_scheduled_actions() but
	 *     per_page/offset/orderby/order are ignored.
	 *
	 *     @type string $integration_id Filter by a single integration ID.
	 *     @type string $status         ActionScheduler status (pending, complete, failed, canceled).
	 *     @type string $search         Search term.
	 * }
	 *
	 * @return int Total count.
	 */
	public static function count_scheduled_actions( $args = [] ) {
		$defaults = [
			'integration_id' => '',
		];
		$args = wp_parse_args( $args, $defaults );

		if ( ! empty( $args['integration_id'] ) ) {
			$args['groups'] = [ self::get_action_group( $args['integration_id'] ) ];
		} else {
			$args['groups'] = self::get_all_action_groups();
		}
		unset( $args['integration_id'] );

		if ( empty( $args['groups'] ) ) {
			return 0;
		}

		return \Newspack\Action_Scheduler::count_scheduled_actions( $args );
	}

	/**
	 * Register integrations.
	 */
	public static function register_integrations() {
		// Native integrations.
		self::register( new Integrations\ESP() );

		// Hook for other plugins/code to register their integrations.
		do_action( 'newspack_reader_activation_register_integrations' );

		// Auto-enable ESP on first registration only, while preserving the legacy sync setting on upgraded sites.
		$enabled_integrations = get_option( self::OPTION_NAME, null );
		if ( null === $enabled_integrations ) {
			$legacy_sync_esp = get_option( 'newspack_reader_activation_sync_esp', null );

			if ( null === $legacy_sync_esp || rest_sanitize_boolean( $legacy_sync_esp ) ) {
				self::enable( 'esp' );
			}
		}

		// Let each integration register its data event handlers.
		foreach ( self::$integrations as $integration ) {
			$integration->register_handlers();
		}

		// Mark integrations as registered.
		self::$integrations_registered = true;
	}

	/**
	 * Register a new integration.
	 *
	 * @param Integration $integration The integration instance to register.
	 *
	 * @return bool True if registered successfully, false if already registered.
	 */
	public static function register( $integration ) {
		if ( ! $integration instanceof Integration ) {
			return false;
		}

		$id = $integration->get_id();

		if ( isset( self::$integrations[ $id ] ) ) {
			return false;
		}

		self::$integrations[ $id ] = $integration;

		return true;
	}

	/**
	 * Enable an integration.
	 *
	 * @param string $integration_id The integration ID to enable.
	 *
	 * @return bool True if enabled successfully, false otherwise.
	 */
	public static function enable( $integration_id ) {
		if ( ! isset( self::$integrations[ $integration_id ] ) ) {
			return false;
		}

		$enabled = self::get_enabled_integration_ids();

		if ( in_array( $integration_id, $enabled, true ) ) {
			return true;
		}

		$enabled[] = $integration_id;

		return update_option( self::OPTION_NAME, $enabled );
	}

	/**
	 * Disable an integration.
	 *
	 * @param string $integration_id The integration ID to disable.
	 *
	 * @return bool True if disabled successfully, false otherwise.
	 */
	public static function disable( $integration_id ) {
		$enabled = self::get_enabled_integration_ids();

		$key = array_search( $integration_id, $enabled, true );

		if ( false === $key ) {
			return true;
		}

		unset( $enabled[ $key ] );

		return update_option( self::OPTION_NAME, array_values( $enabled ) );
	}

	/**
	 * Get all available integrations.
	 *
	 * @return Integration[] Array of all registered integration instances.
	 */
	public static function get_available_integrations() {
		return self::$integrations;
	}

	/**
	 * Get active integrations.
	 *
	 * @return Integration[] Array of enabled integration instances.
	 */
	public static function get_active_integrations() {
		$enabled_ids = self::get_enabled_integration_ids();
		$active      = [];

		foreach ( $enabled_ids as $id ) {
			if ( isset( self::$integrations[ $id ] ) ) {
				$active[ $id ] = self::$integrations[ $id ];
			}
		}

		return $active;
	}

	/**
	 * Get active integrations whose external prerequisites are also configured.
	 *
	 * Iteration sites that perform real I/O on each integration (health checks,
	 * contact pushes, pulls, retries) MUST use this instead of
	 * `get_active_integrations()` to avoid alerting on, or scheduling AS
	 * retries for, integrations the admin has not yet finished setting up.
	 *
	 * `is_set_up()` is a stored-state check by contract — see ESP's override.
	 * Treating it as a runtime probe here would silently drop traffic on
	 * transient provider failures.
	 *
	 * @return Integration[] Integrations that are both enabled AND set up.
	 */
	public static function get_active_configured_integrations() {
		return array_filter(
			self::get_active_integrations(),
			function ( $integration ) {
				return $integration->is_set_up();
			}
		);
	}

	/**
	 * Get a specific integration by ID.
	 *
	 * @param string $integration_id The integration ID.
	 *
	 * @return Integration|null The integration instance or null if not found.
	 */
	public static function get_integration( $integration_id ) {
		return self::$integrations[ $integration_id ] ?? null;
	}

	/**
	 * Check if an integration is enabled.
	 *
	 * @param string $integration_id The integration ID.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public static function is_enabled( $integration_id ) {
		$enabled_ids = self::get_enabled_integration_ids();
		return in_array( $integration_id, $enabled_ids, true );
	}

	/**
	 * Get enabled integration IDs from option.
	 *
	 * @return array Array of enabled integration IDs.
	 */
	private static function get_enabled_integration_ids() {
		$enabled = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $enabled ) ) {
			return [];
		}

		return $enabled;
	}

	/**
	 * Check if integrations have been registered.
	 *
	 * @return bool True if integrations have been registered, false otherwise.
	 */
	public static function are_integrations_registered() {
		return self::$integrations_registered;
	}

	/**
	 * Get settings config for all integrations that have settings fields.
	 *
	 * @return array Keyed array of integration settings.
	 */
	public static function get_all_integration_settings() {
		$result = [];
		foreach ( self::$integrations as $id => $integration ) {
			$fields = $integration->get_settings_fields();
			if ( empty( $fields ) ) {
				continue;
			}
			$result[ $id ] = [
				'id'               => $id,
				'name'             => $integration->get_name(),
				'description'      => $integration->get_description(),
				'enabled'          => self::is_enabled( $id ),
				'is_set_up'        => $integration->is_set_up(),
				'setup_url'        => $integration->get_setup_url(),
				'settings'         => $integration->get_settings_config(),
				'required_plugins' => $integration->get_required_plugins(),
			];
		}
		return $result;
	}

	/**
	 * Update settings for a specific integration from an admin REST request.
	 *
	 * Skips fields whose type is managed server-side (see
	 * Integration::MANAGED_FIELD_TYPES — e.g., 'oauth', 'hidden') so admin
	 * clients can't overwrite tokens or other programmatically-managed values
	 * by POSTing them in the settings payload. Server-side writers continue
	 * to use Integration::update_settings_field_value() directly.
	 *
	 * @param string $integration_id The integration ID.
	 * @param array  $settings       Key-value pairs of settings to update.
	 * @return bool|null True if updated, null if integration not found.
	 */
	public static function update_integration_settings( $integration_id, $settings ) {
		$integration = self::get_integration( $integration_id );
		if ( ! $integration ) {
			return null;
		}
		foreach ( $settings as $key => $value ) {
			if ( $integration->is_managed_settings_field( $key ) ) {
				continue;
			}
			$integration->update_settings_field_value( $key, $value );
		}
		return true;
	}

	/**
	 * Register a data event handler for an integration.
	 *
	 * Validates the method, stores the handler in the map, and registers
	 * a serializable static callable with Data Events.
	 *
	 * What Data Events sees: [ $class, 'dispatch_data_event_handler' ]
	 * — two strings, fully serializable. The instance method is resolved
	 * from the integration registry at execution time.
	 *
	 * @param Integration $integration The integration instance.
	 * @param string      $class       The concrete integration class name (via static::class).
	 * @param string      $action_name The data event action name.
	 * @param string      $method      The instance method to call on this integration.
	 */
	public static function register_data_event_handler( $integration, $class, $action_name, $method ) {
		if ( ! is_callable( [ $integration, $method ] ) ) {
			Logger::error(
				sprintf(
					'Integration "%s" tried to register uncallable method "%s" for data event "%s".',
					$integration->get_id(),
					$method,
					$action_name
				),
				self::LOGGER_HEADER
			);
			return;
		}

		$key = $class . '::' . $action_name;
		self::$handler_map[ $key ] = [
			'integration_id' => $integration->get_id(),
			'method'         => $method,
		];

		Data_Events::register_handler(
			[ $class, 'dispatch_data_event_handler' ],
			$action_name
		);
	}

	/**
	 * Dispatch a data event to the registered integration handler.
	 *
	 * Resolves the concrete integration instance from the registry and
	 * calls the registered instance method. Throws on failure so that
	 * Data Events' retry mechanism can re-queue via ActionScheduler.
	 *
	 * @param string $class     The concrete integration class name (via static::class).
	 * @param int    $timestamp Timestamp of the event.
	 * @param array  $data      Data associated with the event.
	 * @param string $client_id Client ID.
	 *
	 * @throws \RuntimeException When the handler cannot be dispatched.
	 */
	public static function dispatch_data_event_handler( $class, $timestamp, $data, $client_id ) {
		$action = Data_Events::current_event();
		if ( ! $action ) {
			$message = sprintf( 'Integration data event dispatch aborted for %s: no current event available.', $class );
			Logger::error( $message, self::LOGGER_HEADER );
			throw new \RuntimeException( esc_html( $message ) );
		}

		$key = $class . '::' . $action;
		if ( ! isset( self::$handler_map[ $key ] ) ) {
			$message = sprintf( 'No integration data event handler registered for key "%s".', $key );
			Logger::error( $message, self::LOGGER_HEADER );
			throw new \RuntimeException( esc_html( $message ) );
		}

		$entry       = self::$handler_map[ $key ];
		$integration = self::get_integration( $entry['integration_id'] );
		if ( ! $integration ) {
			$message = sprintf( 'Failed to resolve integration "%s" for data event "%s".', $entry['integration_id'], $action );
			Logger::error( $message, self::LOGGER_HEADER );
			throw new \RuntimeException( esc_html( $message ) );
		}

		if ( ! is_callable( [ $integration, $entry['method'] ] ) ) {
			$message = sprintf(
				'Method "%s" is not callable on integration "%s" for data event "%s".',
				$entry['method'],
				$entry['integration_id'],
				$action
			);
			Logger::error( $message, self::LOGGER_HEADER );
			throw new \RuntimeException( esc_html( $message ) );
		}

		$integration->{ $entry['method'] }( $timestamp, $data, $client_id );
	}

	/**
	 * Register WooCommerce My Account endpoints for active integrations.
	 *
	 * Iterates active integrations, collects their declared menu items,
	 * registers rewrite endpoints and per-slug render hooks, and flushes
	 * rewrite rules when the set of registered slugs changes.
	 */
	public static function register_my_account_endpoints() {
		self::$my_account_endpoints = [];

		foreach ( self::get_active_integrations() as $integration ) {
			$item = $integration->get_my_account_menu_item();
			if ( ! is_array( $item ) || empty( $item['slug'] ) || empty( $item['label'] ) ) {
				continue;
			}
			$slug = sanitize_key( $item['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( self::$my_account_endpoints[ $slug ] ) ) {
				// First registration wins; skip collisions.
				continue;
			}
			self::$my_account_endpoints[ $slug ] = $integration->get_id();

			add_rewrite_endpoint( $slug, EP_PAGES );

			add_action(
				'woocommerce_account_' . $slug . '_endpoint',
				function( $value ) use ( $slug ) {
					$integration_id = self::$my_account_endpoints[ $slug ] ?? null;
					if ( ! $integration_id ) {
						return;
					}
					$integration = self::get_integration( $integration_id );
					if ( ! $integration ) {
						return;
					}
					$integration->render_my_account_page( $value );
				}
			);
		}

		// Flush rewrite rules only when the set of slugs changes.
		$current = array_keys( self::$my_account_endpoints );
		sort( $current );
		$previous = get_option( self::MY_ACCOUNT_ENDPOINTS_OPTION, [] );
		if ( ! is_array( $previous ) ) {
			$previous = [];
		}
		sort( $previous );
		if ( $current !== $previous ) {
			// Intentional: only fires when the set of integration-declared
			// endpoints changes, which is rare (enable/disable/install).
			flush_rewrite_rules( false ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			update_option( self::MY_ACCOUNT_ENDPOINTS_OPTION, $current );
		}
	}

	/**
	 * Inject integration menu items into the WooCommerce My Account menu.
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public static function filter_my_account_menu_items( $items ) {
		if ( empty( self::$my_account_endpoints ) ) {
			return $items;
		}

		// Collect items with positions so we can insert in order.
		$to_insert = [];
		foreach ( self::$my_account_endpoints as $slug => $integration_id ) {
			// Skip if an item with this slug already exists (e.g., a core
			// WooCommerce endpoint) to avoid overwriting or dropping entries.
			if ( isset( $items[ $slug ] ) ) {
				continue;
			}
			$integration = self::get_integration( $integration_id );
			if ( ! $integration ) {
				continue;
			}
			$item = $integration->get_my_account_menu_item();
			if ( ! is_array( $item ) || empty( $item['label'] ) ) {
				continue;
			}
			$to_insert[] = [
				'slug'     => $slug,
				'label'    => $item['label'],
				'position' => isset( $item['position'] ) ? (int) $item['position'] : null,
			];
		}

		if ( empty( $to_insert ) ) {
			return $items;
		}

		// Separate positioned vs. appended.
		$positioned = [];
		$appended   = [];
		foreach ( $to_insert as $entry ) {
			if ( null !== $entry['position'] ) {
				$positioned[] = $entry;
			} else {
				$appended[ $entry['slug'] ] = $entry['label'];
			}
		}

		// Sort positioned by position ascending for stable inserts.
		usort(
			$positioned,
			function( $a, $b ) {
				return $a['position'] <=> $b['position'];
			}
		);

		// Insert positioned entries by converting to an indexed sequence.
		if ( ! empty( $positioned ) ) {
			$keys   = array_keys( $items );
			$values = array_values( $items );
			foreach ( $positioned as $entry ) {
				$pos = max( 0, min( count( $keys ), $entry['position'] ) );
				array_splice( $keys, $pos, 0, [ $entry['slug'] ] );
				array_splice( $values, $pos, 0, [ $entry['label'] ] );
			}
			$items = array_combine( $keys, $values );
		}

		// Append the rest, preserving customer-logout at the bottom if present.
		if ( ! empty( $appended ) ) {
			if ( isset( $items['customer-logout'] ) ) {
				$logout = $items['customer-logout'];
				unset( $items['customer-logout'] );
				$items = array_merge( $items, $appended, [ 'customer-logout' => $logout ] );
			} else {
				$items = array_merge( $items, $appended );
			}
		}

		return $items;
	}

	/**
	 * Register query vars for integration My Account endpoints.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function filter_my_account_query_vars( $vars ) {
		foreach ( array_keys( self::$my_account_endpoints ) as $slug ) {
			$vars[] = $slug;
		}
		return $vars;
	}

	/**
	 * Handle an existing user attempting to register via a frontend integration.
	 *
	 * Delegates to the integration's handle_logged_in_user_registration() method if it exists,
	 * allowing integrations to update user data on repeated registration attempts
	 * (e.g. recording a new donation for a returning donor).
	 *
	 * @param \WP_User                                     $user                The logged-in user.
	 * @param \WP_REST_Request                             $request             The registration request.
	 * @param \Newspack\Reader_Activation\Integration|null $integration_instance The integration instance, or null for filter-only registrations.
	 */
	public static function handle_existing_user_registration( $user, $request, $integration_instance ) {
		if ( ! $integration_instance instanceof Integration ) {
			return;
		}
		$integration_instance->handle_logged_in_user_registration( $user, $request );
	}

	/**
	 * Schedule the hourly health check cron event.
	 *
	 * Respects NEWSPACK_CRON_DISABLE to allow selective disabling.
	 */
	public static function schedule_health_check() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'deactivate_health_check' ] );

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::HEALTH_CHECK_CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::deactivate_health_check();
		} elseif ( ! \wp_next_scheduled( self::HEALTH_CHECK_CRON_HOOK ) ) {
			\wp_schedule_event( time(), 'hourly', self::HEALTH_CHECK_CRON_HOOK );
		}
	}

	/**
	 * Deactivate the health check cron event.
	 */
	public static function deactivate_health_check() {
		\wp_clear_scheduled_hook( self::HEALTH_CHECK_CRON_HOOK );
	}

	/**
	 * Run health checks on all active and configured integrations.
	 *
	 * Routed through `get_active_configured_integrations()` so a missing
	 * provider or unconfigured master list — a setup-incomplete state, not a
	 * runtime incident — surfaces in the integrations UI rather than the
	 * alerts channel.
	 *
	 * Logs failures and fires an action for the Alert Manager.
	 */
	public static function run_health_checks() {
		foreach ( self::get_active_configured_integrations() as $integration ) {
			$result = $integration->health_check();
			if ( is_wp_error( $result ) ) {
				Logger::error(
					sprintf(
						'Health check failed for integration "%s": %s',
						$integration->get_name(),
						implode( '; ', $result->get_error_messages() )
					),
					self::LOGGER_HEADER
				);

				/**
				 * Fires when an integration health check fails.
				 *
				 * @param array $payload {
				 *     Health check failure data.
				 *
				 *     @type string    $integration_id   The integration ID.
				 *     @type string    $integration_name The integration display name.
				 *     @type \WP_Error $error            The error object.
				 * }
				 */
				do_action(
					'newspack_integration_health_check_failed',
					[
						'integration_id'   => $integration->get_id(),
						'integration_name' => $integration->get_name(),
						'error'            => $result,
					]
				);
			}
		}
	}
}
Integrations::init();
