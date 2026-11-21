<?php
/**
 * Tests for the story search REST routes the app calls.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * A search term sent as JSON, as the app sends it, matches stories character for character.
 */
class Test_API_Search extends \WP_UnitTestCase {
	/**
	 * Budget the test stories belong to.
	 *
	 * @var int
	 */
	private $budget_id;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->budget_id = self::factory()->term->create( [ 'taxonomy' => Budgets::TAXONOMY ] );
	}

	/**
	 * Create a story in the test budget, stored exactly as given.
	 *
	 * @param string $title       Story title.
	 * @param string $description Description field value, if any.
	 * @return int Post ID.
	 */
	private function create_story( $title, $description = '' ) {
		// The post and meta write paths unslash their input, so slash to store the values as given.
		$post_id = self::factory()->post->create( [ 'post_title' => wp_slash( $title ) ] );
		$this->assertSame( $title, get_post_field( 'post_title', $post_id, 'raw' ) );

		if ( '' !== $description ) {
			$meta_key = Fields::get_field( 'description' )->get_post_meta_name();
			update_post_meta( $post_id, $meta_key, wp_slash( $description ) );
			$this->assertSame( $description, get_post_meta( $post_id, $meta_key, true ) );
		}

		( new Story( $post_id ) )->update_budgets( [ $this->budget_id ] );
		return $post_id;
	}

	/**
	 * Search through a REST route with a JSON body, as the app does.
	 *
	 * @param string $route Route path after the namespace; `%d` is replaced with the budget ID.
	 * @param string $term  Search term, as the reader typed it.
	 * @return int[] Matching post IDs, sorted.
	 */
	private function search( $route, $term ) {
		$request = new \WP_REST_Request( 'POST', '/' . API::NAMESPACE . sprintf( $route, $this->budget_id ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 's' => $term ] ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_map( 'intval', $response->get_data()['story_ids'] );
		sort( $ids );
		return $ids;
	}

	/**
	 * The two story search routes.
	 *
	 * @return array[] Route path.
	 */
	public function search_routes() {
		return [
			'all stories'    => [ '/stories/search' ],
			'budget stories' => [ '/budgets/%d/stories/search' ],
		];
	}

	/**
	 * A term with backslashes matches a story title containing it.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_backslash_term_matches_title( $route ) {
		$match = $this->create_story( 'Files in C:\\drafts\\final' );
		$this->create_story( 'Files in the shared drive' );

		$this->assertSame( [ $match ], $this->search( $route, 'C:\\drafts\\final' ) );
	}

	/**
	 * A term with backslashes matches a story whose Description contains it.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_backslash_term_matches_description( $route ) {
		$match = $this->create_story( 'Quarterly archive', 'Archive at D:\\exports\\2026' );
		$this->create_story( 'Monthly archive', 'Archive at the shared drive' );

		$this->assertSame( [ $match ], $this->search( $route, 'D:\\exports\\2026' ) );
	}

	/**
	 * A backslash next to a LIKE wildcard is matched literally.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_backslash_with_wildcard( $route ) {
		$match = $this->create_story( 'Rate notes', 'Rate C:\\100% final' );
		$this->create_story( 'Other rate notes', 'Rate C:100 final' );

		$this->assertSame( [ $match ], $this->search( $route, 'C:\\100%' ) );
	}

	/**
	 * A backslash before a quote is matched literally, not read as escaping it.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_backslash_with_quote( $route ) {
		$match = $this->create_story( "Notes from O\\'Brien" );
		$this->create_story( "Notes from O'Brien" );

		$this->assertSame( [ $match ], $this->search( $route, "O\\'Brien" ) );
	}

	/**
	 * Terms without backslashes match as they did before.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_terms_without_backslashes( $route ) {
		$in_description = $this->create_story( 'Weekly roundup', 'Weekly roundup of council votes' );
		$in_title       = $this->create_story( 'Notes on council votes' );
		$other_field    = $this->create_story( 'Profile of the new principal', 'Council of elders' );
		$apostrophe     = $this->create_story( "Notes from O'Brien" );
		$this->create_story( "Notes from O\\'Brien" );

		$expected = [ $in_description, $in_title, $other_field ];
		sort( $expected );
		$this->assertSame( $expected, $this->search( $route, 'council' ) );
		$this->assertSame( [ $in_title ], $this->search( $route, '"council votes"' ) );
		$this->assertSame( [ $apostrophe ], $this->search( $route, "O'Brien" ) );
	}
}
