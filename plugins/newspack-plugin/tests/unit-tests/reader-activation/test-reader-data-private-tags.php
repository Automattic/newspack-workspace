<?php
/**
 * Tests for Private_Tags::filter_reader_activity() — strips private tag IDs from
 * the client-side reader-activity data (Part D / NPPD-1464). Always-on when the
 * feature is enabled; no behavior gate.
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

require_once __DIR__ . '/../tags/traits/trait-private-tags-test-helper.php';

/**
 * Tests for Private_Tags::filter_reader_activity().
 *
 * @group private-tags
 */
class Test_Reader_Data_Private_Tags extends WP_UnitTestCase {

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

	// -----------------------------------------------------------------
	// Core stripping behavior.
	// -----------------------------------------------------------------

	/**
	 * Filter reader activity strips private tag ids.
	 */
	public function test_filter_reader_activity_strips_private_tag_ids() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		$activity = [
			'action' => 'article_view',
			'data'   => [
				'tags' => [ $private, $public ],
			],
		];

		$out = Private_Tags::filter_reader_activity( $activity );

		$this->assertSame( [ $public ], $out['data']['tags'] );
	}

	/**
	 * The filter runs through its registered hook — guards against the method working in
	 * isolation while the newspack_reader_activity_article_view registration is broken
	 * (that hook is the production entry point, and this is the security-critical path).
	 */
	public function test_filter_reader_activity_runs_through_registered_hook() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		$activity = [
			'action' => 'article_view',
			'data'   => [ 'tags' => [ $private, $public ] ],
		];

		$out = apply_filters( 'newspack_reader_activity_article_view', $activity );

		$this->assertSame( [ $public ], $out['data']['tags'], 'Private tags should be stripped via the registered hook, not just the method.' );
	}

	/**
	 * Filter reader activity leaves categories intact.
	 */
	public function test_filter_reader_activity_leaves_categories_intact() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		$activity = [
			'action' => 'article_view',
			'data'   => [
				'tags'       => [ $private, $public ],
				'categories' => [ 99, 100 ],
				'author'     => 7,
				'post_id'    => 42,
			],
		];

		$out = Private_Tags::filter_reader_activity( $activity );

		$this->assertSame( [ 99, 100 ], $out['data']['categories'] );
		$this->assertSame( 7, $out['data']['author'] );
		$this->assertSame( 42, $out['data']['post_id'] );
	}

	/**
	 * Filter reader activity empties tags when all private.
	 */
	public function test_filter_reader_activity_empties_tags_when_all_private() {
		$private1 = $this->make_private_tag( 'Beastie' );
		$private2 = $this->make_private_tag( 'Internal' );

		$activity = [
			'data' => [
				'tags' => [ $private1, $private2 ],
			],
		];

		$out = Private_Tags::filter_reader_activity( $activity );

		$this->assertSame( [], $out['data']['tags'] );
	}

	/**
	 * Filter reader activity re indexes into sequential array.
	 */
	public function test_filter_reader_activity_re_indexes_into_sequential_array() {
		$private = $this->make_private_tag( 'Beastie' );
		$pub1    = $this->make_public_tag( 'Jazz' );
		$pub2    = $this->make_public_tag( 'Rock' );

		$activity = [
			'data' => [
				'tags' => [ $pub1, $private, $pub2 ],
			],
		];

		$out = Private_Tags::filter_reader_activity( $activity );

		$this->assertSame( [ 0, 1 ], array_keys( $out['data']['tags'] ) );
	}

	/**
	 * Filter reader activity handles string tag ids.
	 */
	public function test_filter_reader_activity_handles_string_tag_ids() {
		// Some upstream code paths may serialize IDs as strings.
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		$activity = [
			'data' => [
				'tags' => [ (string) $private, (string) $public ],
			],
		];

		$out = Private_Tags::filter_reader_activity( $activity );

		$this->assertSame( [ $public ], $out['data']['tags'] );
	}

	// -----------------------------------------------------------------
	// No-op paths.
	// -----------------------------------------------------------------

	/**
	 * Filter reader activity no op when tags missing.
	 */
	public function test_filter_reader_activity_no_op_when_tags_missing() {
		$activity = [ 'data' => [ 'post_id' => 42 ] ];
		$out      = Private_Tags::filter_reader_activity( $activity );
		$this->assertSame( $activity, $out );
	}

	/**
	 * Filter reader activity no op when no private tags on site.
	 */
	public function test_filter_reader_activity_no_op_when_no_private_tags_on_site() {
		$public = $this->make_public_tag( 'Jazz' );
		$activity = [ 'data' => [ 'tags' => [ $public ] ] ];
		$out      = Private_Tags::filter_reader_activity( $activity );
		$this->assertSame( [ $public ], $out['data']['tags'] );
	}

	/**
	 * Filter reader activity no op when tags not array.
	 */
	public function test_filter_reader_activity_no_op_when_tags_not_array() {
		$activity = [ 'data' => [ 'tags' => 'oops' ] ];
		$out      = Private_Tags::filter_reader_activity( $activity );
		$this->assertSame( $activity, $out );
	}

	// -----------------------------------------------------------------
	// Always-on guarantee: no behavior gate.
	// -----------------------------------------------------------------

	/**
	 * Filter reader activity runs even with all settings off.
	 */
	public function test_filter_reader_activity_runs_even_with_all_settings_off() {
		// Turn every behavior flag off — Part D is always-on, must still strip.
		$this->set_private_tags_settings( [] );
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );

		$activity = [ 'data' => [ 'tags' => [ $private, $public ] ] ];
		$out      = Private_Tags::filter_reader_activity( $activity );

		$this->assertSame( [ $public ], $out['data']['tags'] );
	}
}
