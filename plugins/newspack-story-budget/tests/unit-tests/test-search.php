<?php
/**
 * Tests for the story search custom-field clause.
 *
 * @package Newspack_Story_Budget
 */

use Newspack_Story_Budget\Fields;

/**
 * Special characters in a story search term match custom-field values literally.
 */
class Test_Story_Search extends WP_UnitTestCase {
	/**
	 * Create a post whose searchable description field holds the given value.
	 *
	 * @param string $description Description field value.
	 * @return int Post ID.
	 */
	private function create_story( $description ) {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Untitled story' ] );
		// update_post_meta() unslashes, so slash to store the value as given.
		update_post_meta( $post_id, Fields::get_field( 'description' )->get_post_meta_name(), wp_slash( $description ) );
		return $post_id;
	}

	/**
	 * Run a query carrying the flag the story search REST handlers set.
	 *
	 * @param string $term Search term, as the reader typed it.
	 * @return int[] Matching post IDs.
	 */
	private function search( $term ) {
		$query = new WP_Query(
			[
				'story_budget_search' => true,
				'fields'              => 'ids',
				'posts_per_page'      => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Test fixture; a handful of posts.
				// WP_Query strips one level of slashes from the term; slash it so it reaches the filter as given.
				's'                   => wp_slash( $term ),
			]
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * A term found only in a custom field is matched. Also shows the custom-field clause
	 * is active here, so a no-match below is not a harness artifact.
	 */
	public function test_matches_term_in_custom_field() {
		$match = $this->create_story( 'Weekly roundup of council votes' );
		$this->create_story( 'Profile of the new principal' );

		$this->assertSame( [ $match ], $this->search( 'council' ) );
	}

	/**
	 * Terms containing characters with special meaning elsewhere.
	 *
	 * @return array[] Stored value, non-matching value, search term.
	 */
	public function special_character_terms() {
		return [
			'dollar amount'  => [ 'Budget request: $10 per reader', 'Budget request: pending', '$10' ],
			'backslashes'    => [ 'Files are in C:\\drafts\\final', 'Files are in the shared drive', 'C:\\drafts\\final' ],
			'LIKE wildcards' => [ 'Turnout rose 40% in ward_3', 'Turnout rose 400 in ward 3', '40% in ward_3' ],
		];
	}

	/**
	 * A custom-field term is matched character for character, as title search matches it.
	 *
	 * @dataProvider special_character_terms
	 *
	 * @param string $stored     Field value on the story that should match.
	 * @param string $other      Field value on a story that should not.
	 * @param string $term       Search term.
	 */
	public function test_matches_custom_field_term_literally( $stored, $other, $term ) {
		$match = $this->create_story( $stored );
		$this->create_story( $other );

		$this->assertSame( [ $match ], $this->search( $term ) );
	}
}
