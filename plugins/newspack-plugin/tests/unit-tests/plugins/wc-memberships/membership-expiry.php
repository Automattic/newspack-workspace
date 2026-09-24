<?php
/**
 * Tests for the WC Memberships expiry handler.
 *
 * @package Newspack\Tests
 */

use Newspack\Membership_Expiry;

require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';

/**
 * Test Membership_Expiry::prevent_membership_expiration().
 *
 * @group membership-expiry
 */
class Test_Membership_Expiry extends WP_UnitTestCase {

	/**
	 * The wc_memberships_expire_user_membership filter fires for every user membership,
	 * not just subscription-tied ones. Plain WC_Memberships_User_Membership objects do
	 * not expose get_subscription_id(); calling it must not fatal.
	 *
	 * Reproduces the production fatal observed at v6.39.3 of newspack-plugin:
	 *   Uncaught Error: Call to undefined method WC_Memberships_User_Membership::get_subscription_id()
	 *   in includes/plugins/wc-memberships/class-membership-expiry.php:39
	 */
	public function test_passes_through_non_subscription_membership() {
		$non_subscription_membership = new class() {
			/**
			 * Return a product id.
			 *
			 * @return int
			 */
			public function get_product_id() {
				return 123;
			}

			/**
			 * Return a user id.
			 *
			 * @return int
			 */
			public function get_user_id() {
				return 456;
			}

			// Deliberately no get_subscription_id() — only the Subscriptions integration class has it.
		};

		$result = Membership_Expiry::prevent_membership_expiration( true, $non_subscription_membership );
		$this->assertTrue( $result, 'Filter should return the incoming $cancel_membership unchanged when the membership is not subscription-tied.' );
	}
}
