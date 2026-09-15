<?php
/**
 * Contact Cron orchestration class
 *
 * Handles recurring pull and push of contact data via WP-Cron.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Integrations;

use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Cron Class.
 *
 * Manages recurring contact data synchronization: stages logged-in readers,
 * then a WP-Cron batch pushes the ones whose contact changed since their last
 * push and pulls the ones whose synchronous pull failed. A safety net behind
 * the event-driven syncs, which already cover every change that matters, so
 * it is built to be cheap when nothing changed.
 */
class Contact_Cron {
	/**
	 * Cron interval in seconds (5 minutes): how often the batch runs.
	 *
	 * @var int
	 */
	const CRON_INTERVAL = 300;

	/**
	 * Minimum seconds between two stagings of the same logged-in reader.
	 *
	 * Bounds how often a reader is re-staged, and so how often the batch
	 * rebuilds their contact to compare it. It is not a freshness rule: whether
	 * a staging turns into a push is decided by the change detection in
	 * handle_batch_push(), and whether it turns into a pull by
	 * Contact_Pull::PULL_SYNC_THRESHOLD.
	 *
	 * @var int
	 */
	const ENQUEUE_THROTTLE = 300;

	/**
	 * User meta key for last enqueue timestamp.
	 *
	 * @var string
	 */
	const LAST_ENQUEUE_META = 'newspack_contact_cron_last_enqueue';

	/**
	 * User meta key for the timestamp of the last pull started for the reader
	 * from a page load, whether it ran synchronously or fell back to the batch.
	 * Read against Contact_Pull::PULL_SYNC_THRESHOLD.
	 *
	 * @var string
	 */
	const LAST_PULL_META = 'newspack_contact_cron_last_pull';

	/**
	 * User meta key for the fingerprint of the contact the batch last pushed
	 * successfully for the reader. See Contact_Sync::get_push_fingerprint().
	 *
	 * @var string
	 */
	const LAST_PUSH_HASH_META = 'newspack_contact_cron_last_push_hash';

	/**
	 * WP-Cron hook for batch processing.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'newspack_contact_cron_batch';

	/**
	 * User meta key to stage a user for pull.
	 *
	 * @var string
	 */
	const PULL_PENDING_META = 'newspack_contact_cron_pull_pending';

	/**
	 * User meta key to stage a user for push.
	 *
	 * @var string
	 */
	const PUSH_PENDING_META = 'newspack_contact_cron_push_pending';

	/**
	 * WP-Cron schedule name.
	 *
	 * @var string
	 */
	const CRON_SCHEDULE = 'newspack_contact_cron_interval';

	/**
	 * Logger header for Contact Cron messages.
	 *
	 * @var string
	 */
	const LOGGER_HEADER = 'NEWSPACK-CONTACT-CRON';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', [ __CLASS__, 'maybe_enqueue_contact' ], 20 );
		add_action( 'init', [ __CLASS__, 'schedule_cron' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'handle_batch' ] );
	}

	/**
	 * Register custom cron schedule.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules.
	 */
	public static function add_cron_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = [
			'interval' => self::CRON_INTERVAL,
			'display'  => __( 'Newspack Contact Cron Interval', 'newspack-plugin' ),
		];
		return $schedules;
	}

	/**
	 * Stage the current logged-in reader for the batch push and, once per
	 * staleness threshold, refresh their pulled data.
	 *
	 * Push staging is cheap and happens every throttle window; the batch decides
	 * whether the contact actually changed. The pull is the expensive side (one
	 * provider read per integration), so it starts at most once per
	 * Contact_Pull::PULL_SYNC_THRESHOLD: synchronously when the reader's data is
	 * stale, falling back to the batch only when that fails.
	 */
	public static function maybe_enqueue_contact() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$last_enqueue = (int) get_user_meta( $user_id, self::LAST_ENQUEUE_META, true );

		if ( ( time() - $last_enqueue ) < self::ENQUEUE_THROTTLE ) {
			return;
		}
		update_user_meta( $user_id, self::LAST_ENQUEUE_META, time() );

		self::enqueue_for_push( $user_id );

		$last_pull = (int) get_user_meta( $user_id, self::LAST_PULL_META, true );
		if ( ! Contact_Pull::is_stale( $last_pull ) ) {
			return;
		}
		// Recorded before the outcome is known: a failing pull belongs to the
		// batch and its retries, not to a new synchronous attempt on every page
		// load once the throttle elapses.
		update_user_meta( $user_id, self::LAST_PULL_META, time() );
		$result = Contact_Pull::pull_sync();
		if ( is_wp_error( $result ) ) {
			self::enqueue_for_pull( $user_id );
		}
	}

	/**
	 * Stage a user for pull.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function enqueue_for_pull( $user_id ) {
		update_user_meta( $user_id, self::PULL_PENDING_META, time() );
	}

	/**
	 * Stage a user for push.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function enqueue_for_push( $user_id ) {
		update_user_meta( $user_id, self::PUSH_PENDING_META, time() );
	}

	/**
	 * Get user IDs staged for a given meta key.
	 *
	 * Queries wp_usermeta directly to avoid the JOIN overhead of WP_User_Query.
	 *
	 * @param string $meta_key The user meta key.
	 * @return int[] User IDs.
	 */
	private static function get_pending_users( $meta_key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);
		return array_map( 'intval', $user_ids );
	}

	/**
	 * Ensure the recurring cron event is scheduled.
	 *
	 * Respects NEWSPACK_CRON_DISABLE to allow selective disabling.
	 */
	public static function schedule_cron() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'deactivate_cron' ] );

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::deactivate_cron();
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Deactivate the cron event.
	 */
	public static function deactivate_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Handle the recurring cron event.
	 *
	 * Processes both pull and push queues.
	 */
	public static function handle_batch() {
		self::handle_batch_pull();
		self::handle_batch_push();
	}

	/**
	 * Process the pull queue.
	 *
	 * Queries users staged for pull, processes each one,
	 * and removes the flag per-user after processing.
	 */
	private static function handle_batch_pull() {
		$queue = self::get_pending_users( self::PULL_PENDING_META );
		if ( empty( $queue ) ) {
			return;
		}

		Logger::log( 'Batch pull started for ' . count( $queue ) . ' user(s).', self::LOGGER_HEADER );

		$pending_retries = Contact_Pull::get_pending_retry_user_ids();

		foreach ( $queue as $user_id ) {
			delete_user_meta( $user_id, self::PULL_PENDING_META );
			if ( isset( $pending_retries[ $user_id ] ) ) {
				Logger::log( 'Batch pull skipping user ' . $user_id . ': pending pull retries.', self::LOGGER_HEADER );
				continue;
			}
			$result = Contact_Pull::pull_all( $user_id );
			if ( is_wp_error( $result ) ) {
				Logger::error( 'Batch pull failed for user ' . $user_id . ': ' . $result->get_error_message(), self::LOGGER_HEADER );
			}
		}

		Logger::log( 'Batch pull completed.', self::LOGGER_HEADER );
	}

	/**
	 * Process the push queue.
	 *
	 * Clears every staged reader's flag and pushes only those whose contact
	 * differs from what the batch last pushed for them. Most staged readers are
	 * merely active, not changed; without the comparison each one was rewritten
	 * at the integrations every batch (NEWS-3087). A failed push keeps the
	 * previous fingerprint, so the contact is not recorded as delivered and the
	 * next batch tries again once the sync retries are no longer pending.
	 */
	private static function handle_batch_push() {
		$queue = self::get_pending_users( self::PUSH_PENDING_META );
		if ( empty( $queue ) ) {
			return;
		}

		Logger::log( 'Batch push started for ' . count( $queue ) . ' user(s).', self::LOGGER_HEADER );

		$pending_retries = Contact_Sync::get_pending_retry_user_ids();
		$context         = 'Recurring sync routine';
		$unchanged       = 0;

		foreach ( $queue as $user_id ) {
			delete_user_meta( $user_id, self::PUSH_PENDING_META );
			if ( isset( $pending_retries[ $user_id ] ) ) {
				Logger::log( 'Batch push skipping user ' . $user_id . ': pending sync retries.', self::LOGGER_HEADER );
				continue;
			}

			$contact = Contact_Sync::get_contact_data( $user_id );
			if ( is_wp_error( $contact ) ) {
				Logger::error( 'Batch push failed for user ' . $user_id . ': ' . $contact->get_error_message(), self::LOGGER_HEADER );
				continue;
			}
			$fingerprint = Contact_Sync::get_push_fingerprint( $contact, $context );
			if ( $fingerprint === get_user_meta( $user_id, self::LAST_PUSH_HASH_META, true ) ) {
				$unchanged++;
				continue;
			}

			// sync_contact() rebuilds the contact it pushes. Only readers whose
			// contact moved pay for that second build, and it keeps the push on
			// the same path as every other caller.
			$result = Contact_Sync::sync_contact( $user_id, $context );
			if ( is_wp_error( $result ) ) {
				Logger::error( 'Batch push failed for user ' . $user_id . ': ' . $result->get_error_message(), self::LOGGER_HEADER );
				continue;
			}
			update_user_meta( $user_id, self::LAST_PUSH_HASH_META, $fingerprint );
		}

		Logger::log( sprintf( 'Batch push completed. Skipped %d unchanged user(s).', $unchanged ), self::LOGGER_HEADER );
	}
}
