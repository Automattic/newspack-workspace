<?php
/**
 * Subscribers wizard donor rules.
 *
 * Decides what kind of donor a reader is, for the badge on the Subscribers list
 * and profile, and builds the reader sets the Donor filter narrows by. The badge
 * reads kind(); the filter's set builders and the backfill command mirror its
 * rules over many readers at once, so a change to the rules has to land in all
 * three.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Donor rules for the Subscribers wizard.
 */
class Subscribers_Donors {

	/**
	 * A reader whose only tie to donating is a one-time gift.
	 *
	 * @var string
	 */
	public const KIND_ONE_TIME = 'one-time';

	/**
	 * A reader with a live recurring donation.
	 *
	 * @var string
	 */
	public const KIND_RECURRING = 'recurring';

	/**
	 * A reader whose recurring donation ended.
	 *
	 * @var string
	 */
	public const KIND_FORMER = 'former';

	/**
	 * How many of a reader's latest completed orders the last-donation lookup reads.
	 *
	 * The lookup runs once per One-time donor row, so it can't page through a
	 * reader's whole history the way the ESP sync's one-off lookup does. A donation
	 * older than this many completed orders is not found, and the row has nothing to
	 * link. Raising it makes every One-time donor row on a page read more orders.
	 *
	 * @var int
	 */
	public const ORDER_SCAN_LIMIT = 50;

	/**
	 * The stored values a donor flag query treats as set.
	 *
	 * Reader_Data::get_bool() decodes the stored JSON, so the row reads any truthy
	 * value as set. These are the forms code writes for true: the server stores
	 * wp_json_encode( true ), and the reader-data REST route stores the browser's
	 * JSON.stringify( true ) as sent, both `'true'`. `'1'` is what either path
	 * stores for the number 1, and what update_user_meta() keeps for a raw PHP
	 * true. A flag stored in any other truthy form still reads as set on the row
	 * but is missed by the filter.
	 *
	 * @var string[]
	 */
	private const TRUE_FLAG_VALUES = [ 'true', '1' ];

	/**
	 * Whether donor labels apply on this site.
	 *
	 * Only WooCommerce donations can be told apart. On the other platforms the
	 * browser sets the `is_donor` flag, any logged-in reader can set it on
	 * themselves, and it can't separate one-time from recurring. The badge, the
	 * filter, the `donor` response key and the backfill command all check this
	 * first, so those sites see no change.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Donations::is_platform_wc();
	}

	/**
	 * The kind of donor a reader is.
	 *
	 * SOURCE OF TRUTH for the donor-kind rule. A reader gets at most one kind;
	 * check in this order and stop at the first match (the order the ESP sync's
	 * `Donor_Status` field uses, so the dashboard and the publisher's ESP agree):
	 *
	 *   1. Recurring: holds a donation subscription whose mapped status is
	 *      `active` or `on-hold`.
	 *   2. Former: holds a donation subscription whose mapped status is
	 *      `cancelled` (WooCommerce `cancelled` or `expired`), or has the
	 *      `is_former_donor` flag.
	 *   3. One-time: has the `is_donor` flag and holds no counted donation
	 *      subscription.
	 *   4. None: everyone else.
	 *
	 * A `pending` donation subscription never took its first payment, so it counts
	 * toward no kind, and it doesn't stop a real one-time donor reading as
	 * One-time. Former wins over One-time: a reader who gave once and later
	 * cancelled a recurring donation stays Former even if a later one-time gift set
	 * `is_donor` again.
	 *
	 * Two edges are deliberate. An unpaid, cancelled donation subscription (an
	 * abandoned recurring checkout) reads as Former, as it does in the ESP, because
	 * telling it apart would cost an order lookup per ended subscription in every
	 * row and in the Former filter scan. And the donor is the subscription's
	 * holder, not its payer: for a gifted recurring donation that is the
	 * recipient, matching how get_individual_subscriptions() assigns subscriptions.
	 * The `donation_new` event flags the payer, so a payer with no other donations
	 * reads as One-time. Every caller must resolve the holder the same way.
	 *
	 * It is re-encoded in these places, each of which must be changed in step or
	 * the endpoint's Donor filter will contradict the badge it filters on:
	 *   - Subscribers_Wizard::customer_ids_for_donor_kind() — the endpoint's
	 *     `donor` filter, which builds each kind's reader set from holders() and
	 *     flagged_user_ids() instead of calling this per reader.
	 *   - holders() — which donation subscriptions count, and as live or ended.
	 *   - Newspack\CLI\Donors::backfill() — the support command that flags donors
	 *     from before the flag existed, so they land in the kind this returns.
	 *
	 * It reads nothing but the reader's two flags and the statuses it is given:
	 * the caller resolves which donation subscriptions the reader holds. Callers
	 * check is_enabled() first.
	 *
	 * @param int      $user_id           The reader's user ID.
	 * @param string[] $donation_statuses The mapped statuses
	 *                                    (Subscribers_Wizard::map_subscription_status())
	 *                                    of the donation subscriptions the reader holds.
	 *
	 * @return string One of the KIND_* constants, or '' for a reader who isn't a donor.
	 */
	public static function kind( int $user_id, array $donation_statuses ): string {
		if ( in_array( 'active', $donation_statuses, true ) || in_array( 'on-hold', $donation_statuses, true ) ) {
			return self::KIND_RECURRING;
		}
		if ( in_array( 'cancelled', $donation_statuses, true ) || Reader_Data::get_bool( $user_id, 'is_former_donor' ) ) {
			return self::KIND_FORMER;
		}
		if ( Reader_Data::get_bool( $user_id, 'is_donor' ) ) {
			return self::KIND_ONE_TIME;
		}
		return '';
	}

	/**
	 * A reader's latest completed donation order.
	 *
	 * Reads the reader's newest completed orders, up to ORDER_SCAN_LIMIT, and stops
	 * at the first donation. Only a completed order counts: a processing one may
	 * still fail. The query asks for `shop_order` because WooCommerce otherwise
	 * returns refunds alongside orders. Callers check is_enabled() first and ask
	 * only for One-time donors, so no other row pays for this query.
	 *
	 * @param int $user_id The reader's user ID.
	 *
	 * @return \WC_Order|null The order, or null when none of the scanned orders is a
	 *                        donation.
	 */
	public static function latest_donation_order( int $user_id ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$orders = \wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => [ 'wc-completed' ],
				'type'        => 'shop_order',
				'limit'       => self::ORDER_SCAN_LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);
		foreach ( $orders as $order ) {
			if ( Donations::is_donation_order( $order ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * The products whose subscriptions are donations.
	 *
	 * The legacy donation product's children plus every product flagged as a
	 * donation, the same pair Donations::get_order_donation_product_id() checks. A
	 * flagged product in the trash is not in the flagged list, so subscriptions
	 * still running on it are missed here while the row counts them.
	 *
	 * @return int[]
	 */
	public static function donation_product_ids(): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$ids = array_merge(
			array_values( Donations::get_donation_product_child_products_ids() ),
			Donations::get_flagged_donation_product_ids()
		);
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), fn( $id ) => $id > 0 ) ) );
	}

	/**
	 * The holders of counted donation subscriptions, as live and ended.
	 *
	 * Mirrors the subscriptions the row hands kind() (see
	 * Subscribers_Wizard::get_individual_subscriptions()): each donation
	 * subscription counts for its holder, so a gift counts for its recipient, and a
	 * group subscription counts for no one, because the row shows it with the
	 * reader's groups. Each is bucketed by its mapped status the way kind() reads
	 * it: `active` and `on-hold` are live, `cancelled` is ended, and `pending` is
	 * neither. Bucketing the mapped status, not a list of raw ones, keeps an
	 * unrecognized status where the row puts it: on hold, so live.
	 *
	 * Subscriptions managed on other network sites carry no product data, so they
	 * never count, as on the row. A reader can be in both lists.
	 *
	 * The lookup is one SQL query across every donation product at once, so
	 * $limit is a budget for all of them together: a product with few or no
	 * subscriptions, such as a one-time donation product, leaves the rest to the
	 * others, whatever order the products come in. The budget counts subscriptions
	 * of every status, pending and ended ones included. Past it, the subscriptions
	 * left out count for no one, so a reader whose only live donation subscription
	 * was left out is missing from `live`. The subscriptions found are hydrated
	 * Subscribers_Wizard::FILTER_SCAN_CHUNK at a time to keep peak memory at one
	 * chunk.
	 *
	 * @param int $limit The most donation subscriptions to read across all donation
	 *                   products, or 0 for all of them.
	 *
	 * @return array{live:int[],ended:int[]} Holder user IDs.
	 */
	public static function holders( int $limit = 0 ): array {
		$holders = [
			'live'  => [],
			'ended' => [],
		];
		$product_ids = self::donation_product_ids();
		if ( empty( $product_ids ) || ! function_exists( 'wcs_get_subscriptions_for_product' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return $holders;
		}
		$subscription_ids = \wcs_get_subscriptions_for_product( $product_ids, 'ids', $limit > 0 ? [ 'limit' => $limit ] : [] );
		foreach ( array_chunk( $subscription_ids, Subscribers_Wizard::FILTER_SCAN_CHUNK ) as $chunk ) {
			$subscriptions = array_filter( array_map( 'wcs_get_subscription', $chunk ) );
			Subscribers_Wizard::prime_gift_recipients( $subscriptions );
			foreach ( $subscriptions as $subscription ) {
				$bucket = match ( Subscribers_Wizard::map_subscription_status( $subscription->get_status() ) ) {
					'active', 'on-hold' => 'live',
					'cancelled'         => 'ended',
					default             => '',
				};
				if ( '' === $bucket || ! empty( Group_Subscription_Settings::get_subscription_settings( $subscription )['enabled'] ) ) {
					continue;
				}
				$holders[ $bucket ][] = WooCommerce_Subscriptions_Gifting::get_holder_id( $subscription );
			}
		}
		return [
			'live'  => array_values( array_unique( array_filter( $holders['live'] ) ) ),
			'ended' => array_values( array_unique( array_filter( $holders['ended'] ) ) ),
		];
	}

	/**
	 * Readers with a donor flag set.
	 *
	 * @param string $key   The Reader_Data key: `is_donor` or `is_former_donor`.
	 * @param int    $limit The most reader IDs to return.
	 *
	 * @return int[]
	 */
	public static function flagged_user_ids( string $key, int $limit ): array {
		$query = new \WP_User_Query(
			[
				'role__in'     => Reader_Activation::get_reader_roles(),
				'meta_key'     => Reader_Data::get_meta_key_name( $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => self::TRUE_FLAG_VALUES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'IN',
				'fields'       => 'ID',
				'number'       => $limit,
				'count_total'  => false,
			]
		);
		return array_map( 'intval', $query->get_results() );
	}
}
