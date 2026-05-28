<?php
/**
 * Newspack Data Events.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

/**
 * Main class.
 */
final class Data_Events {
	/**
	 * Asynchronous action name.
	 */
	const ACTION = 'newspack_data_event';

	/**
	 * Header to be used while logging.
	 */
	const LOGGER_HEADER = 'NEWSPACK-DATA-EVENTS';

	/**
	 * Option name for storing the nonce.
	 */
	const NONCE_OPTION = 'newspack_data_events_nonce';

	/**
	 * Option name for storing the nonce expiration.
	 */
	const NONCE_EXPIRATION_OPTION = 'newspack_data_events_nonce_expiration';

	/**
	 * Nonce lifetime in seconds (1 hour).
	 */
	const NONCE_LIFETIME = 3600; // 1 hour in seconds

	/**
	 * Grace period in seconds for accepting old nonces.
	 */
	const NONCE_GRACE_PERIOD = 10; // 10 seconds

	/**
	 * Option name for storing the previous nonce.
	 */
	const PREVIOUS_NONCE_OPTION = 'newspack_data_events_previous_nonce';

	/**
	 * Option name for storing the previous nonce expiration.
	 */
	const PREVIOUS_NONCE_EXPIRATION_OPTION = 'newspack_data_events_previous_nonce_expiration';

	/**
	 * Registered callable handlers, keyed by their action name.
	 *
	 * @var array<string, callable[]>
	 */
	private static $actions = [];

	/**
	 * Registered global callable handlers to be executed on all actions.
	 *
	 * @var callable[]
	 */
	private static $global_handlers = [];

	/**
	 * Dispatches queued for execution on shutdown.
	 *
	 * @var array[]
	 */
	private static $queued_dispatches = [];

	/**
	 * Current action event.
	 *
	 * @var string|null
	 */
	private static $current_event = null;

	/**
	 * The ID of the currently-executing ActionScheduler action.
	 *
	 * @var int|null
	 */
	private static $current_as_action_id = null;

	/**
	 * ActionScheduler hook for handling dispatched events.
	 */
	const DISPATCH_AS_HOOK = 'newspack_data_events_handle';

	/**
	 * ActionScheduler hook for retrying a failed handler.
	 */
	const HANDLER_RETRY_HOOK = 'newspack_data_events_retry_handler';

	/**
	 * Maximum number of retries for a failed handler.
	 */
	const MAX_HANDLER_RETRIES = 5;

	/**
	 * Backoff schedule in seconds for handler retries.
	 * 30s, 2min, 8min, 30min, 2h.
	 */
	const RETRY_BACKOFF = [ 30, 120, 480, 1800, 7200 ];

	/**
	 * Log a message with the Data Events header.
	 *
	 * @param string $message The message to log.
	 * @param string $type    Type of the message. Default 'info'.
	 */
	private static function log( $message, $type = 'info' ) {
		Logger::log( $message, self::LOGGER_HEADER, $type );
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'maybe_handle' ] );
		\add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'maybe_handle' ] );
		\add_action( 'shutdown', [ __CLASS__, 'execute_queued_dispatches' ] );
		\add_action( self::DISPATCH_AS_HOOK, [ __CLASS__, 'handle_from_scheduler' ] );
		\add_action( self::HANDLER_RETRY_HOOK, [ __CLASS__, 'execute_handler_retry' ] );
		\add_action( 'action_scheduler_begin_execute', [ __CLASS__, 'set_current_as_action_id' ] );
		\add_action( 'action_scheduler_after_execute', [ __CLASS__, 'clear_current_as_action_id' ] );
		\add_filter( 'newspack_action_scheduler_hook_labels', [ __CLASS__, 'register_hook_labels' ] );
	}

	/**
	 * Register hook labels for Data Events actions.
	 *
	 * @param array $labels Existing labels.
	 * @return array
	 */
	public static function register_hook_labels( $labels ) {
		$labels[ self::DISPATCH_AS_HOOK ]   = __( 'Data Event Handler', 'newspack-plugin' );
		$labels[ self::HANDLER_RETRY_HOOK ] = __( 'Data Event Retry', 'newspack-plugin' );
		return $labels;
	}

	/**
	 * Whether to use Action Scheduler for dispatching events.
	 *
	 * @return bool
	 */
	private static function use_action_scheduler() {
		/**
		 * Enables Action Scheduler-based dispatching for Data Events.
		 * When enabled, events are persisted to the database via Action Scheduler
		 * instead of being dispatched via non-blocking wp_remote_post().
		 * Requires Action Scheduler to be available.
		 *
		 * @constant NEWSPACK_DATA_EVENTS_ACTIONSCHEDULER
		 * @type     bool
		 * @default  Action Scheduler dispatch disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_DATA_EVENTS_ACTIONSCHEDULER', true );
		 */
		$use = defined( 'NEWSPACK_DATA_EVENTS_ACTIONSCHEDULER' ) && NEWSPACK_DATA_EVENTS_ACTIONSCHEDULER
			&& function_exists( 'as_enqueue_async_action' );

		/**
		 * Filters whether to use Action Scheduler for dispatching Data Events.
		 *
		 * @param bool $use Whether to use Action Scheduler. Default based on the
		 *                  NEWSPACK_DATA_EVENTS_ACTIONSCHEDULER constant and AS availability.
		 */
		return apply_filters( 'newspack_data_events_use_action_scheduler_dispatch', $use );
	}

	/**
	 * Handle a batch of dispatched events from Action Scheduler.
	 *
	 * @param array $dispatches Array of dispatch arrays, each containing action_name, timestamp, data, and client_id.
	 */
	public static function handle_from_scheduler( $dispatches ) {
		if ( ! is_array( $dispatches ) ) {
			self::log( 'Invalid dispatch data from Action Scheduler.', 'error' );
			return;
		}

		foreach ( $dispatches as $dispatch ) {
			if ( ! is_array( $dispatch ) ) {
				continue;
			}

			$action_name = isset( $dispatch['action_name'] ) ? sanitize_text_field( $dispatch['action_name'] ) : null;
			$timestamp   = isset( $dispatch['timestamp'] ) ? absint( $dispatch['timestamp'] ) : null;
			$data        = $dispatch['data'] ?? null;
			$client_id   = isset( $dispatch['client_id'] ) ? sanitize_text_field( $dispatch['client_id'] ) : null;

			if ( empty( $action_name ) || ! self::is_action_registered( $action_name ) ) {
				self::log(
					sprintf( 'Action "%s" not registered when handling from Action Scheduler.', $action_name ?? 'null' ),
					'error'
				);
				continue;
			}

			self::handle( $action_name, $timestamp, $data, $client_id );
		}
	}

	/**
	 * Get the current nonce for data events.
	 *
	 * We use a custom nonce implementation to avoid issues with user authentication.
	 * In some cases, and in some sites, the nonce is created for a user ID but the dispatched request does not have a user ID.
	 * This implementation generates a nonce that is not tied to a user ID, making it more reliable.
	 *
	 * @return string The nonce.
	 */
	public static function get_nonce() {
		$nonce = \get_option( self::NONCE_OPTION, '' );
		$expiration = \get_option( self::NONCE_EXPIRATION_OPTION, 0 );
		$current_time = time();

		// If nonce is empty or expired, generate a new one.
		if ( empty( $nonce ) || $current_time > $expiration ) {
			// Store the current nonce as previous before generating a new one.
			if ( ! empty( $nonce ) ) {
				\update_option( self::PREVIOUS_NONCE_OPTION, $nonce );
				\update_option( self::PREVIOUS_NONCE_EXPIRATION_OPTION, $current_time + self::NONCE_GRACE_PERIOD );
			}

			$nonce = self::generate_nonce();
			$expiration = $current_time + self::NONCE_LIFETIME;

			\update_option( self::NONCE_OPTION, $nonce );
			\update_option( self::NONCE_EXPIRATION_OPTION, $expiration );
		}

		return $nonce;
	}

	/**
	 * Generate a random nonce that's safe for use in URLs.
	 *
	 * @return string URL-safe random nonce.
	 */
	private static function generate_nonce() {
		// Generate password with only alphanumeric characters.
		return \wp_generate_password( 32, false, false );
	}

	/**
	 * Verify the provided nonce.
	 *
	 * @param string $nonce The nonce to verify.
	 * @return bool Whether the nonce is valid.
	 */
	public static function verify_nonce( $nonce ) {
		// Check against current nonce.
		$current_nonce = \get_option( self::NONCE_OPTION, '' );
		if ( $current_nonce === $nonce ) {
			return true;
		}

		// If current nonce doesn't match, check against previous nonce if within grace period.
		$previous_nonce = \get_option( self::PREVIOUS_NONCE_OPTION, '' );
		$previous_expiration = \get_option( self::PREVIOUS_NONCE_EXPIRATION_OPTION, 0 );

		if ( $previous_nonce === $nonce && time() <= $previous_expiration ) {
			return true;
		}

		return false;
	}

	/**
	 * Maybe handle an event.
	 */
	public static function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close(); // phpcs:ignore

		if ( ! isset( $_REQUEST['nonce'] ) || ! self::verify_nonce( \sanitize_text_field( $_REQUEST['nonce'] ) ) ) { // phpcs:ignore
			\wp_die();
		}

		$dispatches = isset( $_POST['dispatches'] ) ? $_POST['dispatches'] : null; // phpcs:ignore

		if ( empty( $dispatches ) || ! is_array( $dispatches ) ) {
			\wp_die();
		}

		foreach ( $dispatches as $dispatch ) {
			$action_name = isset( $dispatch['action_name'] ) ? \sanitize_text_field( $dispatch['action_name'] ) : null;
			if ( empty( $action_name ) || ! isset( self::$actions[ $action_name ] ) ) {
				continue;
			}

			$timestamp = isset( $dispatch['timestamp'] ) ? \sanitize_text_field( $dispatch['timestamp'] ) : null;
			$data      = isset( $dispatch['data'] ) ? $dispatch['data'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$client_id = isset( $dispatch['client_id'] ) ? \sanitize_text_field( $dispatch['client_id'] ) : null;

			self::handle( $action_name, $timestamp, $data, $client_id );
		}

		\wp_die();
	}

	/**
	 * Handle an event.
	 *
	 * @param string $action_name Action name.
	 * @param int    $timestamp   Timestamp.
	 * @param array  $data        Data.
	 * @param string $client_id   Client ID.
	 */
	public static function handle( $action_name, $timestamp, $data, $client_id ) {
		// Set current event.
		self::set_current_event( $action_name );

		// Execute global handlers.
		self::log( sprintf( 'Executing global action handlers for "%s".', $action_name ) );
		foreach ( self::$global_handlers as $handler ) {
			try {
				call_user_func( $handler, $action_name, $timestamp, $data, $client_id );
			} catch ( \Throwable $e ) {
				self::log( $e->getMessage(), 'error' );
				self::fire_handler_failed( $handler, $action_name, $data, $e );
				self::schedule_handler_retry( $handler, $action_name, $timestamp, $data, $client_id, true, 0, $e );
			}
		}

		// Execute action handlers.
		self::log( sprintf( 'Executing action handlers for "%s".', $action_name ) );
		$handlers = self::get_action_handlers( $action_name );
		foreach ( $handlers as $handler ) {
			try {
				call_user_func( $handler, $timestamp, $data, $client_id );
			} catch ( \Throwable $e ) {
				self::log( $e->getMessage(), 'error' );
				self::fire_handler_failed( $handler, $action_name, $data, $e );
				self::schedule_handler_retry( $handler, $action_name, $timestamp, $data, $client_id, false, 0, $e );
			}
		}

		/**
		 * Fires after all global and action-specific handlers have been executed.
		 *
		 * The dynamic portion of the hook name, `$action_name`, refers to the name
		 * of the action being fired.
		 *
		 * @param int    $timestamp   Timestamp.
		 * @param array  $data        Data.
		 * @param string $client_id   Client ID.
		 */
		\do_action( "newspack_data_event_{$action_name}", $timestamp, $data, $client_id );

		/**
		 * Fires after all global and action-specific handlers have been executed.
		 *
		 * @param string $action_name Action name.
		 * @param int    $timestamp   Timestamp.
		 * @param array  $data        Data.
		 * @param string $client_id   Client ID.
		 */
		\do_action( 'newspack_data_event', $action_name, $timestamp, $data, $client_id );

		// Unset current event.
		self::set_current_event( null );
	}

	/**
	 * Get the current event being handled.
	 *
	 * @return string|null Current event.
	 */
	public static function current_event() {
		return self::$current_event;
	}

	/**
	 * Set the current event being handled.
	 *
	 * @param string|null $name Event name.
	 */
	private static function set_current_event( $name ) {
		self::$current_event = $name;
	}

	/**
	 * Set the current ActionScheduler action ID.
	 *
	 * @param int $action_id The AS action ID.
	 */
	public static function set_current_as_action_id( $action_id ) {
		self::$current_as_action_id = $action_id;
	}

	/**
	 * Clear the current ActionScheduler action ID.
	 */
	public static function clear_current_as_action_id() {
		self::$current_as_action_id = null;
	}

	/**
	 * Register a triggerable action.
	 *
	 * @param string $action_name Action name.
	 *
	 * @return void|WP_Error Error if action already registered.
	 */
	public static function register_action( $action_name ) {
		if ( isset( self::$actions[ $action_name ] ) ) {
			return new WP_Error( 'action_already_registered', __( 'Action already registered.', 'newspack' ) );
		}
		self::$actions[ $action_name ] = [];
	}

	/**
	 * Register a handler for a triggerable action.
	 *
	 * Handler callback signature depends on the registration type:
	 *
	 * Action-specific handler (when $action_name is provided):
	 *   function( int $timestamp, array $data, string $client_id ): void
	 *
	 * Global handler (when $action_name is null):
	 *   function( string $action_name, int $timestamp, array $data, string $client_id ): void
	 *
	 * @param callable $handler     Action handler.
	 * @param string   $action_name Action name. If null, handler is called for all actions.
	 *
	 * @return void|WP_Error Error if action not registered, handler already registered or is not callable.
	 */
	public static function register_handler( $handler, $action_name = null ) {
		/** If there's no action name, treat as a global handler. */
		if ( empty( $action_name ) ) {
			self::$global_handlers[] = $handler;
			return;
		}

		$error = false;

		if ( ! isset( self::$actions[ $action_name ] ) ) {
			$error = new WP_Error( 'action_not_registered', __( 'Action not registered.', 'newspack' ) );
		}
		if ( ! is_callable( $handler ) ) {
			$error = new WP_Error( 'handler_not_callable', __( 'Handler is not callable.', 'newspack' ) );
		}

		if ( $error ) {
			self::log(
				sprintf(
					'ATTENTION: Data Event handler for action "%s" was not properly registered: %s',
					$action_name,
					implode( '; ', $error->get_error_messages() )
				)
			);

			return $error;
		}

		if ( in_array( $handler, self::$actions[ $action_name ], true ) ) {
			return new WP_Error( 'handler_already_registered', __( 'Handler already registered.', 'newspack' ) );
		}

		self::$actions[ $action_name ][] = $handler;
	}

	/**
	 * Register a listener so it dispatches an action when a WordPress hook is
	 * fired.
	 *
	 * @param string         $hook_name   WordPress hook name.
	 * @param string         $action_name Action name.
	 * @param callable|array $callable    Optional callable to filter the data
	 *                                    passed to dispatch or an array of
	 *                                    strings to map argument names.
	 */
	public static function register_listener( $hook_name, $action_name, $callable = null ) {
		self::register_action( $action_name );
		\add_action(
			$hook_name,
			function() use ( $action_name, $callable ) {
				$args = func_get_args();
				if ( is_callable( $callable ) ) {
					$data = call_user_func_array( $callable, $args );
				} elseif ( is_array( $callable ) ) {
					$data = [];
					foreach ( $callable as $i => $key ) {
						if ( isset( $args[ $i ] ) ) {
							$data[ $key ] = $args[ $i ];
						}
					}
				} else {
					$data = $args;
				}
				if ( ! empty( $data ) ) {
					self::dispatch( $action_name, $data );
				}
				return $args[0];
			},
			PHP_INT_MAX, // We want dispatches to be executed last so that any modified data is available.
			PHP_INT_MAX // The handler should receive all arguments of a hook.
		);
	}

	/**
	 * Get a list of all registered actions.
	 *
	 * @return string[] Registered actions.
	 */
	public static function get_actions() {
		return array_keys( self::$actions );
	}

	/**
	 * Get a list of all registered action handlers.
	 *
	 * @param string $action_name Action name.
	 *
	 * @return callable[] Registered action handlers.
	 */
	public static function get_action_handlers( $action_name ) {
		if ( ! isset( self::$actions[ $action_name ] ) ) {
			return [];
		}
		return self::$actions[ $action_name ];
	}

	/**
	 * Whether an action is registered.
	 *
	 * @param string $action_name The action name.
	 *
	 * @return bool
	 */
	public static function is_action_registered( $action_name ) {
		return isset( self::$actions[ $action_name ] );
	}

	/**
	 * Dispatch an action event.
	 *
	 * @param string  $action_name   Action name.
	 * @param array   $data          Data to pass to the action.
	 * @param boolean $use_client_id Whether to use the session's client ID. Default is true.
	 *
	 * @return void|WP_Error Error if action not registered.
	 */
	public static function dispatch( $action_name, $data, $use_client_id = true ) {
		if ( ! self::is_action_registered( $action_name ) ) {
			return new WP_Error( 'newspack_data_events_action_not_registered', __( 'Action not registered.', 'newspack' ) );
		}

		$timestamp = time();
		$client_id = null;
		if ( $use_client_id ) {
			$client_id = Reader_Activation::get_client_id();
		}

		/**
		 * Fires when an action is dispatched. This occurs before any handlers are
		 * executed.
		 *
		 * The dynamic portion of the hook name, `$action_name`, refers to the name
		 * of the action being fired.
		 *
		 * @param string $action_name Action name.
		 * @param int    $timestamp   Timestamp.
		 * @param array  $data        Data.
		 * @param string $client_id   Client ID.
		 */
		\do_action( "newspack_data_event_dispatch_{$action_name}", $timestamp, $data, $client_id );

		/**
		 * Fires when an action is dispatched. This occurs before any handlers are
		 * executed.
		 *
		 * @param string $action_name Action name.
		 * @param int    $timestamp   Timestamp.
		 * @param array  $data        Data.
		 * @param string $client_id   Client ID.
		 */
		\do_action( 'newspack_data_event_dispatch', $action_name, $timestamp, $data, $client_id );

		$body = [
			'action_name' => $action_name,
			'timestamp'   => $timestamp,
			'data'        => $data,
			'client_id'   => $client_id,
		];

		/**
		 * Filters the body of the action dispatch request. Return a WP_Error if you want to cancel the dispatch.
		 *
		 * @param array  $body        Body.
		 * @param string $action_name The action name.
		 */
		$body = apply_filters( 'newspack_data_events_dispatch_body', $body, $action_name );

		if ( is_wp_error( $body ) ) {
			self::log( sprintf( 'Error dispatching action "%s": %s', $action_name, implode( '; ', $body->get_error_messages() ) ) );
			return $body;
		}

		self::$queued_dispatches[] = $body;

		// If we're in shutdown, execute the dispatches immediately.
		if ( did_action( 'shutdown' ) ) {
			self::execute_queued_dispatches();
		}
	}

	/**
	 * Execute queued dispatches.
	 */
	public static function execute_queued_dispatches() {
		if ( empty( self::$queued_dispatches ) ) {
			return;
		}

		$actions = array_column( self::$queued_dispatches, 'action_name' );

		self::log( sprintf( 'Dispatching actions: "%s".', implode( ', ', $actions ) ) );

		if ( self::use_action_scheduler() ) {
			self::dispatch_via_action_scheduler();
		} else {
			self::dispatch_via_remote_post();
		}

		// Clear the queue.
		self::$queued_dispatches = [];
	}

	/**
	 * Get the ActionScheduler group for a handler.
	 *
	 * Returns a filterable default of 'newspack'. Integrations or other
	 * systems can filter this to assign handlers to specific groups.
	 *
	 * @param string $class       The handler class name.
	 * @param string $action_name The data event action name.
	 *
	 * @return string The ActionScheduler group name.
	 */
	public static function get_handler_action_group( $class, $action_name ) {
		/**
		 * Filters the ActionScheduler group for a data event handler.
		 *
		 * @param string $group       The group name. Default 'newspack'.
		 * @param string $class       The handler class name.
		 * @param string $action_name The data event action name.
		 */
		return \apply_filters( 'newspack_data_events_handler_action_group', Action_Scheduler::DEFAULT_GROUP, $class, $action_name );
	}

	/**
	 * Dispatch queued events via Action Scheduler.
	 *
	 * Each dispatch is scheduled as an individual AS action for independent
	 * processing, guaranteed delivery, and retry via the handler retry mechanism.
	 */
	private static function dispatch_via_action_scheduler() {
		\as_enqueue_async_action(
			self::DISPATCH_AS_HOOK,
			[ self::$queued_dispatches ],
			Action_Scheduler::DEFAULT_GROUP
		);

		self::log( sprintf( 'Scheduled %d dispatch(es) via Action Scheduler.', count( self::$queued_dispatches ) ) );

		/** This action is documented below in dispatch_via_remote_post(). */
		\do_action( 'newspack_data_events_dispatched', null, self::$queued_dispatches );
	}

	/**
	 * Dispatch queued events via non-blocking wp_remote_post().
	 *
	 * Fallback dispatch method when ActionScheduler is not available.
	 */
	private static function dispatch_via_remote_post() {
		$url = \add_query_arg(
			[
				'action' => self::ACTION,
				'nonce'  => self::get_nonce(),
			],
			\admin_url( 'admin-ajax.php' )
		);

		$request = \wp_remote_post(
			$url,
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'body'      => [ 'dispatches' => self::$queued_dispatches ],
				'cookies'   => $_COOKIE, // phpcs:ignore
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);

		/**
		 * Fires after dispatching queued actions.
		 *
		 * @param WP_Error|\WP_HTTP_Response|null $request           The request object, or null when using Action Scheduler.
		 * @param array                           $queued_dispatches The queued dispatches.
		 */
		\do_action( 'newspack_data_events_dispatched', $request, self::$queued_dispatches );
	}

	/**
	 * Check whether a handler can be serialized for ActionScheduler storage.
	 *
	 * Only static method arrays (e.g. [ClassName::class, 'method']) and string
	 * function names are serializable. Closures and object-method arrays are not.
	 *
	 * @param callable $handler The handler to check.
	 *
	 * @return bool
	 */
	private static function is_handler_serializable( $handler ) {
		if ( is_array( $handler ) && 2 === count( $handler ) && is_string( $handler[0] ) && is_string( $handler[1] ) ) {
			return true;
		}
		if ( is_string( $handler ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Fire the handler failed action for the original failure (before retries).
	 *
	 * @param callable   $handler     The handler that failed.
	 * @param string     $action_name Action name.
	 * @param array      $data        Event data.
	 * @param \Throwable $error       The error that caused the failure.
	 */
	private static function fire_handler_failed( $handler, $action_name, $data, $error ) {
		/**
		 * Fires when a data event handler fails on the original attempt (before retries).
		 *
		 * Used by Alert_Manager to record failures for early pattern detection.
		 *
		 * @param array $failure_data {
		 *     Failure data.
		 *
		 *     @type callable $handler     The handler that failed.
		 *     @type string   $action_name The data event action name.
		 *     @type array    $data        The event data.
		 *     @type string   $reason      The error message.
		 * }
		 */
		do_action(
			'newspack_data_event_handler_failed',
			[
				'handler'     => $handler,
				'action_name' => $action_name,
				'data'        => $data,
				'reason'      => $error->getMessage(),
			]
		);
	}

	/**
	 * Schedule a retry for a failed handler via ActionScheduler.
	 *
	 * @param callable   $handler     The handler that failed.
	 * @param string     $action_name Action name.
	 * @param int        $timestamp   Event timestamp.
	 * @param array      $data        Event data.
	 * @param string     $client_id   Client ID.
	 * @param bool       $is_global   Whether this is a global handler.
	 * @param int        $retry_count Current retry count (0 = first failure).
	 * @param \Throwable $error       The error that caused the failure.
	 */
	private static function schedule_handler_retry( $handler, $action_name, $timestamp, $data, $client_id, $is_global, $retry_count, $error ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( ! self::is_handler_serializable( $handler ) ) {
			self::log( sprintf( 'Handler for "%s" is not serializable, cannot schedule retry.', $action_name ) );
			return;
		}

		$next_retry = $retry_count + 1;
		if ( $next_retry > self::MAX_HANDLER_RETRIES ) {
			self::log(
				sprintf(
					'Max retries (%d) reached for handler on "%s". Giving up. Last error: %s',
					self::MAX_HANDLER_RETRIES,
					$action_name,
					$error->getMessage()
				),
				'error'
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					sprintf( 'Max retries exhausted. Final error: %s', $error->getMessage() )
				);
			}
			/**
			 * Fires when a Data Events handler has exhausted all retry attempts.
			 *
			 * @param array $alert_data {
			 *     Alert data.
			 *
			 *     @type array  $handler       The handler callable.
			 *     @type string $action_name   The data event action name.
			 *     @type array  $data          The event data.
			 *     @type int    $retry_count   Total retries attempted.
			 *     @type string $reason        The final error message.
			 * }
			 */
			do_action(
				'newspack_data_event_retry_exhausted',
				[
					'handler'     => $handler,
					'action_name' => $action_name,
					'data'        => $data,
					'retry_count' => self::MAX_HANDLER_RETRIES,
					'reason'      => $error->getMessage(),
				]
			);
			return;
		}

		$backoff_index   = min( $retry_count, count( self::RETRY_BACKOFF ) - 1 );
		$backoff_seconds = self::RETRY_BACKOFF[ $backoff_index ];

		$retry_data = [
			'handler'     => $handler,
			'action_name' => $action_name,
			'timestamp'   => $timestamp,
			'data'        => $data,
			'client_id'   => $client_id,
			'is_global'   => $is_global,
			'retry_count' => $next_retry,
			'reason'      => $error->getMessage(),
		];

		$group = self::get_handler_action_group(
			is_array( $handler ) ? $handler[0] : '',
			$action_name
		);

		$action_id = \as_schedule_single_action(
			time() + $backoff_seconds,
			self::HANDLER_RETRY_HOOK,
			[ $retry_data ],
			$group
		);

		if ( $action_id ) {
			\ActionScheduler_Logger::instance()->log(
				$action_id,
				sprintf( 'Failure reason: %s', $error->getMessage() )
			);
		}

		self::log(
			sprintf(
				'Scheduled retry %d/%d for handler on "%s" in %ds. Error: %s',
				$next_retry,
				self::MAX_HANDLER_RETRIES,
				$action_name,
				$backoff_seconds,
				$error->getMessage()
			)
		);
	}

	/**
	 * Execute a handler retry from ActionScheduler.
	 *
	 * @param array $retry_data The retry data containing handler, event data, and retry count.
	 */
	public static function execute_handler_retry( $retry_data ) {
		if ( ! is_array( $retry_data ) || empty( $retry_data['handler'] ) || empty( $retry_data['action_name'] ) ) {
			self::log( 'Invalid retry data received from Action Scheduler.', 'error' );
			return;
		}

		$handler     = $retry_data['handler'];
		$action_name = $retry_data['action_name'];
		$timestamp   = $retry_data['timestamp'] ?? null;
		$data        = $retry_data['data'] ?? null;
		$client_id   = $retry_data['client_id'] ?? null;
		$is_global   = $retry_data['is_global'] ?? false;
		$retry_count = $retry_data['retry_count'] ?? 1;

		if ( ! is_callable( $handler ) ) {
			self::log( sprintf( 'Handler for "%s" is no longer callable on retry %d.', $action_name, $retry_count ), 'error' );
			return;
		}

		self::log( sprintf( 'Executing retry %d/%d for handler on "%s".', $retry_count, self::MAX_HANDLER_RETRIES, $action_name ) );

		try {
			self::set_current_event( $action_name );
			if ( $is_global ) {
				call_user_func( $handler, $action_name, $timestamp, $data, $client_id );
			} else {
				call_user_func( $handler, $timestamp, $data, $client_id );
			}
			self::log( sprintf( 'Retry %d succeeded for handler on "%s".', $retry_count, $action_name ) );
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'Retry %d failed for handler on "%s": %s', $retry_count, $action_name, $e->getMessage() ), 'error' );
			self::schedule_handler_retry( $handler, $action_name, $timestamp, $data, $client_id, $is_global, $retry_count, $e );
		} finally {
			self::set_current_event( null );
		}
	}
}
Data_Events::init();
