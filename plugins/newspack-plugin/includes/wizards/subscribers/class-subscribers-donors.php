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
	 * link. Raising it lets a One-time donor row whose gift is further back load
	 * more orders before it finds it, or gives up.
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
	 *      `active` or `on-hold`, other than a `switched` one.
	 *   2. Former: holds a donation subscription whose mapped status is
	 *      `cancelled` (WooCommerce `cancelled` or `expired`), or has the
	 *      `is_former_donor` flag.
	 *   3. One-time: has the `is_donor` flag and holds no counted donation
	 *      subscription.
	 *   4. None: everyone else.
	 *
	 * Two donation subscriptions count toward no kind. A `pending` one never took
	 * its first payment. A `switched` one is the subscription a reader switched
	 * away from: the ESP sync counts it neither active nor former, and the
	 * replacement subscription carries the donation on. Its mapped status is
	 * `on-hold`, so the rule reads the raw status to leave it out. Neither stops a
	 * real one-time donor reading as One-time. Former wins over One-time: a reader
	 * who gave once and later cancelled a recurring donation stays Former even if
	 * a later one-time gift set `is_donor` again.
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
	 * It is re-encoded on the server in these places, each of which must be changed
	 * in step or the endpoint's Donor filter will contradict the badge it filters
	 * on:
	 *   - Subscribers_Wizard::customer_ids_for_donor_kind() — the endpoint's
	 *     `donor` filter, which builds each kind's reader set from holders() and
	 *     flagged_user_ids() instead of calling this per reader.
	 *   - holders() — which donation subscriptions count.
	 *   - scanned_statuses() — which subscription statuses holders() reads.
	 *   - Newspack\CLI\Donors::backfill() — the support command that flags donors
	 *     from before the flag existed, so they land in the kind this returns.
	 *
	 * The live/ended split in rules 1 and 2, and the statuses that count toward no
	 * kind, are written once, in bucket(), which this and scanned_statuses() share.
	 *
	 * One gap is accepted rather than closed. The row leaves out a group
	 * subscription by its own settings, but holders() can't run those in SQL, so
	 * it leaves out the site's group list instead, which reads some subscriptions
	 * differently: one with any line item on a group product (the settings read
	 * the first), `enabled` meta on the subscription or its product that isn't
	 * exactly `yes` or `no`, the two settings
	 * filters, and a five-minute cache. For such a donation subscription the
	 * Donor filter disagrees with this badge. See holders() and the matching note
	 * in the Subscribers_Index class docblock.
	 *
	 * The client reads `donor.kind` and never re-derives it, but it has to know
	 * the kinds: src/wizards/subscribers/donor.js holds the labels, the badge
	 * intents, donorKind() and the Donor filter's options, including the `none`
	 * value for a non-donor (DONOR_FILTER_ELEMENTS). The Donor field's getValue
	 * in screens/SubscriberList.jsx maps a row's '' to that `none`.
	 * screens/PersonProfile.jsx shows the badge.
	 *
	 * kind() reads nothing but the reader's two flags and the statuses it is given:
	 * the caller resolves which donation subscriptions the reader holds. Callers
	 * check is_enabled() first.
	 *
	 * @param int      $user_id           The reader's user ID.
	 * @param string[] $donation_statuses The raw WooCommerce Subscriptions statuses,
	 *                                    without the `wc-` prefix, of the donation
	 *                                    subscriptions the reader holds.
	 *
	 * @return string One of the KIND_* constants, or '' for a reader who isn't a donor.
	 */
	public static function kind( int $user_id, array $donation_statuses ): string {
		$buckets = array_map( [ __CLASS__, 'bucket' ], $donation_statuses );
		if ( in_array( 'live', $buckets, true ) ) {
			return self::KIND_RECURRING;
		}
		if ( in_array( 'ended', $buckets, true ) || Reader_Data::get_bool( $user_id, 'is_former_donor' ) ) {
			return self::KIND_FORMER;
		}
		if ( Reader_Data::get_bool( $user_id, 'is_donor' ) ) {
			return self::KIND_ONE_TIME;
		}
		return '';
	}

	/**
	 * Whether a donation subscription in this status is live or ended.
	 *
	 * A `switched` subscription counts toward no kind (see kind()), so it is left
	 * out before the status is mapped. Every other status is placed by its mapped
	 * status (Subscribers_Wizard::map_subscription_status()), as the row shows it.
	 *
	 * @param string $status A raw WooCommerce Subscriptions status, without the `wc-` prefix.
	 *
	 * @return string `live` for a mapped `active` or `on-hold`, `ended` for a mapped
	 *                `cancelled`, or '' for a status that counts toward no kind:
	 *                `pending` and `switched`.
	 */
	private static function bucket( string $status ): string {
		if ( 'switched' === $status ) {
			return '';
		}
		return match ( Subscribers_Wizard::map_subscription_status( $status ) ) {
			'active', 'on-hold' => 'live',
			'cancelled'         => 'ended',
			default             => '',
		};
	}

	/**
	 * A reader's latest completed donation order.
	 *
	 * Asks for the IDs of the reader's newest completed orders, up to
	 * ORDER_SCAN_LIMIT, then loads them one at a time, newest first, and stops at
	 * the first donation. Renewal orders are `shop_order` too, so a plan's
	 * renewals can sit in front of the latest gift; loading the orders one at a
	 * time means only those in front of it are loaded. Only a completed order
	 * counts: a processing one may still fail. The query asks for `shop_order`
	 * because WooCommerce otherwise returns refunds alongside orders. Callers check
	 * is_enabled() first and ask only for One-time donors, so no other row pays
	 * for this query. It is one query, plus one order load, and a read of its line
	 * items, per order up to and including the first donation. A reader whose
	 * window holds no donation costs ORDER_SCAN_LIMIT loads.
	 *
	 * @param int $user_id The reader's user ID.
	 *
	 * @return \WC_Order|null The order, or null when none of the scanned orders is a
	 *                        donation.
	 */
	public static function latest_donation_order( int $user_id ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order_ids = \wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => [ 'wc-completed' ],
				'type'        => 'shop_order',
				'limit'       => self::ORDER_SCAN_LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'ids',
			]
		);
		foreach ( $order_ids as $order_id ) {
			$order = \wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && Donations::is_donation_order( $order ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * The products whose subscriptions are donations.
	 *
	 * The legacy donation product's children plus every product flagged as a
	 * donation, the same pair Donations::get_order_donation_product_id() checks.
	 * The flagged products are looked up here rather than through
	 * Donations::get_flagged_donation_product_ids(), which leaves out the trash:
	 * the row marks a subscription as a donation from the product's flag whatever
	 * the product's status (Donations::is_donation_product()), so subscriptions
	 * still running on a trashed donation product have to be scanned too.
	 *
	 * @return int[]
	 */
	public static function donation_product_ids(): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$flagged_ids = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => [ 'any', 'trash' ],
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Donation-flagged products only; small meta-filtered set.
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => WooCommerce_Products::DONATION_FLAG_META_KEY,
						'value' => wc_bool_to_string( true ),
					],
				],
			]
		);
		$ids = array_merge(
			array_values( Donations::get_donation_product_child_products_ids() ),
			$flagged_ids
		);
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), fn( $id ) => $id > 0 ) ) );
	}

	/**
	 * The subscription statuses the donor scan reads, as live and ended.
	 *
	 * Each status WooCommerce Subscriptions registers is placed through bucket(),
	 * as kind() reads it: `active` and `on-hold` are live, `cancelled` is ended,
	 * and `pending` and `switched` are neither, so they are never read. Placing by
	 * the mapped status, not a fixed list of raw ones, keeps a status another
	 * plugin registers where the row puts it: on hold, so live. Statuses
	 * WooCommerce Subscriptions doesn't register, such as the trash, are never
	 * read. The row never sees a trashed subscription either, because WooCommerce
	 * Subscriptions leaves it out of a reader's list.
	 *
	 * @return array{live:string[],ended:string[]} Statuses without the `wc-` prefix.
	 */
	private static function scanned_statuses(): array {
		$statuses = [
			'live'  => [],
			'ended' => [],
		];
		foreach ( array_keys( \wcs_get_subscription_statuses() ) as $key ) {
			$status = str_starts_with( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			$bucket = self::bucket( $status );
			if ( '' !== $bucket ) {
				$statuses[ $bucket ][] = $status;
			}
		}
		return $statuses;
	}

	/**
	 * The holders of counted donation subscriptions, as live and ended.
	 *
	 * Mirrors the subscriptions the row hands kind() (see
	 * Subscribers_Wizard::get_individual_subscriptions()), read in SQL through
	 * Subscribers_Index::holder_ids(), so no subscription is loaded. Each donation
	 * subscription counts for its holder: a gift counts for its recipient when the
	 * recipient's account exists, as
	 * WooCommerce_Subscriptions_Gifting::get_holder_id() decides. Only the
	 * statuses scanned_statuses() lists are read, so a pending or switched
	 * subscription, or one in the trash, counts for no one. Subscriptions
	 * managed on other network sites aren't in this site's order tables, so they
	 * never count, as on the row. A reader can be in both lists; the callers let
	 * live outrank ended.
	 *
	 * A group subscription counts for no one, because the row shows it with the
	 * reader's groups. The row tells a group apart by the subscription's own
	 * settings (Group_Subscription_Settings::get_subscription_settings()), which
	 * SQL can't run, so this leaves out the site's group list instead,
	 * Group_Subscription_Settings::get_group_subscription_ids(), as the Subscribers
	 * wizard's Plan and Complimentary filters do. The two can read a subscription
	 * differently: the list takes a subscription with any line item on a group
	 * product, where the settings read the first line item's product; it matches
	 * the subscription's `enabled` meta only when it is exactly `yes` or `no`, and
	 * the product's only when it is exactly `yes`, where the settings read any
	 * boolean string; it applies neither settings
	 * filter; and it is cached for five minutes. For a donation subscription the
	 * two read differently, the Donor filter disagrees with the badge. It is the
	 * gap the Subscribers_Index class docblock describes, accepted here as it is
	 * there.
	 *
	 * The live and the ended lists are read in two queries, each across every
	 * donation product at once, and each holds at most $limit distinct readers.
	 * Past the limit, which readers a list leaves out is up to the database.
	 *
	 * @param int $limit The most readers in each list, or 0 for all of them.
	 *
	 * @return array{live:int[],ended:int[]} Holder user IDs.
	 */
	public static function holders( int $limit = 0 ): array {
		$holders     = [
			'live'  => [],
			'ended' => [],
		];
		$product_ids = self::donation_product_ids();
		if ( empty( $product_ids ) || ! function_exists( 'wcs_get_subscription_statuses' ) ) {
			return $holders;
		}
		$group_ids = class_exists( 'Newspack\Group_Subscription_Settings' ) ? array_map( 'intval', Group_Subscription_Settings::get_group_subscription_ids() ) : [];
		foreach ( self::scanned_statuses() as $bucket => $statuses ) {
			if ( empty( $statuses ) ) {
				continue;
			}
			$holders[ $bucket ] = Subscribers_Index::holder_ids(
				[
					'statuses'    => $statuses,
					'product_ids' => $product_ids,
					'exclude_ids' => $group_ids,
				],
				// holder_ids() always takes a limit, so "all of them" is one no store reaches.
				$limit > 0 ? $limit : PHP_INT_MAX
			);
		}
		return $holders;
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
