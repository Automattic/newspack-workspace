<?php
/**
 * Reader contact data syncing with the active integrations.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;
use Newspack\Data_Events;
use Newspack\Logger;
use Newspack\Reader_Activation\Sync\Metadata;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Sync Class.
 */
class Contact_Sync extends Sync {
	/**
	 * Context of the sync.
	 *
	 * @var string
	 */
	protected static $context = 'Contact Sync';

	/**
	 * Queued syncs containing their contexts keyed by email address.
	 *
	 * @var array[]
	 */
	protected static $queued_syncs = [];

	/**
	 * The ID of the currently-executing ActionScheduler action.
	 *
	 * @var int|null
	 */
	private static $current_as_action_id = null;

	/**
	 * ActionScheduler hook for retrying a failed integration sync.
	 */
	const RETRY_HOOK = 'newspack_contact_sync_retry';

	/**
	 * Maximum number of retries for a failed integration sync.
	 */
	const MAX_RETRIES = 5;

	/**
	 * Backoff schedule in seconds for integration sync retries.
	 * 30s, 2min, 8min, 30min, 2h.
	 */
	const RETRY_BACKOFF = [ 30, 120, 480, 1800, 7200 ];

	/**
	 * Initialize hooks.
	 */
	public static function init_hooks() {
		add_action( 'newspack_scheduled_esp_sync', [ __CLASS__, 'scheduled_sync' ], 10, 2 );
		add_action( 'shutdown', [ __CLASS__, 'run_queued_syncs' ] );
		add_action( self::RETRY_HOOK, [ __CLASS__, 'execute_integration_retry' ] );
		add_action( 'action_scheduler_begin_execute', [ __CLASS__, 'set_current_as_action_id' ] );
		add_action( 'action_scheduler_after_execute', [ __CLASS__, 'clear_current_as_action_id' ] );
		add_filter( 'newspack_action_scheduler_hook_labels', [ __CLASS__, 'register_hook_labels' ] );
	}

	/**
	 * Register hook labels for Contact Sync actions.
	 *
	 * @param array $labels Existing labels.
	 * @return array
	 */
	public static function register_hook_labels( $labels ) {
		$labels[ self::RETRY_HOOK ] = __( 'Contact Sync Retry', 'newspack-plugin' );
		return $labels;
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
	 * Sync contact to the ESP.
	 *
	 * @param array  $contact          The contact data to sync.
	 * @param string $context          The context of the sync. Defaults to static::$context.
	 * @param array  $existing_contact Optional. Existing contact data to merge with. Defaults to null.
	 *
	 * @return true|\WP_Error True if succeeded or WP_Error.
	 */
	public static function sync( $contact, $context = '', $existing_contact = null ) {
		$can_sync = static::can_sync( true );
		if ( $can_sync->has_errors() ) {
			return $can_sync;
		}

		if ( empty( $context ) ) {
			$context = static::$context;
		}

		// If we're running in a data event, queue the sync to run on shutdown.
		if ( Data_Events::current_event() ) {
			if ( ! isset( self::$queued_syncs[ $contact['email'] ] ) ) {
				self::$queued_syncs[ $contact['email'] ] = [
					'contexts'     => [],
					'contact'      => [],
					'as_action_id' => self::$current_as_action_id,
				];
			}
			if ( ! empty( self::$queued_syncs[ $contact['email'] ]['contact']['metadata'] ) ) {
				$contact['metadata'] = array_merge( self::$queued_syncs[ $contact['email'] ]['contact']['metadata'], $contact['metadata'] );
			}
			self::$queued_syncs[ $contact['email'] ]['contexts'][] = $context;
			self::$queued_syncs[ $contact['email'] ]['contact']    = $contact;
			if ( ! did_action( 'shutdown' ) ) {
				return true;
			}
		}

		// Added logging here to more easily monitor integration sync data. Can be removed once integrations are released.
		if ( 'legacy' !== Metadata::get_version() ) {
			Logger::log( sprintf( 'Syncing contact %s for context "%s".', $contact['email'] ?? 'unknown', $context ) );
			Logger::log( $contact );
		}

		return self::push_to_integrations( $contact, $context, $existing_contact );
	}

	/**
	 * Push contact data to all active integrations.
	 *
	 * Failed integrations are scheduled for retry via ActionScheduler
	 * with exponential backoff.
	 *
	 * @param array  $contact          The contact data to sync.
	 * @param string $context          The context of the sync.
	 * @param array  $existing_contact Optional. Existing contact data to merge with.
	 *
	 * @return true|\WP_Error True if all succeeded, or WP_Error with combined messages.
	 */
	private static function push_to_integrations( $contact, $context, $existing_contact = null ) {
		/**
		 * Filters the contact data before syncing to the integration, allowing modifications or additions to the contact data.
		 *
		 * @param array  $contact The contact data to sync.
		 * @param string $context The context of the sync.
		 */
		$contact = \apply_filters( 'newspack_esp_sync_contact', $contact, $context );
		$integrations = Integrations::get_active_integrations();
		$errors       = [];

		// Resolve user ID for retry scheduling.
		$user    = ! empty( $contact['email'] ) ? \get_user_by( 'email', $contact['email'] ) : false;
		$user_id = $user ? $user->ID : 0;

		// Preserve the previous email for retry when the contact's email has changed
		// (e.g. Email_Change context) so integrations can upsert against the old address.
		$previous_email = '';
		if ( ! empty( $existing_contact['email'] ) && $existing_contact['email'] !== $contact['email'] ) {
			$previous_email = $existing_contact['email'];
		}

		foreach ( $integrations as $integration_id => $integration ) {
			$integration_contact = $integration->prepare_contact( $contact );

			// Added logging here to more easily monitor integration sync data. Can be removed once integrations are released.
			if ( 'legacy' !== Metadata::get_version() ) {
				Logger::log( sprintf( 'Syncing contact %s for integration %s with context "%s".', $integration_contact['email'] ?? 'unknown', $integration_id, $context ) );
				Logger::log( $integration_contact );
			}

			$result = $integration->push_contact_data( $integration_contact, $context, $existing_contact );
			if ( \is_wp_error( $result ) ) {
				/**
				 * Fires when a contact sync fails on the original attempt (before retries).
				 *
				 * Used by Alert_Manager to record failures for early pattern detection.
				 *
				 * @param array $failure_data {
				 *     Failure data.
				 *
				 *     @type string $integration_id The integration that failed.
				 *     @type array  $contact        The contact data that failed to sync.
				 *     @type string $context        The sync context.
				 *     @type string $reason         The error message.
				 * }
				 */
				do_action(
					'newspack_sync_contact_failed',
					[
						'integration_id' => $integration_id,
						'contact'        => $contact,
						'context'        => $context,
						'reason'         => $result->get_error_message(),
					]
				);
				self::schedule_integration_retry( $integration_id, $user_id, $context, 0, $result, $previous_email );
				$errors[] = sprintf( '[%s] %s', $integration_id, $result->get_error_message() );
				if ( self::$current_as_action_id ) {
					\ActionScheduler_Logger::instance()->log(
						self::$current_as_action_id,
						sprintf( 'Sync failed for integration "%s" of %s: %s', $integration_id, $contact['email'] ?? 'unknown', $result->get_error_message() )
					);
				}
			} elseif ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					sprintf( 'Sync succeeded for integration "%s" of %s.', $integration_id, $contact['email'] ?? 'unknown' )
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'newspack_esp_sync_failed', implode( '; ', $errors ) );
		}

		return true;
	}

	/**
	 * Schedule a retry for a failed integration sync via ActionScheduler.
	 *
	 * @param string           $integration_id The integration ID.
	 * @param int              $user_id        The WordPress user ID.
	 * @param string           $context        The sync context.
	 * @param int              $retry_count    Current retry count (0 = first failure).
	 * @param string|\WP_Error $error          The error from the failure.
	 * @param string           $previous_email Optional. Previous email for email-change retries.
	 */
	private static function schedule_integration_retry( $integration_id, $user_id, $context, $retry_count, $error, $previous_email = '' ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$user = ! empty( $user_id ) ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			static::log( sprintf( 'Cannot schedule retry for integration "%s": user %d not found.', $integration_id, $user_id ) );
			return;
		}

		$error_message = $error instanceof \WP_Error ? $error->get_error_message() : (string) $error;
		$user_email    = $user ? $user->user_email : 'unknown';

		$next_retry = $retry_count + 1;
		if ( $next_retry > self::MAX_RETRIES ) {
			static::log(
				sprintf(
					'Max retries (%d) reached for integration "%s" sync of user %d (%s). Giving up. Last error: %s',
					self::MAX_RETRIES,
					$integration_id,
					$user_id,
					$user_email,
					$error_message
				)
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					'Max retries exhausted.'
				);
			}
			/**
			 * Fires when a contact sync integration has exhausted all retry attempts.
			 *
			 * @param array $alert_data {
			 *     Alert data.
			 *
			 *     @type string $integration_id The integration that failed.
			 *     @type int    $user_id        The WordPress user ID.
			 *     @type string $context        The sync context.
			 *     @type int    $retry_count    Total retries attempted.
			 *     @type string $reason         The final error message.
			 * }
			 */
			do_action(
				'newspack_sync_retry_exhausted',
				[
					'integration_id' => $integration_id,
					'user_id'        => $user_id,
					'context'        => $context,
					'retry_count'    => self::MAX_RETRIES,
					'reason'         => $error_message,
				]
			);
			return;
		}

		$backoff_index   = min( $retry_count, count( self::RETRY_BACKOFF ) - 1 );
		$backoff_seconds = self::RETRY_BACKOFF[ $backoff_index ];

		$retry_data = [
			'integration_id' => $integration_id,
			'user_id'        => $user_id,
			'context'        => $context,
			'retry_count'    => $next_retry,
			'max_retries'    => self::MAX_RETRIES,
			'reason'         => $error_message,
			'previous_email' => $previous_email,
		];

		\as_schedule_single_action(
			time() + $backoff_seconds,
			self::RETRY_HOOK,
			[ $retry_data ],
			Integrations::get_action_group( $integration_id )
		);

		static::log(
			sprintf(
				'Scheduled retry %d/%d for integration "%s" sync of user %d (%s) in %ds. Error: %s',
				$next_retry,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$user_email,
				$backoff_seconds,
				$error_message
			)
		);
	}

	/**
	 * Execute an integration sync retry from ActionScheduler.
	 *
	 * @param array $retry_data The retry data containing integration_id, user_id, context, and retry_count.
	 *
	 * @throws \Exception When the final retry fails, so ActionScheduler marks the action as "failed".
	 */
	public static function execute_integration_retry( $retry_data ) {
		if ( ! is_array( $retry_data ) || empty( $retry_data['integration_id'] ) || empty( $retry_data['user_id'] ) ) {
			Logger::log( 'Invalid integration retry data received from Action Scheduler.', 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$integration_id = $retry_data['integration_id'];
		$user_id        = $retry_data['user_id'];
		$context        = $retry_data['context'] ?? static::$context;
		$retry_count    = $retry_data['retry_count'] ?? 1;
		$previous_email = $retry_data['previous_email'] ?? '';

		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			Logger::log( sprintf( 'User %d not found on retry %d.', $user_id, $retry_count ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$contact = self::get_contact_data( $user_id );
		if ( is_wp_error( $contact ) ) {
			Logger::log( sprintf( 'Error getting contact data for user %d on retry %d: %s', $user_id, $retry_count, $contact->get_error_message() ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		$integration = Integrations::get_integration( $integration_id );
		if ( ! $integration ) {
			Logger::log( sprintf( 'Integration "%s" not found on retry %d.', $integration_id, $retry_count ), 'NEWSPACK-SYNC', 'error' );
			return;
		}

		static::log( sprintf( 'Executing retry %d/%d for integration "%s" sync of user %d (%s).', $retry_count, self::MAX_RETRIES, $integration_id, $user_id, $contact['email'] ?? 'unknown' ) );

		/** This filter is documented in includes/reader-activation/sync/class-contact-sync.php */
		$contact = \apply_filters( 'newspack_esp_sync_contact', $contact, $context );
		$contact = Sync\Metadata::normalize_contact_data( $contact );

		// Reconstruct existing_contact for email-change retries so integrations
		// can upsert against the previous email address.
		$existing_contact = null;
		if ( ! empty( $previous_email ) ) {
			$existing_contact = array_merge( $contact, [ 'email' => $previous_email ] );
		}

		$integration_contact = $integration->prepare_contact( $contact );
		$result              = $integration->push_contact_data( $integration_contact, $context, $existing_contact );
		if ( \is_wp_error( $result ) ) {
			$error_messages = implode( '; ', $result->get_error_messages() );
			static::log(
				sprintf(
					'Retry %d failed for integration "%s" sync of user %d (%s): %s',
					$retry_count,
					$integration_id,
					$user_id,
					$contact['email'] ?? 'unknown',
					$error_messages
				)
			);
			self::schedule_integration_retry(
				$integration_id,
				$user_id,
				$context,
				$retry_count,
				$result,
				$previous_email
			);
			$error_message = sprintf(
				'Retry %d/%d failed for integration "%s" sync of user %d (%s): %s',
				$retry_count,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$contact['email'] ?? 'unknown',
				$error_messages
			);
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log(
					self::$current_as_action_id,
					$error_message
				);
			}
			// Only throw on the last retry so ActionScheduler marks it as "failed".
			// Intermediate retries schedule the next attempt and complete normally.
			if ( $retry_count >= self::MAX_RETRIES ) {
				throw new \Exception( esc_html( $error_message ) );
			}
		} else {
			$success_message = sprintf(
				'Retry %d/%d succeeded for integration "%s" sync of user %d (%s).',
				$retry_count,
				self::MAX_RETRIES,
				$integration_id,
				$user_id,
				$contact['email'] ?? 'unknown'
			);
			static::log( $success_message );
			if ( self::$current_as_action_id ) {
				\ActionScheduler_Logger::instance()->log( self::$current_as_action_id, $success_message );
			}
		}
	}

	/**
	 * Get the set of user IDs with pending sync retries in ActionScheduler.
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
	 * Check if a user has any pending sync retries in ActionScheduler.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if there are pending retries.
	 */
	public static function has_pending_retries( $user_id ) {
		return isset( self::get_pending_retry_user_ids()[ (int) $user_id ] );
	}

	/**
	 * Schedule a future sync.
	 *
	 * @param int    $user_id The user ID for the contact to sync.
	 * @param string $context The context of the sync.
	 * @param int    $delay   The delay in seconds.
	 */
	public static function schedule_sync( $user_id, $context, $delay ) {
		// Schedule another sync in $delay number of seconds.
		if ( ! is_int( $delay ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		static::log(
			sprintf(
				// Translators: %s is the email address of the contact to synced.
				__( 'Scheduling secondary sync for contact %s.', 'newspack-plugin' ),
				$user->data->user_email
			),
			[
				'user_email' => $user->data->user_email,
				'user_id'    => $user_id,
				'context'    => $context,
			]
		);
		\wp_schedule_single_event( \time() + $delay, 'newspack_scheduled_esp_sync', [ $user_id, $context ] );
	}

	/**
	 * Handle a scheduled sync event.
	 *
	 * @param int    $user_id The user ID for the contact to sync.
	 * @param string $context The context of the sync.
	 */
	public static function scheduled_sync( $user_id, $context ) {
		$contact = Sync\Metadata::get_contact_with_metadata( $user_id );
		if ( empty( $contact['email'] ) ) {
			return;
		}
		self::sync( $contact, $context );
	}

	/**
	 * Get contact data for syncing.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array|\WP_Error The contact data or WP_Error.
	 */
	public static function get_contact_data( $user_id ) {
		$user = \get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'newspack_esp_sync_contact', __( 'User not found.', 'newspack-plugin' ) );
		}

		$contact = [
			'email'    => $user->user_email,
			'name'     => $user->display_name,
			'metadata' => [],
		];

		if ( ! class_exists( '\WC_Customer' ) ) {
			return $contact;
		}
		$customer = new \WC_Customer( $user_id );
		if ( ! $customer || ! $customer->get_id() ) {
			return new \WP_Error(
				'newspack_esp_sync_contact',
				sprintf(
				// Translators: %d is the user ID.
					__( 'Customer with ID %d does not exist.', 'newspack-plugin' ),
					$user_id
				)
			);
		}

		// Ensure the customer has a billing address.
		if ( ! $customer->get_billing_email() && $customer->get_email() ) {
			$customer->set_billing_email( $customer->get_email() );
			$customer->save();
		}

		$contact = Sync\Metadata::get_contact_with_metadata( $customer );

		return $contact;
	}

	/**
	 * Given a user ID or WooCommerce Order, sync that reader's contact data to
	 * the connected ESP.
	 *
	 * @param int|\WC_order $user_id_or_order User ID or WC_Order object.
	 * @param string        $context          The context of the sync.
	 * @param bool          $is_dry_run       True if a dry run.
	 *
	 * @return true|\WP_Error True if the contact was synced successfully, WP_Error otherwise.
	 */
	public static function sync_contact( $user_id_or_order, $context = '', $is_dry_run = false ) {
		$can_sync = static::can_sync( true );
		if ( ! $is_dry_run && $can_sync->has_errors() ) {
			return $can_sync;
		}

		$is_order = $user_id_or_order instanceof \WC_Order;
		$order    = $is_order ? $user_id_or_order : false;
		$user_id  = $is_order ? $order->get_customer_id() : $user_id_or_order;

		$contact = $is_order ? Sync\Metadata::get_contact_with_metadata( $order ) : self::get_contact_data( $user_id );
		if ( \is_wp_error( $contact ) || empty( $contact['email'] ) ) {
			return \is_wp_error( $contact ) ? $contact : new \WP_Error( 'newspack_esp_sync_contact', __( 'Contact email is empty.', 'newspack-plugin' ) );
		}
		$result = $is_dry_run ? true : self::sync( $contact, $context );

		if ( $result && ! \is_wp_error( $result ) ) {
			static::log(
				sprintf(
					// Translators: %1$s is the status and %2$s is the contact's email address.
					__( '%1$s contact data for %2$s.', 'newspack-plugin' ),
					$is_dry_run ? __( 'Would sync', 'newspack-plugin' ) : __( 'Synced', 'newspack-plugin' ),
					$contact['email']
				)
			);
		}

		return $result;
	}

	/**
	 * Run queued syncs.
	 *
	 * @return void
	 */
	public static function run_queued_syncs() {
		if ( empty( self::$queued_syncs ) ) {
			return;
		}

		// Restore the AS action ID so push_to_integrations() can log against it.
		$saved_action_id = self::$current_as_action_id;

		foreach ( self::$queued_syncs as $email => $queued_sync ) {
			self::$current_as_action_id = $queued_sync['as_action_id'] ?? null;

			$user = get_user_by( 'email', $email );
			$contact = null;
			if ( $user ) {
				// For existing users, get fresh contact data.
				$contact = self::get_contact_data( $user->ID );
			} else {
				// For deleted users, try to use the queued contact data directly; $user will return nothing.
				$contact = $queued_sync['contact'];
			}
			if ( ! $contact ) {
				continue;
			}
			$contexts = $queued_sync['contexts'];
			self::sync( $contact, implode( '; ', $contexts ) );
		}

		self::$current_as_action_id = $saved_action_id;
		self::$queued_syncs = [];
	}
}
Contact_Sync::init_hooks();
