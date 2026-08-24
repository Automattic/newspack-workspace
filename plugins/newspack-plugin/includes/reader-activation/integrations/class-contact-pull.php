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
			$integrations = Integrations::get_active_configured_integrations();
		}

		Logger::log( 'Synchronous pull started for user "' . get_current_user_id() . '".', self::LOGGER_HEADER );
		$errors = [];

		foreach ( $integrations as $id => $integration ) {
			// Skip integrations without an (enabled) pull: pausing the inbound
			// toggle stops pulls while the stored incoming-field selection waits
			// for re-enable, and pull-less integrations have nothing to pull from.
			if ( ! $integration->is_pull_enabled() ) {
				continue;
			}

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
		$active_integrations = Integrations::get_active_configured_integrations();
		$errors              = [];

		foreach ( $active_integrations as $integration ) {
			if ( ! $integration->is_pull_enabled() ) {
				continue;
			}
			$selected_fields = $integration->get_enabled_incoming_fields();
			if ( empty( $selected_fields ) ) {
				continue;
			}
			$result = self::pull_single_integration( $user_id, $integration, false, $selected_fields );
			if ( is_wp_error( $result ) ) {
				if ( self::is_permanent_pull_error( $result ) ) {
					Logger::log( sprintf( 'Not scheduling pull retries for integration "%s" of user %d — the failure is permanent: %s', $integration->get_id(), $user_id, $result->get_error_message() ), self::LOGGER_HEADER );
				} else {
					self::schedule_integration_retry( $integration->get_id(), $user_id, 0, $result );
				}
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

		// Defense-in-depth: pull_sync already filters to set-up integrations,
		// but a direct AJAX call could still arrive here for an unconfigured
		// integration. Skip silently with success — "not set up" is a no-op,
		// not an error worth surfacing to the loopback caller.
		if ( ! $integration->is_set_up() ) {
			wp_send_json_success();
		}

		// Same defense-in-depth for a paused or pull-less integration.
		if ( ! $integration->is_pull_enabled() ) {
			wp_send_json_success();
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
	 * A pull whose reader-data writes fail returns a WP_Error: fetching alone
	 * does not count as success, so batch callers tally the reader as an error.
	 * The organic path schedules its usual bounded retries for transient
	 * failures, but not for permanent ones (see is_permanent_pull_error()).
	 *
	 * @param int                                     $user_id         WordPress user ID.
	 * @param \Newspack\Reader_Activation\Integration $integration     The integration instance.
	 * @param bool                                    $dry_run         Optional. When true, fetch and filter but skip
	 *                                                                 persistence, logging the would-be writes instead.
	 *                                                                 The external read still happens. Default false.
	 * @param Incoming_Field[]|null                   $selected_fields Optional. Pre-resolved enabled incoming fields.
	 *                                                                 Batch callers resolve once per integration and
	 *                                                                 pass them in: resolution may hit the provider's
	 *                                                                 API on legacy-shaped settings, so re-resolving
	 *                                                                 per reader multiplies external requests. Default
	 *                                                                 null (resolve here).
	 * @param string[]                                $pending_keys    Optional. Dry-run only, by reference. Keys a
	 *                                                                 preview has already accepted for this reader but
	 *                                                                 not persisted; appended to as more are accepted.
	 *                                                                 A caller pulling the same reader from several
	 *                                                                 integrations passes one array through all of
	 *                                                                 them, so the preview sees the key list a wet run
	 *                                                                 would have grown. Ignored outside a dry run,
	 *                                                                 where real writes make it observable anyway.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function pull_single_integration( $user_id, $integration, $dry_run = false, $selected_fields = null, &$pending_keys = [] ) {
		if ( null === $selected_fields ) {
			$selected_fields = $integration->get_enabled_incoming_fields();
		}
		if ( empty( $selected_fields ) ) {
			return new \WP_Error( 'no_selected_incoming_fields', 'No selected incoming fields for ' . $integration->get_id() );
		}

		try {
			$data = $integration->pull_contact_data( $user_id );

			if ( is_wp_error( $data ) ) {
				Logger::log( 'Pull error from ' . $integration->get_id() . ': ' . $data->get_error_message(), self::LOGGER_HEADER );
				return $data;
			}

			// A provider that returns a non-array payload cannot be filtered or
			// stored. Reject it explicitly instead of letting array_intersect_key()
			// raise a TypeError that the catch below would misclassify as a
			// transient exception and retry five times.
			if ( ! is_array( $data ) ) {
				return new \WP_Error(
					'invalid_pull_payload',
					sprintf( 'Integration "%s" returned a %s instead of an array of contact data.', $integration->get_id(), gettype( $data ) )
				);
			}

			$selected_keys = array_flip(
				array_map(
					function( $field ) {
						return $field->get_key();
					},
					$selected_fields
				)
			);
			$fetched_keys  = array_keys( $data );
			$data          = array_intersect_key( $data, $selected_keys );
			// Keys only: the values are reader data, and a full backfill runs this
			// line once per reader across the whole base.
			Logger::log( 'Pulled data from ' . $integration->get_id() . ': ' . wp_json_encode( array_keys( $data ) ) );

			// A pull that stores nothing is a legitimate outcome — the contact may
			// simply have no values — but it is indistinguishable from a broken read
			// path, which is how an ESP pull returning the wrong payload key went
			// unnoticed. Name the reason so an operator can tell "this reader has no
			// data" from "this integration handed us keys we don't recognise".
			if ( empty( $data ) ) {
				Logger::log(
					sprintf(
						'Nothing to store for user %d from %s: the provider returned %s, none matching the %d enabled incoming field(s) (%s).',
						$user_id,
						$integration->get_id(),
						empty( $fetched_keys ) ? 'no fields' : sprintf( '%d field(s) (%s)', count( $fetched_keys ), implode( ', ', $fetched_keys ) ),
						count( $selected_keys ),
						implode( ', ', array_keys( $selected_keys ) )
					),
					self::LOGGER_HEADER
				);
			}

			// Additive by design: only keys present in the payload are written, and
			// nothing deletes a stored key the provider stopped reporting — a field
			// cleared upstream (or filtered out as empty) leaves the previously
			// stored value in place, and consumers reading stored values (access
			// rules, segmentation) keep matching it. See the README's Pull section.
			$write_errors = [];
			foreach ( $data as $key => $value ) {
				$encoded = wp_json_encode( $value );

				if ( $dry_run ) {
					// Both of update_item()'s rejection causes are deterministic, so
					// the preview can report them without persisting — an operator
					// green-lighting a run deserves to see writes that are guaranteed
					// to fail. Keys already accepted are threaded through as pending:
					// nothing is written, so without them a set of new keys that
					// collectively crosses the cap would preview clean and then fail
					// for real. $pending_keys spans the caller's whole pull of this
					// reader, so keys accepted by an earlier integration count too —
					// in a wet run those writes would have landed before this one.
					$would_store = \Newspack\Reader_Data::validate_item( $user_id, $key, $encoded, $pending_keys );
					if ( is_wp_error( $would_store ) ) {
						Logger::log( sprintf( '[dry-run] Would FAIL storing reader data "%s" for user %d: %s', $key, $user_id, $would_store->get_error_message() ), self::LOGGER_HEADER );
						$write_errors[] = sprintf( '"%s": %s', $key, $would_store->get_error_message() );
						continue;
					}
					$pending_keys[] = $key;
					Logger::log( sprintf( '[dry-run] Would store reader data "%s" for user %d.', $key, $user_id ), self::LOGGER_HEADER );
					continue;
				}

				$stored = \Newspack\Reader_Data::update_item( $user_id, $key, $encoded );
				if ( is_wp_error( $stored ) ) {
					Logger::log( sprintf( 'Failed storing reader data "%s" for user %d: %s', $key, $user_id, $stored->get_error_message() ), self::LOGGER_HEADER );
					$write_errors[] = sprintf( '"%s": %s', $key, $stored->get_error_message() );
				}
			}

			// A pull that fetched but could not persist is a failed pull: callers
			// tally or retry on WP_Error, and the CLI backfill's recovery model
			// (re-run the window for tallied errors) needs write failures visible
			// rather than counted as processed.
			if ( ! empty( $write_errors ) ) {
				return new \WP_Error(
					'reader_data_write_failed',
					sprintf(
						'Failed storing %1$d of %2$d reader data item(s) for user %3$d from %4$s: %5$s',
						count( $write_errors ),
						count( $data ),
						$user_id,
						$integration->get_id(),
						implode( '; ', $write_errors )
					)
				);
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
	 * Pull error codes that no retry can resolve.
	 *
	 * `reader_data_write_failed` — Reader_Data::update_item() rejections are
	 * validation-level and deterministic (`invalid_value` for an empty
	 * sanitized value, `too_many_items` at the per-reader key cap), so a retry
	 * would re-fetch from the provider only to fail the exact same write again.
	 * `no_selected_incoming_fields` — a configuration state, not a failure the
	 * provider can recover from. `invalid_pull_payload` — the integration's own
	 * return type is wrong, which no amount of waiting fixes.
	 *
	 * Fetch-side errors (network, provider 5xx/429) stay retryable.
	 *
	 * @var string[]
	 */
	const PERMANENT_PULL_ERRORS = [
		'reader_data_write_failed',
		'no_selected_incoming_fields',
		'invalid_pull_payload',
	];

	/**
	 * Whether a pull failure is permanent — retrying cannot succeed.
	 *
	 * @param \WP_Error|mixed $error The pull result.
	 * @return bool True when the error can never be resolved by retrying.
	 */
	private static function is_permanent_pull_error( $error ) {
		return $error instanceof \WP_Error && in_array( $error->get_error_code(), self::PERMANENT_PULL_ERRORS, true );
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
	 * @throws \Exception When the final retry fails — or the failure is permanent and
	 *                    further retries cannot succeed — so ActionScheduler marks the
	 *                    action as "failed".
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

		if ( ! $integration->is_set_up() ) {
			Logger::log( sprintf( 'Integration "%s" no longer set up on pull retry %d; aborting retry chain.', $integration_id, $retry_count ), self::LOGGER_HEADER );
			return;
		}

		// Checked before resolving fields: resolution may hit the provider's API
		// on legacy-shaped settings, and a paused inbound toggle means we have no
		// business calling out at all.
		if ( ! $integration->is_pull_enabled() ) {
			Logger::log( sprintf( 'Inbound sync disabled for integration "%s" on pull retry %d; aborting retry chain.', $integration_id, $retry_count ), self::LOGGER_HEADER );
			return;
		}

		// Fields disabled mid-chain is a configuration change, not a failure:
		// end the chain quietly like the is_set_up() guard above, rather than
		// letting the pull return no_selected_incoming_fields and fail the
		// ActionScheduler action. Resolved once here and threaded in, since
		// resolution may hit the provider's API on legacy-shaped settings.
		$selected_fields = $integration->get_enabled_incoming_fields();
		if ( empty( $selected_fields ) ) {
			Logger::log( sprintf( 'Integration "%s" has no enabled incoming fields on pull retry %d; aborting retry chain.', $integration_id, $retry_count ), self::LOGGER_HEADER );
			return;
		}

		Logger::log( sprintf( 'Executing pull retry %d/%d for integration "%s" of user %d.', $retry_count, self::MAX_RETRIES, $integration_id, $user_id ), self::LOGGER_HEADER );

		$result = self::pull_single_integration( $user_id, $integration, false, $selected_fields );
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

			// A permanent failure (e.g. the reader-data write is rejected by
			// validation) fails identically on every attempt: end the chain now
			// so ActionScheduler marks the action failed, instead of burning the
			// remaining retries on provider re-fetches that cannot succeed.
			if ( self::is_permanent_pull_error( $result ) ) {
				throw new \Exception( esc_html( $error_message ) );
			}

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
