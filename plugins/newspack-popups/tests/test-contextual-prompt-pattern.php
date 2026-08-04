<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the Contextual Prompt synced pattern: seeding, the locked and bound
 * structure it seeds with, the slash-safe write helper, and the protection that
 * keeps the pattern out of reach of deletion and unlocking.
 *
 * Newspack Blocks is not loaded in this test env, so set_up() registers a stub
 * donate block type — use_donate_block() requires it — and native-CTA
 * assertions are structural only.
 *
 * @package Newspack_Popups
 */

/**
 * Contextual Prompt pattern test.
 */
class ContextualPromptPatternTest extends WP_UnitTestCase {
	/**
	 * The theme the test env started on.
	 *
	 * @var string
	 */
	private $original_stylesheet;

	/**
	 * The native CTA requires the donate block to be registered.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			register_block_type( 'newspack-blocks/donate' );
		}
	}

	/**
	 * Reset the pattern record, the donor landing page, the theme and the stub
	 * block type.
	 */
	public function tear_down() {
		delete_transient( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT );
		delete_option( 'newspack_popups_donor_landing_page' );
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
	 * Switch to any installed theme of the requested family, skipping the test
	 * when the env has none — the seeded palette slug is what differs between them.
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
	 * The seeded pattern's top-level Group, parsed.
	 *
	 * @return array
	 */
	private function seeded_group() {
		$blocks = parse_blocks( get_post( Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id() )->post_content );
		return $blocks[0];
	}

	/**
	 * Put an accent color in the palette, where get_accent_color() reads it. The
	 * custom origin, so it wins over any accent the active theme declares.
	 *
	 * @param string $color Hex color.
	 */
	private function set_accent_color( $color ) {
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
	 * The pattern is a published synced pattern, created once.
	 */
	public function test_seeds_a_synced_pattern_once() {
		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'wp_block', get_post_type( $id ) );
		$this->assertNotSame( 'unsynced', get_post_meta( $id, 'wp_pattern_sync_status', true ), 'Synced patterns carry no unsynced meta.' );
		$this->assertSame( $id, Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id(), 'Seeding is idempotent.' );
	}

	/**
	 * The seeded structure: a marker-classed Group that accepts no inserts, the
	 * bound copy paragraph, and the CTA — all unmovable and unremovable.
	 */
	public function test_pattern_structure_is_locked_and_bound() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$group = $this->seeded_group();

		$this->assertSame( 'core/group', $group['blockName'] );
		$this->assertStringContainsString( 'newspack-contextual-prompt', $group['attrs']['className'] );
		$this->assertSame( 'insert', $group['attrs']['templateLock'] );
		$this->assertSame(
			[
				'move'   => true,
				'remove' => true,
			],
			$group['attrs']['lock']
		);

		$para = $group['innerBlocks'][0];
		$this->assertSame( 'core/paragraph', $para['blockName'] );
		$this->assertSame( 'Prompt copy', $para['attrs']['metadata']['name'] );
		$this->assertSame( [ '__default' => [ 'source' => 'core/pattern-overrides' ] ], $para['attrs']['metadata']['bindings'] );
		$this->assertSame(
			[
				'move'   => true,
				'remove' => true,
			],
			$para['attrs']['lock']
		);

		$cta = $group['innerBlocks'][1];
		$this->assertSame( 'newspack-blocks/donate', $cta['blockName'] );
		$this->assertSame( 'is-style-modern', $cta['attrs']['className'] );
		$this->assertSame(
			[
				'move'   => true,
				'remove' => true,
			],
			$cta['attrs']['lock']
		);
	}

	/**
	 * The seeded copy is empty: an uninitialized instance displays nothing rather
	 * than placeholder copy, which is what the empty-prompt suppression keys on.
	 * The placeholder is an attribute, which core shows in the editor only.
	 */
	public function test_seeded_copy_is_empty_behind_an_editor_placeholder() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$para = $this->seeded_group()['innerBlocks'][0];

		$this->assertSame( '<p></p>', trim( $para['innerHTML'] ) );
		$this->assertSame( 'Copy is generated for each story.', $para['attrs']['placeholder'] );
	}

	/**
	 * The card seeds explicit typography and text color, and the wrapper carries
	 * exactly the classes core serializes for them: a class set the editor would
	 * regenerate differently is a block validation error the moment it opens.
	 *
	 * The classic theme's "M" step is `normal` — it declares no `medium`, and a
	 * slug it does not declare has no CSS behind it and leaves the size control
	 * empty.
	 */
	public function test_classic_theme_seeds_dark_gray_text_at_normal_size() {
		$this->switch_to_theme_family( false );

		$group = $this->seeded_group();

		$this->assertSame( 'dark-gray', $group['attrs']['textColor'] );
		$this->assertSame( 'normal', $group['attrs']['fontSize'] );
		$this->assertStringContainsString( 'has-text-color has-dark-gray-color', $group['innerHTML'] );
		$this->assertStringContainsString( 'has-normal-font-size', $group['innerHTML'] );
	}

	/**
	 * Block themes name their body-text color and their typography steps
	 * differently, so the seed follows the active theme rather than the slugs the
	 * classic theme happens to declare.
	 */
	public function test_block_theme_seeds_contrast_text_at_medium_size() {
		$this->switch_to_theme_family( true );

		$group = $this->seeded_group();

		$this->assertSame( 'contrast', $group['attrs']['textColor'] );
		$this->assertSame( 'medium', $group['attrs']['fontSize'] );
		$this->assertStringContainsString( 'has-text-color has-contrast-color', $group['innerHTML'] );
		$this->assertStringContainsString( 'has-medium-font-size', $group['innerHTML'] );
	}

	/**
	 * Off-site platform: the CTA is a button pointing at the donor landing page.
	 */
	public function test_offsite_platform_seeds_a_button_cta() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_false' );
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		update_option( 'newspack_popups_donor_landing_page', $page );

		$cta = $this->seeded_group()['innerBlocks'][1];

		$this->assertSame( 'core/buttons', $cta['blockName'] );
		$this->assertStringContainsString( get_permalink( $page ), $cta['innerBlocks'][0]['innerHTML'] );
	}

	/**
	 * The donate CTA is stamped with the theme's accent color, and the stamp is
	 * recorded — the record is what a later restamp compares against, so the two
	 * must never disagree.
	 */
	public function test_donate_cta_is_stamped_with_the_accent_and_recorded() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );
		$this->set_accent_color( '#003da5' );

		$stamped = $this->seeded_group()['innerBlocks'][1]['attrs']['buttonColor'] ?? null;

		$this->assertSame( '#003da5', $stamped );
		$this->assertSame( get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_STAMPED_ACCENT ), $stamped );
	}

	/**
	 * A deleted pattern post is re-seeded rather than leaving instances pointing
	 * at a hole.
	 */
	public function test_reseeds_when_the_post_vanishes() {
		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		wp_delete_post( $id, true );

		$new = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $new );
		$this->assertNotSame( $id, $new );
	}

	/**
	 * Block markup survives a write: the escapes serialize_blocks() emits are
	 * what unslashing strips, so the write helper has to slash first.
	 */
	public function test_pattern_content_round_trips_markup_through_wp_slash() {
		$id     = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$blocks = parse_blocks( get_post( $id )->post_content );

		$blocks[0]['innerBlocks'][0]['innerHTML']    = '<p>Copy with <em>markup</em> &amp; entities.</p>';
		$blocks[0]['innerBlocks'][0]['innerContent'] = [ '<p>Copy with <em>markup</em> &amp; entities.</p>' ];
		// Attribute values are where serialize_blocks() emits escapes — the quotes
		// below leave as escape sequences an unslashed write would eat.
		$blocks[0]['innerBlocks'][0]['attrs']['placeholder'] = 'The reader asks "why now"';
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $id, serialize_blocks( $blocks ) );

		$content = get_post( $id )->post_content;
		$this->assertStringContainsString( '<em>markup</em>', $content );
		$this->assertStringNotContainsString( 'u003c', $content );
		$this->assertSame(
			'The reader asks "why now"',
			parse_blocks( $content )[0]['innerBlocks'][0]['attrs']['placeholder'],
			'The attribute escapes survived the write.'
		);
	}

	/**
	 * Deleting the pattern would break every instance referencing it, so the
	 * capability is denied outright — to administrators too. Other synced
	 * patterns keep theirs.
	 */
	public function test_the_pattern_cannot_be_deleted_by_anyone() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertFalse( current_user_can( 'delete_post', $id ) );

		$other = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );
		$this->assertTrue( current_user_can( 'delete_post', $other ), 'Other synced patterns stay deletable.' );
	}

	/**
	 * The pattern's own locks are what keep instances uniform, so the editor that
	 * opens it offers no way to lift them. Other posts keep block locking.
	 */
	public function test_the_pattern_editor_hides_block_locking() {
		// get_block_editor_settings() collects the iframed editor assets, which
		// needs the enqueue globals a real request would already have.
		wp_styles();
		wp_scripts();

		$id       = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$context  = new WP_Block_Editor_Context( [ 'post' => get_post( $id ) ] );
		$settings = get_block_editor_settings( [], $context );

		$this->assertFalse( $settings['canLockBlocks'] );

		$other_context = new WP_Block_Editor_Context( [ 'post' => get_post( self::factory()->post->create() ) ] );
		$this->assertNotFalse( get_block_editor_settings( [], $other_context )['canLockBlocks'] ?? true, 'Other posts keep block locking.' );
	}
}
