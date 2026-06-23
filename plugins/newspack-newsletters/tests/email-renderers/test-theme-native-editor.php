<?php
/**
 * Class Theme Native Editor Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Guard test for irreducible email-structural constraints under the WC renderer.
 *
 * These constraints must hold regardless of which theme is active or whether the
 * theme-native flag is on or off: the newsletter canvas is always capped at 600 px
 * and the allowed-block list is always restricted to email-safe blocks.
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
	 * Build a minimal block-editor-settings array that mirrors what
	 * block_editor_settings_all passes into override_email_editor_settings().
	 *
	 * The method inspects $editor_settings['__experimentalFeatures']['layout']
	 * to apply the 600 px override, so that key must exist (any non-empty values
	 * are fine — the method replaces them).
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
	 * Build a WP_Block_Editor_Context with the newsletter post attached,
	 * mirroring what WordPress passes to block_editor_settings_all for a
	 * newsletter editor request.
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
	 * Verify that contentSize is pinned to 600 px for a newsletter CPT context.
	 *
	 * The override_email_editor_settings() method must force contentSize to 600 px
	 * so the editor canvas always matches the email max-width, regardless of what
	 * the active theme declares. This is a structural invariant: even with the
	 * theme-native flag ON the email canvas must be 600 px wide (email clients
	 * clip wider content).
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
	 * Verify that wideSize is pinned to 600 px for a newsletter CPT context.
	 *
	 * The override_email_editor_settings() method must force wideSize to 600 px
	 * so a "wide" block alignment cannot exceed the 600 px email envelope.
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
	 * Verify that non-newsletter posts are not affected by the email width override.
	 *
	 * The override_email_editor_settings() method must be a no-op for a regular
	 * post so that standard post editors retain their theme-supplied layout sizes.
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
	 * Verify that newsletters_allowed_block_types() returns an array, not true.
	 *
	 * Returning boolean true would allow every registered block, which is wrong
	 * for email because many blocks (gallery, video, table-of-contents, etc.)
	 * have no email-safe rendering path. The method must restrict to the curated
	 * email-safe subset.
	 *
	 * Note: is_editing_email() uses get_the_ID() which reads from the global
	 * $post; we prime it via setup_postdata() so the method resolves the CPT
	 * correctly in the test environment (no HTTP request / $pagenow available).
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
	 * Verify that non-newsletter posts are not subject to the block restriction.
	 *
	 * The newsletters_allowed_block_types() method must pass through the incoming
	 * value unchanged for post types that are not email editor CPTs, so standard
	 * post editors retain access to all registered blocks.
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
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

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
	 * Flag ON + block theme: strip_editor_modifications() must early-return
	 * (i.e. NOT call remove_editor_styles) for a newsletter request.
	 *
	 * The invariant: when the WC renderer is active and a block theme is in use,
	 * editor styles are preserved so the canvas reflects the theme 1:1.
	 * We verify this by checking that remove_editor_styles had no effect —
	 * specifically that the method returns before reaching the remove_editor_styles()
	 * call. The cleanest observable proxy is `did_action` state: we hook a counter
	 * onto `remove_editor_styles` equivalent (or simply assert the editor_styles
	 * global remains non-empty after the call).
	 *
	 * Practical approach: add a stylesheet via add_editor_style(), call the method,
	 * assert the stylesheet is still registered (= early-return, styles NOT stripped).
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
	 * Flag ON + classic theme: strip_editor_modifications() must run the full strip
	 * (i.e. does NOT early-return after the block-theme guard).
	 *
	 * Classic themes style blocks via editor CSS that the WC email render cannot
	 * reproduce, so stripping is correct behavior.
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
	 * Flag OFF: strip_editor_modifications() must run the full strip (legacy path),
	 * regardless of which theme type is active.
	 *
	 * When the WC renderer flag is off, the block-theme early-return guard is not
	 * reached — the method falls through to the full strip unconditionally.
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
