<?php
/**
 * Contact Pull class
 *
 * Handles pulling contact data from active integrations,
 * with retry logic via ActionScheduler.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Reader_Activation\Integrations;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Pull Class.
 */
class Contact_Pull {

	/**
	 * Threshold in seconds (24 hours) for synchronous vs async pull.
	 *
	 * If the last pull is older than this, the pull runs synchronously.
	 * Otherwise it is queued for the next cron run.
	 *
	 * @var int
	 */
	const PULL_SYNC_THRESHOLD = 86400;

	/**
	 * AJAX action name for the loopback pull endpoint.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'newspack_pull_integration';

	/**
	 * Nonce action name for the loopback pull endpoint.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'newspack_pull_integration_nonce';

	/**
	 * ActionScheduler hook for retrying a failed integration pull.
	 */
	const RETRY_HOOK = 'newspack_contact_pull_retry';

	/**
	 * Maximum number of retries for a failed integration pull.
	 */
	const MAX_RETRIES = 5;

	/**
	 * Backoff schedule in seconds for integration pull retries.
	 * 30s, 2min, 8min, 30min, 2h.
	 */
	const RETRY_BACKOFF = [ 30, 120, 480, 1800, 7200 ];

	/**
	 * Logger header for Contact Pull messages.
	 *
	 * @var string
	 */
	const LOGGER_HEADER = 'NEWSPACK-CONTACT-PULL';

	/**
	 * Initialize hooks.
	 */
	public static function init_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'handle_ajax_pull' ] );
		add_action( self::RETRY_HOOK, [ __CLASS__, 'execute_integration_retry' ] );
		add_filter( 'newspack_action_scheduler_hook_labels', [ __CLASS__, 'register_hook_labels' ] );
	}

	/**
	 * Register hook labels for Contact Pull actions.
	 *
	 * @param array $labels Existing labels.
	 * @return array
	 */
	public static function register_hook_labels( $labels ) {
		$labels[ self::RETRY_HOOK ] = __( 'Contact Pull Retry', 'newspack-plugin' );
		return $labels;
	}

	/**
	 * Get the timeout for loopback pull requests.
	 *
	 * @return int Timeout in seconds.
	 */
	private static function get_pull_request_timeout() {
		/**
		 * Newspack Integrations: Filter the max amount of time (in seconds) to allow for a synchronous contact metadata pull request before falling back to async scheduling.
		 */
		return apply_filters( 'newspack_pull_integration_request_timeout', 1 );
	}

	/**
	 * Whether the timestamp is stale (older than PULL_SYNC_THRESHOLD).
	 *
	 * @param int $timestamp Timestamp.
	 * @return bool True if the timestamp is stale.
	 */
	public static function is_stale( $timestamp ) {
		return ( time() - $timestamp ) >= self::PULL_SYNC_THRESHOLD;
	}

	/**
	 * Run synchronous pull for the current user via per-integration loopback requests.
	 *
	 * Each integration is pulled via a blocking wp_remote_post to the AJAX
	 * endpoint. Returns WP_Error if any integration fails, so the caller
	 * can enqueue the user for the next cron batch.
	 *
	 * @param \Newspack\Reader_Activation\Integration[] $integrations Active integrations to pull from. Defaults to all active integrations.
	 * @return true|\WP_Error True if all succeeded, WP_Error with combined messages.
	 */
	public static function pull_sync( $integrations = [] ) {
		if ( empty( $integrations ) ) {
			$integrations = Integrations::get_active_integrations();
		}

		Logger::log( 'Synchronous pull started for user "' . get_current_user_id() . '".', self::LOGGER_HEADER );
		$errors = [];

		foreach ( $integrations as $id => $integration ) {
			$selected_fields = $integration->get_enabled_incoming_fields();
			if ( empty( $selected_fields ) ) {
				continue;
			}

			$response = self::fire_pull_request( $id );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$error_message = is_wp_error( $response ) ? $response->get_error_message() : 'Unexpected response code: ' . wp_remote_retrieve_response_code( $response );
				Logger::log( 'Loopback pull failed for ' . $id . '. Error: ' . $error_message, self::LOGGER_HEADER );
				$errors[] = sprintf( '[%s] %s', $id, $error_message );
			} else {
				Logger::log( 'Loopback pull succeeded for ' . $id . '.', self::LOGGER_HEADER );
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'newspack_sync_pull_failed', implode( '; ', $errors ) );
		}

		return true;
	}

	/**
	 * Pull all active integrations for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|\WP_Error True if all succeeded, WP_Error with combined messages.
	 */
	public static function pull_all( $user_id ) {
		$active_integrations = Integrations::get_active_integrations();
		$errors              = [];

		foreach ( $active_integrations as $integration ) {
			$selected_fields = $integration->get_enabled_incoming_fields();
			if ( empty( $selected_fields ) ) {
				continue;
			}
			$result = self::pull_single_integration( $user_id, $integration );
			if ( is_wp_error( $result ) ) {
				self::schedule_integration_retry( $integration->get_id(), $user_id, 0, $result );
				$errors[] = sprintf( '[%s] %s', $integration->get_id(), $result->get_error_message() );
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'newspack_contact_pull_failed', implode( '; ', $errors ) );
		}

		return true;
	}

	/**
	 * Fire a blocking loopback request to pull data for a single integration.
	 *
	 * @param string $integration_id The integration identifier.
	 * @return array|\WP_Error The response or WP_Error on failure.
	 */
	private static function fire_pull_request( $integration_id ) {
		$url = add_query_arg(
			[
				'action' => self::AJAX_ACTION,
				'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
			],
			admin_url( 'admin-ajax.php' )
		);

		return wp_remote_post(
			$url,
			[
				'timeout'   => self::get_pull_request_timeout(),
				'blocking'  => true,
				'body'      => [ 'integration_id' => $integration_id ],
				'cookies'   => $_COOKIE, // phpcs:ignore
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			]
		);
	}

	/**
	 * Handle the AJAX loopback request for pulling a single integration.
	 */
	public static function handle_ajax_pull() {
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_REQUEST['nonce'] ), self::NONCE_ACTION ) ) { // phpcs:ignore
			wp_send_json_error( 'Invalid nonce.', 403 );
		}

		$integration_id = isset( $_POST['integration_id'] ) ? sanitize_text_field( $_POST['integration_id'] ) : ''; // phpcs:ignore
		if ( empty( $integration_id ) ) {
			wp_send_json_error( 'Missing integration_id.', 400 );
		}

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration || ! Integrations::is_enabled( $integration_id ) ) {
			wp_send_json_error( 'Integration not found or not enabled.', 404 );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'No user context.', 403 );
		}

		$result = self::pull_single_integration( $user_id, $integration );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * Pull data from a single integration and store selected fields.
	 *
	 * @param int                                     $user_id     WordPress user ID.
	 * @param \Newspack\Reader_Activation\Integration $integration The integration instance.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function pull_single_integration( $user_id, $integration ) {
		$selected_fields = $integration->get_enabled_incoming_fields();
		if ( empty( $selected_fields ) ) {
			return new \WP_Error( 'no_selected_incoming_fields', 'No selected incoming fields for ' . $integration->get_id() );
		}

		try {
			$data = $integration->pull_contact_data( $user_id );

			if ( is_wp_error( $data ) ) {
				Logger::log( 'Pull error from ' . $integration->get_id() . ': ' . $data->get_error_message(), self::LOGGER_HEADER );
				return $data;
			}

			$selected_keys = array_flip(
				array_map(
					function( $field ) {
						return $field->get_key();
					},
					$selected_fields
				)
			);
			$data          = array_intersect_key( $data, $selected_keys );
			Logger::log( 'Pulled data from ' . $integration->get_id() . ': ' . wp_json_encode( $data ) );

			foreach ( $data as $key => $value ) {
				\Newspack\Reader_Data::update_item( $user_id, $key, wp_json_encode( $value ) );
			}

			return true;
		} catch ( \Throwable $e ) {
			Logger::log( 'Pull exception from ' . $integration->get_id() . ': ' . $e->getMessage(), self::LOGGER_HEADER );
			return new \WP_Error( 'pull_exception', $e->getMessage() );
		}
	}

	/**
	 * Get the set of user IDs with pending pull retries in ActionScheduler.
	 *
	 * Useful for batch processing: fetch once, then check membership with isset()
	 * instead of calling has_pending_retries() per user.
	 *
	 * @return array<int, bool> Map keyed by user ID for O(1) lookup.
	 */
	public static function get_pending_retry_user_ids() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}
		$actions = \as_get_scheduled_actions(
			[
				'hook'     => self::RETRY_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			]
		);
		$user_ids = [];
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			if ( ! empty( $args[0]['user_id'] ) ) {
				$user_ids[ (int) $args[0]['user_id'] ] = true;
			}
		}
		return $user_ids;
	}

	/**
	 * Check if a user has any pending pull retries in ActionScheduler.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if there are pending retries.
	 */
	public static function has_pending_retries( $user_id ) {
		return isset( self::get_pending_retry_user_ids()[ (int) $user_id ] );
	}

	/**
	 * Schedule a retry for a failed integration pull via ActionScheduler.
	 *
	 * @param string           $integration_id The integration ID.
	 * @param int              $user_id        The WordPress user ID.
	 * @param int              $retry_count    Current retry count (0 = first failure).
	 * @param string|\WP_Error $error          The error from the failure.
	 */
	private static function schedule_integration_retry( $integration_id, $user_id, $retry_count, $error ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$user = ! empty( $user_id ) ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			Logger::log( sprintf( 'Cannot schedule pull retry for integration "%s": user %d not found.', $integration_id, $user_id ), self::LOGGER_HEADER );
			return;
		}

		$error_message = $error instanceof \WP_Error ? $error->get_error_message() : (string) $error;

		$next_retry = $retry_count + 1;
		if ( $next_retry > self::MAX_RETRIES ) {
			Logger::log(
				sprintf(
					'Max pull retries (%d) reached for integration "%s" of user %d. Giving up. Last error: %s',
					self::MAX_RETRIES,
					$integration_id,
					$user_id,
					$error_message
				),
				self::LOGGER_HEADER
			);
			return;
		}

		$backoff_index   = min( $retry_count, count( self::RETRY_BACKOFF ) - 1 );
		$backoff_seconds = self::RETRY_BACKOFF[ $backoff_index ];

		$retry_data = [
			'integration_id' => $integration_id,
			'user_id'        => $user_id,
			'retry_count'    => $next_retry,
			'max_retries'    => self::MAX_RETRIES,
			'reason'         => $error_message,
		];

		\as_schedule_single_action(
			time() + $backoff_seconds,
			self::RETRY_HOOK,
			[ $retry_data ],
			Integrations::get_action_group( $integration_id )
		);

		Logger::log(
			sprintf(
				'Scheduled pull retry %d/%d for integration "%s" of user %d in %ds. Error: %s',
				$next_retry,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$backoff_seconds,
				$error_message
			),
			self::LOGGER_HEADER
		);
	}

	/**
	 * Execute an integration pull retry from ActionScheduler.
	 *
	 * @param array $retry_data The retry data.
	 *
	 * @throws \Exception When the final retry fails, so ActionScheduler marks the action as "failed".
	 */
	public static function execute_integration_retry( $retry_data ) {
		if ( ! is_array( $retry_data ) || empty( $retry_data['integration_id'] ) || empty( $retry_data['user_id'] ) ) {
			Logger::log( 'Invalid pull retry data received from Action Scheduler.', self::LOGGER_HEADER, 'error' );
			return;
		}

		$integration_id = $retry_data['integration_id'];
		$user_id        = $retry_data['user_id'];
		$retry_count    = $retry_data['retry_count'] ?? 1;

		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			Logger::log( sprintf( 'User %d not found on pull retry %d.', $user_id, $retry_count ), self::LOGGER_HEADER, 'error' );
			return;
		}

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration || ! Integrations::is_enabled( $integration_id ) ) {
			Logger::log( sprintf( 'Integration "%s" not found or not enabled on pull retry %d.', $integration_id, $retry_count ), self::LOGGER_HEADER, 'error' );
			return;
		}

		Logger::log( sprintf( 'Executing pull retry %d/%d for integration "%s" of user %d.', $retry_count, self::MAX_RETRIES, $integration_id, $user_id ), self::LOGGER_HEADER );

		$result = self::pull_single_integration( $user_id, $integration );
		if ( is_wp_error( $result ) ) {
			$error_message = sprintf(
				'Pull retry %d/%d failed for integration "%s" of user %d: %s',
				$retry_count,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$result->get_error_message()
			);
			Logger::log( $error_message, self::LOGGER_HEADER );
			self::schedule_integration_retry( $integration_id, $user_id, $retry_count, $result );

			if ( $retry_count >= self::MAX_RETRIES ) {
				throw new \Exception( esc_html( $error_message ) );
			}
		} else {
			Logger::log(
				sprintf(
					'Pull retry %d/%d succeeded for integration "%s" of user %d.',
					$retry_count,
					self::MAX_RETRIES,
					$integration_id,
					$user_id
				),
				self::LOGGER_HEADER
			);
		}
	}
}
Contact_Pull::init_hooks();
