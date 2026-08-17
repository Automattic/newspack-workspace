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
	 * The copy an instance carries as its own pattern override, so "the site-wide
	 * override won" is provable rather than merely "the override rendered".
	 */
	const PER_POST_COPY = 'Per-post copy';

	/**
	 * The reader revenue platform option repair hooks on, spelled out the way the
	 * pattern class spells it.
	 */
	const PLATFORM_OPTION = 'newspack_reader_revenue_platform';

	/**
	 * The theme the test env started on.
	 *
	 * @var string
	 */
	private $original_stylesheet;

	/**
	 * Register the stub donate block and clear the per-request render state the
	 * previous test left behind.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->reset_request_state();
		// Instances are stripped without the admin opt-in.
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		// The render path persists only for a user who could edit the pattern
		// anyway; a reader's render never writes.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
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
		wp_set_current_user( 0 );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION );
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT );
		delete_option( 'newspack_popups_donor_landing_page' );
		delete_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION );
		delete_option( 'newspack_contextual_prompts_override_body' );
		delete_option( 'newspack_contextual_prompts_override_label' );
		delete_option( 'newspack_contextual_prompts_override_url' );
		delete_option( self::PLATFORM_OPTION );
		if ( get_stylesheet() !== $this->original_stylesheet ) {
			switch_theme( $this->original_stylesheet );
		}
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
	 * Copy by default: the pattern's own paragraph is seeded empty, so an instance
	 * carrying no override renders nothing at all.
	 *
	 * @param string|null $copy The instance's own pattern override copy, or null
	 *                          for an instance carrying none.
	 * @return string Rendered markup.
	 */
	private function render_instance( $copy = self::PER_POST_COPY ) {
		$attrs = [ 'ref' => Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() ];
		if ( null !== $copy ) {
			$attrs['content'] = [ Newspack_Popups_Contextual_Prompt_Pattern::BOUND_NAME => [ 'content' => $copy ] ];
		}
		return do_blocks( '<!-- wp:block ' . wp_json_encode( $attrs ) . ' /-->' );
	}

	/**
	 * Turn the site-wide override on.
	 *
	 * @param string $body  Override copy.
	 * @param string $cta   'form' or 'button'.
	 * @param string $label Button label.
	 * @param string $url   Button destination.
	 */
	private function set_override( $body, $cta = 'button', $label = 'Give now', $url = 'https://example.com/drive/' ) {
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::OVERRIDE_CTA_OPTION, $cta );
		update_option( 'newspack_contextual_prompts_override_body', $body );
		update_option( 'newspack_contextual_prompts_override_label', $label );
		update_option( 'newspack_contextual_prompts_override_url', $url );
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
	 * alone, never a dead button or a form on a disabled platform. The drop is a
	 * render-time fallback: the stored pattern keeps its CTA, because the pattern
	 * takes no inserts and a persisted removal could never be undone.
	 */
	public function test_offsite_without_landing_page_drops_the_cta() {
		$this->set_platform( true );
		Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->set_platform( false );

		$html = $this->render_instance();

		$this->assertStringNotContainsString( self::DONATE_STUB_CLASS, $html );
		$this->assertStringNotContainsString( 'wp-block-button', $html );

		$group = $this->stored_group();
		$this->assertCount( 2, $group['innerBlocks'], 'The stored pattern keeps its CTA.' );

		$dropped = Newspack_Popups_Contextual_Prompt_Render::normalize_cta( $group );
		$this->assertCount( 1, $dropped['innerBlocks'], 'In memory, only the copy paragraph remains.' );
		$this->assertSame( 1, count( array_filter( $dropped['innerContent'], 'is_null' ) ), 'One placeholder per remaining child.' );
	}

	/**
	 * A donor landing page taken down leaves nothing to point the CTA at, so the
	 * render drops it — but the stored button survives. Persisting the removal
	 * would lose it for good: find_cta() would never see one again, and the
	 * pattern accepts no inserts to put one back by hand.
	 */
	public function test_a_dropped_cta_survives_in_the_stored_pattern() {
		$this->set_platform( false );
		$permalink = $this->set_donor_landing_page();
		$ref       = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', get_post( $ref )->post_content );

		wp_update_post(
			[
				'ID'          => (int) get_option( 'newspack_popups_donor_landing_page' ),
				'post_status' => 'draft',
			]
		);

		// The stored button points at a page readers can no longer reach, so the
		// publisher clears it in the pattern editor.
		$blocks                      = parse_blocks( get_post( $ref )->post_content );
		$blocks[0]['innerBlocks'][1] = Newspack_Popups_Contextual_Prompt_Pattern::build_buttons_child( '', 'Donate' );
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );

		$html = $this->render_instance();

		$this->assertStringNotContainsString( 'wp-block-button', $html, 'The reader is never shown a dead ask.' );
		$this->assertStringContainsString( 'wp:buttons', get_post( $ref )->post_content, 'The stored CTA is still there.' );
	}

	/**
	 * And once a landing page is configured again, the next render repoints the
	 * CTA it kept rather than leaving the prompt copy-only for good.
	 */
	public function test_a_dropped_cta_comes_back_once_a_landing_page_exists() {
		$this->set_platform( false );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		// Nothing to point at yet: this render drops the CTA.
		$this->assertStringNotContainsString( 'wp-block-button', $this->render_instance() );

		$permalink = $this->set_donor_landing_page();
		$this->reset_request_state();

		$html = $this->render_instance();

		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', get_post( $ref )->post_content, 'And the stored pattern is repointed too.' );
	}

	/**
	 * A pattern seeded before any donor landing page existed carries a button with
	 * no destination. Once one is configured the button is repointed at it, in the
	 * render and in the stored pattern, rather than left a dead ask.
	 */
	public function test_offsite_urlless_button_is_repointed_to_a_new_landing_page() {
		$this->set_platform( false );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->assertStringNotContainsString( 'href=', get_post( $ref )->post_content, 'The seeded button really has no destination.' );

		$permalink = $this->set_donor_landing_page();

		$html = $this->render_instance();

		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', get_post( $ref )->post_content );
	}

	/**
	 * Off site with no destination anywhere: the seeded button is dropped — copy
	 * alone, never a dead Donate button.
	 */
	public function test_offsite_urlless_button_without_a_landing_page_is_dropped() {
		$this->set_platform( false );
		Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$html = $this->render_instance();

		$this->assertStringContainsString( self::PER_POST_COPY, $html );
		$this->assertStringNotContainsString( 'wp-block-button', $html );
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
		global $wpdb;
		$this->set_platform( true );
		$ref    = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$before = get_post( $ref );

		// The write is a statement of its own, so the queries are what counts it.
		$writes = 0;
		add_filter(
			'query',
			function ( $query ) use ( &$writes, $wpdb ) {
				if ( 0 === stripos( $query, 'UPDATE' ) && false !== strpos( $query, $wpdb->posts ) ) {
					++$writes;
				}
				return $query;
			}
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
	 * The record describes the stored pattern's donate child, so a write that did
	 * not land must not move it: a record disagreeing with the stored child reads
	 * as a publisher color and freezes the restamp for good.
	 */
	public function test_a_failed_write_leaves_the_accent_record_alone() {
		$this->set_platform( true );
		$this->set_accent_color( '#003da5' );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->set_accent_color( '#ff0000' );
		$saved = $this->save_between_read_and_write( $ref );
		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$this->assertSame( $saved, get_post( $ref )->post_content, 'The write was refused.' );
		$this->assertSame( '#003da5', get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ), 'So the record still describes what is stored.' );
	}

	/**
	 * Simulate a save landing between repair's read of the pattern and its write:
	 * the row is written and its revision moved on while repair reads the stamp
	 * record, which it does after reading the pattern and before writing it.
	 *
	 * @param int $ref Pattern post ID.
	 *
	 * @return string The content the concurrent save stored.
	 */
	private function save_between_read_and_write( $ref ) {
		global $wpdb;
		$saved = "<!-- wp:paragraph -->\n<p>Saved from the editor.</p>\n<!-- /wp:paragraph -->";
		$bump  = function ( $value ) use ( $ref, $saved, $wpdb ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->posts,
				[
					'post_content'      => $saved,
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
				],
				[ 'ID' => $ref ]
			);
			clean_post_cache( $ref );
			return $value;
		};
		$option = Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT;
		add_filter( 'option_' . $option, $bump );
		add_filter( 'default_option_' . $option, $bump );

		return $saved;
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

	/**
	 * The site-wide override replaces the copy of every instance — the story's own
	 * pattern override included, which the binding would otherwise re-apply over
	 * it. Form mode leaves the CTA alone.
	 */
	public function test_override_replaces_per_post_copy() {
		$this->set_platform( true );
		$this->set_override( 'Support our spring drive.', 'form' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( 'Support our spring drive.', $html );
		$this->assertStringNotContainsString( self::PER_POST_COPY, $html, 'The story copy does not come back at bind time.' );
		$this->assertStringContainsString( self::DONATE_STUB_CLASS, $html, 'Form mode keeps the donate form.' );
	}

	/**
	 * Button mode on a native site: the override button replaces the donate form.
	 */
	public function test_override_button_mode_replaces_the_form() {
		$this->set_platform( true );
		$this->set_override( 'Support our spring drive.' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( 'Support our spring drive.', $html );
		$this->assertStringContainsString( 'href="https://example.com/drive/"', $html );
		$this->assertStringContainsString( '>Give now</a>', $html );
		$this->assertStringNotContainsString( self::DONATE_STUB_CLASS, $html );
	}

	/**
	 * Off site with no donor landing page, so normalization drops the CTA: the
	 * override appends its own, and fund-drive mode still has a working ask.
	 */
	public function test_override_button_mode_appends_cta_when_missing() {
		$this->set_platform( false );
		$this->set_override( 'Support our spring drive.' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( 'Support our spring drive.', $html );
		$this->assertStringContainsString( 'href="https://example.com/drive/"', $html );
		$this->assertStringContainsString( '>Give now</a>', $html );
	}

	/**
	 * Script markup in the override copy or label is rendered as text, never as an
	 * element. The override is admin supplied and lands on every story, so a
	 * dropped esc_html() here is stored XSS on the whole site.
	 */
	public function test_override_script_payload_is_escaped() {
		$this->set_platform( false );
		$this->set_override( '<script>alert("xss")</script>', 'button', '<script>alert("label")</script>' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html, 'The copy payload is entity-encoded.' );
		$this->assertStringContainsString( '&lt;script&gt;alert(&quot;label&quot;)&lt;/script&gt;', $html, 'The label payload is entity-encoded.' );
		$this->assertDoesNotMatchRegularExpression( '#<\s*script#i', $html, 'No live script element reaches the reader.' );
	}

	/**
	 * An event-handler payload in the override copy comes out as text: no tag in
	 * the rendered prompt carries an on* attribute.
	 */
	public function test_override_event_handler_payload_is_escaped() {
		$this->set_platform( false );
		$this->set_override( 'Support us <img src=x onerror="alert(1)">' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( 'Support us &lt;img src=x onerror=&quot;alert(1)&quot;&gt;', $html, 'The payload is entity-encoded.' );
		$this->assertDoesNotMatchRegularExpression( '#<[a-z][^>]*\son[a-z]+\s*=#i', $html, 'No rendered tag carries an event handler attribute.' );
	}

	/**
	 * A javascript: destination in the override URL is dropped rather than
	 * rendered as a live link.
	 */
	public function test_override_javascript_url_is_dropped() {
		$this->set_platform( false );
		$this->set_override( 'Support our spring drive.', 'button', 'Give now', 'javascript:alert(1)' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( '>Give now</a>', $html, 'The button still renders.' );
		$this->assertStringContainsString( 'href=""', $html, 'esc_url() emptied the destination.' );
		$this->assertDoesNotMatchRegularExpression( '#javascript\s*:#i', $html, 'No live javascript: URL reaches the reader.' );
	}

	/**
	 * A literal $-sequence in override copy or label survives verbatim — never
	 * expanded as a regex backreference.
	 */
	public function test_dollar_sequences_are_not_expanded() {
		$this->set_platform( false );
		$this->set_override( 'Give $5 today — just ${1} a week.', 'button', 'Give $5' );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( 'Give $5 today — just ${1} a week.', $html );
		$this->assertStringContainsString( '>Give $5</a>', $html );
	}

	/**
	 * Configuring the donation platform for the first time adds the option rather
	 * than updating one, and repair has to run on that too — otherwise the very
	 * publisher who has just set donations up keeps the wrong CTA.
	 */
	public function test_first_platform_configuration_repairs_the_pattern() {
		$this->set_platform( true );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->assertStringContainsString( 'wp:newspack-blocks/donate', get_post( $ref )->post_content );

		$permalink = $this->set_donor_landing_page();
		$this->set_platform( false );

		delete_option( self::PLATFORM_OPTION );
		add_option( self::PLATFORM_OPTION, 'stripe' );

		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', get_post( $ref )->post_content );
	}

	/**
	 * A feature the admin has switched off never writes: the instance is stripped
	 * anyway, so a render must not repair the pattern on its way past.
	 */
	public function test_a_withdrawn_opt_in_does_not_repair_the_pattern() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->set_platform( true );
		$stored = get_post( $ref )->post_content;

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$this->assertSame( '', trim( $this->render_instance() ), 'The instance is stripped.' );
		$this->assertSame( $stored, get_post( $ref )->post_content, 'And the stale pattern is left alone.' );
	}

	/**
	 * A newsroom that opts out keeps the prompts it has already published: they
	 * stop rendering for readers, and come back with their own copy when the
	 * newsroom opts back in.
	 */
	public function test_published_prompts_are_hidden_while_opted_out_and_come_back() {
		$this->set_platform( true );
		$ref     = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$content = '<!-- wp:block ' . wp_json_encode(
			[
				'ref'     => $ref,
				'content' => [ Newspack_Popups_Contextual_Prompt_Pattern::BOUND_NAME => [ 'content' => self::PER_POST_COPY ] ],
			]
		) . ' /-->';

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$this->assertSame( '', trim( do_blocks( $content ) ), 'Readers see nothing while the site is opted out.' );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		$this->assertStringContainsString( self::PER_POST_COPY, do_blocks( $content ), 'And the prompt comes back with its copy.' );
	}

	/**
	 * The seed claims a lock before inserting, so a call arriving while another
	 * request is still writing gets nothing rather than a second pattern —
	 * instances made against the loser's pattern would never be managed again.
	 */
	public function test_a_held_seed_claim_does_not_insert_a_second_pattern() {
		add_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION, time(), '', false );

		$this->assertSame( 0, Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() );
		$this->assertEmpty(
			get_posts(
				[
					'post_type'   => 'wp_block',
					'post_status' => 'any',
					'numberposts' => 5,
				]
			)
		);
	}

	/**
	 * A caller that cannot claim answers with the winner's pattern, once there is
	 * one: a record still pointing at nothing is no answer, and a caller handed it
	 * would address instances at a hole.
	 */
	public function test_a_held_seed_claim_answers_with_the_record() {
		update_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, 4242 );
		add_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION, time(), '', false );

		$this->assertSame( 0, Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id(), 'A record pointing at nothing is not an answer.' );

		// The winner finished writing while this caller waited.
		$recorded = self::factory()->post->create(
			[
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
			]
		);
		update_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, $recorded );

		$this->assertSame( $recorded, Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() );
	}

	/**
	 * A claim left behind by a request that died mid-seed is stale after its TTL,
	 * so seeding is blocked for seconds rather than for good.
	 */
	public function test_a_stale_seed_claim_is_reclaimed() {
		add_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION, time() - 120, '', false );

		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'wp_block', get_post_type( $id ) );
		$this->assertFalse( get_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION ), 'The claim is released.' );
	}

	/**
	 * Two first loads can still overlap around the insert itself. A seeder that
	 * finds a pattern recorded while it was writing drops its own and adopts the
	 * recorded one: the record is what every instance is made against, so keeping
	 * both would leave a pattern nothing manages.
	 */
	public function test_a_seed_that_loses_the_race_adopts_the_recorded_pattern() {
		$rival  = self::factory()->post->create(
			[
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
			]
		);
		$orphan = 0;
		add_action(
			'wp_insert_post',
			function ( $post_id, $post ) use ( $rival, &$orphan ) {
				if ( 'wp_block' === $post->post_type && (int) $post_id !== $rival ) {
					$orphan = (int) $post_id;
					update_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, $rival );
				}
			},
			10,
			2
		);

		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertSame( $rival, $id );
		$this->assertGreaterThan( 0, $orphan );
		$this->assertNull( get_post( $orphan ), 'The pattern nothing recorded is dropped.' );
		$this->assertSame( $rival, (int) get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID ) );
		$this->assertFalse( get_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION ), 'The claim is released.' );
	}

	/**
	 * A claim that went stale while its holder worked is another request's to take,
	 * and the holder must not then release it: the successor would be left seeding
	 * without a lock, and a third request could seed a second pattern alongside it.
	 * So the release is conditional on the value this request claimed with.
	 */
	public function test_an_expired_claim_does_not_release_its_successor() {
		global $wpdb;
		$successor = 'claimed-by-the-next-request';
		add_action(
			'wp_insert_post',
			function ( $post_id, $post ) use ( $wpdb, $successor ) {
				if ( 'wp_block' !== $post->post_type ) {
					return;
				}
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->options,
					[ 'option_value' => $successor ],
					[ 'option_name' => Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION ]
				);
			},
			10,
			2
		);

		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $id );
		$held = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION
			)
		);
		$this->assertSame( $successor, $held, 'The claim its successor holds is left standing.' );
	}

	/**
	 * The lock does not stand in the way of re-seeding: a record left pointing at
	 * a pattern that vanished anyway — a migration, a direct query — is replaced.
	 */
	public function test_a_deleted_pattern_is_seeded_again() {
		$first = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$guard = [ 'Newspack_Popups_Contextual_Prompt_Pattern', 'prevent_pattern_deletion' ];
		remove_filter( 'pre_delete_post', $guard );
		wp_delete_post( $first, true );
		add_filter( 'pre_delete_post', $guard, 10, 2 );

		$second = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $second );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 'wp_block', get_post_type( $second ) );
	}

	/**
	 * A trashed pattern is restored in place rather than replaced: instances
	 * reference it by id, so a new pattern would empty every prompt on the site.
	 */
	public function test_a_trashed_pattern_is_restored_in_place() {
		$this->set_platform( true );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		wp_trash_post( $ref );

		$restored = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertSame( $ref, $restored, 'The recorded id survives the trash.' );
		$this->assertSame( 'publish', get_post_status( $ref ) );
		$this->assertStringContainsString( self::PER_POST_COPY, $this->render_instance(), 'And instances render again.' );
	}

	/**
	 * The same for a pattern left unpublished — a draft the publisher saved from
	 * the pattern editor, say.
	 */
	public function test_an_unpublished_pattern_is_republished_in_place() {
		$this->set_platform( true );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		wp_update_post(
			[
				'ID'          => $ref,
				'post_status' => 'draft',
			]
		);

		$restored = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertSame( $ref, $restored );
		$this->assertSame( 'publish', get_post_status( $ref ) );
		$this->assertStringContainsString( self::PER_POST_COPY, $this->render_instance() );
	}

	/**
	 * The bound paragraph's name is the key every instance's copy is stored under,
	 * so a rename in the pattern editor is reverted rather than left to orphan the
	 * copy of every prompt already written.
	 */
	public function test_a_renamed_bound_paragraph_is_pinned_back() {
		$this->set_platform( true );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$blocks = parse_blocks( get_post( $ref )->post_content );
		$blocks[0]['innerBlocks'][0]['attrs']['metadata']['name'] = 'Story copy';
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );

		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$para = $this->stored_group()['innerBlocks'][0];
		$this->assertSame( Newspack_Popups_Contextual_Prompt_Pattern::BOUND_NAME, $para['attrs']['metadata']['name'] );
		$this->assertSame(
			[ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
			$para['attrs']['metadata']['bindings'],
			'The binding is left alone.'
		);
		$this->assertStringContainsString( self::PER_POST_COPY, $this->render_instance(), 'Overrides written under the seeded name still resolve.' );
	}

	/**
	 * Overrides enabled on another child would share the copy paragraph's one key,
	 * so the binding is dropped rather than renamed — and the copy paragraph, the
	 * pattern's only bound field, is left as it is.
	 */
	public function test_an_override_bound_to_another_child_is_stripped() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$blocks = parse_blocks( get_post( $ref )->post_content );
		$blocks[0]['innerBlocks'][1]['innerBlocks'][0]['attrs']['metadata'] = [
			'name'     => 'Donate button',
			'bindings' => [ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
		];
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );

		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$group = $this->stored_group();
		$this->assertArrayNotHasKey( 'metadata', $group['innerBlocks'][1]['innerBlocks'][0]['attrs'], 'The stray binding and the name that keyed it are gone.' );
		$this->assertSame( Newspack_Popups_Contextual_Prompt_Pattern::BOUND_NAME, $group['innerBlocks'][0]['attrs']['metadata']['name'], 'The copy paragraph keeps its name.' );
		$this->assertSame(
			[ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
			$group['innerBlocks'][0]['attrs']['metadata']['bindings'],
			'And its binding.'
		);
	}

	/**
	 * The copy paragraph's binding is the address every prompt's own copy is
	 * written to, not a design choice: the pattern editor's Overrides control can
	 * switch it off, which would orphan the copy of every prompt already
	 * published, so it is bound back under the seeded name.
	 */
	public function test_a_disabled_copy_binding_is_restored() {
		$this->set_platform( true );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$blocks = parse_blocks( get_post( $ref )->post_content );
		unset( $blocks[0]['innerBlocks'][0]['attrs']['metadata'] );
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );

		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$para = $this->stored_group()['innerBlocks'][0];
		$this->assertSame( Newspack_Popups_Contextual_Prompt_Pattern::BOUND_NAME, $para['attrs']['metadata']['name'] );
		$this->assertSame(
			[ '__default' => [ 'source' => 'core/pattern-overrides' ] ],
			$para['attrs']['metadata']['bindings']
		);
		$this->assertStringContainsString( self::PER_POST_COPY, $this->render_instance(), 'And each story\'s own copy resolves again.' );
	}

	/**
	 * The marker class is the card's whole identity — analytics, placement and the
	 * handling of a detached card all find it by that class — and the Additional
	 * CSS classes field can take it off. It is put back in the attribute and in
	 * the saved wrapper alike: core validates a block against what it would
	 * serialize now, so one without the other is a recovery prompt.
	 */
	public function test_a_stripped_marker_class_is_restored() {
		$this->set_platform( true );
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$marker = Newspack_Popups_Contextual_Prompt_Pattern::MARKER_CLASS;
		$blocks = parse_blocks( get_post( $ref )->post_content );
		$blocks[0]['attrs']['className'] = 'publisher-class';
		$blocks[0]['innerHTML']          = str_replace( $marker, 'publisher-class', $blocks[0]['innerHTML'] );
		foreach ( $blocks[0]['innerContent'] as $index => $chunk ) {
			if ( is_string( $chunk ) ) {
				$blocks[0]['innerContent'][ $index ] = str_replace( $marker, 'publisher-class', $chunk );
			}
		}
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, serialize_blocks( $blocks ) );

		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$group = $this->stored_group();
		$this->assertStringContainsString( $marker, $group['attrs']['className'] );
		$this->assertStringContainsString( 'publisher-class', $group['attrs']['className'], 'Classes the publisher added are kept.' );
		$this->assertStringContainsString( $marker, $group['innerHTML'], 'The saved wrapper carries it too.' );
		$this->assertStringContainsString( 'data-newspack-cp-post-id', $this->render_instance(), 'And the card is stamped again.' );
	}

	/**
	 * Repair reads the pattern, works on the parsed copy and writes it back, so a
	 * pattern the publisher saved from the editor in between would be overwritten
	 * with content derived from before their edit. The write is refused instead.
	 *
	 * The editor save is simulated on the stamp record, which repair reads after
	 * the pattern and before writing it.
	 */
	public function test_repair_refuses_to_overwrite_a_concurrent_save() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		// A platform change repair would otherwise write.
		$this->set_platform( true );

		$saved = $this->save_between_read_and_write( $ref );
		Newspack_Popups_Contextual_Prompt_Pattern::repair();

		$this->assertSame( $saved, get_post( $ref )->post_content, 'The publisher\'s save stands.' );
	}

	/**
	 * The guard itself: the compare and the swap are one statement, so content
	 * read at one revision is swapped in only while the row still carries that
	 * revision — a check followed by a write leaves a window a save can land in.
	 * A write naming no revision swaps on whatever is stored now.
	 */
	public function test_the_write_swaps_only_on_the_revision_it_read() {
		global $wpdb;
		$ref  = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$read = get_post( $ref )->post_modified_gmt;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[ 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ],
			[ 'ID' => $ref ]
		);
		clean_post_cache( $ref );
		$stored = get_post( $ref )->post_content;

		$this->assertFalse( Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, 'stale', $read ) );
		$this->assertSame( $stored, get_post( $ref )->post_content );

		$this->assertTrue( Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, 'fresh' ) );
		$this->assertSame( 'fresh', get_post( $ref )->post_content );
	}

	/**
	 * What lands moves the revision on and refreshes the cache: the row is written
	 * behind the Posts API, so a stale cached post would leave the rest of the
	 * request — and the next freshness check — reading content nothing stores.
	 */
	public function test_a_landed_write_is_what_every_later_read_sees() {
		global $wpdb;
		$ref  = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[ 'post_modified_gmt' => $past ],
			[ 'ID' => $ref ]
		);
		clean_post_cache( $ref );
		// Warm the cache with the copy the write replaces.
		$this->assertSame( $past, get_post( $ref )->post_modified_gmt );

		$this->assertTrue( Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $ref, 'fresh' ) );

		$after = get_post( $ref );
		$this->assertSame( 'fresh', $after->post_content, 'The cached post is the written one.' );
		$this->assertNotSame( $past, $after->post_modified_gmt, 'And the revision moved on.' );
		$this->assertSame( $after->post_modified_gmt, $this->stored_modified_gmt( $ref ), 'In the row as much as in the cache.' );
	}

	/**
	 * The pattern's revision as the table has it.
	 *
	 * @param int $ref Pattern post ID.
	 *
	 * @return string Post modified time in GMT.
	 */
	private function stored_modified_gmt( $ref ) {
		global $wpdb;

		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $ref )
		);
	}

	/**
	 * A reader's render never writes: persisting from an unprivileged request
	 * would push the pattern through KSES and stabilize on the filtered copy. The
	 * reader still gets the CTA the platform calls for, in memory.
	 */
	public function test_an_anonymous_render_does_not_persist_the_repair() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		$ref = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->set_platform( true );
		$stored = get_post( $ref )->post_content;

		wp_set_current_user( 0 );
		$html = $this->render_instance();

		$this->assertStringContainsString( self::DONATE_STUB_CLASS, $html, 'The reader gets the normalized CTA.' );
		$this->assertSame( $stored, get_post( $ref )->post_content, 'And the stored pattern is left alone.' );
	}

	/**
	 * Switch to any installed theme of the requested family, skipping the test
	 * when the env has none.
	 *
	 * @param bool $block_theme Whether to switch to a block theme.
	 */
	private function switch_to_theme_family( $block_theme ) {
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( method_exists( $theme, 'is_block_theme' ) && $block_theme === $theme->is_block_theme() ) {
				switch_theme( $stylesheet );
				wp_clean_theme_json_cache();
				return;
			}
		}

		$this->markTestSkipped( $block_theme ? 'No block theme is available in this test environment.' : 'No classic theme is available in this test environment.' );
	}

	/**
	 * A classic theme has no layout support, so the card's own gap emits no CSS
	 * and its children sit in the restored inner container — a structure the
	 * editor and the front end share, and which the card's CSS has to address for
	 * the two to agree. A block theme needs none of it: layout support emits both
	 * rules itself.
	 */
	public function test_the_layout_css_is_classic_only() {
		$this->switch_to_theme_family( false );

		$css = Newspack_Popups_Contextual_Prompt_Render::get_layout_css();
		$this->assertStringContainsString( '.newspack-contextual-prompt > .wp-block-group__inner-container > * + *', $css );
		$this->assertStringContainsString( 'margin-block-start:var(--wp--preset--spacing--30,1rem)', $css );
		$this->assertStringContainsString( 'flex-shrink:0', $css );

		$settings = Newspack_Popups_Contextual_Prompt_Render::add_editor_layout_styles( [] );
		$this->assertSame( [ [ 'css' => $css ] ], $settings['styles'], 'The editor canvas gets the same CSS.' );

		$this->switch_to_theme_family( true );

		$this->assertSame( '', Newspack_Popups_Contextual_Prompt_Render::get_layout_css() );
		$this->assertSame( [], Newspack_Popups_Contextual_Prompt_Render::add_editor_layout_styles( [] ), 'And the editor canvas gets nothing.' );
	}

	/**
	 * The theme is faulted in rather than switched to: the test environment has no
	 * Newspack theme installed.
	 */
	public function test_the_button_colour_css_is_newspack_classic_only() {
		$css   = $this->under_theme( 'newspack-theme', [ Newspack_Popups_Contextual_Prompt_Render::class, 'get_button_color_css' ] );
		$other = $this->under_theme( 'twentytwentyfour', [ Newspack_Popups_Contextual_Prompt_Render::class, 'get_button_color_css' ] );

		$this->assertStringContainsString( '.newspack-contextual-prompt .wp-block-button:not(.is-style-outline)', $css );
		$this->assertStringContainsString( '> .wp-block-button__link:not(.is-style-outline):not(.has-background)', $css );
		$this->assertStringContainsString( '{color:var(--newspack-theme-color-against-secondary)}', $css );

		$this->assertSame( '', $other, 'Another theme is left to colour its own buttons.' );
	}

	/**
	 * The restated colour pairs with the theme's own button background, so a
	 * background the publisher chose is left alone.
	 */
	public function test_a_publisher_background_is_out_of_reach() {
		$css = $this->under_theme( 'newspack-theme', [ Newspack_Popups_Contextual_Prompt_Render::class, 'get_button_color_css' ] );

		$this->assertStringContainsString( ':not(.has-background)', $css );
	}

	/**
	 * The filter is the only route onto a theme the slug check misses.
	 */
	public function test_the_button_colour_css_is_filterable() {
		$opt_in = static function () {
			return '.custom{color:#fff}';
		};

		add_filter( 'newspack_contextual_prompts_button_color_css', $opt_in );
		$forked   = $this->under_theme( 'some-fork', [ Newspack_Popups_Contextual_Prompt_Render::class, 'get_button_color_css' ] );
		$newspack = $this->under_theme( 'newspack-theme', [ Newspack_Popups_Contextual_Prompt_Render::class, 'get_button_color_css' ] );
		remove_filter( 'newspack_contextual_prompts_button_color_css', $opt_in );

		$this->assertSame( '.custom{color:#fff}', $forked, 'A theme this does not name can opt in.' );
		$this->assertSame( '.custom{color:#fff}', $newspack, 'And one it does can replace what it sends.' );
	}

	/**
	 * Neither surface can lose the CTA's colour to an edit that touches only the
	 * other. The front end is read back off the handle it rides on.
	 */
	public function test_both_surfaces_are_delivered_the_same_css() {
		$this->switch_to_theme_family( false );
		wp_deregister_style( Newspack_Popups_Contextual_Prompt_Render::LAYOUT_STYLE_HANDLE );

		$newspack = static function () {
			return 'newspack-theme';
		};
		add_filter( 'template', $newspack );

		$expected = Newspack_Popups_Contextual_Prompt_Render::get_layout_css()
			. Newspack_Popups_Contextual_Prompt_Render::get_button_color_css();
		$settings = Newspack_Popups_Contextual_Prompt_Render::add_editor_layout_styles( [] );
		Newspack_Popups_Contextual_Prompt_Render::enqueue_layout_styles();
		$inline = wp_styles()->get_data( Newspack_Popups_Contextual_Prompt_Render::LAYOUT_STYLE_HANDLE, 'after' );

		remove_filter( 'template', $newspack );

		$this->assertStringContainsString( '{color:var(--newspack-theme-color-against-secondary)}', $expected, 'The colour rule is part of what is delivered.' );
		$this->assertSame( [ [ 'css' => $expected ] ], $settings['styles'], 'The editor canvas gets it.' );
		$this->assertIsArray( $inline );
		$this->assertSame( $expected, implode( '', $inline ), 'And so does the front end.' );
	}

	/**
	 * Run a callable with `get_template()` reporting a given slug.
	 *
	 * @param string   $template Theme slug to report.
	 * @param callable $callback Callable to run.
	 *
	 * @return mixed The callable's return value.
	 */
	private function under_theme( $template, $callback ) {
		$filter = static function () use ( $template ) {
			return $template;
		};

		add_filter( 'template', $filter );
		try {
			return call_user_func( $callback );
		} finally {
			remove_filter( 'template', $filter );
		}
	}

	/**
	 * An inactive override — enabled with no copy — leaves the instance alone, so
	 * the story's own copy renders.
	 */
	public function test_inactive_override_is_a_noop() {
		$this->set_platform( false );
		$this->set_donor_landing_page();
		update_option( Newspack_Popups_Settings::OVERRIDE_ENABLED_OPTION, true );

		$html = $this->render_instance( self::PER_POST_COPY );

		$this->assertStringContainsString( self::PER_POST_COPY, $html );
	}
}
