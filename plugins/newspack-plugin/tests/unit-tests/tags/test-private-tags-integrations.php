<?php
/**
 * Tests for the Newspack\Private_Tags class — third-party + admin integrations:
 * Google Ad Manager targeting, Yoast schema/sitemap, the REST label, the admin
 * "Private" column, and the admin term_name label.
 *
 * Core behavior lives in test-private-tags.php; frontend filters in
 * test-private-tags-frontend.php.
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

require_once __DIR__ . '/traits/trait-private-tags-test-helper.php';

/**
 * Private_Tags integration + admin tests.
 *
 * @group private-tags
 */
class Test_Private_Tags_Integrations extends WP_UnitTestCase {

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
	// filter_ad_targeting() — GAM integration.
	// -----------------------------------------------------------------

	/**
	 * Filter ad targeting strips private tag slugs.
	 */
	public function test_filter_ad_targeting_strips_private_tag_slugs() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$tgt     = [ 'tag' => [ $slug, 'jazz' ] ];

		$out = Private_Tags::filter_ad_targeting( $tgt, [] );

		$this->assertSame( [ 'jazz' ], $out['tag'] );
	}

	/**
	 * Filter ad targeting unsets tag key when all private.
	 */
	public function test_filter_ad_targeting_unsets_tag_key_when_all_private() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$tgt     = [ 'tag' => [ $slug ] ];

		$out = Private_Tags::filter_ad_targeting( $tgt, [] );

		$this->assertArrayNotHasKey( 'tag', $out );
	}

	/**
	 * Filter ad targeting unchanged when behavior disabled.
	 */
	public function test_filter_ad_targeting_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'           => false,
				'gam_targeting' => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$tgt     = [ 'tag' => [ $slug ] ];

		$this->assertSame( $tgt, Private_Tags::filter_ad_targeting( $tgt, [] ) );
	}

	/**
	 * Filter ad targeting unchanged when no tag key.
	 */
	public function test_filter_ad_targeting_unchanged_when_no_tag_key() {
		$this->make_private_tag( 'Beastie' );
		$tgt = [ 'category' => [ 'news' ] ];
		$this->assertSame( $tgt, Private_Tags::filter_ad_targeting( $tgt, [] ) );
	}

	// -----------------------------------------------------------------
	// Yoast integrations.
	// -----------------------------------------------------------------

	/**
	 * Filter yoast schema article strips private tag names.
	 */
	public function test_filter_yoast_schema_article_strips_private_tag_names() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private, $public ], 'post_tag' );

		// filter_yoast_schema_article relies on get_queried_object_id().
		$this->go_to( get_permalink( $post_id ) );

		$data = [ 'keywords' => [ 'Beastie', 'Jazz' ] ];
		$out  = Private_Tags::filter_yoast_schema_article( $data, null );

		$this->assertSame( [ 'Jazz' ], $out['keywords'] );
	}

	/**
	 * Filter yoast schema article unsets keywords when all private.
	 */
	public function test_filter_yoast_schema_article_unsets_keywords_when_all_private() {
		$private = $this->make_private_tag( 'Beastie' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private ], 'post_tag' );

		$this->go_to( get_permalink( $post_id ) );

		$data = [ 'keywords' => [ 'Beastie' ] ];
		$out  = Private_Tags::filter_yoast_schema_article( $data, null );

		$this->assertArrayNotHasKey( 'keywords', $out );
	}

	/**
	 * Filter yoast schema article unchanged when behavior disabled.
	 */
	public function test_filter_yoast_schema_article_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'            => false,
				'yoast_metadata' => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private ], 'post_tag' );
		$this->go_to( get_permalink( $post_id ) );

		$data = [ 'keywords' => [ 'Beastie' ] ];
		$this->assertSame( $data, Private_Tags::filter_yoast_schema_article( $data, null ) );
	}

	/**
	 * Filter yoast sitemap term ids adds private ids.
	 */
	public function test_filter_yoast_sitemap_term_ids_adds_private_ids() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::filter_yoast_sitemap_term_ids( [ 999 ] );
		$this->assertContains( 999, $out );
		$this->assertContains( $private, $out );
	}

	/**
	 * Filter yoast sitemap term ids dedupes.
	 */
	public function test_filter_yoast_sitemap_term_ids_dedupes() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::filter_yoast_sitemap_term_ids( [ $private, 5 ] );
		$count   = array_count_values( $out )[ $private ] ?? 0;
		$this->assertSame( 1, $count );
	}

	/**
	 * Filter yoast sitemap term ids unchanged when behavior disabled.
	 */
	public function test_filter_yoast_sitemap_term_ids_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'           => false,
				'yoast_sitemap' => false,
			]
		);
		$this->make_private_tag( 'Beastie' );
		$existing = [ 1, 2, 3 ];
		$this->assertSame( $existing, Private_Tags::filter_yoast_sitemap_term_ids( $existing ) );
	}

	// -----------------------------------------------------------------
	// REST label.
	// -----------------------------------------------------------------

	/**
	 * Append private label to rest labels private tag for editor.
	 */
	public function test_append_private_label_to_rest_labels_private_tag_for_editor() {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );

		$response = new WP_REST_Response( [ 'name' => 'Beastie' ] );
		$out      = Private_Tags::append_private_label_to_rest( $response, $term );
		$this->assertStringEndsWith( '(private)', $out->data['name'] );
	}

	/**
	 * Append private label to rest no op for subscriber.
	 */
	public function test_append_private_label_to_rest_no_op_for_subscriber() {
		$subscriber = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );

		$response = new WP_REST_Response( [ 'name' => 'Beastie' ] );
		$out      = Private_Tags::append_private_label_to_rest( $response, $term );
		$this->assertSame( 'Beastie', $out->data['name'] );
	}

	/**
	 * Append private label to rest skips when name missing.
	 */
	public function test_append_private_label_to_rest_skips_when_name_missing() {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );

		$response = new WP_REST_Response( [ 'id' => $private ] );
		$out      = Private_Tags::append_private_label_to_rest( $response, $term );
		$this->assertArrayNotHasKey( 'name', $out->data );
	}

	// -----------------------------------------------------------------
	// Admin column.
	// -----------------------------------------------------------------

	/**
	 * Add private column appends column.
	 */
	public function test_add_private_column_appends_column() {
		$out = Private_Tags::add_private_column(
			[
				'name' => 'Name',
				'slug' => 'Slug',
			]
		);
		$this->assertArrayHasKey( 'np_private', $out );
	}

	/**
	 * Render private column shows marker for private tag.
	 */
	public function test_render_private_column_shows_marker_for_private_tag() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::render_private_column( '', 'np_private', $private );
		$this->assertStringContainsString( 'data-np-private="1"', $out );
	}

	/**
	 * Render private column blank marker for public tag.
	 */
	public function test_render_private_column_blank_marker_for_public_tag() {
		$public = $this->make_public_tag( 'Jazz' );
		$out    = Private_Tags::render_private_column( '', 'np_private', $public );
		$this->assertStringContainsString( 'data-np-private="0"', $out );
	}

	/**
	 * Render private column passes through other columns.
	 */
	public function test_render_private_column_passes_through_other_columns() {
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::render_private_column( 'orig', 'name', $private );
		$this->assertSame( 'orig', $out );
	}

	// -----------------------------------------------------------------
	// term_name filter (admin label).
	// -----------------------------------------------------------------

	/**
	 * Append private label to name in admin via term id call.
	 */
	public function test_append_private_label_to_name_in_admin_via_term_id_call() {
		set_current_screen( 'edit-tags' );
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::append_private_label_to_name( 'Beastie', $private, 'post_tag' );
		$this->assertStringEndsWith( '(private)', $out );
		set_current_screen( 'front' );
	}

	/**
	 * Append private label to name skips on frontend.
	 */
	public function test_append_private_label_to_name_skips_on_frontend() {
		set_current_screen( 'front' );
		$private = $this->make_private_tag( 'Beastie' );
		$out     = Private_Tags::append_private_label_to_name( 'Beastie', $private, 'post_tag' );
		$this->assertSame( 'Beastie', $out );
	}

	/**
	 * Append private label to name handles wp term arg.
	 */
	public function test_append_private_label_to_name_handles_wp_term_arg() {
		set_current_screen( 'edit-tags' );
		$private = $this->make_private_tag( 'Beastie' );
		$term    = get_term( $private, 'post_tag' );
		$out     = Private_Tags::append_private_label_to_name( 'Beastie', $term );
		$this->assertStringEndsWith( '(private)', $out );
		set_current_screen( 'front' );
	}

	/**
	 * Append private label to name ignores non post tag taxonomy.
	 */
	public function test_append_private_label_to_name_ignores_non_post_tag_taxonomy() {
		set_current_screen( 'edit-tags' );
		$cat_id = $this->factory()->term->create( [ 'taxonomy' => 'category' ] );
		update_term_meta( $cat_id, Private_Tags::META_KEY, 1 );
		$out = Private_Tags::append_private_label_to_name( 'News', $cat_id, 'category' );
		$this->assertSame( 'News', $out );
		set_current_screen( 'front' );
	}
}
