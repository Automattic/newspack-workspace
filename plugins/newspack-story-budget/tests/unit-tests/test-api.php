<?php
/**
 * Test API
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

use Newspack_Story_Budget\Fields\Abstract_Field;

/**
 * Test API Class.
 */
class Test_API extends \WP_UnitTestCase {

	protected $administrator;
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
			$story = new Story( $post_id );
			$story->update_budgets( [ self::$budgets[ $i % 2 ] ] );
		}
	}

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();

		$this->administrator = $this->factory->user->create(
			[
				'role' => 'administrator',
			]
		);
		wp_set_current_user( $this->administrator );
	}

	/**
	 * Test get stories.
	 */
	public function test_get_stories() {
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories', API::NAMESPACE ) );
		$response = API::get_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 100, $data['stories'] );
		$this->assertEquals( 100, $data['total'] );
	}

	/**
	 * Test get stories with specific IDs.
	 */
	public function test_get_stories_with_ids() {
		// Get a subset of story IDs to test with.
		$story_ids = array_slice( self::$stories, 0, 3 );
		sort( $story_ids );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories', API::NAMESPACE ) );
		$request->set_param( 'ids', $story_ids );

		$response = API::get_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 3, $data['stories'], 'Should get 3 stories.' );
		$this->assertEquals( 3, $data['total'] );

		// Verify we got the correct stories.
		$response_story_ids = array_map(
			function( $story ) {
				return $story['id'];
			},
			$data['stories']
		);

		$this->assertEqualsCanonicalizing( $story_ids, $response_story_ids );

		$story_ids[] = 99999; // Bogus story ID.
		$request->set_param( 'ids', $story_ids );
		$this->assertCount( 4, $request->get_param( 'ids' ) );

		$response = API::get_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 3, $data['stories'], 'Invalid story IDs should be ignored.' );
		$this->assertEquals( 3, $data['total'] );
	}

	/**
	 * Test get stories modified or created since a timestamp.
	 */
	public function test_get_stories_since() {
		// Set all existing stories' `_modified` meta to a known past time so they
		// won't be returned when filtering by the current timestamp.
		$past_time = time() - 100;
		foreach ( self::$stories as $post_id ) {
			update_post_meta( $post_id, Abstract_Field::FIELD_PREFIX . '_modified', $past_time );
		}

		// Capture the current timestamp as the threshold.
		$current_time = time();

		// Create a new story; its `_modified` will be set to >= $current_time via the save_post hook.
		$new_story = self::factory()->post->create();
		$story = new Story( $new_story );
		$story->update_budgets( [ self::$budgets[0] ] );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories', API::NAMESPACE ) );
		$request->set_param( 'since', $current_time );
		$request->set_param( 'metadata', true );

		$response = API::get_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		// Should only get the new story.
		$this->assertCount( 1, $data['stories'], 'Should only get the new story.' );
		$this->assertEquals( 1, $data['total'], 'Should only get the new story.' );
		$this->assertEquals( $new_story, $data['stories'][0]['id'], 'Should only get the new story.' );
		$this->assertArrayHasKey( 'metadata', $data['stories'][0], 'Should get metadata.' );
	}

	/**
	 * Test get stories as author.
	 */
	public function test_get_stories_as_author() {
		$author = $this->factory->user->create(
			[
				'role' => 'author',
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_author' => $author,
			]
		);
		$story = new Story( $post_id );
		$story->update_budgets( [ self::$budgets[0] ] );

		wp_set_current_user( $author );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories', API::NAMESPACE ) );
		$response = API::get_stories( $request );

		$data = $response->get_data();

		$this->assertCount( 1, $data['stories'] );
		$this->assertEquals( $post_id, $data['stories'][0]['id'] );
	}

	/**
	 * Test get stories search.
	 */
	public function test_get_stories_search() {
		$story = self::factory()->post->create(
			[
				'post_title' => 'Test Search String',
			]
		);
		$story_obj = new Story( $story );
		$story_obj->update_budgets( [ self::$budgets[0] ] );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories/search', API::NAMESPACE ) );
		$request->set_param( 's', 'Test Search String' );

		$response = API::get_stories_search( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 1, $data['story_ids'] );
		$this->assertEquals( 1, $data['total'] );
	}

	/**
	 * Test get story.
	 */
	public function test_get_story() {
		$story_id = self::$stories[0];
		$request = new \WP_REST_Request(
			'GET',
			sprintf( '%s/stories/%d', API::NAMESPACE, $story_id )
		);
		$request->set_param( 'id', $story_id );

		$response = API::get_story( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertEquals( $story_id, $data['id'] );
	}

	/**
	 * Test get budgets.
	 */
	public function test_get_budgets() {
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets', API::NAMESPACE ) );
		$response = API::get_budgets( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 2, $data['budgets'] );
		$this->assertEquals( 2, $data['total'] );
	}

	/**
	 * Test get budgets limit.
	 */
	public function test_get_budgets_limit() {
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets', API::NAMESPACE ) );
		$request->set_param( 'limit', 1 );

		$response = API::get_budgets( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 1, $data['budgets'] );
		$this->assertEquals( 2, $data['total'] );
	}

	/**
	 * Test get budget stories.
	 */
	public function test_get_budget_stories() {
		$budget_id = self::$budgets[0];
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets/%d/stories', API::NAMESPACE, $budget_id ) );
		$request->set_param( 'id', $budget_id );

		$response = API::get_budget_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 50, $data['stories'] );
		$this->assertEquals( 50, $data['total'] );
	}

	/**
	 * Test get budget stories search.
	 */
	public function test_get_budget_stories_search() {
		$budget_id = self::$budgets[0];
		$story = self::factory()->post->create(
			[
				'post_title' => 'Test Search String',
			]
		);
		$story_obj = new Story( $story );
		$story_obj->update_budgets( [ $budget_id ] );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets/%d/stories/search', API::NAMESPACE, $budget_id ) );
		$request->set_param( 'id', $budget_id );
		$request->set_param( 's', 'Test Search String' );

		$response = API::get_budget_stories_search( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 1, $data['story_ids'] );
		$this->assertEquals( 1, $data['total'] );
	}
}
