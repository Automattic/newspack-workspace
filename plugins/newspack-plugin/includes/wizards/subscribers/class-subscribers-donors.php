<?php
/**
 * Subscribers wizard donor rules.
 *
 * Decides what kind of donor a reader is, for the badge on the Subscribers list
 * and profile and for the Donor filter. The rules live here so the badge, the
 * filter and the backfill command all read from one place.
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
	 *     `donor` filter, which builds each kind's reader set from donation
	 *     subscriptions and the two flags instead of calling this per reader.
	 *   - Newspack\CLI\Donors::backfill() — the support command that flags donors
	 *     from before the flag existed, so they land in the kind this returns.
	 *
	 * Pure: it reads only the reader's two flags and the statuses it is given, so a
	 * set builder or a command can call it without a request. Callers check
	 * is_enabled() first.
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
}
