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
		// post_b, not post_a: set_up already scopes a prompt to post_a, and a story
		// has at most one Contextual Prompt — creating against it would upsert.
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'          => self::$post_b,
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
		$this->assertSame( self::$post_b, (int) get_post_field( 'post_parent', $prompt_id ) );
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
		$scoped_ids = wp_list_pluck( Newspack_Popups_Model::retrieve_scoped_popups( self::$post_b ), 'id' );
		$this->assertContains( $prompt_id, $scoped_ids );
	}

	/**
	 * With no button URL, the prompt renders copy only (no button block).
	 */
	public function test_create_scoped_prompt_without_button() {
		// post_b, not post_a: set_up already scopes a prompt to post_a, and a story
		// has at most one Contextual Prompt — creating against it would upsert.
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id' => self::$post_b,
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
	 * A scoped prompt whose placement drifted to an overlay must never be served as
	 * a scoped prompt, and updating it must restore the inline contract. Otherwise an
	 * in-article donation ask renders as a full-screen takeover on the story.
	 */
	public function test_overlay_placement_drift_is_contained() {
		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 2,
			]
		);

		// Sanity: it starts inline and is retrievable.
		$this->assertSame( 'inline', get_post_meta( $prompt_id, 'placement', true ) );
		$this->assertCount( 1, Newspack_Popups_Model::retrieve_scoped_popups( $post_id ) );

		// Drift: someone flips placement to an overlay in the prompt CPT editor.
		update_post_meta( $prompt_id, 'placement', 'center' );

		// The scoped query must not return it while it is an overlay.
		$this->assertCount(
			0,
			Newspack_Popups_Model::retrieve_scoped_popups( $post_id ),
			'An overlay-placed prompt must not be served as a scoped in-article prompt.'
		);

		// Updating through the panel re-asserts the inline contract.
		Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'     => 'Fund this reporting.',
				'position' => 2,
			]
		);
		$this->assertSame( 'inline', get_post_meta( $prompt_id, 'placement', true ) );
		$this->assertSame( 'blocks_count', get_post_meta( $prompt_id, 'trigger_type', true ) );
		$this->assertCount( 1, Newspack_Popups_Model::retrieve_scoped_popups( $post_id ) );
	}

	/**
	 * Update re-asserts the campaign group (so the prompt stays identifiable) without
	 * discarding an extra group a publisher filed it under, and leaves a deliberate
	 * frequency cap alone — 'always' is a creation default, not an invariant.
	 */
	public function test_update_preserves_publisher_choices() {
		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 2,
			]
		);

		// Publisher caps frequency and files it under an extra campaign group.
		Newspack_Popups_Model::set_popup_options( $prompt_id, [ 'frequency' => 'daily' ] );
		$taxonomy = Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY;
		$extra    = wp_insert_term( 'Spring Drive', $taxonomy );
		wp_set_post_terms( $prompt_id, [ (int) $extra['term_id'] ], $taxonomy, true );

		Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'     => 'Edited copy.',
				'position' => 4,
			]
		);

		$this->assertSame(
			'daily',
			get_post_meta( $prompt_id, 'frequency', true ),
			'A deliberate frequency cap must survive an update.'
		);

		$group_names = wp_get_post_terms( $prompt_id, $taxonomy, [ 'fields' => 'names' ] );
		$this->assertContains( Newspack_Popups_Post_Scope::CAMPAIGN_GROUP_NAME, $group_names );
		$this->assertContains( 'Spring Drive', $group_names, 'An extra campaign group must not be wiped.' );
	}

	/**
	 * Model output is untrusted. It is escaped where it is baked into the prompt's
	 * block content, so it cannot introduce executable markup — the prompt CPT's
	 * content is rendered with dangerouslySetInnerHTML by the single-prompt block.
	 */
	public function test_model_output_cannot_inject_markup() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => '<script>alert(1)</script><img src=x onerror=alert(2)>Fund it',
				'button_label' => '<b onmouseover=alert(3)>Give</b>',
				'button_url'   => 'javascript:alert(4)',
				'position'     => 1,
			]
		);
		$content = get_post_field( 'post_content', $prompt_id );

		// The model-derived regions carry no raw markup, only escaped entities.
		preg_match( '#<p[^>]*>(.*?)</p>#s', $content, $paragraph );
		$this->assertStringNotContainsString( '<', $paragraph[1], 'Model copy is escaped.' );
		$this->assertStringContainsString( '&lt;script&gt;', $paragraph[1] );

		// No event handler survives on a real tag, and the javascript: URL is dropped.
		$this->assertSame( 0, preg_match( '#<[a-zA-Z][^>]*\son[a-z]+\s*=#i', $content ), 'No inline event handlers.' );
		$this->assertSame( 0, preg_match( '#href=["\']javascript:#i', $content ), 'javascript: URLs are stripped.' );

		// Only our own wrapper tags are raw in the document.
		preg_match_all( '#<([a-zA-Z][a-zA-Z0-9]*)#', $content, $tags );
		$this->assertEmpty(
			array_diff( array_unique( $tags[1] ), [ 'div', 'p', 'a' ] ),
			'No unexpected raw tags reached the stored content.'
		);

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * Per-story show/hide: hiding a prompt keeps it editable in the panel but
	 * takes it off the story. Hidden == draft, so the existing published-only
	 * scoped query does the work.
	 */
	public function test_show_hide_toggle() {
		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 2,
			]
		);

		$this->assertTrue( Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $post_id )['enabled'] );
		$this->assertCount( 1, Newspack_Popups_Model::retrieve_scoped_popups( $post_id ) );

		// Hide it.
		Newspack_Popups_Post_Scope::set_scoped_prompt_enabled( $prompt_id, false );
		$this->assertCount( 0, Newspack_Popups_Model::retrieve_scoped_popups( $post_id ), 'A hidden prompt is off the story.' );

		$hidden = Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $post_id );
		$this->assertSame( $prompt_id, $hidden['id'], 'A hidden prompt is still editable in the panel.' );
		$this->assertFalse( $hidden['enabled'] );
		$this->assertSame( 'Fund this reporting.', $hidden['body'], 'Its copy survives being hidden.' );

		// Show it again.
		Newspack_Popups_Post_Scope::set_scoped_prompt_enabled( $prompt_id, true );
		$this->assertTrue( Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $post_id )['enabled'] );
		$this->assertCount( 1, Newspack_Popups_Model::retrieve_scoped_popups( $post_id ) );
	}

	/**
	 * Toggling something that isn't a scoped prompt is rejected.
	 */
	public function test_toggle_rejects_non_scoped_prompt() {
		$plain = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$this->assertWPError( Newspack_Popups_Post_Scope::set_scoped_prompt_enabled( $plain, false ) );
	}

	/**
	 * Site-wide override swaps the copy of every Contextual Prompt while active,
	 * without disturbing placement, id, or the underlying prompt's stored copy.
	 */
	public function test_site_wide_override_swaps_copy() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => 'Story-specific ask.',
				'button_label' => 'Give',
				'button_url'   => 'https://example.com/story',
				'position'     => 3,
			]
		);
		$this->go_to( get_permalink( $post_id ) );

		// Off by default.
		$before = Newspack_Popups_Post_Scope::get_scoped_popups_for_current_post();
		$this->assertStringContainsString( 'Story-specific ask.', $before[0]['content'] );

		// An enabled override with no copy must not blank prompts.
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		$empty = Newspack_Popups_Post_Scope::get_scoped_popups_for_current_post();
		$this->assertStringContainsString( 'Story-specific ask.', $empty[0]['content'], 'An empty override is inactive.' );

		update_option( 'newspack_contextual_prompts_override_body', 'Our spring drive is on.' );
		update_option( 'newspack_contextual_prompts_override_label', 'Join the drive' );
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive' );

		$after = Newspack_Popups_Post_Scope::get_scoped_popups_for_current_post();
		$this->assertStringContainsString( 'Our spring drive is on.', $after[0]['content'] );
		$this->assertStringContainsString( 'Join the drive', $after[0]['content'] );
		$this->assertStringNotContainsString( 'Story-specific ask.', $after[0]['content'] );

		// Placement and identity are untouched — the override takes over the
		// position rather than creating a prompt of its own.
		$this->assertSame( $prompt_id, $after[0]['id'] );
		$this->assertSame( '3', (string) get_post_meta( $prompt_id, 'trigger_blocks_count', true ) );

		// The story's own copy is preserved for when the override is lifted.
		$this->assertSame(
			'Story-specific ask.',
			Newspack_Popups_Post_Scope::get_scoped_prompt_for_post( $post_id )['body']
		);

		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, false );
		$restored = Newspack_Popups_Post_Scope::get_scoped_popups_for_current_post();
		$this->assertStringContainsString( 'Story-specific ask.', $restored[0]['content'] );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * The default design is applied at render time, not baked into each prompt —
	 * so changing it restyles prompts that already exist. This is the property the
	 * whole "default design in settings" feature rests on.
	 */
	public function test_design_is_render_time_not_baked() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => 'Fund this reporting.',
				'button_label' => 'Give',
				'button_url'   => 'https://example.com/donate',
				'position'     => 1,
			]
		);
		$content = get_post_field( 'post_content', $prompt_id );

		// The prompt carries the styling hook but no baked colours.
		$this->assertStringContainsString( 'newspack-contextual-prompt', $content );
		$this->assertStringNotContainsString( 'background-color:', $content, 'Styling must not be baked into prompt content.' );
		$this->assertStringNotContainsString( '#f7f7f8', $content );

		// Default CSS uses the fallback background.
		$this->assertStringContainsString( '#f7f7f8', Newspack_Popups_Post_Scope::get_design_css() );

		// Changing the setting changes the CSS for the ALREADY-CREATED prompt,
		// with no rewrite of its content.
		update_option( Newspack_Popups_Settings::DESIGN_BACKGROUND_OPTION, '#112233' );
		update_option( Newspack_Popups_Settings::DESIGN_ACCENT_OPTION, '#445566' );
		$css = Newspack_Popups_Post_Scope::get_design_css();
		$this->assertStringContainsString( '#112233', $css );
		$this->assertStringContainsString( '#445566', $css );
		$this->assertStringContainsString( '.newspack-contextual-prompt', $css );
		$this->assertSame(
			$content,
			get_post_field( 'post_content', $prompt_id ),
			'Restyling must not touch existing prompt content.'
		);

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * Create a prompt and customize it the way a publisher would in the block
	 * editor: restyle the wrapper and drop in an extra block.
	 *
	 * @param int $post_id Story ID.
	 * @return int Prompt ID.
	 */
	private function create_customized_prompt( $post_id ) {
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => 'Original copy.',
				'button_label' => 'Give',
				'button_url'   => 'https://example.com/a',
				'position'     => 1,
			]
		);

		$custom = str_replace(
			'<div class="wp-block-group newspack-contextual-prompt">',
			'<div class="wp-block-group newspack-contextual-prompt has-background" style="background-color:#004400">',
			get_post_field( 'post_content', $prompt_id )
		);
		$custom = str_replace(
			"</div>\n<!-- /wp:group -->",
			"<!-- wp:html --><div class=\"publisher-custom\">custom</div><!-- /wp:html -->\n</div>\n<!-- /wp:group -->",
			$custom
		);
		wp_update_post(
			[
				'ID'           => $prompt_id,
				'post_content' => $custom,
			]
		);

		return $prompt_id;
	}

	/**
	 * A prompt restyled in the block editor keeps its design when an editor later
	 * edits the copy from the panel. Without this, per-prompt customization is
	 * silently wiped by the next save.
	 */
	public function test_customized_prompt_survives_copy_edit() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Pristine.',
				'position' => 1,
			]
		);
		$this->assertFalse( Newspack_Popups_Post_Scope::is_customized( $prompt_id ), 'A freshly created prompt is pristine.' );
		wp_delete_post( $prompt_id, true );

		$prompt_id = $this->create_customized_prompt( $post_id );
		$this->assertTrue( Newspack_Popups_Post_Scope::is_customized( $prompt_id ) );

		Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'         => 'Updated from the panel.',
				'button_label' => 'Donate now',
				'button_url'   => 'https://example.com/b',
				'position'     => 2,
			]
		);
		$content = get_post_field( 'post_content', $prompt_id );

		// Copy and CTA are updated.
		$this->assertStringContainsString( 'Updated from the panel.', $content );
		$this->assertStringNotContainsString( 'Original copy.', $content );
		$this->assertStringContainsString( 'Donate now', $content );
		$this->assertStringContainsString( 'example.com/b', $content );

		// The publisher's own work is untouched.
		$this->assertStringContainsString( '#004400', $content, 'Custom styling must survive.' );
		$this->assertStringContainsString( 'publisher-custom', $content, 'Publisher-added blocks must survive.' );

		// Scoped-prompt invariants are meta, so they still apply.
		$this->assertSame( '2', (string) get_post_meta( $prompt_id, 'trigger_blocks_count', true ) );
		$this->assertSame( 'inline', get_post_meta( $prompt_id, 'placement', true ) );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * Resetting discards the custom design but keeps the current copy.
	 */
	public function test_reset_prompt_design() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = $this->create_customized_prompt( $post_id );

		Newspack_Popups_Post_Scope::reset_prompt_design( $prompt_id );
		$content = get_post_field( 'post_content', $prompt_id );

		$this->assertStringNotContainsString( '#004400', $content );
		$this->assertStringNotContainsString( 'publisher-custom', $content );
		$this->assertStringContainsString( 'Original copy.', $content, 'Reset keeps the copy, only the design goes.' );
		$this->assertFalse( Newspack_Popups_Post_Scope::is_customized( $prompt_id ) );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * The in-place update is a second path that writes model output into content,
	 * so it must escape exactly as the generated path does.
	 */
	public function test_in_place_update_escapes_model_output() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = $this->create_customized_prompt( $post_id );

		Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'         => '<script>alert(1)</script><img src=x onerror=alert(2)>Fund it',
				'button_label' => '<b onmouseover=alert(3)>Give</b>',
				'button_url'   => 'https://example.com/ok',
				'position'     => 1,
			]
		);
		$content = get_post_field( 'post_content', $prompt_id );

		$this->assertSame( 0, preg_match( '#<script#i', $content ), 'No script tag may be written.' );
		$this->assertSame( 0, preg_match( '#<[a-zA-Z][^>]*\son[a-z]+\s*=#i', $content ), 'No inline event handlers.' );
		$this->assertStringContainsString( '&lt;script&gt;', $content, 'Model output is escaped.' );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * The site-wide override takes over a customized prompt too, and does so
	 * without touching stored content — so the custom design returns when the
	 * override is switched off.
	 */
	public function test_override_takes_over_customized_prompt() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = $this->create_customized_prompt( $post_id );
		$stored    = get_post_field( 'post_content', $prompt_id );
		$this->go_to( get_permalink( $post_id ) );

		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Spring drive.' );
		// A URL is required for the override to be active in plain-button mode,
		// otherwise it would replace every prompt with an ask nobody can act on.
		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/drive' );

		$rendered = Newspack_Popups_Post_Scope::get_scoped_popups_for_current_post()[0]['content'];
		$this->assertStringContainsString( 'Spring drive.', $rendered );
		$this->assertStringNotContainsString( '#004400', $rendered, 'Override takes over the design too.' );
		$this->assertSame( $stored, get_post_field( 'post_content', $prompt_id ), 'Override must not rewrite stored content.' );

		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, false );
		$restored = Newspack_Popups_Post_Scope::get_scoped_popups_for_current_post()[0]['content'];
		$this->assertStringContainsString( '#004400', $restored, 'Custom design returns when the override is off.' );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * The default design must stay overridable by a prompt's own inline styles —
	 * adding !important here would silently break per-prompt customization.
	 */
	public function test_design_css_stays_overridable() {
		$this->assertStringNotContainsString( '!important', Newspack_Popups_Post_Scope::get_design_css() );
	}

	/**
	 * Copy is written verbatim into a customized prompt. The in-place updater feeds
	 * the copy to preg_replace as a *replacement* string, where `$n` / `${n}` are
	 * backreferences — so an ordinary dollar amount was being deleted, and `${n}`
	 * syntax could rebuild markup that esc_html() had already neutralised.
	 */
	public function test_in_place_update_preserves_dollar_amounts_and_blocks_backrefs() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => 'Original.',
				'button_label' => 'Give',
				'button_url'   => 'https://example.com/old',
				'position'     => 1,
			]
		);

		// Make it "customized" so the in-place path is the one exercised.
		wp_update_post(
			[
				'ID'           => $prompt_id,
				'post_content' => str_replace( 'wp-block-group', 'wp-block-group custom-shell', get_post_field( 'post_content', $prompt_id ) ),
			]
		);
		$this->assertTrue( Newspack_Popups_Post_Scope::is_customized( $prompt_id ), 'Precondition: prompt is customized.' );

		Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'         => 'Give $5 today — ${2}img src=x onerror=alert(1)${1} keeps reporting free.',
				'button_label' => 'Donate now',
				'button_url'   => 'https://example.com/new',
				'position'     => 1,
			]
		);
		$content = get_post_field( 'post_content', $prompt_id );

		$this->assertStringContainsString( 'Give $5 today', $content, 'Dollar amounts survive.' );
		$this->assertStringContainsString( '${2}img', $content, 'Backreference syntax is kept literal.' );
		$this->assertSame( 0, preg_match( '#<img\b#i', $content ), 'No real <img> tag is reconstructed.' );
		$this->assertSame( 0, preg_match( '#<[a-zA-Z][^>]*\son[a-z]+\s*=#i', $content ), 'No inline event handler.' );
		$this->assertStringContainsString( 'custom-shell', $content, 'The custom design survives the copy edit.' );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * The button label is rewritten inside the anchor, replacing the old one — the
	 * naive first-match replacement wrote it before `<a>` and left the old label.
	 */
	public function test_in_place_update_replaces_button_label_inside_the_link() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'      => $post_id,
				'body'         => 'Original.',
				'button_label' => 'OLDLABEL',
				'button_url'   => 'https://example.com/old',
				'position'     => 1,
			]
		);
		wp_update_post(
			[
				'ID'           => $prompt_id,
				'post_content' => str_replace( 'wp-block-group', 'wp-block-group custom-shell', get_post_field( 'post_content', $prompt_id ) ),
			]
		);

		Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'         => 'Original.',
				'button_label' => 'NEWLABEL',
				'button_url'   => 'https://example.com/new',
				'position'     => 1,
			]
		);
		$content = get_post_field( 'post_content', $prompt_id );

		$this->assertStringNotContainsString( 'OLDLABEL', $content, 'The old label is gone, not merely joined by the new one.' );
		$this->assertMatchesRegularExpression( '#<a[^>]*>\s*NEWLABEL\s*</a>#', $content, 'The new label sits inside the anchor.' );
		$this->assertStringContainsString( 'https://example.com/new', $content );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * Scoped prompts stay out of the wizard/exporter listing and the unbounded
	 * active-popups query, which is the scale premise the whole design rests on.
	 */
	public function test_scoped_prompts_excluded_from_general_queries() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$scoped  = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Scoped.',
				'position' => 1,
			]
		);

		$listed = wp_list_pluck( Newspack_Popups_Model::retrieve_popups(), 'id' );
		$this->assertNotContains( $scoped, $listed, 'Excluded from the Campaigns wizard / exporter listing.' );

		$active = wp_list_pluck( Newspack_Popups_Model::retrieve_active_popups(), 'id' );
		$this->assertNotContains( $scoped, $active, 'Excluded from the unbounded front-end query.' );
	}

	/**
	 * A prompt does not outlive its article.
	 */
	public function test_scoped_prompt_follows_parent_lifecycle() {
		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 1,
			]
		);

		wp_trash_post( $post_id );
		$this->assertSame( 'trash', get_post_status( $prompt_id ), 'Trashing the article trashes its prompt.' );

		wp_untrash_post( $post_id );
		$this->assertNotSame( 'trash', get_post_status( $prompt_id ), 'Restoring the article restores its prompt.' );

		wp_delete_post( $post_id, true );
		$this->assertNull( get_post( $prompt_id ), 'Deleting the article deletes its prompt.' );
	}

	/**
	 * The ordinary deletion route is trash, then Empty Trash. By then the prompt is
	 * already trashed, so a delete lookup that omits 'trash' finds nothing and the
	 * prompt outlives its article.
	 */
	public function test_trash_then_permanent_delete_removes_the_prompt() {
		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 1,
			]
		);

		wp_trash_post( $post_id );
		$this->assertSame( 'trash', get_post_status( $prompt_id ), 'Precondition: the prompt is trashed with the article.' );

		wp_delete_post( $post_id, true ); // Empty Trash.
		$this->assertNull( get_post( $prompt_id ), 'Emptying the trash removes the prompt too, rather than orphaning it.' );
	}

	/**
	 * Activating a campaign group drafts every published prompt not in the supplied
	 * id list — and scoped prompts are excluded from the listing those ids come
	 * from, so without the same exclusion here they would all be silently disabled.
	 */
	public function test_batch_publish_leaves_scoped_prompts_alone() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$scoped  = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 1,
			]
		);
		$sitewide = self::factory()->post->create(
			[
				'post_type'   => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status' => 'publish',
			]
		);

		// A second site-wide prompt NOT in the id list: batch_publish must still
		// deactivate it, otherwise this test would also pass if the exclusion broke
		// the query outright or gutted batch_publish into a no-op.
		$other_sitewide = self::factory()->post->create(
			[
				'post_type'   => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status' => 'publish',
			]
		);

		Newspack_Popups_Settings::batch_publish( [ $sitewide ] );

		$this->assertSame( 'publish', get_post_status( $scoped ), 'The story prompt is untouched by a campaign activation.' );
		$this->assertSame( 'publish', get_post_status( $sitewide ) );
		$this->assertSame( 'draft', get_post_status( $other_sitewide ), 'Site-wide prompts outside the list are still deactivated.' );
	}

	/**
	 * A story has at most one Contextual Prompt: a create for a post that already
	 * has one updates it rather than minting an unreachable duplicate.
	 */
	public function test_create_upserts_an_existing_prompt() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$first = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'First copy.',
				'position' => 1,
			]
		);
		$second = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Second copy.',
				'position' => 2,
			]
		);

		$this->assertSame( $first, $second, 'The second create returns the same prompt.' );
		$this->assertCount(
			1,
			Newspack_Popups_Model::retrieve_scoped_popups( $post_id ),
			'Only one prompt exists for the story, so only one renders.'
		);
		$this->assertStringContainsString( 'Second copy.', get_post_field( 'post_content', $first ) );
	}

	/**
	 * A site-wide override with no URL would replace every prompt with an ask that
	 * has nothing to click — on exactly the sites where the button is the donation
	 * path. It counts as inactive, like an override with no copy.
	 */
	public function test_override_without_a_url_is_inactive_in_button_mode() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( 'newspack_contextual_prompts_override_body', 'Give today.' );
		update_option( 'newspack_contextual_prompts_override_url', '' );

		$this->assertFalse(
			Newspack_Popups_Settings::is_override_active(),
			'No URL in button mode means the override would strip the CTA, so it stays inactive.'
		);

		update_option( 'newspack_contextual_prompts_override_url', 'https://example.com/fund-drive' );
		$this->assertTrue( Newspack_Popups_Settings::is_override_active() );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * Resetting a prompt returns it to pristine even when the site's donation
	 * platform has changed since it was created — otherwise the reset leaves it
	 * reading as customized and the reset link never clears.
	 */
	public function test_reset_returns_prompt_to_pristine_after_platform_change() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 1,
			]
		);
		wp_update_post(
			[
				'ID'           => $prompt_id,
				'post_content' => str_replace( 'wp-block-group', 'wp-block-group custom-shell', get_post_field( 'post_content', $prompt_id ) ),
			]
		);
		$this->assertTrue( Newspack_Popups_Post_Scope::is_customized( $prompt_id ) );

		// The publisher moves donations off-platform, then resets the design.
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		Newspack_Popups_Post_Scope::reset_prompt_design( $prompt_id );

		$this->assertFalse(
			Newspack_Popups_Post_Scope::is_customized( $prompt_id ),
			'Reset restores the pristine baseline rather than leaving it stuck as customized.'
		);

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * Changing the site's donation platform must not retroactively mark every
	 * pristine prompt as customized — that would claim a custom design the publisher
	 * never made and route copy edits onto the in-place updater.
	 */
	public function test_customized_detection_survives_donation_platform_change() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Fund this reporting.',
				'position' => 1,
			]
		);
		$this->assertFalse( Newspack_Popups_Post_Scope::is_customized( $prompt_id ), 'Freshly built prompt is pristine.' );

		// The publisher moves donations off-platform.
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$this->assertFalse(
			Newspack_Popups_Post_Scope::is_customized( $prompt_id ),
			'Still pristine — the baseline uses the mode the prompt was built with.'
		);

		// And a copy edit still goes down the regenerate path, not the in-place one.
		$updated = Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'     => 'Edited copy.',
				'position' => 1,
			]
		);
		$this->assertNotWPError( $updated );
		$this->assertStringContainsString( 'Edited copy.', get_post_field( 'post_content', $prompt_id ) );

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
	}

	/**
	 * If the copy block was removed while customizing, the edit fails loudly rather
	 * than reporting success while the story keeps serving the old copy.
	 */
	public function test_missing_copy_block_reports_an_error() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );

		$post_id   = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$prompt_id = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'  => $post_id,
				'body'     => 'Original copy.',
				'position' => 1,
			]
		);

		// Customize it AND remove the copy hook, as a publisher rebuilding the card would.
		wp_update_post(
			[
				'ID'           => $prompt_id,
				'post_content' => "<!-- wp:paragraph -->\n<p>Hand-built card.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$this->assertTrue( Newspack_Popups_Post_Scope::is_customized( $prompt_id ) );

		$result = Newspack_Popups_Post_Scope::update_scoped_prompt(
			$prompt_id,
			[
				'body'     => 'New copy that has nowhere to go.',
				'position' => 1,
			]
		);

		$this->assertWPError( $result, 'A no-op edit is reported, not swallowed.' );
		$this->assertSame( 'newspack_popups_copy_block_missing', $result->get_error_code() );
		$this->assertStringContainsString(
			'Hand-built card.',
			get_post_field( 'post_content', $prompt_id ),
			'The publisher content is left untouched.'
		);

		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
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

	/**
	 * When Newspack donations are in use, the CTA is the native donate block (so
	 * conversions classify as donations); otherwise a plain button + URL.
	 */
	public function test_donate_block_vs_button() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		// Native donations: donate block, no plain button.
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		$with_block = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'    => $post_id,
				'body'       => 'Fund the reporting.',
				'button_url' => 'https://example.com/donate',
			]
		);
		$content = get_post_field( 'post_content', $with_block );
		$this->assertStringContainsString( 'wp:newspack-blocks/donate', $content );
		$this->assertStringNotContainsString( 'wp-block-button', $content );
		remove_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		// Off-site donations: plain button linking to the URL, no donate block.
		$other_post = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$with_button = Newspack_Popups_Post_Scope::create_scoped_prompt(
			[
				'post_id'    => $other_post,
				'body'       => 'Fund the reporting.',
				'button_url' => 'https://example.com/donate',
			]
		);
		$content = get_post_field( 'post_content', $with_button );
		$this->assertStringContainsString( 'wp-block-button', $content );
		$this->assertStringContainsString( 'https://example.com/donate', $content );
		$this->assertStringNotContainsString( 'newspack-blocks/donate', $content );
	}
}
