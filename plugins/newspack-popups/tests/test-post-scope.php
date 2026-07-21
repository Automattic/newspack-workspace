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

	/**
	 * Creating a scoped prompt from an approved candidate produces an inline,
	 * post-scoped prompt with the copy, button, campaign group, and audit meta.
	 */
	public function test_create_scoped_prompt() {
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'          => self::$post_a,
				'body'             => 'Fund the reporting behind this story.',
				'button_label'     => 'Fund this reporting',
				'button_url'       => 'https://example.com/donate',
				'position'         => 2,
				'ai_generated'     => true,
				'template_version' => 'donation/2026-07-scaffold',
				'request_id'       => 'req-123',
			]
		);

		$this->assertIsInt( $prompt_id );

		// Scoped to its post, and inline at the requested position.
		$this->assertSame( self::$post_a, (int) get_post_field( 'post_parent', $prompt_id ) );
		$this->assertSame( 'inline', get_post_meta( $prompt_id, 'placement', true ) );
		$this->assertSame( 'blocks_count', get_post_meta( $prompt_id, 'trigger_type', true ) );
		$this->assertSame( '2', (string) get_post_meta( $prompt_id, 'trigger_blocks_count', true ) );

		// Content carries the copy and the button.
		$content = get_post_field( 'post_content', $prompt_id );
		$this->assertStringContainsString( 'Fund the reporting behind this story.', $content );
		$this->assertStringContainsString( 'https://example.com/donate', $content );
		$this->assertStringContainsString( 'Fund this reporting', $content );

		// Collected under the Contextual Prompts campaign group.
		$terms = wp_get_post_terms( $prompt_id, Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY, [ 'fields' => 'names' ] );
		$this->assertContains( Newspack_Popups_Post_Scope::CAMPAIGN_GROUP_NAME, $terms );

		// Audit trail.
		$this->assertSame( '1', (string) get_post_meta( $prompt_id, Newspack_Popups_Post_Scope::META_AI_GENERATED, true ) );
		$this->assertSame( 'donation/2026-07-scaffold', get_post_meta( $prompt_id, Newspack_Popups_Post_Scope::META_AI_TEMPLATE_VERSION, true ) );
		$this->assertSame( 'req-123', get_post_meta( $prompt_id, Newspack_Popups_Post_Scope::META_AI_REQUEST_ID, true ) );

		// It is excluded from the general query but retrievable for its post.
		$eligible_ids = wp_list_pluck( Newspack_Popups_Model::retrieve_eligible_popups(), 'id' );
		$this->assertNotContains( $prompt_id, $eligible_ids );
		$scoped_ids = wp_list_pluck( Newspack_Popups_Model::retrieve_scoped_popups( self::$post_a ), 'id' );
		$this->assertContains( $prompt_id, $scoped_ids );
	}

	/**
	 * With no button URL, the prompt renders copy only (no button block).
	 */
	public function test_create_scoped_prompt_without_button() {
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id' => self::$post_a,
				'body'    => 'Copy only, no button.',
			]
		);

		$content = get_post_field( 'post_content', $prompt_id );
		$this->assertStringContainsString( 'Copy only, no button.', $content );
		$this->assertStringNotContainsString( 'wp-block-button', $content );
	}

	/**
	 * Creation validates its inputs.
	 */
	public function test_create_scoped_prompt_validation() {
		$this->assertWPError(
			Newspack_Popups_Post_Scope::create_scoped_prompt(
				[
					'post_id' => 0,
					'body'    => 'x',
				] 
			),
			'A missing post is rejected.'
		);
		$this->assertWPError(
			Newspack_Popups_Post_Scope::create_scoped_prompt(
				[
					'post_id' => self::$post_a,
					'body'    => '   ',
				]
			),
			'Empty copy is rejected.'
		);
	}

	/**
	 * A created prompt can be fetched back as editable fields and updated in place.
	 */
	public function test_fetch_and_update_scoped_prompt() {
		// A fresh post with no scoped prompt from set_up.
		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => 'Original copy.',
				'button_label' => 'Give',
				'button_url'   => 'https://example.com/a',
				'position'     => 2,
			]
		);

		// Round-trip: fetch the editable fields for the post.
		$fetched = Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $post_id );
		$this->assertSame( $prompt_id, $fetched['id'] );
		$this->assertSame( 'Original copy.', $fetched['body'] );
		$this->assertSame( 'Give', $fetched['button_label'] );
		$this->assertSame( 'https://example.com/a', $fetched['button_url'] );
		$this->assertSame( 2, $fetched['position'] );

		// Update in place (same prompt, no new one created).
		$updated = Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'         => 'Edited copy.',
				'button_label' => 'Donate now',
				'button_url'   => 'https://example.com/b',
				'position'     => 5,
			]
		);
		$this->assertSame( $prompt_id, $updated );

		$refetched = Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $post_id );
		$this->assertSame( $prompt_id, $refetched['id'], 'Updates the existing prompt, not a new one.' );
		$this->assertSame( 'Edited copy.', $refetched['body'] );
		$this->assertSame( 5, $refetched['position'] );
		$this->assertStringContainsString( 'Edited copy.', get_post_field( 'post_content', $prompt_id ) );
		$this->assertStringContainsString( 'https://example.com/b', get_post_field( 'post_content', $prompt_id ) );
	}

	/**
	 * Updating something that isn't a scoped prompt is rejected.
	 */
	public function test_update_rejects_non_scoped_prompt() {
		$this->assertWPError(
			Newspack_Popups_Post_Scope::update_scoped_prompt( self::$unscoped_id, [ 'body' => 'x' ] ),
			'A site-wide prompt is not a scoped prompt.'
		);
		$this->assertWPError(
			Newspack_Popups_Post_Scope::update_scoped_prompt( self::$post_a, [ 'body' => 'x' ] ),
			'A regular post is not a prompt.'
		);
	}

	/**
	 * No scoped prompt yet: fetch returns null.
	 */
	public function test_fetch_returns_null_when_none() {
		$this->assertNull( Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( self::$post_b ) );
	}
}
