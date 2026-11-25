<?php
/**
 * Tests which subscription status changes can change a reader's access.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Connection;

/**
 * Tests for WooCommerce_Connection::status_change_affects_access().
 *
 * @group subscription_status_access
 */
class Newspack_Test_WooCommerce_Connection_Subscription_Status extends WP_UnitTestCase {
	/**
	 * Status pairs and whether moving from the first to the second can change access.
	 *
	 * @return array[]
	 */
	public function data_status_changes() {
		return [
			'active to pending-cancel keeps access'   => [ 'active', 'pending-cancel', false ],
			'pending-cancel to active keeps access'   => [ 'pending-cancel', 'active', false ],
			'prefixed statuses are read the same way' => [ 'wc-active', 'wc-pending-cancel', false ],
			'active to on-hold can end access'        => [ 'active', 'on-hold', true ],
			'on-hold to cancelled can end access'     => [ 'on-hold', 'cancelled', true ],
			'pending-cancel to cancelled ends access' => [ 'pending-cancel', 'cancelled', true ],
			'pending to active grants access'         => [ 'pending', 'active', true ],
			'active to active is a plan switch'       => [ 'active', 'active', true ],
			'an event with no status pair counts too' => [ '', '', true ],
		];
	}

	/**
	 * Only a move between two statuses that both grant access leaves access as it
	 * was. A same-status event is a plan switch, which can change the products.
	 *
	 * @dataProvider data_status_changes
	 *
	 * @param string $before   Status before the change.
	 * @param string $after    Status after the change.
	 * @param bool   $expected Whether the change can affect access.
	 */
	public function test_status_change_affects_access( $before, $after, $expected ) {
		$this->assertSame( $expected, WooCommerce_Connection::status_change_affects_access( $before, $after ) );
	}
}
