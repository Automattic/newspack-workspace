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
	 * Why the sign-up being filtered lost a gated list, if it did. One of
	 * 'not_own_address', 'no_access', or '' when nothing was refused.
	 *
	 * Read when the refusal is turned into a message for the reader.
	 *
	 * @var string
	 */
	private static $sign_up_refusal = '';

	/**
	 * The address, lowercased, that $sign_up_refusal was recorded for.
	 *
	 * A sign-up that never reaches filter_subscribe_lists() resets nothing, yet its
	 * error can carry the same code. Keeping the address lets the message callback
	 * claim only an error raised for the address the refusal was recorded for.
	 *
	 * @var string
	 */
	private static $sign_up_refusal_email = '';

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
	 * WP option key for the site-wide auto-signup setting: whether a reader who gains
	 * access to a restricted list is added to it. A gate set to "default" follows it.
	 * Stores: bool (1 or 0); absent reads as on, so read it through
	 * is_site_auto_signup_on().
	 */
	const AUTO_SIGNUP_OPTION = 'newspack_premium_newsletters_auto_signup';

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
	 * WP option key for how far the one-time purchase lapse sweep has checked.
	 * Stores: array<string,int>, keyed by duration (e.g. '30 days'): the order
	 * creation time, as a Unix timestamp, up to which lapses have been queued.
	 */
	const ONE_TIME_PURCHASE_SWEEP_OPTION = 'newspack_premium_newsletters_one_time_purchase_sweep';

	/**
	 * Most pages of orders the lapse sweep reads for one duration in one run.
	 * Every paid order created in the window is loaded, not only one-time
	 * orders, so this bounds the run's memory; a sweep that reaches it resumes
	 * from the last order it read on the next run.
	 */
	const SWEEP_MAX_PAGES = 10;

	/**
	 * Orders per page in the lapse sweep. Tests shrink it to exercise the walk.
	 *
	 * @var int
	 */
	private static $sweep_page_size = 100;

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

		// Refuse sign-ups to gated lists unless the reader is signing up their own address.
		add_filter( 'newspack_newsletters_subscribe_lists', [ __CLASS__, 'filter_subscribe_lists' ], 10, 2 );

		// Tell a reader who was refused a premium list what to do about it.
		add_filter( 'newspack_newsletters_add_contact_reader_error_message', [ __CLASS__, 'filter_sign_up_error_message' ], 20, 3 );

		// Tell the Newsletter Subscription Form block which lists are gated.
		add_filter( 'newspack_newsletters_subscription_list_gates', [ __CLASS__, 'filter_list_gates' ] );

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
	 * Whether the site has any premium newsletter gate.
	 *
	 * Without one no list can be gated, so callers skip their per-list work. Only
	 * these gates can restrict a list: Content_Restriction_Control::get_post_gates()
	 * reads no others for a list post.
	 *
	 * Reads Content_Gate directly rather than get_gates(): that one memoizes in a
	 * static nothing clears outside tests, so a process that outlives a request
	 * would never see a gate published after its first check. Content_Gate's own
	 * cache is flushed on every post and meta write. That covers this check only:
	 * the per-list answer comes from Content_Restriction_Control's memo, another
	 * static nothing clears, so in such a process a list keeps the answer it got
	 * the first time it was judged.
	 *
	 * @return bool
	 */
	private static function has_newsletter_gates(): bool {
		return ! empty( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', true ) );
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
		// Bail before the gate lookup; add_users_to_queue() would refuse the entry anyway.
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
		// The event names a line item by its parent product, so a sibling of a gated
		// variation gets this far; the order's own line items settle it.
		$order = function_exists( 'wc_get_order' ) ? \wc_get_order( (int) ( $data['order_id'] ?? 0 ) ) : false;
		if ( ! $order instanceof \WC_Order || ! Access_Rules::order_has_product( $order, self::get_one_time_purchase_product_ids() ) ) {
			return;
		}
		self::add_users_to_queue(
			self::get_order_reader_ids( (int) $order->get_customer_id(), (string) $order->get_billing_email() ),
			$is_paid ? self::SOURCE_ONE_TIME_PURCHASE : self::SOURCE_ONE_TIME_PURCHASE_ENDED
		);
	}

	/**
	 * Queue a remove-only check for each reader whose one-time purchase access
	 * ran out since the last sweep.
	 *
	 * Runs as part of every scheduled queue run, so a lapse reaches the ESP
	 * within about an hour. The sweep keeps no state per order: it walks paid
	 * orders by creation date, so an order placed before the sweep existed is
	 * still checked when its access ends. A reader who bought again keeps their
	 * lists, because the check looks at all of their qualifying orders, not only
	 * the one that lapsed.
	 *
	 * The first sweep for a duration starts from that moment, so access that had
	 * already lapsed is not reconciled here. The position survives access control
	 * standing down, so lapses from that time are swept once it comes back.
	 *
	 * @return void
	 */
	public static function enqueue_lapsed_one_time_purchases(): void {
		if ( ! self::is_access_control_active() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$positions = get_option( self::ONE_TIME_PURCHASE_SWEEP_OPTION, [] );
		$positions = is_array( $positions ) ? $positions : [];
		$now       = time();
		$updated   = [];
		foreach ( self::get_finite_one_time_purchase_rules() as $duration => $value ) {
			$cutoff   = (int) Access_Rules::get_one_time_purchase_cutoff( $value, $now );
			$position = isset( $positions[ $duration ] ) ? (int) $positions[ $duration ] : $cutoff;
			// Month arithmetic can step the cutoff back near a month's end. Nothing
			// lapses then, and the position waits for the cutoff to pass it again.
			if ( $cutoff > $position ) {
				// The rule grants only to orders created after the cutoff, so an order
				// created at the last position had lapsed already and one created a
				// second later is the first that may have lapsed since.
				$position = self::enqueue_readers_with_orders_between( $value['product_ids'], $position + 1, $cutoff );
			}
			$updated[ $duration ] = $position;
		}
		update_option( self::ONE_TIME_PURCHASE_SWEEP_OPTION, $updated, false );
	}

	/**
	 * Queue a remove-only check for the readers of each paid order, created in a
	 * time range, that contains one of the given products.
	 *
	 * @param int[] $product_ids Product IDs to look for.
	 * @param int   $start       Unix timestamp; orders created at or after it count.
	 * @param int   $end         Unix timestamp; orders created at or before it count.
	 *
	 * @return int The creation time the walk covered up to: $end, or earlier when
	 *             the page cap stopped it.
	 */
	private static function enqueue_readers_with_orders_between( array $product_ids, int $start, int $end ): int {
		$page_size = max( 1, (int) self::$sweep_page_size );
		$query     = [
			// Default order types include refunds, which copy the order's line items
			// but have no customer to check.
			'type'         => 'shop_order',
			'status'       => self::get_paid_order_statuses(),
			'date_created' => $start . '...' . $end,
			'orderby'      => 'date ID',
			'order'        => 'ASC',
			'limit'        => $page_size,
			'return'       => 'objects',
		];
		$user_ids     = [];
		$last_created = null;
		for ( $page = 1; $page <= self::SWEEP_MAX_PAGES; $page++ ) {
			$query['page'] = $page;
			$orders        = \wc_get_orders( $query );
			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$last_created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : $last_created;
				if ( Access_Rules::order_has_product( $order, $product_ids ) ) {
					$user_ids = array_merge( $user_ids, self::get_order_reader_ids( (int) $order->get_customer_id(), (string) $order->get_billing_email() ) );
				}
			}
			if ( count( $orders ) < $page_size ) {
				self::add_users_to_queue( $user_ids, self::SOURCE_ONE_TIME_PURCHASE_ENDED );
				return $end;
			}
		}
		self::add_users_to_queue( $user_ids, self::SOURCE_ONE_TIME_PURCHASE_ENDED );
		if ( null === $last_created ) {
			return $end;
		}
		Logger::log(
			sprintf( 'One-time purchase lapse sweep read %d orders and continues on the next run.', $page_size * self::SWEEP_MAX_PAGES ),
			'PREMIUM-NEWSLETTERS'
		);
		// Orders created in the same second as the last one read may be on the next
		// page, so the next run starts from that second again. max() keeps the sweep
		// moving when one second holds more orders than a run reads: it skips that
		// second's remaining orders rather than re-reading the same ones forever.
		return max( $start, $last_created - 1 );
	}

	/**
	 * The one-time purchase rules of every premium newsletter gate that grants
	 * paid access.
	 *
	 * @return array<int, array{product_ids: int[], duration_value: int, duration_unit: string}> Sanitized rule values.
	 */
	private static function get_one_time_purchase_rules(): array {
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
	 * @return array<string, array{product_ids: int[], duration_value: int, duration_unit: string}> Sanitized
	 *         rule values keyed by duration, e.g. '30 days'.
	 */
	private static function get_finite_one_time_purchase_rules(): array {
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
		return $rules;
	}

	/**
	 * Product and variation IDs that any one-time purchase rule names.
	 *
	 * @return int[]
	 */
	private static function get_one_time_purchase_product_ids(): array {
		$product_ids = [];
		foreach ( self::get_one_time_purchase_rules() as $value ) {
			$product_ids = array_merge( $product_ids, $value['product_ids'] );
		}
		return array_values( array_unique( $product_ids ) );
	}

	/**
	 * Product IDs that let a `woo_order_updated` event through to the order
	 * lookup. The event names a line item by its parent product, so a rule that
	 * names a variation is matched here through the variation's parent.
	 *
	 * @return int[]
	 */
	private static function get_one_time_purchase_event_product_ids(): array {
		$product_ids = self::get_one_time_purchase_product_ids();
		foreach ( $product_ids as $product_id ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$product_ids[] = $parent_id;
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
	private static function get_order_reader_ids( int $customer_id, string $billing_email ): array {
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
	private static function get_paid_order_statuses(): array {
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
	private static function is_remove_only_source( string $source ): bool {
		return in_array( $source, [ self::SOURCE_PLAN_SWITCH, self::SOURCE_ONE_TIME_PURCHASE_ENDED ], true );
	}

	/**
	 * Filter the subscription lists to prevent premium newsletters from being shown when restricted
	 * or when their gate hides them.
	 *
	 * Newsletters builds every reader-facing list surface from the lists this returns: My Account,
	 * sign-up forms, registration and the post-checkout modal. Dropping a hidden list here is what
	 * keeps it off all of them. It also keeps a hidden list out of the set My Account removes a
	 * reader from when they save, so saving that form can never unsubscribe them from it.
	 *
	 * Admin screens keep every list, so an editor can still pick any of them. An admin-ajax
	 * request leaves hidden lists out ({@see leaves_hidden_lists_out()}), and keeps
	 * restricted lists as every admin request does.
	 *
	 * @param array $lists The lists.
	 *
	 * @return array The filtered lists.
	 */
	public static function filter_subscription_lists( $lists ) {
		if ( ! self::leaves_hidden_lists_out() ) {
			return $lists;
		}

		// With no gate of either kind, no list can be restricted, so skip the
		// per-list check entirely.
		if ( empty( self::get_gates() ) && empty( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ) ) ) {
			return $lists;
		}

		// Restricted lists stand down on every admin request, admin-ajax included.
		$is_admin = is_admin();

		// Goes inert with the rest of gating (NPPD-1846). With Audience Management off
		// Access Control restricts nothing at all, and that includes premium lists:
		// restricted lists reappear in signup forms and anyone can join them. That is
		// the intended feature, not a leak — "Audience Management off" is meant to be
		// indistinguishable, for readers, from never having enabled Access Control.
		// The disable confirmation says so before the publisher commits to it.
		$lists = array_values(
			array_filter(
				$lists,
				function( $list ) use ( $is_admin ) {
					return ( $is_admin || ! Content_Restriction_Control::is_post_restricted( false, $list->get_id() ) )
						&& ! self::is_list_hidden( $list->get_id() );
				}
			)
		);
		return $lists;
	}

	/**
	 * Whether lists hidden from readers are left out of this request.
	 *
	 * Admin screens show them, so an editor can still pick any list. An admin-ajax
	 * request isn't an admin screen. Background jobs run under admin-ajax (Action
	 * Scheduler's async runner, Data Events' remote-post dispatch) as well as WP-Cron,
	 * where is_admin() is false. Treating admin-ajax the same way leaves hidden lists
	 * out under either runner, so a job that rewrites a reader's lists from this set
	 * can't drop a hidden one. The access check still adds and removes readers on
	 * hidden lists ({@see is_list_hidden()}). Restricted lists are unaffected:
	 * filter_subscription_lists() keeps them on any admin request. No first-party
	 * reader page renders through admin-ajax.
	 *
	 * The lists filter and the list-gates filter both ask this. The Subscribe block's
	 * "show premium lists" mode only works when they agree.
	 *
	 * @return bool
	 */
	private static function leaves_hidden_lists_out(): bool {
		return ! is_admin() || wp_doing_ajax();
	}

	/**
	 * Remove lists a reader may not join from a sign-up.
	 *
	 * Display is all filter_subscription_lists() decides. Without this, a request
	 * naming a gated list's ID subscribes the contact anyway, and they stay
	 * subscribed until a later event queues their access check.
	 *
	 * A gated list can be joined only by the logged-in reader signing up their
	 * own address, and only if their account has access. The submitted address is
	 * compared with the session reader's own and nothing more: the account behind
	 * it, if there is one, is never looked up. If the result depended on that
	 * account, anyone could submit another person's address and learn from it
	 * whether that person has access.
	 *
	 * @param string[] $lists   Public IDs of the lists requested.
	 * @param array    $contact Contact information, with an `email` key.
	 *
	 * @return string[] The lists the sign-up may proceed with.
	 */
	public static function filter_subscribe_lists( $lists, $contact = [] ) {
		// Reset before any early return, so a refusal recorded here is never read for a later sign-up.
		self::$sign_up_refusal       = '';
		self::$sign_up_refusal_email = '';
		if ( ! is_array( $lists ) || empty( $lists ) || ! self::is_access_control_active() ) {
			return $lists;
		}
		if ( ! self::has_newsletter_gates() ) {
			return $lists;
		}
		if ( ! class_exists( 'Newspack\Newsletters\Subscription_Lists' ) || ! method_exists( 'Newspack\Newsletters\Subscription_List', 'from_public_id' ) ) {
			return $lists;
		}
		$session_user   = wp_get_current_user();
		$email          = is_array( $contact ) && isset( $contact['email'] ) && is_string( $contact['email'] ) ? trim( $contact['email'] ) : '';
		$is_own_address = $session_user->exists() && '' !== $email && 0 === strcasecmp( $email, $session_user->user_email );

		return array_values(
			array_filter(
				$lists,
				function ( $public_id ) use ( $is_own_address, $session_user, $email ) {
					$list = Subscription_List::from_public_id( $public_id );
					// A public ID can name any post, not only a list. Judging those would
					// turn a sign-up attempt into a way to probe every gate on the site,
					// so anything that isn't a list passes through unjudged.
					if ( ! $list || \Newspack\Newsletters\Subscription_Lists::CPT !== get_post_type( $list->get_id() ) ) {
						return true;
					}
					if ( ! Content_Restriction_Control::post_has_restrictions( false, $list->get_id() ) ) {
						return true;
					}
					// The reason comes from the same two facts as the rule: whether this is the
					// session reader's own address, and whether that reader has access. The
					// access check stays behind the address check, so it never runs for an
					// address that isn't theirs.
					if ( ! $is_own_address ) {
						self::$sign_up_refusal       = 'not_own_address';
						self::$sign_up_refusal_email = strtolower( $email );
						return false;
					}
					if ( Content_Restriction_Control::is_post_restricted( false, $list->get_id(), $session_user->ID ) ) {
						self::$sign_up_refusal       = 'no_access';
						self::$sign_up_refusal_email = strtolower( $email );
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * Replace the generic sign-up error with one that explains a premium list
	 * refusal.
	 *
	 * The generic message tells a refused reader nothing. These two say what
	 * happened, and neither says anything about another account's access: the
	 * first goes to every request for an address that is not the session
	 * reader's, whatever that address's account can read, and the second is
	 * about the session reader's own account.
	 *
	 * They do tell a new address from a registered one. A sign-up form can
	 * create an account for a new address and sign the visitor in as it before
	 * the lists are checked, so that visitor gets the second message. The same
	 * response already says as much: it carries the new reader's login cookie.
	 *
	 * @param string $message   The reader-facing message so far.
	 * @param array  $params    Details of the sign-up request, with an `email` key.
	 * @param mixed  $raw_error The error the sign-up produced.
	 *
	 * @return string
	 */
	public static function filter_sign_up_error_message( $message, $params = [], $raw_error = null ) {
		if ( ! is_wp_error( $raw_error ) || 'newspack_newsletters_lists_not_allowed' !== $raw_error->get_error_code() ) {
			return $message;
		}
		$email = is_array( $params ) && isset( $params['email'] ) && is_string( $params['email'] ) ? strtolower( trim( $params['email'] ) ) : '';
		if ( '' === self::$sign_up_refusal || $email !== self::$sign_up_refusal_email ) {
			return $message;
		}
		if ( 'not_own_address' === self::$sign_up_refusal ) {
			return __( 'Sign in with this email address to subscribe to premium newsletters.', 'newspack-plugin' );
		}
		if ( 'no_access' === self::$sign_up_refusal ) {
			return __( 'This account doesn\'t include access to premium newsletters.', 'newspack-plugin' );
		}
		return $message;
	}

	/**
	 * Report the premium newsletter gates that restrict each subscription list.
	 *
	 * Uses the same matcher that decides access, so a gate is listed here exactly
	 * when it can restrict the list: published, with registration or paid access
	 * switched on, and content rules that match. The one exception is a request that
	 * isn't an admin screen ({@see leaves_hidden_lists_out()}), where a list hidden
	 * from readers is left out of the map, so the Subscribe block's "show premium
	 * lists" mode never renders it.
	 *
	 * @param array[] $list_gates Gates keyed by public list ID.
	 *
	 * @return array[] Gates keyed by public list ID. Each gate has `id` and `title`,
	 *                 plus `edit_url` for users who can open the wizard.
	 */
	public static function filter_list_gates( $list_gates ) {
		$list_gates = is_array( $list_gates ) ? $list_gates : [];
		if ( ! self::is_access_control_active() || ! class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
			return $list_gates;
		}
		if ( ! self::has_newsletter_gates() ) {
			return $list_gates;
		}
		// The Premium Newsletters wizard's capability and page slug. The link goes
		// to the wizard's edit route rather than the gate's post editor, which
		// edits the layout readers see and not the gate's rules.
		$can_edit = current_user_can( 'manage_options' );
		foreach ( \Newspack\Newsletters\Subscription_Lists::get_all() as $list ) {
			// The marker must mean the same thing as the sign-up check, which honors
			// a list marked exempt. get_post_gates() alone would mark it as gated
			// while anyone can join it.
			if ( ! Content_Restriction_Control::post_has_restrictions( false, $list->get_id() ) ) {
				continue;
			}
			// The editor reads this map from an admin request and keeps the gate, so it
			// can still name a hidden list.
			if ( self::leaves_hidden_lists_out() && self::is_list_hidden( $list->get_id() ) ) {
				continue;
			}
			$gates = Content_Restriction_Control::get_post_gates( $list->get_id() );
			if ( empty( $gates ) ) {
				continue;
			}
			$list_gates[ $list->get_public_id() ] = array_map(
				function ( $gate ) use ( $can_edit ) {
					$item = [
						'id'    => (int) $gate['id'],
						'title' => (string) $gate['title'],
					];
					if ( $can_edit ) {
						$item['edit_url'] = admin_url( 'admin.php?page=newspack-premium-newsletters#/edit/' . (int) $gate['id'] );
					}
					return $item;
				},
				array_values( $gates )
			);
		}
		return $list_gates;
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
	 * Whether a gate hides a list from readers.
	 *
	 * Asked of the gates that currently restrict the list, so a draft gate, or one with
	 * neither registration nor paid access on, hides nothing. One gate that hides the
	 * list is enough, even when another gate covering it shows it. A list marked exempt
	 * is never hidden: Access Control does not govern it, and the sign-up check and the
	 * list markers already treat it that way. Hiding only changes what readers see: the
	 * access check still adds and removes readers on a hidden list.
	 *
	 * @param int $list_id List post ID.
	 *
	 * @return bool
	 */
	public static function is_list_hidden( int $list_id ): bool {
		// Gates are inert until they take over from Memberships: see is_access_control_active().
		if ( ! self::is_access_control_active() ) {
			return false;
		}
		if ( ! Content_Restriction_Control::post_has_restrictions( false, $list_id ) ) {
			return false;
		}
		foreach ( Content_Restriction_Control::get_post_gates( $list_id ) as $gate ) {
			if ( ! empty( $gate['hide_lists'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the site-wide auto-signup option is on.
	 *
	 * An option that was never saved reads as on. Every read of the option goes
	 * through here, so the access check, the editor's "Use site setting" label and
	 * the migrator's summary can't disagree about a site that never saved it.
	 *
	 * @return bool
	 */
	public static function is_site_auto_signup_on(): bool {
		return (bool) get_option( self::AUTO_SIGNUP_OPTION, 1 );
	}

	/**
	 * Whether a gate signs readers up to its lists automatically.
	 *
	 * A gate set to "on" or "off" decides for itself. Any other value, including a
	 * gate saved before the setting existed, follows the site-wide option, so a site
	 * that never touches the per-gate setting behaves as it always has.
	 *
	 * @param array $gate Gate data from {@see Content_Gate::get_gate()}.
	 *
	 * @return bool
	 */
	private static function gate_signs_up_readers( array $gate ): bool {
		$mode = $gate['auto_signup'] ?? 'default';
		if ( 'on' === $mode ) {
			return true;
		}
		if ( 'off' === $mode ) {
			return false;
		}
		return self::is_site_auto_signup_on();
	}

	/**
	 * Whether a reader who has access to a list is signed up to it automatically.
	 *
	 * Callers ask this only after finding the reader unrestricted for the list, and
	 * a list can sit behind several gates. The gates that admit the reader by their
	 * own rules decide, so a reader who got in through a gate set to "off" is left
	 * to opt in themselves even when another gate covering the list is set to "on"
	 * and does not admit them. One admitting gate set to "on" is enough to sign them up.
	 *
	 * When no gate admits the reader by its own rules, they were let in some other way:
	 * a staff account that skips access checks, a user who can edit the list post, a
	 * list whose gates have no access mode on, or every gate refusing without a layout
	 * to show. The lists' own gates decide then, and any of them resolving to "on" signs
	 * the reader up. With every gate on "default" both paths reduce to the site-wide option,
	 * so that is returned without judging the reader against any gate.
	 *
	 * Callers ask only about a list a published gate names. For any other list the answer
	 * is the site-wide option while no gate sets its own auto-signup, and false once one does.
	 *
	 * @param int $list_id List post ID.
	 * @param int $user_id Reader to decide for.
	 *
	 * @return bool
	 */
	public static function is_auto_signup_list( int $list_id, int $user_id ): bool {
		if ( ! self::has_auto_signup_override() ) {
			return self::is_site_auto_signup_on();
		}

		$admitting_gates = array_filter(
			Content_Restriction_Control::get_post_gates( $list_id ),
			function ( $gate ) use ( $user_id ) {
				return ! Content_Restriction_Control::evaluate_gate( $gate, $user_id )['restricted'];
			}
		);
		if ( ! empty( $admitting_gates ) ) {
			return self::any_gate_signs_up( $admitting_gates );
		}

		$list_gates = array_filter(
			Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', true ),
			function ( $gate ) use ( $list_id ) {
				foreach ( $gate['content_rules'] as $content_rule ) {
					if ( 'newsletters' === $content_rule['slug'] && in_array( $list_id, array_map( 'intval', (array) $content_rule['value'] ), true ) ) {
						return true;
					}
				}
				return false;
			}
		);
		return self::any_gate_signs_up( $list_gates );
	}

	/**
	 * Whether any of the given gates signs readers up automatically.
	 *
	 * @param array[] $gates Gate data from {@see Content_Gate::get_gate()}.
	 *
	 * @return bool
	 */
	private static function any_gate_signs_up( array $gates ): bool {
		foreach ( $gates as $gate ) {
			if ( self::gate_signs_up_readers( $gate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any published premium newsletter gate sets its own auto-signup.
	 *
	 * Reads Content_Gate directly, like can_any_list_auto_signup().
	 *
	 * @return bool
	 */
	private static function has_auto_signup_override(): bool {
		foreach ( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', true ) as $gate ) {
			if ( in_array( $gate['auto_signup'] ?? 'default', [ 'on', 'off' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether auto-signup can apply to any list on the site.
	 *
	 * True when the site-wide option is on, or any gate is set to "on". It is the
	 * cheap test for work that only matters if some list might sign a reader up,
	 * such as the renewal snapshot and the per-list auto-signup lookup. Reads
	 * Content_Gate directly: its cache is flushed on every gate write, where
	 * self::$gates is not.
	 *
	 * @return bool
	 */
	public static function can_any_list_auto_signup(): bool {
		if ( self::is_site_auto_signup_on() ) {
			return true;
		}
		foreach ( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', true ) as $gate ) {
			if ( 'on' === ( $gate['auto_signup'] ?? '' ) ) {
				return true;
			}
		}
		return false;
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
		// A remove-only check never adds lists; see the SOURCE_* constants. Nor does
		// any check while no list can sign a reader up, and asking that once here
		// spares judging the reader against every gate on every list.
		$can_add_lists   = ! self::is_remove_only_source( $source ) && self::can_any_list_auto_signup();
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
			} elseif ( $can_add_lists && self::is_auto_signup_list( (int) $list_id, (int) $user_id ) ) {
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
	 * Single-user form of add_users_to_queue().
	 *
	 * @param int    $user_id The ID of the user to schedule the access check for.
	 * @param string $source  Source event tag (one of the SOURCE_* constants, or
	 *                        empty string for an untagged enqueue).
	 *
	 * @return void
	 */
	private static function add_user_to_queue( $user_id, $source = '' ) {
		self::add_users_to_queue( [ (int) $user_id ], (string) $source );
	}

	/**
	 * Add users to the access-check queue in one write, each entry tagged with
	 * the same source event.
	 *
	 * Entries are deduplicated by user_id. When an entry already exists for the
	 * user, a renewal-source enqueue overrides any non-renewal source, but a
	 * non-renewal source never downgrades an existing renewal entry. This keeps
	 * the renewal-snapshot semantics intact when multiple events fire for the
	 * same user within a single cron window. A remove-only source never replaces
	 * an existing entry either, so it can't cancel a pending check that would add
	 * lists.
	 *
	 * @param int[]  $user_ids The IDs of the users to schedule the access check for.
	 * @param string $source   Source event tag (one of the SOURCE_* constants, or
	 *                         empty string for an untagged enqueue).
	 *
	 * @return void
	 */
	private static function add_users_to_queue( array $user_ids, string $source = '' ): void {
		// Guarded at the chokepoint rather than per handler: the renewal handler
		// reaches this directly rather than through maybe_enqueue_access_check(), and
		// any new entry point would otherwise have to remember on its own. Nothing
		// accumulates while access control is inactive, so re-enabling processes current
		// events rather than a backlog of stale ones.
		if ( ! self::is_access_control_active() ) {
			return;
		}
		$user_ids = array_unique( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( empty( $user_ids ) ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, [] );

		foreach ( $user_ids as $user_id ) {
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
		// membership until their next subscription event. Accepted deliberately —
		// acting on hours-old subscription state is the worse failure — but it means
		// the off window is not free, and a reconciliation sweep on re-enable is the
		// fix if that ever bites. One-time purchase lapses are the exception: they
		// come from order dates rather than stored events, so the lapse sweep keeps
		// its position and catches up once access control comes back. One-time
		// purchases and refunds made during the window are not reconciled.
		if ( ! self::is_access_control_active() ) {
			if ( wp_next_scheduled( self::SCHEDULED_HOOK ) || ! empty( get_option( self::QUEUE_OPTION, [] ) ) ) {
				self::unschedule_access_check_event();
			}
			return;
		}
		if ( ! wp_next_scheduled( self::SCHEDULED_HOOK ) ) {
			// The lapse sweep waits for the event scheduled here, which is due at once:
			// after a long off window its catch-up is too much work for a page load.
			self::process_queued_checks();
			wp_schedule_event( time(), 'hourly', self::SCHEDULED_HOOK );
		}
	}

	/**
	 * Process all pending access checks, then run the one-time purchase lapse
	 * sweep and process the checks it queued.
	 *
	 * Registered as the callback for the SCHEDULED_HOOK cron event. Checks
	 * already queued run before the sweep, so a sweep that dies, even on a fatal
	 * error no catch can stop, delays only lapses rather than every reader's
	 * check. A sweep that fails keeps its previous position, so the next run
	 * retries the same orders. The failure goes to the persistent Newspack log as
	 * well, since a sweep that keeps failing is otherwise invisible: readers whose
	 * access ended stay on premium lists, and nothing else reports it.
	 *
	 * @return void
	 */
	public static function process_access_check_queue() {
		self::process_queued_checks();
		try {
			self::enqueue_lapsed_one_time_purchases();
		} catch ( \Throwable $e ) {
			$message = sprintf( 'One-time purchase lapse sweep failed: %s', $e->getMessage() );
			Logger::log( $message, 'PREMIUM-NEWSLETTERS' );
			// An empty email keeps the log from naming whoever's request ran the sweep.
			Logger::newspack_log(
				'newspack_premium_newsletters_sweep',
				$message,
				[
					'file'       => 'newspack_premium_newsletters',
					'user_email' => '',
				],
				'error'
			);
		}
		self::process_queued_checks();
	}

	/**
	 * Process all pending access checks from the queue.
	 *
	 * Each entry is processed in its own try/catch so a single bad entry (e.g. a
	 * deleted list post referenced from the restriction rules) cannot abort the
	 * rest of the batch. The queue is cleared after the loop completes; if a
	 * transient ESP failure occurs check_access() leaves the renewal snapshot in
	 * place so the next enqueue for that user still respects it.
	 *
	 * @return void
	 */
	private static function process_queued_checks(): void {
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
		// Capture the renewal-time snapshot when auto-signup can apply to any list.
		// Without it the snapshot has no effect (check_access only consults it
		// inside the auto-signup branch), so skip the ESP fetch in that case.
		if ( self::can_any_list_auto_signup() && class_exists( 'Newspack_Newsletters_Subscription' ) ) {
			$email         = $user->user_email;
			$current_lists = Newspack_Newsletters_Subscription::get_contact_lists( $email );
			if ( is_array( $current_lists ) ) {
				update_user_meta( $user->ID, self::SUBSCRIBED_LISTS_META_KEY, $current_lists );
			}
		}

		// Always enqueue the renewal-source check so the snapshot governs THIS
		// access check (and only this one). If product_subscription_changed also
		// fires for the same user, the dedup logic in add_users_to_queue() keeps
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
