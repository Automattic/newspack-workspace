<?php
/**
 * Tests for the gate map the subscribe block reads.
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests Newspack_Newsletters_Blocks::get_list_gates().
 *
 * @group subscribe-block
 */
class Blocks_List_Gates_Test extends WP_UnitTestCase {

	/**
	 * A callback returning something other than an array can't break the block.
	 */
	public function test_list_gates_ignore_a_non_array_value() {
		add_filter( 'newspack_newsletters_subscription_list_gates', '__return_false' );
		$result = Newspack_Newsletters_Blocks::get_list_gates();
		remove_filter( 'newspack_newsletters_subscription_list_gates', '__return_false' );

		$this->assertSame( [], $result );
	}
}
