<?php
/**
 * Tests for the gate map the subscribe block's editor reads.
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
	 * With nothing hooked, no list is reported as gated.
	 */
	public function test_list_gates_default_to_empty() {
		$this->assertSame( [], Newspack_Newsletters_Blocks::get_list_gates() );
	}

	/**
	 * A callback's map is passed through.
	 */
	public function test_list_gates_come_from_the_filter() {
		$gates  = [
			'newspack-12' => [
				[
					'id'    => 5,
					'title' => 'Members',
				],
			],
		];
		$filter = function () use ( $gates ) {
			return $gates;
		};
		add_filter( 'newspack_newsletters_subscription_list_gates', $filter );
		$result = Newspack_Newsletters_Blocks::get_list_gates();
		remove_filter( 'newspack_newsletters_subscription_list_gates', $filter );

		$this->assertSame( $gates, $result );
	}

	/**
	 * A callback returning something other than an array can't break the editor.
	 */
	public function test_list_gates_ignore_a_non_array_value() {
		add_filter( 'newspack_newsletters_subscription_list_gates', '__return_false' );
		$result = Newspack_Newsletters_Blocks::get_list_gates();
		remove_filter( 'newspack_newsletters_subscription_list_gates', '__return_false' );

		$this->assertSame( [], $result );
	}
}
