<?php
/**
 * Tests for the Teams for Memberships integration.
 *
 * @package Newspack\Tests
 */

use Newspack\Teams_For_Memberships;

require_once __DIR__ . '/../../mocks/teams-for-memberships-mocks.php';

/**
 * Test Teams_For_Memberships helpers.
 *
 * @group teams-for-memberships
 */
class Test_Teams_For_Memberships extends WP_UnitTestCase {

	/**
	 * Reset stub state before each test so assertions don't leak across runs.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['teams_mock_is_renewal']    = false;
		$GLOBALS['teams_mock_subscriptions'] = [];
		$GLOBALS['teams_mock_teams_for_sub'] = [];
		$GLOBALS['teams_mock_item_meta']     = [];
	}

	/**
	 * The filter must pass through unchanged when the action is not `create`.
	 *
	 * `renew` and `seat_change` should never be rewritten – we only intervene
	 * when Teams would otherwise spawn a new team.
	 */
	public function test_restore_team_meta_on_renewal_passes_through_non_create_actions() {
		$this->assertSame(
			'renew',
			Teams_For_Memberships::restore_team_meta_on_renewal( 'renew', null )
		);
		$this->assertSame(
			'seat_change',
			Teams_For_Memberships::restore_team_meta_on_renewal( 'seat_change', null )
		);
	}

	/**
	 * The filter must be registered in init().
	 */
	public function test_filter_is_registered() {
		$this->assertNotFalse(
			has_filter(
				'wc_memberships_for_teams_determine_order_item_action',
				[ Teams_For_Memberships::class, 'restore_team_meta_on_renewal' ]
			),
			'The restore_team_meta_on_renewal filter should be registered during init().'
		);
	}

	/**
	 * Happy path: a renewal order whose parent subscription has a linked team for
	 * the same product should have its order-item meta restored and the action
	 * rewritten from `create` to `renew`.
	 */
	public function test_restore_team_meta_on_renewal_restores_meta_and_returns_renew() {
		$team_id         = 1001;
		$product_id      = 2002;
		$subscription_id = 3003;
		$item_id         = 4004;

		$subscription = new Teams_Mock_Subscription( $subscription_id );
		$team         = new Teams_Mock_Team( $team_id, $product_id );
		$order        = new WC_Order( [ 'status' => 'pending' ] );
		$item         = new Teams_Mock_Order_Item( $item_id, $product_id, $order );

		$GLOBALS['teams_mock_is_renewal']    = true;
		$GLOBALS['teams_mock_subscriptions'] = [ $subscription ];
		$GLOBALS['teams_mock_teams_for_sub'] = [ $subscription_id => [ $team ] ];

		$result = Teams_For_Memberships::restore_team_meta_on_renewal( 'create', $item );

		$this->assertSame( 'renew', $result, 'Action should be rewritten to renew.' );
		$this->assertArrayHasKey( $item_id, $GLOBALS['teams_mock_item_meta'], 'Item meta should have been written.' );
		$this->assertSame(
			$team_id,
			$GLOBALS['teams_mock_item_meta'][ $item_id ]['_wc_memberships_for_teams_team_id'] ?? null,
			'The existing team id should be restored onto the order item.'
		);
		$this->assertSame(
			true,
			$GLOBALS['teams_mock_item_meta'][ $item_id ]['_wc_memberships_for_teams_team_renewal'] ?? null,
			'The renewal flag should be set on the order item.'
		);
		$this->assertSame(
			'__deleted__',
			$GLOBALS['teams_mock_item_meta'][ $item_id ]['_wc_memberships_for_teams_team_seat_change'] ?? null,
			'The seat-change flag should be explicitly cleared so the dispatcher treats this as a renewal.'
		);
	}

	/**
	 * Variable-subscription case: the team may be linked to a specific variation,
	 * in which case `$team->get_product_id()` equals the item's variation id (not
	 * its parent product id). The filter must match either.
	 */
	public function test_restore_team_meta_on_renewal_matches_variation_id() {
		$team_id          = 1001;
		$parent_product   = 2002;
		$variation_id     = 2003;
		$subscription_id  = 3003;
		$item_id          = 4004;

		$subscription = new Teams_Mock_Subscription( $subscription_id );
		// Team is linked to the variation id, not the parent product id.
		$team  = new Teams_Mock_Team( $team_id, $variation_id );
		$order = new WC_Order( [ 'status' => 'pending' ] );
		$item  = new Teams_Mock_Order_Item( $item_id, $parent_product, $order, $variation_id );

		$GLOBALS['teams_mock_is_renewal']    = true;
		$GLOBALS['teams_mock_subscriptions'] = [ $subscription ];
		$GLOBALS['teams_mock_teams_for_sub'] = [ $subscription_id => [ $team ] ];

		$result = Teams_For_Memberships::restore_team_meta_on_renewal( 'create', $item );

		$this->assertSame( 'renew', $result, 'Variation-linked teams should still match and be rewritten to renew.' );
		$this->assertSame(
			$team_id,
			$GLOBALS['teams_mock_item_meta'][ $item_id ]['_wc_memberships_for_teams_team_id'] ?? null,
			'The existing team id should be restored even when the team is linked to a variation.'
		);
	}

	/**
	 * When the renewal order contains a line item for a product that does NOT
	 * match the team's product, the filter must leave the action untouched –
	 * renewals can contain unrelated items (donations, seat changes, mixed carts).
	 */
	public function test_restore_team_meta_on_renewal_ignores_items_for_unrelated_products() {
		$team_id              = 1001;
		$team_product_id      = 2002;
		$unrelated_product_id = 9999;
		$subscription_id      = 3003;
		$item_id              = 5005;

		$subscription = new Teams_Mock_Subscription( $subscription_id );
		$team         = new Teams_Mock_Team( $team_id, $team_product_id );
		$order        = new WC_Order( [ 'status' => 'pending' ] );
		$item         = new Teams_Mock_Order_Item( $item_id, $unrelated_product_id, $order );

		$GLOBALS['teams_mock_is_renewal']    = true;
		$GLOBALS['teams_mock_subscriptions'] = [ $subscription ];
		$GLOBALS['teams_mock_teams_for_sub'] = [ $subscription_id => [ $team ] ];

		$result = Teams_For_Memberships::restore_team_meta_on_renewal( 'create', $item );

		$this->assertSame( 'create', $result, 'Action should be unchanged for unrelated product items.' );
		$this->assertArrayNotHasKey( $item_id, $GLOBALS['teams_mock_item_meta'], 'No meta should be written for unrelated items.' );
	}
}
