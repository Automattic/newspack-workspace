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
	 * flows that happen to dequeue the same user. Plan-switch and
	 * one-time-purchase-ended entries are remove-only: check_access() never adds
	 * lists for them, and they never replace another entry for the same user.
	 * A one-time purchase that ends can't grant access, so adding lists there
	 * would only re-add lists the reader had left; maybe_enqueue_access_check()
	 * covers the plan-switch case.
	 */
	const SOURCE_RENEWAL                 = 'renewal';
	const SOURCE_SUBSCRIPTION_CHANGED    = 'subscription_changed';
	const SOURCE_DONATION_CHANGED        = 'donation_changed';
	const SOURCE_READER_VERIFIED         = 'reader_verified';
	const SOURCE_GROUP_MEMBERSHIP        = 'group_membership';
	const SOURCE_PLAN_SWITCH             = 'plan_switch';
	const SOURCE_ONE_TIME_PURCHASE       = 'one_time_purchase';
	const SOURCE_ONE_TIME_PURCHASE_ENDED = 'one_time_purchase_ended';

	/**
	 * WP option key for the time of the last one-time purchase lapse sweep.
	 * Stores: int (Unix timestamp).
	 */
	const ONE_TIME_PURCHASE_SWEEP_OPTION = 'newspack_premium_newsletters_one_time_purchase_sweep';

	/**
	 * The lapse sweep reads orders in pages of this many, and gives up after
	 * this many pages for one duration. Normally a sweep covers one hour of
	 * orders; the cap only matters after a long cron outage.
	 */
	const SWEEP_ORDERS_PAGE_SIZE = 100;
	const SWEEP_MAX_PAGES        = 50;

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

		// Joining or leaving a group changes a reader's access without any Data Event
		// naming them, so the membership write itself has to trigger their check.
		add_action( 'added_user_meta', [ __CLASS__, 'maybe_enqueue_group_membership_check' ], 10, 3 );
		add_action( 'deleted_user_meta', [ __CLASS__, 'maybe_enqueue_group_membership_check' ], 10, 3 );

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
		Data_Events::register_handler( [ __CLASS__, 'handle_woo_order_updated' ], 'woo_order_updated' );
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
	 * Data Events handler for `woo_order_updated`.
	 *
	 * An order for a product a one-time purchase rule names grants access only
	 * while it counts as paid, so its buyer is checked when it starts or stops
	 * counting: on payment, and on a later refund or cancellation. The event
	 * fires once per line item; the queue keeps one entry per reader.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function handle_woo_order_updated( $timestamp, $data, $client_id ) {
		// Bail before the gate lookup; add_user_to_queue() would refuse the entry anyway.
		if ( ! self::is_access_control_active() ) {
			return;
		}
		$paid_statuses = self::get_paid_order_statuses();
		$was_paid      = in_array( $data['status_from'] ?? '', $paid_statuses, true );
		$is_paid       = in_array( $data['status'] ?? '', $paid_statuses, true );
		if ( $was_paid === $is_paid ) {
			return;
		}
		if ( ! in_array( (int) ( $data['product_id'] ?? 0 ), self::get_one_time_purchase_event_product_ids(), true ) ) {
			return;
		}
		$source = $is_paid ? self::SOURCE_ONE_TIME_PURCHASE : self::SOURCE_ONE_TIME_PURCHASE_ENDED;
		foreach ( self::get_order_reader_ids( (int) ( $data['user_id'] ?? 0 ), (string) ( $data['email'] ?? '' ) ) as $user_id ) {
			self::add_user_to_queue( $user_id, $source );
		}
	}

	/**
	 * Queue a remove-only check for each reader whose one-time purchase access
	 * ran out since the last sweep.
	 *
	 * Runs at the start of every queue run, so a lapse reaches the ESP within
	 * about an hour. Sweeping by order date, rather than scheduling a check per
	 * order at purchase time, also covers orders placed before this sweep
	 * existed. A reader who bought again keeps their lists, because the check
	 * looks at every qualifying order, not the one that lapsed.
	 *
	 * The first sweep only records its time. Lapses from before it, or from while
	 * access control was inactive, are left to `wp newspack
	 * verify-premium-newsletters --live`.
	 *
	 * @return void
	 */
	public static function enqueue_lapsed_one_time_purchases() {
		if ( ! self::is_access_control_active() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$now      = time();
		$last_run = (int) get_option( self::ONE_TIME_PURCHASE_SWEEP_OPTION, 0 );
		if ( $last_run > 0 && $last_run < $now ) {
			foreach ( self::get_finite_one_time_purchase_rules() as $value ) {
				$previous_cutoff = Access_Rules::get_one_time_purchase_cutoff( $value, $last_run );
				$cutoff          = Access_Rules::get_one_time_purchase_cutoff( $value, $now );
				// Month arithmetic can step the cutoff back near a month's end; the
				// orders it skips are swept once it passes them again.
				if ( $cutoff > $previous_cutoff ) {
					self::enqueue_readers_with_orders_between( $value['product_ids'], $previous_cutoff + 1, $cutoff );
				}
			}
		}
		update_option( self::ONE_TIME_PURCHASE_SWEEP_OPTION, $now, false );
	}

	/**
	 * Queue a remove-only check for the readers of each paid order created in a
	 * time range that contains one of the given products.
	 *
	 * @param int[] $product_ids Product IDs to look for.
	 * @param int   $start       Unix timestamp; orders created at or after it count.
	 * @param int   $end         Unix timestamp; orders created at or before it count.
	 */
	private static function enqueue_readers_with_orders_between( $product_ids, $start, $end ) {
		$query = [
			'status'       => self::get_paid_order_statuses(),
			'date_created' => $start . '...' . $end,
			'orderby'      => 'date ID',
			'order'        => 'ASC',
			'limit'        => self::SWEEP_ORDERS_PAGE_SIZE,
			'return'       => 'objects',
		];
		for ( $page = 1; $page <= self::SWEEP_MAX_PAGES; $page++ ) {
			$query['page'] = $page;
			$orders        = \wc_get_orders( $query );
			foreach ( $orders as $order ) {
				if ( ! Access_Rules::order_has_product( $order, $product_ids ) ) {
					continue;
				}
				foreach ( self::get_order_reader_ids( (int) $order->get_customer_id(), (string) $order->get_billing_email() ) as $user_id ) {
					self::add_user_to_queue( $user_id, self::SOURCE_ONE_TIME_PURCHASE_ENDED );
				}
			}
			if ( count( $orders ) < self::SWEEP_ORDERS_PAGE_SIZE ) {
				return;
			}
		}
		Logger::log(
			sprintf(
				'One-time purchase lapse sweep stopped after %d orders; run `wp newspack verify-premium-newsletters --live` to check the rest.',
				self::SWEEP_ORDERS_PAGE_SIZE * self::SWEEP_MAX_PAGES
			),
			'PREMIUM-NEWSLETTERS'
		);
	}

	/**
	 * The one-time purchase rules of every premium newsletter gate that grants
	 * paid access.
	 *
	 * @return array[] Sanitized rule values (product_ids, duration_value, duration_unit).
	 */
	private static function get_one_time_purchase_rules() {
		$rules = [];
		foreach ( self::get_gates() as $gate ) {
			if ( empty( $gate['custom_access']['active'] ) ) {
				continue;
			}
			foreach ( (array) ( $gate['custom_access']['access_rules'] ?? [] ) as $group ) {
				foreach ( (array) $group as $rule ) {
					if ( ! is_array( $rule ) || 'one_time_purchase' !== ( $rule['slug'] ?? '' ) ) {
						continue;
					}
					$value = Access_Rules::sanitize_one_time_purchase_value( $rule['value'] ?? [] );
					if ( ! empty( $value['product_ids'] ) ) {
						$rules[] = $value;
					}
				}
			}
		}
		return $rules;
	}

	/**
	 * The one-time purchase rules that can lapse, merged so each duration is
	 * swept once.
	 *
	 * Lifetime access never lapses, and a misconfigured duration never granted
	 * anything, so neither is returned.
	 *
	 * @return array[] Sanitized rule values, one per duration.
	 */
	private static function get_finite_one_time_purchase_rules() {
		$rules = [];
		foreach ( self::get_one_time_purchase_rules() as $value ) {
			if ( ! is_int( Access_Rules::get_one_time_purchase_cutoff( $value ) ) ) {
				continue;
			}
			$duration = $value['duration_value'] . ' ' . $value['duration_unit'];
			if ( isset( $rules[ $duration ] ) ) {
				$value['product_ids'] = array_values( array_unique( array_merge( $rules[ $duration ]['product_ids'], $value['product_ids'] ) ) );
			}
			$rules[ $duration ] = $value;
		}
		return array_values( $rules );
	}

	/**
	 * Product IDs that mark a `woo_order_updated` event as one a one-time
	 * purchase rule cares about.
	 *
	 * The event names a line item by its parent product, so a rule naming a
	 * variation is matched through the variation's parent. That lets a sibling
	 * variation queue a check too, which is harmless: the check grants nothing
	 * the rule doesn't.
	 *
	 * @return int[]
	 */
	private static function get_one_time_purchase_event_product_ids() {
		$product_ids = [];
		foreach ( self::get_one_time_purchase_rules() as $value ) {
			foreach ( $value['product_ids'] as $product_id ) {
				$product_ids[] = $product_id;
				$parent_id     = (int) wp_get_post_parent_id( $product_id );
				if ( $parent_id ) {
					$product_ids[] = $parent_id;
				}
			}
		}
		return array_values( array_unique( $product_ids ) );
	}

	/**
	 * The readers an order counts toward: its customer, and the account whose
	 * email matches the billing email. The one-time purchase rule matches orders
	 * both ways, which is how a guest order grants access to an account.
	 *
	 * @param int    $customer_id   Order customer ID; 0 for a guest order.
	 * @param string $billing_email Order billing email.
	 *
	 * @return int[] User IDs.
	 */
	private static function get_order_reader_ids( $customer_id, $billing_email ) {
		$user_ids = $customer_id ? [ $customer_id ] : [];
		if ( $billing_email ) {
			$user = get_user_by( 'email', $billing_email );
			if ( $user ) {
				$user_ids[] = (int) $user->ID;
			}
		}
		return array_values( array_unique( $user_ids ) );
	}

	/**
	 * Order statuses the one-time purchase rule counts as paid.
	 *
	 * @return string[]
	 */
	private static function get_paid_order_statuses() {
		return function_exists( 'wc_get_is_paid_statuses' ) ? \wc_get_is_paid_statuses() : [ 'processing', 'completed' ];
	}

	/**
	 * Whether a queue entry from this source may only remove lists. See the
	 * SOURCE_* constants.
	 *
	 * @param string $source Queue entry source.
	 *
	 * @return bool
	 */
	private static function is_remove_only_source( $source ) {
		return in_array( $source, [ self::SOURCE_PLAN_SWITCH, self::SOURCE_ONE_TIME_PURCHASE_ENDED ], true );
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

		// With no gates at all, no list can be restricted, so skip the per-list
		// check entirely. Both kinds have to be absent: get_gates()'s $is_newsletter
		// argument selects between premium newsletter gates and ordinary ones rather
		// than widening to both — the meta query is EXISTS vs NOT EXISTS — while the
		// per-list check below runs through Content_Restriction_Control, which
		// honours any gate whose content rules match the list. Testing only the
		// newsletter half would skip lists an ordinary gate restricts. In non-test
		// requests both gate lookups are cached (Content_Gate::get_gates cache is
		// disabled under PHPUnit), so the fast path stays cheap.
		if ( empty( self::get_gates() ) && empty( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ) ) ) {
			return $lists;
		}

		// Goes inert with the rest of gating (NPPD-1846). With Audience Management off
		// Access Control restricts nothing at all, and that includes premium lists:
		// restricted lists reappear in signup forms and anyone can join them. That is
		// the intended feature, not a leak — "Audience Management off" is meant to be
		// indistinguishable, for readers, from never having enabled Access Control.
		// The disable confirmation says so before the publisher commits to it.
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
	 * Whether premium newsletter gates govern access on this site yet.
	 *
	 * Access still belongs to Woo Memberships until a site cuts over, so the
	 * restriction filter hands back whatever it was given while Memberships is
	 * active. That is harmless for a render decision and wrong for an access one:
	 * this class asks by passing `false`, which comes back reading as "nobody is
	 * restricted", and with auto-signup on that subscribes every queued reader to
	 * every gated list. Gates are safe to create ahead of a cutover only because
	 * everything here stands down until Memberships is gone.
	 *
	 * @return bool
	 */
	private static function is_access_control_active(): bool {
		return Content_Gate::is_gating_active() && ! Memberships::is_active();
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
		// Bail before any per-user or per-list lookup: see is_access_control_active().
		if ( ! self::is_access_control_active() ) {
			return;
		}
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
		// A remove-only check never adds lists; see the SOURCE_* constants.
		$auto_signup     = ! self::is_remove_only_source( $source ) && (bool) get_option( 'newspack_premium_newsletters_auto_signup', 1 );
		$lists_to_add    = [];
		$lists_to_remove = [];

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
		// Guarded at the chokepoint rather than per handler: the renewal handler
		// reaches this directly rather than through maybe_enqueue_access_check(), and
		// a fifth entry point would otherwise have to remember on its own. Nothing
		// accumulates while access control is inactive, so re-enabling processes current
		// events rather than a backlog of stale ones.
		if ( ! self::is_access_control_active() ) {
			return;
		}
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
		} elseif ( self::SOURCE_RENEWAL !== $existing_source && ! self::is_remove_only_source( $source ) ) {
			// Never downgrade an existing renewal entry, and never let a remove-only
			// entry replace a check that can add lists.
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
		// Runs on every `init`, which is what makes this self-healing: the event is
		// cleared on the first request after gating goes inactive and re-armed on the
		// first request after it comes back, whatever changed the setting. A hook on the
		// setting transition would miss a direct option write, and unscheduling from
		// there would be undone by this method on the very next request anyway.
		//
		// Clearing discards pending entries rather than holding them, so re-enabling
		// processes current events instead of replaying a stale backlog. The cost is
		// that entitlement changes made during the off window are not reconciled: a
		// reader who cancels while access control is inactive keeps their premium list
		// membership until their next subscription event, and one-time purchases that
		// lapse in the window are not swept. Accepted deliberately —
		// acting on hours-old subscription state is the worse failure — but it means
		// the off window is not free, and a reconciliation sweep on re-enable is the
		// fix if that ever bites.
		if ( ! self::is_access_control_active() ) {
			if ( wp_next_scheduled( self::SCHEDULED_HOOK ) || ! empty( get_option( self::QUEUE_OPTION, [] ) ) ) {
				self::unschedule_access_check_event();
			}
			return;
		}
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
	 * The one-time purchase lapse sweep runs first so its checks join this run.
	 * A sweep that fails keeps its previous time, so the next run retries the
	 * same orders.
	 *
	 * @return void
	 */
	public static function process_access_check_queue() {
		try {
			self::enqueue_lapsed_one_time_purchases();
		} catch ( \Throwable $e ) {
			Logger::log(
				sprintf( 'One-time purchase lapse sweep failed: %s', $e->getMessage() ),
				'PREMIUM-NEWSLETTERS'
			);
		}
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

		// Forget the last sweep, so the next one starts from when access control comes
		// back rather than covering the whole off window; see register_access_check_event().
		delete_option( self::ONE_TIME_PURCHASE_SWEEP_OPTION );
	}

	/**
	 * Set the user's subscribed lists so they can be checked before auto-signup.
	 *
	 * @param int   $timestamp Timestamp of the event.
	 * @param array $data      Data associated with the event.
	 * @param int   $client_id ID of the client that triggered the event.
	 */
	public static function set_subscribed_lists( $timestamp, $data, $client_id ) {
		// Bail before the work, not just before the queue write: the snapshot below
		// costs a remote ESP round-trip and a user-meta write, and it exists only to
		// inform an access check that cannot run while access control is inactive.
		if ( ! self::is_access_control_active() ) {
			return;
		}
		if ( empty( $data['user_id'] ) ) {
			return;
		}
		$user = get_user_by( 'id', (int) $data['user_id'] );
		if ( ! $user ) {
			return;
		}

		self::snapshot_lists_and_enqueue_renewal_check( $user );

		// A group's renewal moves it through On hold and back to Active, which
		// re-checks every member. Members get the same snapshot as the owner, so
		// auto-signup can't re-add a premium list a member left on their own.
		foreach ( self::get_group_member_ids( $data ) as $member_id ) {
			$member = get_user_by( 'id', $member_id );
			if ( $member ) {
				self::snapshot_lists_and_enqueue_renewal_check( $member );
			}
		}
	}

	/**
	 * Snapshot a reader's current lists and queue their renewal-source access check.
	 *
	 * @param \WP_User $user The reader whose access the renewal decides.
	 */
	private static function snapshot_lists_and_enqueue_renewal_check( $user ) {
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
	 * Get the members of the group subscription a Data Event concerns.
	 *
	 * Subscription events name only the owner, yet a group subscription's status
	 * decides its members' access too. Without this, members keep premium lists
	 * after the group lapses and never gain them when it comes back.
	 *
	 * @param array $data Data associated with the event.
	 *
	 * @return int[] Member user IDs, or an empty array when the event names no group subscription.
	 */
	private static function get_group_member_ids( $data ) {
		if ( empty( $data['subscription_id'] ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return [];
		}
		$subscription = wcs_get_subscription( (int) $data['subscription_id'] );
		if ( ! $subscription || ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return [];
		}
		return array_map( 'intval', Group_Subscription::get_members( $subscription ) );
	}

	/**
	 * Queue an access check for a reader who just joined or left a group subscription.
	 *
	 * @param int|int[] $meta_ids ID(s) of the affected meta row(s).
	 * @param int       $user_id  ID of the user whose meta changed.
	 * @param string    $meta_key Meta key.
	 */
	public static function maybe_enqueue_group_membership_check( $meta_ids, $user_id, $meta_key ) {
		if ( Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY !== $meta_key ) {
			return;
		}
		self::add_user_to_queue( (int) $user_id, self::SOURCE_GROUP_MEMBERSHIP );
	}

	/**
	 * Maybe add or remove the user from restricted lists based on their access status.
	 * When the event names a group subscription and can change its access, the
	 * members are queued too.
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
		if ( ! self::event_changes_group_access( $data ) ) {
			return;
		}
		// A plan switch keeps the same status on both sides, so the event can't tell
		// a seat-count change, which leaves access alone, from a move to other
		// products. Members get a remove-only check: a downgrade still takes away
		// lists the new plan doesn't cover, and auto-signup can't re-add lists they
		// left. The cost is that an upgrade doesn't auto-add newly covered lists for
		// existing members, though the owner, checked the usual way, gets them.
		$is_plan_switch = ! empty( $data['status_before'] ) && ( $data['status_after'] ?? '' ) === $data['status_before'];
		foreach ( self::get_group_member_ids( $data ) as $member_id ) {
			self::add_user_to_queue( $member_id, $is_plan_switch ? self::SOURCE_PLAN_SWITCH : $source );
		}
	}

	/**
	 * Whether a subscription event can change what a group's members are entitled to.
	 *
	 * Members are skipped only when the status moves between the two statuses that
	 * always grant access, Active and Pending cancel. Checking them then would
	 * re-add every premium list a member had left whenever auto-signup is on. Any
	 * other move may change access: On hold still grants it while a payment retry is
	 * pending, so On hold to Expired, the last step of a lapse after failed
	 * payments, ends it. A plan switch reports the same status on both sides but
	 * may change the products, so it counts as a change too.
	 *
	 * The status pair can't show payment recovery, so On hold to Active after a
	 * successful retry still checks members, as it does the owner.
	 *
	 * @param array $data Data associated with the event.
	 *
	 * @return bool
	 */
	private static function event_changes_group_access( $data ) {
		$status_before = $data['status_before'] ?? '';
		$status_after  = $data['status_after'] ?? '';
		if ( $status_before === $status_after ) {
			return true;
		}
		return ! ( WooCommerce_Connection::is_subscription_active( $status_before ) && WooCommerce_Connection::is_subscription_active( $status_after ) );
	}
}

Premium_Newsletters::init();
