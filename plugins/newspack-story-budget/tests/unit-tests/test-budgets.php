<?php
/**
 * Test Budgets
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

/**
 * Test Budgets Class.
 */
class Test_Budgets extends \WP_UnitTestCase {

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
	 * Test get budgets.
	 */
	public function test_get_budgets() {
		$budgets = Budgets::get_budgets();
		$this->assertCount( 2, $budgets );
		$this->assertInstanceOf( Budget::class, $budgets[0] );
		$this->assertInstanceOf( Budget::class, $budgets[1] );
	}

	/**
	 * Test get budgets excludes archived.
	 */
	public function test_get_budgets_excludes_archived() {
		$budget = new Budget( self::$budgets[0] );
		$budget->archive();

		$budgets = Budgets::get_budgets();
		$this->assertCount( 1, $budgets );

		$budget->unarchive();
	}

	/**
	 * Test get budgets include archived.
	 */
	public function test_get_budgets_include_archived() {
		$budget = new Budget( self::$budgets[0] );
		$budget->archive();

		$budgets = Budgets::get_budgets( true );
		$this->assertCount( 2, $budgets );

		$budget->unarchive();
	}

	/**
	 * Test get stories from one budget.
	 */
	public function test_get_stories() {
		$stories = Budgets::get_stories();
		$this->assertCount( 100, $stories );
		$this->assertInstanceOf( 'WP_Query', Budgets::$stories_query );
		$this->assertContainsOnlyInstancesOf( 'Newspack_Story_Budget\Story', $stories );
	}

	/**
	 * Test get stories args.
	 */
	public function test_get_stories_args() {
		// Limit.
		$result = Budgets::get_stories( [ 'posts_per_page' => 10 ] );
		$this->assertCount( 10, $result );

		// Fields.
		$result = Budgets::get_stories( [ 'fields' => 'ids' ] );
		$this->assertContainsOnly( 'int', $result );
	}

	/**
	 * Test get stories tax query.
	 */
	public function test_get_stories_tax_query() {
		$stories = Budgets::get_stories();

		$post = $this->factory->post->create();
		$story_1 = $stories[0]->id;
		$story_2 = $stories[1]->id;
		$tag_1 = $this->factory->tag->create();
		$tag_2 = $this->factory->tag->create();
		wp_set_post_terms( $post, [ $tag_1, $tag_2 ], 'post_tag' );
		wp_set_post_terms( $story_1, [ $tag_1, $tag_2 ], 'post_tag' );
		wp_set_post_terms( $story_2, [ $tag_1 ], 'post_tag' );


		$result = Budgets::get_stories(
			[
				'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'post_tag',
						'terms'    => $tag_1,
					],
				],
			]
		);
		$this->assertCount( 2, $result );

		$this->setExpectedIncorrectUsage( 'get_stories' );
		$result = Budgets::get_stories(
			[
				'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'post_tag',
						'terms'    => $tag_1,
					],
					[
						'taxonomy' => 'post_tag',
						'terms'    => $tag_2,
					],
					'relation' => 'OR',
				],
			]
		);
		$this->assertCount( 1, $result );
		$this->assertEquals( $story_1, $result[0]->id );
	}

	/**
	 * Test register_cron_jobs and clear_cron_jobs.
	 */
	public function test_register_and_clear_cron_jobs() {
		Budgets::clear_cron_jobs();

		$this->assertFalse(
			wp_next_scheduled( Budgets::AUTO_ARCHIVE_CRON_HOOK ),
			'Cron hook should not be scheduled initially'
		);

		Budgets::register_cron_jobs();

		$timestamp = wp_next_scheduled( Budgets::AUTO_ARCHIVE_CRON_HOOK );
		$this->assertNotFalse(
			$timestamp,
			'Cron hook should be scheduled after registration'
		);

		Budgets::clear_cron_jobs();

		$this->assertFalse(
			wp_next_scheduled( Budgets::AUTO_ARCHIVE_CRON_HOOK ),
			'Cron hook should be cleared after clear_cron_jobs()'
		);
	}

	/**
	 * Test process_auto_archive_budgets method.
	 */
	public function test_process_auto_archive_budgets() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$budget->unarchive();

		$yesterday = new \DateTime( 'yesterday' );
		$budget->set_auto_archive( $yesterday );

		$future_budget_id = self::$budgets[1];
		$future_budget = new Budget( $future_budget_id );
		$future_budget->unarchive();
		$future_budget->set_auto_archive( new \DateTime( '+1 week' ) );

		$archived_count = Budgets::process_auto_archive_budgets();

		$this->assertEquals( 1, $archived_count, 'Should archive exactly one budget' );

		$refreshed_budget = new Budget( $budget_id );
		$this->assertTrue( $refreshed_budget->archived, 'Past-due budget should be archived' );

		$refreshed_future_budget = new Budget( $future_budget_id );
		$this->assertFalse( $refreshed_future_budget->archived, 'Future-dated budget should not be archived' );
		$this->assertNotEmpty( $refreshed_future_budget->archive_at, 'Future auto-archive date should remain' );
	}

	/**
	 * Test process_auto_archive_budgets with no eligible budgets.
	 */
	public function test_process_auto_archive_budgets_no_eligible() {
		$budget1 = new Budget( self::$budgets[0] );
		$budget1->unarchive();
		$budget1->clear_auto_archive();

		$budget2 = new Budget( self::$budgets[1] );
		$budget2->unarchive();
		$budget2->set_auto_archive( new \DateTime( '+1 week' ) );

		$result = Budgets::process_auto_archive_budgets();
		$this->assertEmpty( $result );

		$refreshed_budget1 = new Budget( self::$budgets[0] );
		$this->assertFalse( $refreshed_budget1->archived );

		$refreshed_budget2 = new Budget( self::$budgets[1] );
		$this->assertFalse( $refreshed_budget2->archived );
	}

	/**
	 * Test update_budgets_order method.
	 */
	public function test_update_budgets_order() {
		Budgets::update_budgets_order( [ self::$budgets[1], self::$budgets[0] ] );

		$refreshed_budget1 = new Budget( self::$budgets[0] );
		$refreshed_budget2 = new Budget( self::$budgets[1] );

		$this->assertEquals( 2, $refreshed_budget1->order );
		$this->assertEquals( 1, $refreshed_budget2->order );
	}

	/**
	 * Test get_budgets ordering.
	 */
	public function test_get_budgets_ordering() {
		Budgets::update_budgets_order( [ self::$budgets[1], self::$budgets[0] ] );

		$budgets = Budgets::get_budgets();
		$this->assertEquals( self::$budgets[1], $budgets[0]->id );
		$this->assertEquals( self::$budgets[0], $budgets[1]->id );

		delete_term_meta( self::$budgets[0], Budget::ORDER_META_KEY );
		delete_term_meta( self::$budgets[1], Budget::ORDER_META_KEY );

		$budgets = Budgets::get_budgets();
		$this->assertCount( 2, $budgets );
	}

	/**
	 * Test get_stories with query arg parameter.
	 */
	public function test_get_stories_with_query_arg() {
		$draft_post_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'draft',
			]
		);
		wp_set_post_terms( $draft_post_id, [ self::$budgets[0] ], Budgets::TAXONOMY );

		$published_stories = Budgets::get_stories( [ 'post_status' => 'publish' ] );
		$all_stories       = Budgets::get_stories();

		$this->assertGreaterThan( count( $published_stories ), count( $all_stories ) );
	}

	/**
	 * Test process_auto_archive_budgets with already archived budget.
	 */
	public function test_process_auto_archive_budgets_already_archived() {
		$budget = new Budget( self::$budgets[0] );
		$budget->archive();
		$budget->set_auto_archive( new \DateTime( 'yesterday' ) ); // This should not work on archived budget.

		$result = Budgets::process_auto_archive_budgets();
		$this->assertEmpty( $result );

		// Clean up.
		$budget->unarchive();
	}
}
