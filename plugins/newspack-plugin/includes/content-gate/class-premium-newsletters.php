<?php
/**
 * Premium Newsletters.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack_Newsletters_Contacts;
use Newspack_Newsletters_Subscription;
use Newspack\Newsletters\Subscription_List;

defined( 'ABSPATH' ) || exit;

/**
 * Premium Newsletters integration and access control.
 *
 * Registers filters, data-event handlers, and scheduled hooks for premium newsletters.
 */
class Premium_Newsletters {
	/**
	 * Cache of premium newsletter gates.
	 *
	 * @var array|null
	 */
	private static $gates = null;

	/**
	 * Cache of restricted lists.
	 *
	 * @var string[]
	 */
	private static $restricted_lists = [];

	/**
	 * Hook name for the scheduled access check action.
	 */
	const SCHEDULED_HOOK = 'newspack_premium_newsletters_access_check';

	/**
	 * WP option key for the pending user ID queue.
	 * Stores: int[]
	 */
	const QUEUE_OPTION = 'newspack_premium_newsletters_access_check_queue';

	/**
	 * Log a warning once the queue exceeds this many unique user IDs.
	 */
	const MAX_QUEUE_SIZE = 500;

	/**
	 * Queue-entry source tags. Each access-check queue entry records which event
	 * enqueued it so that downstream logic (e.g. consulting the renewal snapshot)
	 * can be scoped to the originating event instead of leaking into unrelated
	 * flows that happen to dequeue the same user.
	 */
	const SOURCE_RENEWAL              = 'renewal';
	const SOURCE_SUBSCRIPTION_CHANGED = 'subscription_changed';
	const SOURCE_DONATION_CHANGED     = 'donation_changed';
	const SOURCE_READER_VERIFIED      = 'reader_verified';

	/**
	 * User meta key for the renewal-time snapshot of the contact's full ESP list
	 * membership. Captured by set_subscribed_lists() when a renewal fires; consulted
	 * by check_access() to suppress auto-signup of any restricted list the contact
	 * had unsubscribed from before the renewal. Cleared after a successful access
	 * check. Note: this stores the contact's complete ESP list set, not only the
	 * restricted lists — the auto-signup branch filters down to restricted lists
	 * at check time.
	 *
	 * Stores: string[] of public list IDs.
	 */
	const SUBSCRIBED_LISTS_META_KEY = '_newspack_newsletters_subscribed_lists';

	/**
	 * Initialize.
	 */
	public static function init() {
		// Filter the subscription lists.
		add_filter( 'newspack_newsletters_subscription_lists', [ __CLASS__, 'filter_subscription_lists' ] );

		// Register Data Events handlers.
		add_action( 'init', [ __CLASS__, 'register_handlers' ] );

		// Register the scheduled-event callback (works for both WP cron and ActionScheduler).
		add_action( 'init', [ __CLASS__, 'register_access_check_event' ] );
		add_action( self::SCHEDULED_HOOK, [ __CLASS__, 'process_access_check_queue' ] );

		// Clean up the queue option on plugin deactivation.
		add_action( 'newspack_deactivation', [ __CLASS__, 'unschedule_access_check_event' ] );
	}

	/**
	 * Get all active premium newsletter gates.
	 * If the results have been previously fetched, return the cached results.
	 *
	 * @return array The premium newsletter gates.
	 */
	public static function get_gates() {
		if ( null !== self::$gates ) {
			return self::$gates;
		}
		self::$gates = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', true );
		return self::$gates;
	}

	/**
	 * Register Data Events handlers.
	 * To trigger an access check, add a handler for a Data Event that includes `user_id` in the data payload.
	 */
	public static function register_handlers() {
		Data_Events::register_handler( [ __CLASS__, 'set_subscribed_lists' ], 'subscription_renewal_attempt' );
		Data_Events::register_handler( [ __CLASS__, 'handle_product_subscription_changed' ], 'product_subscription_changed' );
		Data_Events::register_handler( [ __CLASS__, 'handle_donation_subscription_changed' ], 'donation_subscription_changed' );
		Data_Events::register_handler( [ __CLASS__, 'handle_reader_verified' ], 'reader_verified' );
	}

	/**
	 * Data Events handler for `product_subscription_changed`.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function handle_product_subscription_changed( $timestamp, $data, $client_id ) {
		self::maybe_enqueue_access_check( $timestamp, $data, $client_id, self::SOURCE_SUBSCRIPTION_CHANGED );
	}

	/**
	 * Data Events handler for `donation_subscription_changed`.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function handle_donation_subscription_changed( $timestamp, $data, $client_id ) {
		self::maybe_enqueue_access_check( $timestamp, $data, $client_id, self::SOURCE_DONATION_CHANGED );
	}

	/**
	 * Data Events handler for `reader_verified`.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function handle_reader_verified( $timestamp, $data, $client_id ) {
		self::maybe_enqueue_access_check( $timestamp, $data, $client_id, self::SOURCE_READER_VERIFIED );
	}

	/**
	 * Filter the subscription lists to prevent premium newsletters from being shown when restricted.
	 *
	 * @param array $lists The lists.
	 *
	 * @return array The filtered lists.
	 */
	public static function filter_subscription_lists( $lists ) {
		if ( is_admin() ) {
			return $lists;
		}
		$lists = array_values(
			array_filter(
				$lists,
				function( $list ) {
					return ! Content_Restriction_Control::is_post_restricted( false, $list->get_id() );
				}
			)
		);
		return $lists;
	}

	/**
	 * Given a local list ID, return the public list ID.
	 *
	 * @param string $list_id The local list ID.
	 *
	 * @return string|null The public list ID, or null if $list_id is not a valid local list ID.
	 */
	private static function get_public_id( $list_id ) {
		if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
			return null;
		}
		$list = new Subscription_List( $list_id );
		if ( ! $list ) {
			return null;
		}
		return $list->get_public_id();
	}

	/**
	 * Add a user to the given lists.
	 *
	 * @param string   $email The email address of the user.
	 * @param string[] $lists_to_add The list IDs to add the user to.
	 * @param string[] $lists_to_remove The list IDs to remove the user from.
	 * @param string   $context The context of the action.
	 *
	 * @return bool|\WP_Error True when there was nothing to do or the contacts API
	 *                       reported success, WP_Error from the contacts API on failure.
	 */
	private static function add_and_remove_lists( $email, $lists_to_add, $lists_to_remove, $context = 'Updating premium newsletter lists' ) {
		if ( ! class_exists( 'Newspack_Newsletters_Contacts' ) || ! class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			return true;
		}
		if ( empty( $lists_to_add ) && empty( $lists_to_remove ) ) {
			return true;
		}
		$lists_to_add    = array_map( [ __CLASS__, 'get_public_id' ], $lists_to_add );
		$lists_to_remove = array_map( [ __CLASS__, 'get_public_id' ], $lists_to_remove );
		$current_lists   = Newspack_Newsletters_Subscription::get_contact_lists( $email );
		if ( ! is_array( $current_lists ) ) {
			$current_lists = [];
		}

		// No need to add the user to lists they are already subscribed to.
		$lists_to_add = array_values( array_diff( array_filter( $lists_to_add ), $current_lists ) );

		// No need to remove the user from lists they're not subscribed to.
		$lists_to_remove = array_values( array_intersect( array_filter( $lists_to_remove ), $current_lists ) );

		if ( empty( $lists_to_add ) && empty( $lists_to_remove ) ) {
			return true;
		}

		return Newspack_Newsletters_Contacts::add_and_remove_lists( $email, $lists_to_add, $lists_to_remove, $context );
	}

	/**
	 * Get all lists restricted by content gates.
	 *
	 * @return string[] The restricted list IDs.
	 */
	public static function get_restricted_lists() {
		if ( ! empty( self::$restricted_lists ) ) {
			return self::$restricted_lists;
		}
		$gates = self::get_gates();
		if ( empty( $gates ) ) {
			return [];
		}
		$restricted_lists = [];
		foreach ( $gates as $gate ) {
			$content_rules = array_values(
				array_filter(
					Content_Rules::get_gate_content_rules( $gate['id'] ),
					function ( $content_rule ) {
						return $content_rule['slug'] === 'newsletters';
					}
				)
			);
			if ( empty( $content_rules ) ) {
				continue;
			}
			$restricted_lists = array_values(
				array_unique(
					array_merge(
						$restricted_lists,
						array_merge(
							...array_column( $content_rules, 'value' )
						)
					)
				)
			);
		}
		$restricted_lists = array_map( 'intval', $restricted_lists );
		self::$restricted_lists = $restricted_lists;
		return self::$restricted_lists;
	}

	/**
	 * Check list access for the user.
	 *
	 * The renewal snapshot is only consulted when this access check was enqueued
	 * by a renewal event (source === SOURCE_RENEWAL). Other event flows that
	 * happen to dequeue the same user must not be silently filtered by a snapshot
	 * that was captured for a different reason.
	 *
	 * @param int    $user_id The ID of the user to check access for.
	 * @param string $source  The originating event source for this queue entry.
	 *                        One of the SOURCE_* constants, or empty string for
	 *                        legacy/untagged entries.
	 *
	 * @return void
	 */
	private static function check_access( $user_id, $source = '' ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		$restricted_lists = self::get_restricted_lists() ?? [];
		if ( empty( $restricted_lists ) ) {
			return;
		}
		$is_renewal_check = self::SOURCE_RENEWAL === $source;
		$subscribed_lists = $is_renewal_check
			? get_user_meta( $user_id, self::SUBSCRIBED_LISTS_META_KEY, true )
			: '';
		$auto_signup      = (bool) get_option( 'newspack_premium_newsletters_auto_signup', 1 );
		$lists_to_add     = [];
		$lists_to_remove  = [];

		// When a renewal snapshot is present we need to compare each restricted list's
		// public ID against the snapshot. Build the local→public map once per run so
		// we don't instantiate a Subscription_List per list per user per cron tick.
		// Lists whose public ID can't be resolved are intentionally skipped from
		// auto-signup — they can't be matched against the snapshot, and silently
		// adding them would defeat the unsubscribe-respecting behavior.
		$restricted_public_ids = [];
		if ( is_array( $subscribed_lists ) ) {
			foreach ( $restricted_lists as $list_id ) {
				$public_id = self::get_public_id( $list_id );
				if ( null !== $public_id ) {
					$restricted_public_ids[ $list_id ] = $public_id;
				}
			}
		}

		foreach ( $restricted_lists as $list_id ) {
			if ( Content_Restriction_Control::is_post_restricted( false, $list_id, $user_id ) ) {
				$lists_to_remove[] = $list_id;
			} elseif ( $auto_signup ) {
				if ( ! is_array( $subscribed_lists ) ) {
					$lists_to_add[] = $list_id;
				} elseif (
					isset( $restricted_public_ids[ $list_id ] )
					&& in_array( $restricted_public_ids[ $list_id ], $subscribed_lists, true )
				) {
					$lists_to_add[] = $list_id;
				}
			}
		}

		$email  = $user->user_email;
		$result = self::add_and_remove_lists( $email, $lists_to_add, $lists_to_remove );

		// Only clear the renewal snapshot when this was a renewal-source check AND
		// the ESP call succeeded. Non-renewal sources never touch the snapshot, so
		// they can't accidentally consume it; provider failures leave the snapshot
		// in place so the next renewal-source enqueue still respects the unsubscribe.
		if ( $is_renewal_check && ! is_wp_error( $result ) ) {
			delete_user_meta( $user_id, self::SUBSCRIBED_LISTS_META_KEY );
		}
	}

	/**
	 * Normalize a queue entry into a [ user_id, source ] pair.
	 *
	 * Entries persisted by older versions of this class were bare integers; current
	 * entries are arrays carrying the originating event source. This helper hides
	 * the difference so callers don't have to.
	 *
	 * @param mixed $entry Queue entry.
	 *
	 * @return array{0:int,1:string} [ user_id, source ]. user_id is 0 for invalid entries.
	 */
	private static function normalize_queue_entry( $entry ) {
		if ( is_array( $entry ) ) {
			return [ (int) ( $entry['user_id'] ?? 0 ), (string) ( $entry['source'] ?? '' ) ];
		}
		return [ (int) $entry, '' ];
	}

	/**
	 * Add the user to the access-check queue, tagged with the source event.
	 *
	 * Entries are deduplicated by user_id. When an entry already exists for the
	 * user, a renewal-source enqueue overrides any non-renewal source, but a
	 * non-renewal source never downgrades an existing renewal entry. This keeps
	 * the renewal-snapshot semantics intact when multiple events fire for the
	 * same user within a single cron window.
	 *
	 * @param int    $user_id The ID of the user to schedule the access check for.
	 * @param string $source  Source event tag (one of the SOURCE_* constants, or
	 *                        empty string for an untagged enqueue).
	 *
	 * @return void
	 */
	private static function add_user_to_queue( $user_id, $source = '' ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, [] );

		// Find an existing entry for this user (handles legacy int entries too).
		$existing_index  = null;
		$existing_source = '';
		foreach ( $queue as $i => $entry ) {
			[ $entry_user_id, $entry_source ] = self::normalize_queue_entry( $entry );
			if ( $entry_user_id === $user_id ) {
				$existing_index  = $i;
				$existing_source = $entry_source;
				break;
			}
		}

		$new_entry = [
			'user_id' => $user_id,
			'source'  => $source,
		];

		if ( null === $existing_index ) {
			$queue[] = $new_entry;
		} elseif ( self::SOURCE_RENEWAL !== $existing_source ) {
			// Never downgrade an existing renewal entry.
			$queue[ $existing_index ] = $new_entry;
		}

		$queue = array_values( $queue );

		// Warn if the queue is growing unusually large — likely indicates a cron outage.
		if ( count( $queue ) > self::MAX_QUEUE_SIZE ) {
			Logger::log(
				sprintf(
					'Access-check queue has grown to %d entries — WP-Cron or ActionScheduler may not be running.',
					count( $queue )
				),
				'PREMIUM-NEWSLETTERS'
			);
		}

		// Persist updated queue (autoload = false to avoid loading on every request).
		update_option(
			self::QUEUE_OPTION,
			$queue,
			false
		);
	}

	/**
	 * Schedule a recurring event to check access for the users in the queue.
	 *
	 * @return void
	 */
	public static function register_access_check_event() {
		if ( ! wp_next_scheduled( self::SCHEDULED_HOOK ) ) {
			self::process_access_check_queue();
			wp_schedule_event( time(), 'hourly', self::SCHEDULED_HOOK );
		}
	}

	/**
	 * Process all pending access checks from the queue.
	 *
	 * Registered as the callback for the SCHEDULED_HOOK cron event. Each entry is
	 * processed in its own try/catch so a single bad entry (e.g. a deleted list
	 * post referenced from the restriction rules) cannot abort the rest of the
	 * batch. The queue is cleared after the loop completes; if a transient ESP
	 * failure occurs check_access() leaves the renewal snapshot in place so the
	 * next enqueue for that user still respects it.
	 *
	 * @return void
	 */
	public static function process_access_check_queue() {
		$queue = get_option( self::QUEUE_OPTION, [] );
		if ( empty( $queue ) ) {
			return;
		}
		foreach ( $queue as $entry ) {
			[ $user_id, $source ] = self::normalize_queue_entry( $entry );
			if ( ! $user_id ) {
				continue;
			}
			try {
				self::check_access( $user_id, $source );
			} catch ( \Throwable $e ) {
				Logger::log(
					sprintf(
						'Access check for user %d failed: %s',
						$user_id,
						$e->getMessage()
					),
					'PREMIUM-NEWSLETTERS'
				);
			}
		}
		self::clear_queue();
	}

	/**
	 * Delete the queue option entirely.
	 *
	 * Called after each queue processing run and on plugin deactivation
	 * (via the newspack_deactivation hook registered in init()).
	 *
	 * @return void
	 */
	public static function clear_queue() {
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Unschedule the recurring event.
	 *
	 * @return void
	 */
	public static function unschedule_access_check_event() {
		// Remove any existing WP Cron events.
		wp_clear_scheduled_hook( self::SCHEDULED_HOOK );

		// Delete the queue option.
		self::clear_queue();
	}

	/**
	 * Set the user's subscribed lists so they can be checked before auto-signup.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function set_subscribed_lists( $timestamp, $data, $client_id ) {
		if ( empty( $data['user_id'] ) ) {
			return;
		}
		$user = get_user_by( 'id', (int) $data['user_id'] );
		if ( ! $user ) {
			return;
		}

		// Capture the renewal-time snapshot when auto-signup is enabled. Without
		// auto-signup the snapshot has no effect (check_access only consults it
		// inside the auto-signup branch), so skip the ESP fetch in that case.
		$auto_signup = (bool) get_option( 'newspack_premium_newsletters_auto_signup', 1 );
		if ( $auto_signup && class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			$email         = $user->user_email;
			$current_lists = Newspack_Newsletters_Subscription::get_contact_lists( $email );
			if ( is_array( $current_lists ) ) {
				update_user_meta( $user->ID, self::SUBSCRIBED_LISTS_META_KEY, $current_lists );
			}
		}

		// Always enqueue the renewal-source check so the snapshot governs THIS
		// access check (and only this one). If product_subscription_changed also
		// fires for the same user, the dedup logic in add_user_to_queue() keeps
		// the renewal source.
		self::add_user_to_queue( (int) $user->ID, self::SOURCE_RENEWAL );
	}

	/**
	 * Maybe add or remove the user from restricted lists based on their access status.
	 *
	 * @param int    $timestamp Timestamp of the event.
	 * @param array  $data      Data associated with the event.
	 * @param int    $client_id ID of the client that triggered the event.
	 * @param string $source    Optional source tag for the queue entry. The
	 *                          per-event handle_*() wrappers pass the matching
	 *                          SOURCE_* constant; direct callers (including tests)
	 *                          may omit this for an untagged enqueue.
	 */
	public static function maybe_enqueue_access_check( $timestamp, $data, $client_id, $source = '' ) {
		if ( empty( $data['user_id'] ) ) {
			return;
		}
		self::add_user_to_queue( (int) $data['user_id'], $source );
	}
}

Premium_Newsletters::init();
