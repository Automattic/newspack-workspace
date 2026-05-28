<?php
/**
 * Test Budget
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

/**
 * Test Budget Class.
 */
class Test_Budget extends \WP_UnitTestCase {

	protected static $budgets = [];
	protected static $stories = [];

	/**
	 * WP setup before class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$budgets = self::factory()->term->create_many(
			2,
			[
				'taxonomy' => Budgets::TAXONOMY,
			]
		);
		self::$stories = self::factory()->post->create_many(
			100,
			[
				'post_type' => 'post',
			]
		);
		foreach ( self::$stories as $i => $post_id ) {
			wp_set_post_terms( $post_id, [ self::$budgets[ $i % 2 ] ], Budgets::TAXONOMY );
		}
	}

	/**
	 * Test budget.
	 */
	public function test_new_budget() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$this->assertTrue( $budget->is_valid() );
		$this->assertEquals( $budget_id, $budget->id );
	}

	/**
	 * Test non budget.
	 */
	public function test_new_non_budget() {
		$budget = new Budget( 0 );
		$this->assertFalse( $budget->is_valid() );

		$tag = $this->factory->tag->create();
		$budget = new Budget( $tag );
		$this->assertFalse( $budget->is_valid() );
	}

	/**
	 * Test to array.
	 */
	public function test_to_array() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$this->assertEquals(
			[
				'id'          => $budget_id,
				'name'        => get_term( $budget_id, Budgets::TAXONOMY )->name,
				'slug'        => get_term( $budget_id, Budgets::TAXONOMY )->slug,
				'description' => get_term( $budget_id, Budgets::TAXONOMY )->description,
				'archived'    => false,
				'archive_at'  => '',
				'story_count' => 0,
				'order'       => 0,
			],
			$budget->to_array()
		);
	}

	/**
	 * Test archive.
	 */
	public function test_archive() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$this->assertFalse( $budget->archived );

		$budget->archive();
		$this->assertTrue( $budget->archived );
		$this->assertTrue( (bool) get_term_meta( $budget_id, 'archived', true ) );

		$budget->unarchive();
		$this->assertFalse( $budget->archived );
		$this->assertFalse( (bool) get_term_meta( $budget_id, 'archived', true ) );
	}

	/**
	 * Test get stories.
	 */
	public function test_get_stories() {
		$budget = new Budget( self::$budgets[0] );
		$stories = $budget->get_stories();
		$this->assertCount( 50, $stories );

		// Assert that returned items are stories.
		$this->assertContainsOnlyInstancesOf( 'Newspack_Story_Budget\Story', $stories );
	}

	/**
	 * Test setting and getting auto-archive date.
	 */
	public function test_set_get_auto_archive() {
		$budget_id = self::$budgets[0];
		$budget    = new Budget( $budget_id );

		$budget->unarchive();
		$this->assertFalse( $budget->archived );
		$this->assertEmpty( $budget->archive_at );

		$future_date = new \DateTime( '+3 days' );
		$result      = $budget->set_auto_archive( $future_date );
		$this->assertNotEmpty( $result );

		$refreshed_budget = new Budget( $budget_id );
		$this->assertNotEmpty( $refreshed_budget->archive_at );

		$stored_date = new \DateTime( $refreshed_budget->archive_at );
		$this->assertEquals(
			$future_date->format( 'c' ),
			$stored_date->format( 'c' )
		);
	}

	/**
	 * Test clearing auto-archive date.
	 */
	public function test_clear_auto_archive() {
		$budget_id = self::$budgets[1];
		$budget    = new Budget( $budget_id );

		$budget->unarchive();

		$future_date = new \DateTime( '+3 days' );
		$budget->set_auto_archive( $future_date );

		$this->assertNotEmpty( $budget->archive_at );

		$result = $budget->clear_auto_archive();
		$this->assertTrue( $result );

		$refreshed_budget = new Budget( $budget_id );
		$this->assertEmpty( $refreshed_budget->archive_at );
	}

	/**
	 * Test that archiving a budget clears its auto-archive date.
	 */
	public function test_archive_clears_auto_archive() {
		$budget_id = self::$budgets[0];
		$budget    = new Budget( $budget_id );

		$budget->unarchive();

		$future_date = new \DateTime( '+1 week' );
		$budget->set_auto_archive( $future_date );
		$this->assertNotEmpty( $budget->archive_at );

		$budget->archive();

		$refreshed_budget = new Budget( $budget_id );
		$this->assertTrue( $refreshed_budget->archived );
		$this->assertEmpty( $refreshed_budget->archive_at );
	}

	/**
	 * Test add stories to budget.
	 */
	public function test_add_stories() {
		$budget = new Budget( self::$budgets[0] );

		// Create new stories for testing.
		$new_stories = $this->factory->post->create_many( 3, [ 'post_type' => 'post' ] );

		$result = $budget->add_stories( $new_stories );
		$this->assertEquals( 3, $result );

		// Verify stories were added to budget.
		foreach ( $new_stories as $story_id ) {
			$story_budgets = wp_get_post_terms( $story_id, Budgets::TAXONOMY, [ 'fields' => 'ids' ] );
			$this->assertTrue( in_array( $budget->id, $story_budgets ) );
		}
	}

	/**
	 * Test remove stories from budget.
	 */
	public function test_remove_stories() {
		$budget = new Budget( self::$budgets[0] );

		$test_stories = $this->factory->post->create_many( 2, [ 'post_type' => 'post' ] );
		$budget->add_stories( $test_stories );

		foreach ( $test_stories as $story_id ) {
			$story_budgets = wp_get_post_terms( $story_id, Budgets::TAXONOMY, [ 'fields' => 'ids' ] );
			$this->assertTrue( in_array( $budget->id, $story_budgets ) );
		}

		$result = $budget->remove_stories( [ $test_stories[0] ] );
		$this->assertEquals( 1, $result );

		// Verify it was removed.
		$story_budgets = wp_get_post_terms( $test_stories[0], Budgets::TAXONOMY, [ 'fields' => 'ids' ] );
		$this->assertFalse( in_array( $budget->id, $story_budgets ) );

		// Verify the other story is still there.
		$story_budgets = wp_get_post_terms( $test_stories[1], Budgets::TAXONOMY, [ 'fields' => 'ids' ] );
		$this->assertTrue( in_array( $budget->id, $story_budgets ) );
	}

	/**
	 * Test set auto archive on archived budget.
	 */
	public function test_set_auto_archive_on_archived_budget() {
		$budget_id = self::$budgets[1];
		$budget = new Budget( $budget_id );

		$budget->archive();

		$result = $budget->set_auto_archive( '+3 days' );
		$this->assertFalse( $result );
	}

	/**
	 * Test get auto archive method.
	 */
	public function test_get_auto_archive() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );

		$budget->unarchive();
		$budget->clear_auto_archive();

		// Should be empty initially.
		$date = $budget->get_auto_archive();
		$this->assertEmpty( $date );

		// Set a date and verify.
		$future_date = new \DateTime( '+2 weeks' );
		$budget->set_auto_archive( $future_date );

		$date = $budget->get_auto_archive();
		$this->assertNotEmpty( $date );

		$retrieved_date = new \DateTime( $date );
		$this->assertEquals(
			$future_date->setTime( 0, 0, 0 )->format( 'c' ),
			$retrieved_date->format( 'c' )
		);
	}

	/**
	 * Test archive and unarchive order handling.
	 */
	public function test_archive_unarchive_order() {
		$budget_id = self::$budgets[0];
		$budget    = new Budget( $budget_id );

		// Set initial order.
		update_term_meta( $budget_id, Budget::ORDER_META_KEY, 5 );
		$budget = new Budget( $budget_id ); // Refresh.
		$this->assertEquals( 5, $budget->order );

		// Archive should reset order to 0.
		$budget->archive();
		$this->assertEquals( 0, $budget->order );
		$this->assertEmpty( get_term_meta( $budget_id, Budget::ORDER_META_KEY, true ) );

		// Unarchive should set order to 0.
		$budget->unarchive();
		$refreshed_budget = new Budget( $budget_id );
		$this->assertEquals( 0, $refreshed_budget->order );
	}

	/**
	 * Test get budget errors.
	 */
	public function test_get_budget_errors() {
		// Test valid budget.
		$budget = new Budget( self::$budgets[0] );
		$errors = $budget->get_budget_errors();
		$this->assertFalse( $errors->has_errors() );

		// Test invalid budget.
		$budget = new Budget( 999999 );
		$errors = $budget->get_budget_errors();
		$this->assertTrue( $errors->has_errors() );
		$this->assertEquals( 'not_found', $errors->get_error_code() );

		// Test wrong taxonomy.
		$tag_id = $this->factory->tag->create();
		$budget = new Budget( $tag_id );
		$errors = $budget->get_budget_errors();
		$this->assertTrue( $errors->has_errors() );
		$this->assertEquals( 'not_found', $errors->get_error_code() );
	}
}
