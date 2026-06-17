<?php
/**
 * Tests for Private_Tags::filter_feed_terms() — strips private tags from <category>
 * elements across all feed surfaces (Part B / NPPD-1462).
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

require_once __DIR__ . '/traits/trait-private-tags-test-helper.php';

/**
 * Tests for Private_Tags::filter_feed_terms() across feed surfaces.
 *
 * @group private-tags
 */
class Test_Private_Tags_RSS extends WP_UnitTestCase {

	use Private_Tags_Test_Helper;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->enable_private_tags_feature();
		$this->reset_private_tags_state();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->reset_private_tags_state();
		parent::tear_down();
	}

	/**
	 * Toggle the global wp_query->is_feed flag for the duration of a callable.
	 *
	 * Is_feed() reads $wp_query->is_feed; flipping it for the test is more
	 * Targeted than a full feed-query simulation.
	 *
	 * @param callable $fn Callable to invoke under is_feed=true.
	 * @return mixed The callable's return value.
	 */
	private function with_feed_context( callable $fn ) {
		global $wp_query;
		$orig             = $wp_query->is_feed;
		$wp_query->is_feed = true;
		try {
			return $fn();
		} finally {
			$wp_query->is_feed = $orig;
		}
	}

	/**
	 * Build a terms array for filter input.
	 *
	 * @param int[] $term_ids Tag IDs.
	 * @return WP_Term[]
	 */
	private function get_terms_array( array $term_ids ) {
		return array_values(
			array_map(
				function( $id ) {
					return get_term( $id, 'post_tag' );
				},
				$term_ids
			)
		);
	}

	// -----------------------------------------------------------------
	// Happy path: strips private tag from feed <category>.
	// -----------------------------------------------------------------

	/**
	 * Filter feed terms strips private tag in feed.
	 */
	public function test_filter_feed_terms_strips_private_tag_in_feed() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$terms   = $this->get_terms_array( [ $private, $public ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'Jazz', reset( $out )->name );
	}

	/**
	 * Filter feed terms returns unchanged when no private tags on site.
	 */
	public function test_filter_feed_terms_returns_unchanged_when_no_private_tags_on_site() {
		$public = $this->make_public_tag( 'Jazz' );
		$terms  = $this->get_terms_array( [ $public ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'Jazz', reset( $out )->name );
	}

	/**
	 * Filter feed terms returns empty array for post with only private tags.
	 */
	public function test_filter_feed_terms_returns_empty_array_for_post_with_only_private_tags() {
		$private = $this->make_private_tag( 'Beastie' );
		$terms   = $this->get_terms_array( [ $private ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertSame( [], $out );
	}

	/**
	 * Filter feed terms re indexes into sequential array.
	 */
	public function test_filter_feed_terms_re_indexes_into_sequential_array() {
		$private = $this->make_private_tag( 'Beastie' );
		$public1 = $this->make_public_tag( 'Jazz' );
		$public2 = $this->make_public_tag( 'Rock' );
		$terms   = $this->get_terms_array( [ $public1, $private, $public2 ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertSame( [ 0, 1 ], array_keys( $out ) );
	}

	// -----------------------------------------------------------------
	// Guards: taxonomy / feed-context / array-type.
	// -----------------------------------------------------------------

	/**
	 * Filter feed terms bails for non post tag taxonomy.
	 */
	public function test_filter_feed_terms_bails_for_non_post_tag_taxonomy() {
		$private = $this->make_private_tag( 'Beastie' );
		$terms   = $this->get_terms_array( [ $private ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'category' );
			}
		);

		$this->assertCount( 1, $out, 'Category-taxonomy lookup must NOT be filtered.' );
	}

	/**
	 * Filter feed terms bails when not in feed.
	 */
	public function test_filter_feed_terms_bails_when_not_in_feed() {
		$private = $this->make_private_tag( 'Beastie' );
		$terms   = $this->get_terms_array( [ $private ] );

		// No with_feed_context wrapper — call outside a feed.
		$out = Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );

		$this->assertCount( 1, $out, 'Non-feed lookups should pass through untouched.' );
	}

	/**
	 * Filter feed terms returns non array unchanged.
	 */
	public function test_filter_feed_terms_returns_non_array_unchanged() {
		// get_the_terms can return false (no terms) or WP_Error (failure); both should pass through.
		$this->assertFalse(
			$this->with_feed_context(
				function() {
					return Private_Tags::filter_feed_terms( false, 0, 'post_tag' );
				}
			)
		);

		$err = new WP_Error( 'oops', 'broken' );
		$out = $this->with_feed_context(
			function() use ( $err ) {
				return Private_Tags::filter_feed_terms( $err, 0, 'post_tag' );
			}
		);
		$this->assertSame( $err, $out );
	}

	// -----------------------------------------------------------------
	// Behavior gating.
	// -----------------------------------------------------------------

	/**
	 * Filter feed terms skips when feed terms disabled.
	 */
	public function test_filter_feed_terms_skips_when_feed_terms_disabled() {
		$this->set_private_tags_settings(
			[
				'all'        => false,
				'feed_terms' => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$terms   = $this->get_terms_array( [ $private ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertCount( 1, $out, 'When feed_terms is off, the private tag should remain.' );
	}

	/**
	 * Filter feed terms master all enables stripping.
	 */
	public function test_filter_feed_terms_master_all_enables_stripping() {
		$this->set_private_tags_settings(
			[
				'all'        => true,
				'feed_terms' => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$terms   = $this->get_terms_array( [ $private ] );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertSame( [], $out );
	}

	// -----------------------------------------------------------------
	// Non-WP_Term entries: must be left intact.
	// -----------------------------------------------------------------

	/**
	 * Filter feed terms preserves non wp term entries.
	 */
	public function test_filter_feed_terms_preserves_non_wp_term_entries() {
		$private = $this->make_private_tag( 'Beastie' );
		// Garbage entry that shouldn't be evaluated by in_array term_id check.
		$terms = array_merge( [ 'not-a-term-object' ], $this->get_terms_array( [ $private ] ) );

		$out = $this->with_feed_context(
			function() use ( $terms ) {
				return Private_Tags::filter_feed_terms( $terms, 0, 'post_tag' );
			}
		);

		$this->assertContains( 'not-a-term-object', $out, 'Non-WP_Term entries must survive the filter.' );
		$names = array_map(
			function( $t ) {
				return $t instanceof WP_Term ? $t->name : null;
			},
			$out
		);
		$this->assertNotContains( 'Beastie', $names );
	}

	// -----------------------------------------------------------------
	// Integration: end-to-end feed render strips private tag from <category>.
	// -----------------------------------------------------------------

	/**
	 * Feed output excludes private tag category element.
	 */
	public function test_feed_output_excludes_private_tag_category_element() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private, $public ], 'post_tag' );

		$output = $this->with_feed_context(
			function() use ( $post_id ) {
				global $post;
				$post = get_post( $post_id );
				setup_postdata( $post );
				ob_start();
				the_category_rss( 'rss2' );
				$out = ob_get_clean();
				wp_reset_postdata();
				return $out;
			}
		);

		$this->assertStringContainsString( 'Jazz', $output );
		$this->assertStringNotContainsString( 'Beastie', $output );
	}
}
