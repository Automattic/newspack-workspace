<?php
/**
 * The `accessible_gates` reader data item and the Campaigns criteria that read it.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a list of the paid content gates each reader can pass, so prompts and
 * pricing offers can target "can / cannot access gate X".
 *
 * Access here means holding a product a gate's rules require: a subscription
 * the reader owns, a seat in someone else's group subscription, or a one-time
 * purchase. `active_subscriptions` sees only the first, and
 * `active_memberships` stops changing once WooCommerce Memberships is off.
 *
 * Each reader's list carries a stamp: the gate configuration it was computed
 * against and when. A page view recomputes a list that is missing, was
 * computed before a gate changed, or is more than a day old. The age limit is
 * what catches access that ends with no event, such as a one-time purchase
 * running out.
 */
final class Gate_Access_Reader_Data {
	const STORE_KEY = 'accessible_gates';

	/**
	 * User meta holding the stamp of the reader's stored list.
	 */
	const STAMP_META_KEY = 'newspack_accessible_gates_stamp';

	/**
	 * Option holding the gate configuration version, changed on any gate write.
	 */
	const GATES_VERSION_OPTION = 'newspack_accessible_gates_version';

	/**
	 * Rule types that grant access by holding a product.
	 */
	const PRODUCT_RULES = [ 'subscription', 'one_time_purchase' ];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_reader_data_read_only_keys', [ __CLASS__, 'register_read_only_key' ] );
		add_filter( 'newspack_popups_default_criteria', [ __CLASS__, 'register_criteria' ] );
		add_action( 'init', [ __CLASS__, 'register_data_event_handlers' ] );

		// Joining or leaving a group fires no Data Event naming the member.
		add_action( 'added_user_meta', [ __CLASS__, 'handle_group_membership_change' ], 10, 3 );
		add_action( 'deleted_user_meta', [ __CLASS__, 'handle_group_membership_change' ], 10, 3 );

		// Any write to a gate can change who passes it.
		add_action( 'save_post_' . Content_Gate::GATE_CPT, [ __CLASS__, 'bump_gates_version' ] );
		add_action( 'deleted_post', [ __CLASS__, 'handle_post_deleted' ], 10, 2 );
		add_action( 'added_post_meta', [ __CLASS__, 'handle_post_meta_change' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'handle_post_meta_change' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'handle_post_meta_change' ], 10, 3 );

		// Runs before Reader_Data hands the stored items to the browser (priority 10
		// on both paths).
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_backfill_current_reader' ], 9 );
		add_filter( 'newspack_session_hydration_response', [ __CLASS__, 'maybe_backfill_hydrated_reader' ], 9, 2 );
	}

	/**
	 * Whether Access Control is on. Gates do not restrict anything without it, so
	 * neither the item nor the criteria mean anything either.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Content_Gate::is_newspack_feature_enabled();
	}

	/**
	 * Mark the item server-owned, so a reader cannot write their way into or out
	 * of a segment.
	 *
	 * @param string[] $keys Read-only keys.
	 *
	 * @return string[]
	 */
	public static function register_read_only_key( array $keys ): array {
		$keys[] = self::STORE_KEY;
		return $keys;
	}

	/**
	 * Register the Data Event handlers that keep the item current.
	 */
	public static function register_data_event_handlers(): void {
		Data_Events::register_handler( [ __CLASS__, 'handle_user_event' ], 'reader_logged_in' );
		Data_Events::register_handler( [ __CLASS__, 'handle_product_subscription_changed' ], 'product_subscription_changed' );
		Data_Events::register_handler( [ __CLASS__, 'handle_woo_order_updated' ], 'woo_order_updated' );
	}

	/**
	 * A gate's rule groups that grant access by product alone.
	 *
	 * A group mixing in another rule (email domain, institution, reader data) is
	 * left out: those answer differently depending on the request that asks, and
	 * they are not a purchase.
	 *
	 * @param array $gate Gate as Content_Gate::get_gate() returns it.
	 *
	 * @return array[] Rule groups.
	 */
	public static function get_product_rule_groups( array $gate ): array {
		$groups = Access_Rules::normalize_rules( $gate['custom_access']['access_rules'] ?? [] );
		return array_values(
			array_filter(
				$groups,
				fn( $group ) => is_array( $group ) && ! empty( $group ) && ! array_filter(
					$group,
					fn( $rule ) => ! in_array( $rule['slug'] ?? '', self::PRODUCT_RULES, true )
				)
			)
		);
	}

	/**
	 * Paid gates: published, with custom access on and at least one rule group
	 * that grants access by product.
	 *
	 * @return array[] Gates as Content_Gate::get_gate() returns them.
	 */
	public static function get_paid_gates(): array {
		return array_values(
			array_filter(
				Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ),
				fn( $gate ) => ! is_wp_error( $gate ) && ! empty( $gate['custom_access']['active'] ) && self::get_product_rule_groups( $gate )
			)
		);
	}

	/**
	 * The IDs of the paid gates a reader holds a product for.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return int[]
	 */
	public static function get_accessible_gate_ids( int $user_id ): array {
		$gate_ids = [];
		foreach ( self::get_paid_gates() as $gate ) {
			$context = [ 'payment_recovery_grace' => $gate['custom_access']['payment_recovery_grace'] ?? true ];
			if ( Access_Rules::evaluate_rules( self::get_product_rule_groups( $gate ), $user_id, $context ) ) {
				$gate_ids[] = (int) $gate['id'];
			}
		}
		return $gate_ids;
	}

	/**
	 * The current gate configuration version.
	 *
	 * @return string
	 */
	private static function get_gates_version(): string {
		return (string) get_option( self::GATES_VERSION_OPTION, '' );
	}

	/**
	 * Mark every reader's list stale. Each recomputes on its own next page view.
	 */
	public static function bump_gates_version(): void {
		// Autoloaded: every logged-in page view reads it.
		update_option( self::GATES_VERSION_OPTION, uniqid( '', true ), true );
	}

	/**
	 * Bump the version when a gate is deleted. The row is already gone by then, so
	 * the type comes from the post object core passes along.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The deleted post.
	 */
	public static function handle_post_deleted( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && Content_Gate::GATE_CPT === $post->post_type ) {
			self::bump_gates_version();
		}
	}

	/**
	 * Bump the version when a gate's access rules change. They are stored with a
	 * bare update_post_meta() call, which fires no save_post. Only `custom_access`
	 * decides access, so the key is checked before the post type: these hooks fire
	 * for every post meta write on the site.
	 *
	 * @param int|int[] $meta_ids Meta ID(s).
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 */
	public static function handle_post_meta_change( $meta_ids, $post_id, $meta_key ): void {
		if ( 'custom_access' === $meta_key && Content_Gate::GATE_CPT === get_post_type( $post_id ) ) {
			self::bump_gates_version();
		}
	}

	/**
	 * Mark one reader's list stale.
	 *
	 * @param int $user_id User ID.
	 */
	private static function mark_stale( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::STAMP_META_KEY );
		}
	}

	/**
	 * Recompute and store the reader's item. A reader with no access gets an
	 * empty list rather than no item, so a "cannot access" segment matches them
	 * on a known answer. An unchanged list is not rewritten, which keeps
	 * `newspack_reader_data_updated` listeners quiet.
	 *
	 * The stamp is written even when the item write is refused (a reader at the
	 * reader data key cap). Staleness is read from the stamp alone, so that
	 * reader is not recomputed on every page view.
	 *
	 * @param int $user_id User ID.
	 */
	public static function refresh( int $user_id ): void {
		if ( $user_id <= 0 || ! self::is_enabled() ) {
			return;
		}
		// The memo lives as long as the process, and an Action Scheduler run handles
		// several Data Events in one: a purchase made after an earlier event in the
		// same run would otherwise read that event's answer.
		Access_Rules::flush_one_time_purchase_memo();
		$value = wp_json_encode( self::get_accessible_gate_ids( $user_id ) );
		if ( Reader_Data::get_data( $user_id, self::STORE_KEY ) !== $value ) {
			Reader_Data::update_item( $user_id, self::STORE_KEY, $value );
		}
		update_user_meta(
			$user_id,
			self::STAMP_META_KEY,
			[
				'version'  => self::get_gates_version(),
				'computed' => time(),
			]
		);
	}

	/**
	 * Whether a reader's stored list needs recomputing.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	private static function is_stale( int $user_id ): bool {
		$stamp = get_user_meta( $user_id, self::STAMP_META_KEY, true );
		return ! is_array( $stamp )
			|| ( $stamp['version'] ?? null ) !== self::get_gates_version()
			|| (int) ( $stamp['computed'] ?? 0 ) < time() - DAY_IN_SECONDS;
	}

	/**
	 * Data Event handler for events that carry the reader's `user_id`.
	 *
	 * @param int   $timestamp Event timestamp.
	 * @param array $data      Event data.
	 */
	public static function handle_user_event( $timestamp, $data ): void {
		self::refresh( (int) ( $data['user_id'] ?? 0 ) );
	}

	/**
	 * Data Event handler for `product_subscription_changed`. The event names the
	 * subscription's purchaser only. A gift's access belongs to its recipient, and
	 * a group subscription's to its members too, so those readers are marked
	 * stale, at the cost of one meta delete each.
	 *
	 * @param int   $timestamp Event timestamp.
	 * @param array $data      Event data.
	 */
	public static function handle_product_subscription_changed( $timestamp, $data ): void {
		self::refresh( (int) ( $data['user_id'] ?? 0 ) );
		if ( empty( $data['subscription_id'] ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$subscription = \wcs_get_subscription( (int) $data['subscription_id'] );
		if ( ! $subscription ) {
			return;
		}
		if ( class_exists( 'WCS_Gifting' ) && \WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			self::mark_stale( (int) \WCS_Gifting::get_recipient_user( $subscription ) );
		}
		if ( ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return;
		}
		foreach ( Group_Subscription::get_members( $subscription ) as $member_id ) {
			self::mark_stale( (int) $member_id );
		}
	}

	/**
	 * Data Event handler for `woo_order_updated`. A one-time purchase rule grants
	 * access while the order counts as paid, so the buyer is refreshed when an
	 * order starts or stops counting.
	 *
	 * @param int   $timestamp Event timestamp.
	 * @param array $data      Event data.
	 */
	public static function handle_woo_order_updated( $timestamp, $data ): void {
		if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
			return;
		}
		$paid_statuses = \wc_get_is_paid_statuses();
		$was_paid      = in_array( $data['status_from'] ?? '', $paid_statuses, true );
		$is_paid       = in_array( $data['status'] ?? '', $paid_statuses, true );
		if ( $was_paid !== $is_paid ) {
			self::refresh( (int) ( $data['user_id'] ?? 0 ) );
		}
	}

	/**
	 * Mark a reader stale when they join or leave a group subscription. Bulk adds
	 * in the admin fire this once per member, so it stays a meta delete.
	 *
	 * @param int|int[] $meta_ids Meta ID(s).
	 * @param int       $user_id  User whose meta changed.
	 * @param string    $meta_key Meta key.
	 */
	public static function handle_group_membership_change( $meta_ids, $user_id, $meta_key ): void {
		if ( Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY === $meta_key ) {
			self::mark_stale( (int) $user_id );
		}
	}

	/**
	 * Recompute a missing or stale list.
	 *
	 * A switched session belongs to an admin browsing as the reader, which
	 * Reader_Data never lets write the reader's data.
	 *
	 * @param int $user_id User ID.
	 */
	private static function maybe_backfill( int $user_id ): void {
		if ( $user_id > 0 && self::is_enabled() && ! Reader_Data::is_switched_session() && self::is_stale( $user_id ) ) {
			self::refresh( $user_id );
		}
	}

	/**
	 * Backfill the item for the logged-in reader on a page render.
	 */
	public static function maybe_backfill_current_reader(): void {
		self::maybe_backfill( get_current_user_id() );
	}

	/**
	 * Backfill the item before the session hydration response reads it, the path
	 * a reader on a cached page takes.
	 *
	 * @param array $data    Hydration response data.
	 * @param int   $user_id The authenticated user's ID.
	 *
	 * @return array Unchanged response data.
	 */
	public static function maybe_backfill_hydrated_reader( $data, $user_id ) {
		self::maybe_backfill( (int) $user_id );
		return $data;
	}

	/**
	 * Register the "Can access" and "Cannot access" Campaigns criteria.
	 *
	 * Options are computed in admin only: the segments editor is their sole
	 * consumer, and a list criterion with options renders there as a checkbox list.
	 * Values are strings because the editor compares checkbox values strictly; the
	 * front-end list matchers compare loosely against the stored integer IDs.
	 *
	 * @param array $criteria Default criteria.
	 *
	 * @return array
	 */
	public static function register_criteria( array $criteria ): array {
		if ( ! self::is_enabled() ) {
			return $criteria;
		}
		// Admin-ajax and heartbeat requests are admin too, but never render the editor.
		$options = is_admin() && ! wp_doing_ajax() ? self::get_gate_options() : [];

		$criteria['can_access_gates']    = [
			'name'               => __( 'Can access content gate(s)', 'newspack-plugin' ),
			'description'        => __( 'If the reader has paid access to any of the selected content gates, through a subscription they own, a group subscription they belong to, or a purchase.', 'newspack-plugin' ),
			'category'           => 'reader_revenue',
			'matching_function'  => 'list__in',
			'matching_attribute' => self::STORE_KEY,
			'options'            => $options,
		];
		$criteria['cannot_access_gates'] = [
			'name'               => __( 'Cannot access content gate(s)', 'newspack-plugin' ),
			'description'        => __( 'If the reader has paid access to none of the selected content gates.', 'newspack-plugin' ),
			'category'           => 'reader_revenue',
			'matching_function'  => 'list__not_in',
			'matching_attribute' => self::STORE_KEY,
			'options'            => $options,
		];
		return $criteria;
	}

	/**
	 * Paid gates as criterion options.
	 *
	 * @return array[] [ 'label' => string, 'value' => string ] pairs.
	 */
	private static function get_gate_options(): array {
		return array_map(
			fn( $gate ) => [
				'label' => $gate['title'],
				'value' => (string) $gate['id'],
			],
			self::get_paid_gates()
		);
	}
}
Gate_Access_Reader_Data::init();
