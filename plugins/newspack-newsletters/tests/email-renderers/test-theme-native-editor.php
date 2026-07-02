<?php
/**
 * Class Theme Native Editor Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Guard tests for irreducible email-structural constraints: the newsletter canvas is always
 * capped at 600 px and the allowed-block list is always restricted to email-safe blocks,
 * regardless of which theme is active or the theme-native flag state.
 */
class Test_Theme_Native_Editor extends WP_UnitTestCase {

	/**
	 * Create a newsletter CPT post and return its WP_Post object.
	 *
	 * @return \WP_Post
	 */
	private function create_newsletter_post(): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
				'post_title'  => 'Guard-test newsletter',
			]
		);
		return get_post( $post_id );
	}

	/**
	 * Build a minimal block-editor-settings array mirroring what block_editor_settings_all passes
	 * to override_email_editor_settings() — the layout key is required for the 600 px override.
	 *
	 * @return array
	 */
	private function make_editor_settings(): array {
		return [
			'__experimentalFeatures' => [
				'layout'     => [
					'contentSize' => '1200px',
					'wideSize'    => '1200px',
				],
				'typography' => [
					'fontFamilies' => [
						[
							'slug' => 'inter',
							'name' => 'Inter',
						],
					],
				],
			],
		];
	}

	/**
	 * Build a WP_Block_Editor_Context with the newsletter post attached.
	 *
	 * @param \WP_Post $post Newsletter post.
	 * @return \WP_Block_Editor_Context
	 */
	private function make_editor_context( \WP_Post $post ): \WP_Block_Editor_Context {
		return new \WP_Block_Editor_Context( [ 'post' => $post ] );
	}

	// -------------------------------------------------------------------------
	// Email-width constraints.
	// -------------------------------------------------------------------------

	/**
	 * Pins contentSize to 600 px for a newsletter CPT context — email clients clip wider content
	 * so this is a structural invariant even with the theme-native flag ON.
	 */
	public function test_override_sets_content_size_to_600px_for_newsletter_post() {
		$post     = $this->create_newsletter_post();
		$settings = $this->make_editor_settings();
		$context  = $this->make_editor_context( $post );

		$result = \Newspack_Newsletters_Editor::override_email_editor_settings( $settings, $context );

		$this->assertSame(
			'600px',
			$result['__experimentalFeatures']['layout']['contentSize'],
			'contentSize must be 600 px for a newsletter editor regardless of theme.'
		);
	}

	/**
	 * Pins wideSize to 600 px so a "wide" block alignment cannot exceed the email envelope.
	 */
	public function test_override_sets_wide_size_to_600px_for_newsletter_post() {
		$post     = $this->create_newsletter_post();
		$settings = $this->make_editor_settings();
		$context  = $this->make_editor_context( $post );

		$result = \Newspack_Newsletters_Editor::override_email_editor_settings( $settings, $context );

		$this->assertSame(
			'600px',
			$result['__experimentalFeatures']['layout']['wideSize'],
			'wideSize must be 600 px for a newsletter editor regardless of theme.'
		);
	}

	/**
	 * Leaves theme-supplied sizes untouched for non-newsletter posts (the override is a no-op).
	 */
	public function test_override_does_not_affect_non_newsletter_post() {
		$post_id  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$post     = get_post( $post_id );
		$settings = $this->make_editor_settings();
		$context  = $this->make_editor_context( $post );

		$result = \Newspack_Newsletters_Editor::override_email_editor_settings( $settings, $context );

		$this->assertSame(
			'1200px',
			$result['__experimentalFeatures']['layout']['contentSize'],
			'contentSize must not be altered for a non-newsletter post type.'
		);
	}

	// -------------------------------------------------------------------------
	// Allowed-block constraints.
	// -------------------------------------------------------------------------

	/**
	 * Returns an array (not true) for newsletters — boolean true
	 * would allow every registered block, many of which have no email-safe rendering path.
	 */
	public function test_allowed_block_types_returns_array_not_true_for_newsletter() {
		$newsletter = $this->create_newsletter_post();

		// Prime the global $post so get_the_ID() returns the newsletter post.
		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $newsletter;
		setup_postdata( $newsletter );

		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $newsletter );

		wp_reset_postdata();

		$this->assertIsArray(
			$result,
			'newsletters_allowed_block_types() must return an array, not boolean true, for newsletters.'
		);
	}

	/**
	 * Verify that the newsletter allow-list includes core structural blocks.
	 *
	 * Paragraph, heading, group, columns, and buttons are required by every
	 * newsletter. Their absence from the allow-list would break the editor.
	 */
	public function test_allowed_block_types_includes_core_structural_blocks() {
		$newsletter = $this->create_newsletter_post();

		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $newsletter;
		setup_postdata( $newsletter );

		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $newsletter );

		wp_reset_postdata();

		$required_blocks = [
			'core/paragraph',
			'core/heading',
			'core/group',
			'core/columns',
			'core/column',
			'core/buttons',
			'core/button',
		];
		foreach ( $required_blocks as $block ) {
			$this->assertContains(
				$block,
				$result,
				"Required block '$block' must be in the newsletter allow-list."
			);
		}
	}

	/**
	 * Call newsletters_allowed_block_types() with the global $post primed to a
	 * newsletter so is_editing_email() resolves true.
	 *
	 * @param \WP_Post $newsletter Newsletter post.
	 * @return array The resolved allow-list.
	 */
	private function resolve_allowed_blocks( \WP_Post $newsletter ): array {
		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$previous_post = $post;
		$post          = $newsletter;
		setup_postdata( $newsletter );
		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $newsletter );
		wp_reset_postdata();
		// Restore the prior global $post — wp_reset_postdata() alone can leave it
		// mutated (backupGlobals is off), making tests order-dependent.
		$post = $previous_post;
		return (array) $result;
	}

	/**
	 * The WC engine can render table/gallery/media-text/cover, so they join the
	 * allow-list when the WC renderer flag is on.
	 */
	public function test_allowed_block_types_adds_wc_native_blocks_when_flag_on() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$result = $this->resolve_allowed_blocks( $this->create_newsletter_post() );

		foreach ( [ 'core/table', 'core/gallery', 'core/media-text', 'core/cover' ] as $block ) {
			$this->assertContains(
				$block,
				$result,
				"WC-native block '$block' must be allowed when the WC renderer flag is on."
			);
		}
	}

	/**
	 * The legacy MJML renderer cannot render these blocks, so they must be absent
	 * from the allow-list when the WC renderer flag is off.
	 */
	public function test_allowed_block_types_excludes_wc_native_blocks_when_flag_off() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$result = $this->resolve_allowed_blocks( $this->create_newsletter_post() );

		foreach ( [ 'core/table', 'core/gallery', 'core/media-text', 'core/cover', 'core/audio', 'core/video' ] as $block ) {
			$this->assertNotContains(
				$block,
				$result,
				"Block '$block' must not be allowed under the legacy MJML renderer."
			);
		}
	}

	/**
	 * Audio and video render as static link/poster fallbacks in email (no inline
	 * playback), so they ship as a labeled experiment — off by default, even with
	 * the WC flag on.
	 */
	public function test_allowed_block_types_excludes_experimental_media_by_default() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$result = $this->resolve_allowed_blocks( $this->create_newsletter_post() );

		$this->assertNotContains( 'core/audio', $result, 'Experimental audio block must be off by default.' );
		$this->assertNotContains( 'core/video', $result, 'Experimental video block must be off by default.' );
		$this->assertContains( 'core/table', $result, 'Solid WC-native blocks must still be allowed by default.' );
	}

	/**
	 * The experimental audio/video blocks can be opted into via the
	 * newspack_newsletters_wc_experimental_blocks filter.
	 */
	public function test_experimental_blocks_filter_enables_audio_video() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		add_filter( 'newspack_newsletters_wc_experimental_blocks', '__return_true' );

		$result = $this->resolve_allowed_blocks( $this->create_newsletter_post() );

		// Both filters are removed in tear_down().
		$this->assertContains( 'core/audio', $result, 'Audio must be allowed when experimental blocks are enabled.' );
		$this->assertContains( 'core/video', $result, 'Video must be allowed when experimental blocks are enabled.' );
	}

	/**
	 * Passes the incoming value through unchanged for non-newsletter
	 * posts, leaving standard post editors access to all registered blocks.
	 */
	public function test_allowed_block_types_passes_through_for_non_newsletter() {
		$post_id      = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$regular_post = get_post( $post_id );

		global $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $regular_post;
		setup_postdata( $regular_post );

		$result = \Newspack_Newsletters_Editor::newsletters_allowed_block_types( true, $regular_post );

		wp_reset_postdata();

		$this->assertTrue(
			$result,
			'newsletters_allowed_block_types() must not restrict blocks for a non-newsletter post.'
		);
	}

	// -------------------------------------------------------------------------
	// strip_editor_modifications() early-return invariants.
	// -------------------------------------------------------------------------

	/**
	 * Saved globals restored in tear_down.
	 *
	 * @var array{pagenow: string|null, get: array, editor_support: mixed, editor_global: mixed}
	 */
	private $strip_globals_backup = [];

	/**
	 * Save globals before each test (only relevant for strip tests, harmless otherwise).
	 */
	public function set_up() {
		parent::set_up();
		$this->strip_globals_backup = [
			'pagenow'        => $GLOBALS['pagenow'] ?? null,
			'get'            => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'editor_support' => get_theme_support( 'editor-styles' ),
			'editor_global'  => $GLOBALS['editor_styles'] ?? null,
		];
	}

	/**
	 * Restore globals and remove any flag filters added during tests.
	 */
	public function tear_down() {
		// Remove only the flag callbacks these tests add — not every callback on the
		// hook — so we don't strip production/other-test filters (order-independence).
		// Cleaning up here (rather than inline) guarantees removal even if a test
		// assertion fails or an exception is thrown mid-test.
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		remove_filter( 'newspack_newsletters_wc_experimental_blocks', '__return_true' );
		remove_filter( 'newspack_newsletters_wc_experimental_blocks', '__return_false' );

		if ( null === $this->strip_globals_backup['pagenow'] ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->strip_globals_backup['pagenow'];
		}
		$_GET = $this->strip_globals_backup['get']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// strip_editor_modifications() can call remove_editor_styles(), which mutates
		// the `editor-styles` theme support and $GLOBALS['editor_styles']. Restore both
		// so the suite stays order-independent (phpunit.xml.dist sets backupGlobals=false).
		remove_theme_support( 'editor-styles' );
		if ( false !== $this->strip_globals_backup['editor_support'] ) {
			add_theme_support( 'editor-styles' );
		}
		if ( null === $this->strip_globals_backup['editor_global'] ) {
			unset( $GLOBALS['editor_styles'] );
		} else {
			$GLOBALS['editor_styles'] = $this->strip_globals_backup['editor_global'];
		}

		parent::tear_down();
	}

	/**
	 * Simulate a post.php email-editor request for the given post ID.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	private function simulate_email_editor_request( int $post_id ): void {
		global $pagenow;
		$pagenow      = 'post.php';
		$_GET['post'] = $post_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Flag ON + block theme: strip_editor_modifications() must NOT strip editor styles — verified by
	 * adding a sentinel style and checking that editor-styles theme support survives the call.
	 */
	public function test_strip_does_not_run_for_block_theme_with_flag_on() {
		$original = get_stylesheet();
		switch_theme( 'newspack-block-theme' );
		if ( ! wp_is_block_theme() ) {
			// newspack-block-theme not resolvable as a block theme in this env.
			switch_theme( $original );
			$this->markTestSkipped( 'newspack-block-theme is not available as a block theme in this environment.' );
		}

		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$newsletter = $this->create_newsletter_post();
		$this->simulate_email_editor_request( $newsletter->ID );

		// Add a sentinel editor style so we can detect whether it was stripped.
		add_editor_style( 'sentinel-style.css' );

		\Newspack_Newsletters_Editor::strip_editor_modifications();

		// remove_editor_styles() removes the `editor-styles` theme support. If the
		// support is still present, the strip early-returned (block theme path).
		$styles_kept = get_theme_support( 'editor-styles' );

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		switch_theme( $original );

		$this->assertNotFalse(
			$styles_kept,
			'strip_editor_modifications() must NOT strip editor styles for block theme + flag on.'
		);
	}

	/**
	 * Flag ON + classic theme: strip_editor_modifications() runs the full strip — classic-theme editor
	 * CSS can't be reproduced by the WC email render, so stripping is correct.
	 */
	public function test_strip_runs_for_classic_theme_with_flag_on() {
		$original = get_stylesheet();
		switch_theme( 'newspack-theme' );
		if ( wp_is_block_theme() ) {
			switch_theme( $original );
			$this->markTestSkipped( 'newspack-theme is unexpectedly a block theme in this environment.' );
		}

		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$newsletter = $this->create_newsletter_post();
		$this->simulate_email_editor_request( $newsletter->ID );

		add_editor_style( 'sentinel-style.css' );

		\Newspack_Newsletters_Editor::strip_editor_modifications();

		// Classic theme + flag on must run the full strip: remove_editor_styles()
		// removes the `editor-styles` theme support entirely.
		$styles_after = get_theme_support( 'editor-styles' );

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		switch_theme( $original );

		$this->assertFalse(
			$styles_after,
			'strip_editor_modifications() must strip editor styles for classic theme + flag on.'
		);
	}

	/**
	 * Flag OFF: strip_editor_modifications() runs the full strip regardless of theme type (legacy path).
	 */
	public function test_strip_runs_when_flag_is_off() {
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

		$newsletter = $this->create_newsletter_post();
		$this->simulate_email_editor_request( $newsletter->ID );

		add_editor_style( 'sentinel-style.css' );

		\Newspack_Newsletters_Editor::strip_editor_modifications();

		// Flag off (legacy path) must run the full strip regardless of theme type:
		// remove_editor_styles() removes the `editor-styles` theme support.
		$styles_after = get_theme_support( 'editor-styles' );

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

		$this->assertFalse(
			$styles_after,
			'strip_editor_modifications() must strip editor styles when the WC renderer flag is off.'
		);
	}
}
