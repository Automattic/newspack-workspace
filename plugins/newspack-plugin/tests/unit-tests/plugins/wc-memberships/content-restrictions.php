<?php
/**
 * Tests for WooCommerce Memberships content restrictions.
 *
 * @package Newspack\Tests
 */

use Newspack\Memberships;

/**
 * Test WooCommerce Memberships content restriction handling.
 *
 * @group wc-memberships
 */
class Test_Memberships_Content_Restrictions extends WP_UnitTestCase {

	/**
	 * Ensure the restriction handler is removed using its callable.
	 *
	 * WordPress 7.1 replaced spl_object_hash() with spl_object_id() when building
	 * hook callback IDs. Passing the callable keeps removal compatible with both.
	 *
	 * @dataProvider restriction_handler_priorities
	 *
	 * @param int $priority Hook priority.
	 */
	public function test_removes_posts_restriction_handler( $priority ) {
		$restriction_instance = new class() {
			/**
			 * Mock restriction handler.
			 */
			public function handle_restriction_modes() {}
		};
		$callback             = [ $restriction_instance, 'handle_restriction_modes' ];

		add_action( 'wp', $callback, $priority );
		$this->assertSame( $priority, has_action( 'wp', $callback ) );

		Memberships::remove_posts_restriction_handler( $restriction_instance );

		$this->assertFalse( has_action( 'wp', $callback ) );
	}

	/**
	 * WooCommerce Memberships restriction handler priorities.
	 *
	 * @return array[]
	 */
	public function restriction_handler_priorities() {
		return [
			'current versions' => [ 9 ],
			'before 1.27.2'    => [ 10 ],
		];
	}
}
