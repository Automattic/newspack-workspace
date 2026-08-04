<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test the Contextual Prompt synced pattern: seeding, the locked and bound
 * structure it seeds with, and the slash-safe write helper.
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
	 * The native CTA requires the donate block to be registered.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-blocks/donate' ) ) {
			register_block_type( 'newspack-blocks/donate' );
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
	 */
	public function test_seeded_copy_is_empty() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$this->assertSame( '<p></p>', trim( $this->seeded_group()['innerBlocks'][0]['innerHTML'] ) );
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
}
