<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests the batched editor posts endpoint.
 *
 * @package Newspack_Blocks
 */

/**
 * The batch endpoint lets the editor load posts for every Homepage Posts block on a page with one
 * request, applying deduplication in document order on the server.
 */
class HomepagePostsBatchTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Posts in one category, newest first.
	 *
	 * @var int[]
	 */
	private $post_ids = [];

	/**
	 * Category holding the posts.
	 *
	 * @var int
	 */
	private $category_id;

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->category_id = self::factory()->category->create();
		for ( $i = 0; $i < 6; $i++ ) {
			// Newest first, so a query for N posts returns the first N of $post_ids.
			array_unshift(
				$this->post_ids,
				self::factory()->post->create(
					[
						'post_status'   => 'publish',
						'post_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 10 - $i ) * HOUR_IN_SECONDS ),
						'post_category' => [ $this->category_id ],
					]
				)
			);
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Send a batch request.
	 *
	 * @param array $queries Block queries, in document order.
	 * @param int[] $exclude Posts to exclude from the first deduplicating block.
	 * @return WP_REST_Response
	 */
	private function batch( $queries, $exclude = [] ) {
		$request = new WP_REST_Request( 'POST', '/newspack-blocks/v1/newspack-blocks-posts-batch' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				[
					'queries' => $queries,
					'exclude' => $exclude,
				]
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * A block query for posts in the test category.
	 *
	 * @param string $client_id   Block client ID.
	 * @param int    $count       Posts to show.
	 * @param bool   $deduplicate Whether the block deduplicates.
	 * @return array
	 */
	private function category_query( $client_id, $count, $deduplicate = true ) {
		return [
			'clientId'    => $client_id,
			'deduplicate' => $deduplicate,
			'postsQuery'  => [
				'postsToShow' => $count,
				'categories'  => [ $this->category_id ],
			],
		];
	}

	/**
	 * IDs of the posts a result returned.
	 *
	 * @param array $result One entry of the batch response.
	 * @return int[]
	 */
	private static function ids( $result ) {
		return array_column( $result['posts'], 'id' );
	}

	public function test_returns_results_in_request_order() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$results = $this->batch( [ $this->category_query( 'a', 2 ), $this->category_query( 'b', 1 ) ] )->get_data();

		self::assertSame( [ 'a', 'b' ], array_column( $results, 'clientId' ) );
	}

	/**
	 * Run a query through the single-block route.
	 *
	 * @param array $params Query parameters.
	 * @return array Posts.
	 */
	private function single( $params ) {
		$single = new WP_REST_Request( 'GET', '/newspack-blocks/v1/newspack-blocks-posts' );
		$single->set_query_params( $params );
		return rest_do_request( $single )->get_data();
	}

	/**
	 * A batch of one is the only size that cannot carry state from one query to the next, so the
	 * query under test here is the last of three.
	 */
	public function test_a_later_result_matches_the_single_block_endpoint() {
		$expected = $this->single(
			[
				'postsToShow' => 2,
				'categories'  => [ $this->category_id ],
				'exclude'     => array_slice( $this->post_ids, 0, 3 ), // What the two queries above show.
			]
		);

		$results = $this->batch( [ $this->category_query( 'a', 2 ), $this->category_query( 'b', 1 ), $this->category_query( 'c', 2 ) ] )->get_data();

		self::assertSame( $expected, $results[2]['posts'], 'A batched query returns what the single-block route returns for the same query.' );
	}

	/**
	 * A post can embed a Homepage Posts block of its own. Formatting such a post renders that
	 * block, which records its posts in the deduplication list the query builder reads. Left in
	 * place, that list excludes those posts from the queries below it in the batch.
	 */
	public function test_a_post_that_embeds_a_block_does_not_hide_posts_from_later_queries() {
		$embedding_post = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'post_content' => '<!-- wp:newspack-blocks/homepage-articles {"postsToShow":2,"deduplicate":true} /-->',
			]
		);

		$expected = $this->single( [ 'postsToShow' => 4 ] );

		$results = $this->batch(
			[
				[
					'clientId'    => 'embed',
					'deduplicate' => false,
					'postsQuery'  => [
						'include'     => [ $embedding_post ],
						'postsToShow' => 1,
					],
				],
				[
					'clientId'    => 'after',
					'deduplicate' => true,
					'postsQuery'  => [ 'postsToShow' => 4 ],
				],
			]
		)->get_data();

		self::assertSame( array_column( $expected, 'id' ), self::ids( $results[1] ), 'The query after the embedding post returns what it would on its own.' );
	}

	public function test_deduplicating_blocks_skip_posts_shown_above_them() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$results = $this->batch( [ $this->category_query( 'a', 2 ), $this->category_query( 'b', 2 ) ] )->get_data();

		self::assertSame( array_slice( $this->post_ids, 0, 2 ), self::ids( $results[0] ) );
		self::assertSame( array_slice( $this->post_ids, 2, 2 ), self::ids( $results[1] ), 'The second block starts after the posts the first block shows.' );
	}

	public function test_a_block_without_deduplication_neither_skips_nor_hides_posts() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$results = $this->batch(
			[
				$this->category_query( 'a', 2 ),
				$this->category_query( 'b', 2, false ),
				$this->category_query( 'c', 2 ),
			]
		)->get_data();

		self::assertSame( array_slice( $this->post_ids, 0, 2 ), self::ids( $results[1] ), 'A block without deduplication may repeat posts shown above it.' );
		self::assertSame( array_slice( $this->post_ids, 2, 2 ), self::ids( $results[2] ), 'Posts shown by a block without deduplication stay available below it.' );
	}

	public function test_the_initial_exclusion_list_applies_to_deduplicating_blocks() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$results = $this->batch(
			[ $this->category_query( 'a', 2 ), $this->category_query( 'b', 2, false ) ],
			[ $this->post_ids[0] ]
		)->get_data();

		self::assertSame( array_slice( $this->post_ids, 1, 2 ), self::ids( $results[0] ) );
		self::assertSame( array_slice( $this->post_ids, 0, 2 ), self::ids( $results[1] ) );
	}

	public function test_an_invalid_query_fails_alone() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$invalid                              = $this->category_query( 'b', 2 );
		$invalid['postsQuery']['postsToShow'] = 'not-a-number';

		$results = $this->batch( [ $this->category_query( 'a', 1 ), $invalid, $this->category_query( 'c', 1 ) ] )->get_data();

		self::assertArrayHasKey( 'error', $results[1] );
		self::assertArrayNotHasKey( 'posts', $results[1] );
		self::assertSame( [ $this->post_ids[1] ], self::ids( $results[2] ), 'Later blocks still load, and the failed block adds nothing to the exclusion list.' );
	}

	public function test_requires_edit_posts() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		self::assertSame( 403, $this->batch( [ $this->category_query( 'a', 1 ) ] )->get_status() );
	}

	public function test_rejects_a_longer_exclusion_list_than_the_limit() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$exclude = range( 1, Newspack_Blocks_API::POSTS_BATCH_MAX_EXCLUDE + 1 );

		self::assertSame( 400, $this->batch( [ $this->category_query( 'a', 1 ) ], $exclude )->get_status() );
	}

	public function test_rejects_more_queries_than_the_limit() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$queries = [];
		for ( $i = 0; $i <= Newspack_Blocks_API::POSTS_BATCH_MAX_QUERIES; $i++ ) {
			$queries[] = $this->category_query( "block-$i", 1 );
		}

		self::assertSame( 400, $this->batch( $queries )->get_status() );
	}
}
