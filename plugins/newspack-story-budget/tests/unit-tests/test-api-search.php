<?php
/**
 * Tests for the story search REST routes the app calls.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

require_once dirname( __DIR__ ) . '/trait-rest-story-helpers.php';

/**
 * A search term sent as JSON, as the app sends it, matches stories character for character.
 */
class Test_API_Search extends \WP_UnitTestCase {
	use REST_Story_Helpers;

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->budget_id = self::factory()->term->create( [ 'taxonomy' => Budgets::TAXONOMY ] );
	}

	/**
	 * Create a story in the test budget through the app's routes, and check it was stored as given.
	 *
	 * @param string $title       Story title.
	 * @param string $description Description field value, if any, saved as the inline field editor saves it.
	 * @return int Post ID.
	 */
	private function create_story( $title, $description = '' ) {
		$post_id = $this->create_story_via_app( $title );
		$this->assertSame( $title, get_post_field( 'post_title', $post_id, 'raw' ) );

		if ( '' !== $description ) {
			$response = $this->rest( 'POST', '/stories/' . $post_id . '/description', [ 'value' => $description ] );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $description, get_post_meta( $post_id, $this->description_key(), true ) );
		}

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
		$response = $this->rest( 'POST', sprintf( $route, $this->budget_id ), [ 's' => $term ] );
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

	/**
	 * A story saved outside the app, which already stores backslashes, is found by a backslash term.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_story_saved_outside_app_is_found( $route ) {
		// Core REST and the block editor send slashed data to wp_insert_post(), which unslashes it.
		$match = wp_insert_post(
			wp_slash(
				[
					'post_title'  => 'Files in C:\\drafts\\final',
					'post_status' => 'draft',
					'post_type'   => 'post',
				]
			)
		);
		$this->assertSame( 'Files in C:\\drafts\\final', get_post_field( 'post_title', $match, 'raw' ) );
		( new Story( $match ) )->update_budgets( [ $this->budget_id ] );
		$this->create_story( 'Files in the shared drive' );

		$this->assertSame( [ $match ], $this->search( $route, 'C:\\drafts\\final' ) );
	}

	/**
	 * A story the app saved before backslashes were kept is found by the stripped text, not the original.
	 *
	 * @dataProvider search_routes
	 *
	 * @param string $route Route path.
	 */
	public function test_story_saved_before_fix_matches_stripped_term( $route ) {
		$match = $this->create_story( 'Quarterly archive' );
		// What the app's save path used to store for "Archive at D:\exports\2026".
		update_post_meta( $match, $this->description_key(), 'Archive at D:exports2026' );

		$this->assertSame( [ $match ], $this->search( $route, 'D:exports2026' ) );
		$this->assertSame( [], $this->search( $route, 'D:\\exports\\2026' ) );
	}
}
