<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class PostScope Test
 *
 * Tests post-scoped prompts (Contextual Prompts): they are excluded from the
 * general eligible-prompts query, retrievable for their parent post, and gated
 * to display only on that post.
 *
 * @package Newspack_Popups
 */

/**
 * Post scope test case.
 */
class PostScopeTest extends WP_UnitTestCase {
	private static $unscoped_id = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $scoped_id   = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $post_a      = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $post_b      = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * Set up: two inline prompts (one site-wide, one scoped to post A) and two posts.
	 */
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();

		// Clear any prompts from earlier tests.
		foreach ( Newspack_Popups_Model::retrieve_popups( true ) as $popup ) {
			wp_delete_post( $popup['id'], true );
		}

		self::$post_a = self::factory()->post->create( [ 'post_type' => 'post' ] );
		self::$post_b = self::factory()->post->create( [ 'post_type' => 'post' ] );

		self::$unscoped_id = self::create_inline_prompt();
		self::$scoped_id   = self::create_inline_prompt();
		wp_update_post(
			[
				'ID'          => self::$scoped_id,
				'post_parent' => self::$post_a,
			]
		);
	}

	/**
	 * Create a published inline prompt.
	 *
	 * @return int Prompt ID.
	 */
	private static function create_inline_prompt() {
		$id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => 'Prompt',
				'post_content' => 'Prompt content.',
				'post_status'  => 'publish',
			]
		);
		Newspack_Popups_Model::set_popup_options( $id, [ 'placement' => 'inline' ] );
		return $id;
	}

	/**
	 * Scoped prompts are kept out of the general eligible-prompts query.
	 */
	public function test_scoped_prompt_excluded_from_eligible_query() {
		$eligible_ids = wp_list_pluck( Newspack_Popups_Model::retrieve_eligible_popups(), 'id' );

		$this->assertContains( self::$unscoped_id, $eligible_ids, 'Site-wide prompt should be eligible.' );
		$this->assertNotContains( self::$scoped_id, $eligible_ids, 'Scoped prompt should be excluded from the general query.' );
	}

	/**
	 * Scoped prompts are retrievable for their parent post only.
	 */
	public function test_retrieve_scoped_popups_targets_the_parent_post() {
		$for_a = wp_list_pluck( Newspack_Popups_Model::retrieve_scoped_popups( self::$post_a ), 'id' );
		$for_b = wp_list_pluck( Newspack_Popups_Model::retrieve_scoped_popups( self::$post_b ), 'id' );

		$this->assertContains( self::$scoped_id, $for_a, 'Scoped prompt should be retrievable for its parent post.' );
		$this->assertNotContains( self::$unscoped_id, $for_a, 'Site-wide prompt is not a scoped prompt.' );
		$this->assertEmpty( $for_b, 'A post with no scoped prompts returns none.' );
		$this->assertEmpty( Newspack_Popups_Model::retrieve_scoped_popups( 0 ), 'No post ID returns none.' );
	}

	/**
	 * A scoped prompt displays on its parent post and nowhere else.
	 */
	public function test_should_display_gates_to_parent_post() {
		$scoped_popup = Newspack_Popups_Model::retrieve_popup_by_id( self::$scoped_id );

		// On the parent post: shown even if the prior check was false.
		$this->go_to( get_permalink( self::$post_a ) );
		$this->assertTrue(
			Newspack_Popups_Post_Scope::filter_should_display( false, $scoped_popup ),
			'Scoped prompt should display on its parent post.'
		);

		// On a different post: hidden even if the prior check was true.
		$this->go_to( get_permalink( self::$post_b ) );
		$this->assertFalse(
			Newspack_Popups_Post_Scope::filter_should_display( true, $scoped_popup ),
			'Scoped prompt should not display on other posts.'
		);
	}

	/**
	 * A non-scoped prompt's display decision is left untouched by the filter.
	 */
	public function test_should_display_leaves_unscoped_prompts_untouched() {
		$unscoped_popup = Newspack_Popups_Model::retrieve_popup_by_id( self::$unscoped_id );

		$this->go_to( get_permalink( self::$post_a ) );
		$this->assertTrue( Newspack_Popups_Post_Scope::filter_should_display( true, $unscoped_popup ) );
		$this->assertFalse( Newspack_Popups_Post_Scope::filter_should_display( false, $unscoped_popup ) );
	}

	/**
	 * The query-exclusion helper restricts to top-level prompts and preserves other args.
	 */
	public function test_exclude_scoped_from_args_helper() {
		$args = Newspack_Popups_Post_Scope::exclude_scoped_from_args(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'posts_per_page' => 100,
			]
		);
		$this->assertSame( 0, $args['post_parent'], 'Eligible query is restricted to top-level (site-wide) prompts.' );
		$this->assertSame( 100, $args['posts_per_page'], 'Existing args are preserved.' );
	}
}
