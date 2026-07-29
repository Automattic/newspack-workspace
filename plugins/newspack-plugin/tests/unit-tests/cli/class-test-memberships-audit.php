<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing
/**
 * Tests the `wp newspack audit-membership-subscriptions` classifier (NPPD-2070).
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Memberships_Audit;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-memberships-audit.php';

/**
 * The classification table is the whole point of the command: WooCommerce
 * Memberships grants content access to the membership *holder*, Access Control
 * grants it to the *subscription's customer*, and every row below is a way those
 * two can disagree at the flip.
 *
 * @group Memberships_Audit
 */
class Test_Memberships_Audit extends WP_UnitTestCase {

	/**
	 * Build a facts array with only the interesting fields spelled out.
	 *
	 * @param array $overrides Fields to override.
	 *
	 * @return array
	 */
	private function facts( array $overrides = [] ) {
		return array_merge(
			[
				'holder_id'                => 501,
				'team_id'                  => 0,
				'subscription_id'          => 0,
				'subscription_customer_id' => null,
				'subscription_status'      => null,
				'wcsg_recipient_id'        => null,
				'order_id'                 => 0,
				'order_customer_id'        => null,
				'order_status'             => '',
				'order_is_paid'            => false,
			],
			$overrides
		);
	}

	/**
	 * The headline case: a gift purchase leaves the membership on the recipient
	 * and the live subscription on the buyer, so the reader who actually reads
	 * loses access at the flip while the buyer gains access they never had.
	 */
	public function test_live_subscription_owned_by_someone_else_is_a_gift() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'active',
					]
				)
			)
		);
	}

	/**
	 * `pending-cancel` still grants access until the term ends, so it counts as
	 * live — flipping during that window loses the recipient real access.
	 */
	public function test_pending_cancel_mismatch_is_still_a_gift() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'pending-cancel',
					]
				)
			)
		);
	}

	/**
	 * A mismatch over a DEAD subscription is not a gift residual: nobody —
	 * neither buyer nor recipient — gets access from it under Access Control, so
	 * it belongs to the lapsed cohort. Putting it on the gift list would send the
	 * publisher a buyer-vs-recipient question about a membership that is simply
	 * expired.
	 */
	public function test_mismatch_over_a_dead_subscription_is_inactive_not_gift() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT_INACTIVE,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'cancelled',
					]
				)
			)
		);
	}

	/**
	 * `on-hold` (payment retry) grants access under WooCommerce Memberships but
	 * NOT under Access Control — `WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES`
	 * is active + pending-cancel only. Counting it as access-granting would
	 * report a reader as covered on the very run meant to catch their loss; the
	 * dunning cohort is its own migration question (NPPD-2052).
	 */
	public function test_on_hold_subscription_does_not_grant_access() {
		$this->assertSame(
			Memberships_Audit::CLASS_MEMBER_OWNED_INACTIVE,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 501,
						'subscription_status'      => 'on-hold',
					]
				)
			)
		);
	}

	/**
	 * The access-granting list must not drift from the one the runtime actually
	 * evaluates — a divergence would silently move readers between "covered" and
	 * "loses access" on every audit.
	 */
	public function test_access_granting_statuses_match_the_runtime() {
		if ( ! class_exists( '\Newspack\WooCommerce_Connection' ) ) {
			$this->markTestSkipped( 'WooCommerce_Connection unavailable.' );
		}
		$this->assertSame(
			\Newspack\WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES,
			Memberships_Audit::ACCESS_GRANTING_SUBSCRIPTION_STATUSES,
			'Access Control grants access on exactly these subscription statuses; the audit must ask the same question.'
		);
	}

	/**
	 * Memberships-for-Teams stamps the *team's* subscription — owned by the team
	 * owner — onto every seat's membership. Without a team check, every seat on
	 * every team reads as a gift, and an institutional site's whole population
	 * lands on a list that feeds $0-subscription granting, duplicating the group
	 * subscription migrate-teams already creates.
	 */
	public function test_team_seat_is_not_a_gift() {
		$this->assertSame(
			Memberships_Audit::CLASS_TEAM_BACKED,
			Memberships_Audit::classify(
				$this->facts(
					[
						'team_id'                  => 4242,
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'active',
					]
				)
			)
		);
	}

	/**
	 * WooCommerce Subscriptions Gifting resolves a gifted subscription to its
	 * recipient, and so does Access Control
	 * (`WooCommerce_Connection::get_active_subscriptions_for_user`). Those
	 * readers keep access at the flip — reporting them would produce a worklist
	 * that grants access twice.
	 */
	public function test_subscriptions_gifting_recipient_keeps_access() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT_WCSG,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'active',
						'wcsg_recipient_id'        => 501,
					]
				)
			)
		);
	}

	/**
	 * A gifted subscription whose recipient is somebody else entirely is still a
	 * plain mismatch — the holder gets nothing from it.
	 */
	public function test_gifted_subscription_to_a_third_party_is_still_a_gift() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'active',
						'wcsg_recipient_id'        => 777,
					]
				)
			)
		);
	}

	public function test_live_subscription_owned_by_the_member_is_covered() {
		$this->assertSame(
			Memberships_Audit::CLASS_MEMBER_OWNED,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 501,
						'subscription_status'      => 'active',
					]
				)
			)
		);
	}

	public function test_member_owned_dead_subscription_is_inactive() {
		$this->assertSame(
			Memberships_Audit::CLASS_MEMBER_OWNED_INACTIVE,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 501,
						'subscription_status'      => 'expired',
					]
				)
			)
		);
	}

	/**
	 * `_subscription_id` pointing at a subscription that no longer exists is a
	 * distinct data problem from having no link at all — the membership looks
	 * backed in the admin, but there is nothing for Access Control to key on.
	 */
	public function test_dangling_subscription_link_is_reported_separately() {
		$this->assertSame(
			Memberships_Audit::CLASS_SUBSCRIPTION_MISSING,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => null,
						'subscription_status'      => null,
					]
				)
			)
		);
	}

	/**
	 * WCM commonly sets both links (the subscription's parent order). When the
	 * subscription is gone but the order is intact, the order is real evidence —
	 * falling through to it keeps the reader on a reportable list instead of in
	 * the evidence-free `subscription-missing` bucket.
	 */
	public function test_dangling_subscription_falls_through_to_a_usable_order() {
		$this->assertSame(
			Memberships_Audit::CLASS_ORDER_ONLY,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => null,
						'order_id'                 => 4242,
						'order_customer_id'        => 501,
						'order_is_paid'            => true,
					]
				)
			)
		);
	}

	/**
	 * The sibling shape found in the same field run: a membership backed only by
	 * a completed one-off order. Nothing recurring exists, so Access Control's
	 * subscription rule can never reproduce the access.
	 */
	public function test_order_backed_membership_with_no_subscription_is_order_only() {
		$this->assertSame(
			Memberships_Audit::CLASS_ORDER_ONLY,
			Memberships_Audit::classify(
				$this->facts(
					[
						'order_id'          => 4242,
						'order_customer_id' => 501,
						'order_is_paid'     => true,
					]
				)
			)
		);
	}

	/**
	 * A membership left standing over a refunded, cancelled or failed order is
	 * NOT the same ask: the default report exists to produce a list of readers
	 * to grant free subscriptions to, and nobody should be granted perpetual
	 * access off a purchase that was refunded.
	 */
	public function test_unpaid_order_is_its_own_class() {
		$this->assertSame(
			Memberships_Audit::CLASS_ORDER_ONLY_UNPAID,
			Memberships_Audit::classify(
				$this->facts(
					[
						'order_id'          => 4242,
						'order_customer_id' => 501,
						'order_status'      => 'refunded',
						'order_is_paid'     => false,
					]
				)
			)
		);
	}

	/**
	 * An order whose customer isn't the holder is a gifted one-off purchase. It
	 * is still order-only — the repair is the same $0 subscription for the
	 * holder — and the buyer travels with the row as evidence.
	 */
	public function test_order_backed_membership_bought_by_someone_else_is_order_only() {
		$this->assertSame(
			Memberships_Audit::CLASS_ORDER_ONLY,
			Memberships_Audit::classify(
				$this->facts(
					[
						'order_id'          => 4242,
						'order_customer_id' => 500,
						'order_is_paid'     => true,
					]
				)
			)
		);
	}

	/**
	 * A membership whose `_order_id` points at an order that no longer exists
	 * has no evidence to gather, so it falls in with the comp/legacy cohort the
	 * parity diff already names.
	 */
	public function test_dangling_order_link_falls_back_to_no_purchase_record() {
		$this->assertSame(
			Memberships_Audit::CLASS_NO_PURCHASE_RECORD,
			Memberships_Audit::classify(
				$this->facts(
					[
						'order_id'          => 4242,
						'order_customer_id' => null,
					]
				)
			)
		);
	}

	public function test_membership_with_no_purchase_record_is_comp_legacy() {
		$this->assertSame(
			Memberships_Audit::CLASS_NO_PURCHASE_RECORD,
			Memberships_Audit::classify( $this->facts() )
		);
	}

	/**
	 * A membership can carry both links (the subscription's parent order). The
	 * subscription is what Access Control evaluates, so it decides the class —
	 * otherwise every subscription-backed membership would be miscounted as
	 * order-only.
	 */
	public function test_subscription_link_takes_precedence_over_the_order_link() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'active',
						'order_id'                 => 4242,
						'order_customer_id'        => 500,
					]
				)
			)
		);
	}

	/**
	 * Statuses arrive from `WC_Subscription::get_status()` unprefixed but from
	 * raw post rows prefixed; both must read as live, or a `wc-active` gift row
	 * would be filed as lapsed and never reach the publisher.
	 */
	public function test_status_prefix_is_normalized() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'wc-active',
					]
				)
			)
		);
	}

	/**
	 * The ID list is what gets piped into `migrate-manual-members
	 * --user-ids-file`, so it must be one line per *user*: a reader holding two
	 * flagged memberships would otherwise be granted two $0 subscriptions.
	 */
	public function test_flagged_user_ids_are_deduplicated_in_first_seen_order() {
		$rows = [
			[
				'class'     => Memberships_Audit::CLASS_GIFT,
				'member_id' => 501,
			],
			[
				'class'     => Memberships_Audit::CLASS_ORDER_ONLY,
				'member_id' => 777,
			],
			[
				'class'     => Memberships_Audit::CLASS_GIFT,
				'member_id' => 501,
			],
		];

		$this->assertSame( [ 501, 777 ], Memberships_Audit::flagged_user_ids( $rows ) );
	}

	/**
	 * A gifted subscription that is no longer access-granting carries nothing
	 * over — Access Control checks the status before it checks the gifting. The
	 * "recipient keeps access" class must not absorb it, or the reader who is in
	 * fact losing access is reported as safe.
	 */
	public function test_dead_gifting_subscription_is_not_treated_as_carried_over() {
		$this->assertSame(
			Memberships_Audit::CLASS_GIFT_INACTIVE,
			Memberships_Audit::classify(
				$this->facts(
					[
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'on-hold',
						'wcsg_recipient_id'        => 501,
					]
				)
			)
		);
	}

	/**
	 * An orphaned membership (post_author 0) must not collide with an
	 * unresolvable gifting recipient, which also reads as 0.
	 */
	public function test_orphaned_membership_is_not_matched_to_an_unresolved_recipient() {
		$this->assertNotSame(
			Memberships_Audit::CLASS_GIFT_WCSG,
			Memberships_Audit::classify(
				$this->facts(
					[
						'holder_id'                => 0,
						'subscription_id'          => 90210,
						'subscription_customer_id' => 500,
						'subscription_status'      => 'active',
						'wcsg_recipient_id'        => 0,
					]
				)
			)
		);
	}

	/**
	 * This mapping decides which memberships are examined at all: get it wrong
	 * and `get_posts()` matches nothing, so the command reports "no gift
	 * memberships found" on a site full of them.
	 */
	public function test_membership_statuses_are_prefixed_for_the_post_query() {
		$this->assertSame(
			[ 'wcm-active', 'wcm-complimentary', 'wcm-pending' ],
			Memberships_Audit::prefix_membership_statuses( [ 'active', 'complimentary', 'pending' ] )
		);
	}

	public function test_already_prefixed_membership_statuses_are_left_alone() {
		$this->assertSame(
			[ 'wcm-active', 'wcm-pending' ],
			Memberships_Audit::prefix_membership_statuses( [ 'wcm-active', 'pending' ] )
		);
	}

	/**
	 * An empty list would match no memberships — falling back to the constant
	 * keeps a filtered-to-nothing site auditable instead of silently clean.
	 */
	public function test_empty_membership_status_list_falls_back_to_the_constant() {
		$this->assertSame(
			Memberships_Audit::ACTIVE_MEMBERSHIP_STATUSES,
			Memberships_Audit::prefix_membership_statuses( [] )
		);
	}

	/**
	 * Every class the classifier can return must be in ALL_CLASSES and have a
	 * label — the per-plan summary loops over the former and dereferences the
	 * latter, so a class added without updating both prints a warning-riddled
	 * summary mid-audit on a live site.
	 */
	public function test_every_class_constant_is_counted() {
		$reflection = new \ReflectionClass( Memberships_Audit::class );
		$class_constants = array_filter(
			$reflection->getConstants(),
			function ( $value, $name ) {
				// CLASS_LABELS shares the prefix but is the gloss map, not a class.
				return is_string( $value ) && 0 === strpos( $name, 'CLASS_' );
			},
			ARRAY_FILTER_USE_BOTH
		);

		$this->assertNotEmpty( $class_constants );
		foreach ( $class_constants as $name => $value ) {
			$this->assertContains( $value, Memberships_Audit::ALL_CLASSES, $name . ' is missing from ALL_CLASSES.' );
			$this->assertArrayHasKey( $value, Memberships_Audit::CLASS_LABELS, $name . ' has no entry in CLASS_LABELS.' );
		}
	}

	/**
	 * The default report must stay a subset of what --only accepts, or a class
	 * shown by default couldn't be selected on its own.
	 */
	public function test_reported_classes_are_selectable() {
		foreach ( Memberships_Audit::REPORTED_CLASSES as $class ) {
			$this->assertContains( $class, Memberships_Audit::SELECTABLE_CLASSES );
		}
	}

	public function test_only_flag_defaults_to_every_reported_class() {
		$this->assertSame(
			Memberships_Audit::REPORTED_CLASSES,
			Memberships_Audit::parse_only_classes( '' )
		);
	}

	public function test_only_flag_accepts_a_subset() {
		$this->assertSame(
			[ Memberships_Audit::CLASS_ORDER_ONLY ],
			Memberships_Audit::parse_only_classes( 'order-only' )
		);
	}

	/**
	 * A typo must abort rather than silently narrow the report to nothing — an
	 * empty gift list reads as "this site has no gift problem".
	 */
	public function test_only_flag_rejects_an_unknown_class() {
		$result = Memberships_Audit::parse_only_classes( 'gifts' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_memberships_audit_unknown_class', $result->get_error_code() );
	}

	public function test_plan_ids_are_parsed_and_deduplicated() {
		$this->assertSame( [ 78, 91 ], Memberships_Audit::resolve_plan_ids( '78, 91, 78' ) );
	}

	/**
	 * Same strictness as --only, for the same reason: a mistyped plan list that
	 * silently drops a plan produces a report that misses the readers on it.
	 */
	public function test_plan_ids_reject_a_non_numeric_token() {
		$result = Memberships_Audit::resolve_plan_ids( '78,12abc' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_memberships_audit_plan_id', $result->get_error_code() );
	}

	public function test_plan_ids_reject_zero() {
		$this->assertInstanceOf( \WP_Error::class, Memberships_Audit::resolve_plan_ids( '0' ) );
	}
}
