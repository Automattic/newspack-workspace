<?php
/**
 * Tests for the Teams for Memberships integration.
 *
 * @package Newspack\Tests
 */

use Newspack\Teams_For_Memberships;

require_once __DIR__ . '/../../mocks/teams-for-memberships-mocks.php';
require_once __DIR__ . '/../../mocks/teams-for-memberships-membership-mocks.php';

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

	/**
	 * The member end-date sync must be registered on member-add at priority 20,
	 * after Teams' own subscription integration (priority 10).
	 */
	public function test_sync_member_end_date_hook_is_registered() {
		$this->assertSame(
			20,
			has_action(
				'wc_memberships_for_teams_add_team_member',
				[ Teams_For_Memberships::class, 'sync_member_end_date_to_team' ]
			),
			'sync_member_end_date_to_team should be hooked at priority 20.'
		);
	}

	/**
	 * The core bug: a member with no end date on a team that has a future end
	 * date should inherit the team's end date.
	 */
	public function test_sync_stamps_team_end_when_member_has_none() {
		$team_end = time() + ( 30 * DAY_IN_SECONDS );
		$team     = new \SkyVerge\WooCommerce\Memberships\Teams\Team( 101, $team_end );
		$um       = new \WC_Memberships_User_Membership( 0, 'active' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, $team, $um );

		$this->assertSame(
			[ gmdate( 'Y-m-d H:i:s', $team_end ) ],
			$um->set_end_calls,
			'The member end date should be set to the team end date (as a UTC string).'
		);
		$this->assertSame( [], $um->status_calls, 'An already-active membership needs no status change.' );
	}

	/**
	 * A membership that already has an end date is left untouched -- whatever the
	 * plan or upstream Team::add_member() set is owned by them. This is true even
	 * when that date is shorter than the team end (we only fill missing dates).
	 */
	public function test_sync_skips_member_with_existing_end_date() {
		$team_end   = time() + ( 60 * DAY_IN_SECONDS );
		$member_end = time() + ( 10 * DAY_IN_SECONDS );
		$team       = new \SkyVerge\WooCommerce\Memberships\Teams\Team( 102, $team_end );
		$um         = new \WC_Memberships_User_Membership( $member_end, 'active' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, $team, $um );

		$this->assertSame( [], $um->set_end_calls, 'An existing end date must not be overwritten.' );
		$this->assertSame( [], $um->status_calls );
	}

	/**
	 * A member who already holds a longer end date (e.g. a separately purchased
	 * individual membership) must keep it -- the sync never shortens.
	 */
	public function test_sync_preserves_longer_member_end() {
		$team_end   = time() + ( 30 * DAY_IN_SECONDS );
		$member_end = time() + ( 365 * DAY_IN_SECONDS );
		$team       = new \SkyVerge\WooCommerce\Memberships\Teams\Team( 103, $team_end );
		$um         = new \WC_Memberships_User_Membership( $member_end, 'active' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, $team, $um );

		$this->assertSame( [], $um->set_end_calls, 'A longer existing end date must not be overwritten.' );
		$this->assertSame( [], $um->status_calls );
	}

	/**
	 * Unlimited team (no end date): the sync must be a no-op.
	 */
	public function test_sync_is_noop_for_unlimited_team() {
		$team = new \SkyVerge\WooCommerce\Memberships\Teams\Team( 104, 0 );
		$um   = new \WC_Memberships_User_Membership( 0, 'active' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, $team, $um );

		$this->assertSame( [], $um->set_end_calls, 'Nothing should be written for an unlimited team.' );
	}

	/**
	 * The hook only fills the end date; it never changes membership status, so it
	 * triggers no synchronous ESP list mutations. Upstream Team::add_member()
	 * already reconciles status, and is_active() handles lazy expiry.
	 */
	public function test_sync_does_not_change_status_when_filling_date() {
		$team_end = time() + ( 30 * DAY_IN_SECONDS );
		$team     = new \SkyVerge\WooCommerce\Memberships\Teams\Team( 105, $team_end );
		$um       = new \WC_Memberships_User_Membership( 0, 'expired' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, $team, $um );

		$this->assertSame( [ gmdate( 'Y-m-d H:i:s', $team_end ) ], $um->set_end_calls );
		$this->assertSame( [], $um->status_calls, 'The hook must not change membership status.' );
	}

	/**
	 * A member added to an already-lapsed team gets the past end date but must
	 * NOT be force-expired here -- that would synchronously fire the ESP
	 * list-removal side effect. is_active() expires it lazily instead.
	 */
	public function test_sync_does_not_force_expire_for_lapsed_team() {
		$team_end = time() - ( 30 * DAY_IN_SECONDS );
		$team     = new \SkyVerge\WooCommerce\Memberships\Teams\Team( 106, $team_end );
		$um       = new \WC_Memberships_User_Membership( 0, 'active' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, $team, $um );

		$this->assertSame( [ gmdate( 'Y-m-d H:i:s', $team_end ) ], $um->set_end_calls, 'The past team end date should still be applied.' );
		$this->assertSame( [], $um->status_calls, 'The membership must not be force-expired on add.' );
	}

	/**
	 * Defensive guards: a non-Team object must be a no-op (safe when Teams is absent).
	 */
	public function test_sync_ignores_non_team_object() {
		$um = new \WC_Memberships_User_Membership( 0, 'active' );

		Teams_For_Memberships::sync_member_end_date_to_team( null, new \stdClass(), $um );

		$this->assertSame( [], $um->set_end_calls, 'A non-Team object should be ignored.' );
	}
}
