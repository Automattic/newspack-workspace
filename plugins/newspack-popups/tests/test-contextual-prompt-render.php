<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the Contextual Prompt pattern render pipeline: the instance window that
 * scopes normalization to blocks coming from the pattern, the repair that
 * reconciles the stored pattern with the site's donation platform, and the
 * accent restamp that follows a theme color change.
 *
 * Newspack Blocks is not loaded in this test env, so set_up() registers a stub
 * donate block type — use_donate_block() requires it, and the stub's render
 * callback is what "the donate form rendered" asserts against.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt pattern render test.
 */
class ContextualPromptRenderTest extends WP_UnitTestCase {
	/**
	 * Marker the stub donate block renders, standing in for the donate form.
	 */
	const DONATE_STUB_CLASS = 'newspack-donate-stub';

	/**
	 * A destination no builder would produce, so a pass-through is provable.
	 */
	const CUSTOM_URL = 'https://example.com/custom/';

	/**
	 * Register the stub donate block and clear the per-request render state the
	 * previous test left behind.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_request_state();
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			register_block_type(
				'newspack-blocks/donate',
				[
					'render_callback' => function () {
						return '<div class="' . self::DONATE_STUB_CLASS . '"></div>';
					},
				]
			);
		}
	}

	/**
	 * Reset the pattern record, the donor landing page and the stub block type.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT );
		delete_option( 'newspack_popups_donor_landing_page' );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			unregister_block_type( 'newspack-blocks/donate' );
		}
		parent::tear_down();
		wp_clean_theme_json_cache();
	}

	/**
	 * The render class carries per-request state (the instance window and the
	 * once-a-request repair guard); a test run is one process, so it is reset
	 * between tests the way a new request would.
	 */
	private function reset_request_state() {
		foreach ( [ 'in_instance', 'repaired' ] as $name ) {
			$property = new ReflectionProperty( 'Newspack_Popups_Contextual_Prompt_Render', $name );
			$property->setAccessible( true );
			$property->setValue( null, false );
		}
	}

	/**
	 * Render an instance of the pattern, the way a post referencing it does.
	 *
	 * @return string Rendered markup.
	 */
	private function render_instance() {
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		return do_blocks( '<!-- wp:block {"ref":' . $ref . '} /-->' );
	}

	/**
	 * The stored pattern's top-level Group, parsed.
	 *
	 * @return array
	 */
	private function stored_group() {
		return parse_blocks( get_post( Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() )->post_content )[0];
	}

	/**
	 * Point the donation platform at the native donate block, or off site.
	 *
	 * @param bool $native Whether the site uses native (WooCommerce) donations.
	 */
	private function set_platform( $native ) {
		remove_all_filters( 'newspack_contextual_prompts_use_donate_block' );
		add_filter( 'newspack_contextual_prompts_use_donate_block', $native ? '__return_true' : '__return_false' );
	}

	/**
	 * Create a published donor landing page and point Campaigns settings at it.
	 *
	 * @return string The page permalink.
	 */
	private function set_donor_landing_page() {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Donate to us',
				'post_status' => 'publish',
			]
		);
		update_option( 'newspack_popups_donor_landing_page', $page_id );
		return get_permalink( $page_id );
	}

	/**
	 * Put an accent color in the palette, where get_accent_color() reads it. The
	 * custom origin, so it wins over any accent the active theme declares.
	 *
	 * @param string $color Hex color.
	 */
	private function set_accent_color( $color ) {
		remove_all_filters( 'wp_theme_json_data_user' );
		add_filter(
			'wp_theme_json_data_user',
			function ( $theme_json ) use ( $color ) {
				return $theme_json->update_with(
					[
						'version'  => 3,
						'settings' => [
							'color' => [
								'palette' => [
									[
										'slug'  => 'accent',
										'name'  => 'Accent',
										'color' => $color,
									],
								],
							],
						],
					]
				);
			}
		);
		wp_clean_theme_json_cache();
	}

	/**
	 * Native platform: the instance renders the donate form, and the window it
	 * opened is closed again by the time the instance is done.
	 */
	public function test_native_platform_renders_the_donate_form() {
		$this->set_platform( true );

		$html = $this->render_instance();

		$this->assertStringContainsString( self::DONATE_STUB_CLASS, $html );
		$this->assertFalse( Newspack_Popups_Contextual_Prompt_Render::is_in_instance(), 'The window closes with the instance.' );
	}

	/**
	 * A pattern seeded before the site moved to native donations renders the
	 * donate form, and the stored pattern is repaired rather than left stale.
	 */
	public function test_native_platform_swaps_a_stale_button_in_memory() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->set_platform( true );

		$html = $this->render_instance();

		$this->assertStringContainsString( self::DONATE_STUB_CLASS, $html );
		$this->assertStringContainsString( 'wp:newspack-blocks/donate', get_post( $ref )->post_content );
	}

	/**
	 * A pattern seeded on native donations becomes a button to the donor landing
	 * page once the site moves off site.
	 */
	public function test_offsite_platform_swaps_the_form_for_the_landing_button() {
		$this->set_platform( true );
		$ref       = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$permalink = $this->set_donor_landing_page();
		$this->set_platform( false );

		$html = $this->render_instance();

		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $html );
		$this->assertStringNotContainsString( self::DONATE_STUB_CLASS, $html );
		$this->assertStringContainsString( 'wp:buttons', get_post( $ref )->post_content );
	}

	/**
	 * Off site with no donor landing page: the CTA is dropped entirely — copy
	 * alone, never a dead button or a form on a disabled platform.
	 */
	public function test_offsite_without_landing_page_drops_the_cta() {
		$this->set_platform( true );
		Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->set_platform( false );

		$html = $this->render_instance();

		$this->assertStringNotContainsString( self::DONATE_STUB_CLASS, $html );
		$this->assertStringNotContainsString( 'wp-block-button', $html );

		$group = $this->stored_group();
		$this->assertCount( 1, $group['innerBlocks'], 'Only the copy paragraph remains.' );
		$this->assertSame( 1, count( array_filter( $group['innerContent'], 'is_null' ) ), 'One placeholder per remaining child.' );
	}

	/**
	 * A button the publisher repointed keeps its destination: repair only acts on
	 * a CTA that disagrees with the platform.
	 */
	public function test_custom_url_button_passes_through_untouched() {
		$this->set_platform( false );
		$permalink = $this->set_donor_landing_page();
		$ref       = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$blocks = parse_blocks( get_post( $ref )->post_content );
		$button = $blocks[0]['innerBlocks'][1]['innerBlocks'][0];

		$button['attrs']['url'] = self::CUSTOM_URL;
		$button['innerHTML']    = str_replace( esc_url( $permalink ), self::CUSTOM_URL, $button['innerHTML'] );
		$button['innerContent'] = [ $button['innerHTML'] ];

		$blocks[0]['innerBlocks'][1]['innerBlocks'][0] = $button;
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );
		$before = get_post( $ref )->post_content;

		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$this->assertStringContainsString( self::CUSTOM_URL, $before, 'The fixture really carries the custom destination.' );
		$this->assertSame( $before, get_post( $ref )->post_content );
	}

	/**
	 * Repair rewrites the pattern only when it has something to change.
	 */
	public function test_repair_is_a_noop_when_nothing_changed() {
		$this->set_platform( true );
		$ref    = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$before = get_post( $ref );

		$writes = 0;
		add_filter(
			'wp_insert_post_data',
			function ( $data, $postarr ) use ( &$writes, $ref ) {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $ref ) {
					++$writes;
				}
				return $data;
			},
			10,
			2
		);

		$this->render_instance();
		$this->render_instance();
		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$after = get_post( $ref );
		$this->assertSame( 0, $writes, 'The pattern was never rewritten.' );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified, $after->post_modified );
	}

	/**
	 * A donate CTA still carrying the color the seed stamped follows the theme's
	 * accent when it changes, and the record follows with it.
	 */
	public function test_accent_restamp_follows_an_untouched_stamp() {
		$this->set_platform( true );
		$this->set_accent_color( '#003da5' );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->set_accent_color( '#ff0000' );
		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$this->assertSame( '#ff0000', $this->stored_group()['innerBlocks'][1]['attrs']['buttonColor'] );
		$this->assertSame( '#ff0000', get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ) );
		$this->assertSame( $ref, Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id(), 'The pattern was repaired, not re-seeded.' );
	}

	/**
	 * A color the publisher chose is not the seed's stamp, so a theme accent
	 * change leaves it alone.
	 */
	public function test_accent_restamp_respects_a_publisher_color() {
		$this->set_platform( true );
		$this->set_accent_color( '#003da5' );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$blocks = parse_blocks( get_post( $ref )->post_content );
		$blocks[0]['innerBlocks'][1]['attrs']['buttonColor'] = '#123456';
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );

		$this->set_accent_color( '#ff0000' );
		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$this->assertSame( '#123456', $this->stored_group()['innerBlocks'][1]['attrs']['buttonColor'] );
		$this->assertSame( '#003da5', get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ), 'The record still describes the stamp the seed left.' );
	}

	/**
	 * With no recorded stamp — a site seeded off site, or before the record
	 * existed — there is nothing to tell a seeded color from a chosen one, so the
	 * restamp stays out of it.
	 */
	public function test_accent_restamp_does_nothing_without_a_record() {
		$this->set_platform( true );
		$this->set_accent_color( '#003da5' );
		Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT );

		$this->set_accent_color( '#ff0000' );
		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$this->assertSame( '#003da5', $this->stored_group()['innerBlocks'][1]['attrs']['buttonColor'] );
		$this->assertFalse( get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ) );
	}

	/**
	 * Repair runs once a request, so a platform change mid-request leaves the
	 * stored pattern stale — and only the in-memory normalization can still get
	 * the right CTA in front of the reader.
	 */
	public function test_the_instance_window_normalizes_a_stale_stored_pattern() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		// The first render latches the once-a-request repair guard.
		$this->render_instance();
		$this->set_platform( true );

		$html = $this->render_instance();

		$this->assertStringContainsString( self::DONATE_STUB_CLASS, $html, 'The reader gets the CTA the platform calls for.' );
		$this->assertStringContainsString( 'wp:buttons', get_post( $ref )->post_content, 'Repair was skipped, so the stored pattern is still stale.' );
	}

	/**
	 * A CTA rebuilt for one render is thrown away with the request, so it must
	 * not move the record describing the stored pattern's stamp — a record that
	 * disagrees with the stored child reads as a publisher color and freezes the
	 * restamp for good.
	 */
	public function test_normalizing_a_stale_button_leaves_the_accent_record_alone() {
		$this->set_platform( true );
		$this->set_accent_color( '#003da5' );
		$group = $this->stored_group();

		$group['innerBlocks'][1] = Newspack_Popups_Contextual_Prompt_Pattern::build_buttons_child( self::CUSTOM_URL, 'Donate' );
		$this->set_accent_color( '#ff0000' );

		$result = Newspack_Popups_Contextual_Prompt_Render::normalize_cta( $group );

		$this->assertSame( 'newspack-blocks/donate', $result['innerBlocks'][1]['blockName'], 'The stale button was swapped.' );
		$this->assertSame( '#ff0000', $result['innerBlocks'][1]['attrs']['buttonColor'], 'The rebuilt child carries the current accent.' );
		$this->assertSame( '#003da5', get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ), 'The record still describes the stored pattern.' );
	}

	/**
	 * Newspack Blocks deactivated is not a change of donation platform: the render
	 * falls back to a button, but nothing about the publisher's donate CTA is
	 * rewritten, so reactivating restores it intact.
	 */
	public function test_a_missing_donate_block_does_not_rewrite_the_pattern() {
		$this->set_platform( true );
		$this->set_accent_color( '#003da5' );
		$ref    = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$stored = get_post( $ref )->post_content;

		$permalink = $this->set_donor_landing_page();
		unregister_block_type( 'newspack-blocks/donate' );

		$html = $this->render_instance();

		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $html, 'The reader still gets a working ask.' );
		$this->assertSame( $stored, get_post( $ref )->post_content, 'The stored donate CTA is left alone.' );
		$this->assertSame( '#003da5', get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ), 'And so is its accent record.' );
	}

	/**
	 * The same Group pasted into a post as ordinary content is not an instance:
	 * normalization is scoped to the pattern, so a detached copy keeps whatever
	 * CTA it was pasted with and the pattern post is never touched.
	 */
	public function test_a_detached_group_is_left_alone() {
		$this->set_platform( false );
		$permalink = $this->set_donor_landing_page();
		$ref       = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$detached  = get_post( $ref )->post_content;
		$this->set_platform( true );

		$html = do_blocks( $detached );

		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $html );
		$this->assertStringNotContainsString( self::DONATE_STUB_CLASS, $html );
		$this->assertSame( $detached, get_post( $ref )->post_content, 'The pattern post is untouched.' );
	}
}
