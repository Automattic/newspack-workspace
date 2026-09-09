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
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::SEEDING_LOCK_OPTION );
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID );
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
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
	 * Delete the pattern post the way a migration or a direct query would: past
	 * the guard that refuses it, leaving the record pointing at nothing.
	 *
	 * @param int $pattern_id Pattern post ID.
	 */
	private function delete_pattern_post( $pattern_id ) {
		$guard = [ 'Newspack_Popups_Contextual_Prompt_Pattern', 'prevent_pattern_deletion' ];
		remove_filter( 'pre_delete_post', $guard );
		wp_delete_post( $pattern_id, true );
		add_filter( 'pre_delete_post', $guard, 10, 2 );
	}

	/**
	 * The pattern an opted-in site is using.
	 *
	 * @return int Pattern post ID.
	 */
	private function opt_in() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		return Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
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
	 * The seeded pattern explains itself where admins land: the description
	 * shown in the pattern editor's summary panel.
	 */
	public function test_seeds_a_description() {
		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertNotSame( '', get_post( $id )->post_excerpt );
	}

	/**
	 * Rename is not capability-gated the way delete is, so a rename is reverted
	 * at the data layer while the rest of the save goes through.
	 */
	public function test_rename_is_reverted() {
		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Renamed',
			]
		);

		$this->assertSame( 'Contextual Prompt', get_post( $id )->post_title );
	}

	/**
	 * A new wp_block carrying the marker — what the editor's Duplicate action
	 * would create — is refused; updates to the pattern itself pass, as do
	 * unrelated new patterns.
	 */
	public function test_duplication_is_refused() {
		$id      = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$content = get_post( $id )->post_content;

		$duplicate               = new stdClass();
		$duplicate->post_content = $content;
		$this->assertWPError( Newspack_Popups_Contextual_Prompt_Pattern::prevent_pattern_duplication( $duplicate, null ) );

		$update               = new stdClass();
		$update->ID           = $id;
		$update->post_content = $content;
		$this->assertSame( $update, Newspack_Popups_Contextual_Prompt_Pattern::prevent_pattern_duplication( $update, null ) );

		$unrelated               = new stdClass();
		$unrelated->post_content = '<!-- wp:paragraph --><p>Plain</p><!-- /wp:paragraph -->';
		$this->assertSame( $unrelated, Newspack_Popups_Contextual_Prompt_Pattern::prevent_pattern_duplication( $unrelated, null ) );
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
		$this->assertSame( 'Prompt Copy', $para['attrs']['metadata']['name'] );
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
	 * The seeded copy is a general ask, in the markup rather than behind an editor
	 * placeholder: it is what an instance nobody has written story-specific copy
	 * for falls back to, and a placeholder never reaches a reader.
	 */
	public function test_seeded_copy_is_the_default_ask() {
		add_filter( 'newspack_contextual_prompts_use_donate_block', '__return_true' );

		$para = $this->seeded_group()['innerBlocks'][0];

		$this->assertSame(
			'<p>Reporting like this takes time and costs money. If you value it, consider supporting our newsroom.</p>',
			trim( $para['innerHTML'] )
		);
		$this->assertSame( $para['innerHTML'], $para['innerContent'][0], 'The markup carries the copy once, in both places core reads it.' );
		$this->assertArrayNotHasKey( 'placeholder', $para['attrs'] );
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
	 * Seeding runs from the admin or the REST API, under the seeding
	 * administrator's own profile language — while the title and copy it stores
	 * are the site's, read by every reader. So the build and the insert run under
	 * the site locale, and the switch is undone whatever happens.
	 */
	public function test_seeding_runs_under_the_site_locale() {
		$switched = [];
		$restored = 0;
		// The language a request runs in, which in the admin is the user's own.
		add_filter(
			'determine_locale',
			function () {
				return 'fr_FR';
			}
		);
		add_action(
			'switch_locale',
			function ( $locale ) use ( &$switched ) {
				$switched[] = $locale;
			}
		);
		add_action(
			'restore_previous_locale',
			function () use ( &$restored ) {
				++$restored;
			}
		);

		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		// Core re-runs create_initial_taxonomies() on every locale change, dropping
		// the prompt CPT's category and tag attachment for the rest of the process
		// — the end of the request in production, the rest of this run here.
		Newspack_Popups::register_cpt();

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( [ get_locale() ], $switched, 'The seed ran under the site locale.' );
		$this->assertSame( 1, $restored, 'And handed the request back its own.' );
	}

	/**
	 * Undone when the insert throws, too: a request left in the site locale would
	 * run every later string — and the taxonomy registration a locale change
	 * re-runs — under a language it is not in.
	 */
	public function test_a_throwing_insert_still_restores_the_locale() {
		$restored = 0;
		add_filter(
			'determine_locale',
			function () {
				return 'fr_FR';
			}
		);
		add_action(
			'restore_previous_locale',
			function () use ( &$restored ) {
				++$restored;
			}
		);
		add_action(
			'save_post',
			function () {
				throw new RuntimeException( 'The insert failed.' );
			}
		);

		$thrown = false;
		try {
			Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		} catch ( RuntimeException $e ) {
			$thrown = true;
		}

		// As above: the locale change dropped the prompt CPT's taxonomies.
		Newspack_Popups::register_cpt();

		$this->assertTrue( $thrown, 'The insert threw.' );
		$this->assertSame( 1, $restored, 'And the request got its own locale back.' );
	}

	/**
	 * A pattern post that vanished — a migration, a direct query, a restored
	 * backup — is re-seeded rather than leaving instances pointing at a hole.
	 */
	public function test_reseeds_when_the_post_vanishes() {
		$id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$this->delete_pattern_post( $id );

		$new = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $new );
		$this->assertNotSame( $id, $new );
	}

	/**
	 * Opting out is a newsroom deciding against AI use, so the design it
	 * generated leaves the site: the pattern is unpublished, which takes it out
	 * of the patterns browser and leaves instances resolving to nothing.
	 */
	public function test_opting_out_takes_the_pattern_off_the_site() {
		$pattern_id = $this->opt_in();

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$this->assertNotSame( 'publish', get_post_status( $pattern_id ) );
	}

	/**
	 * But the pattern itself is kept: every prompt already published references it
	 * by id and carries its story-specific copy as an override on that reference,
	 * so a newsroom that pauses AI use and resumes months later gets the same
	 * pattern back, and with it the prompts it had written.
	 */
	public function test_opting_back_in_restores_the_same_pattern() {
		$pattern_id = $this->opt_in();
		$content    = get_post( $pattern_id )->post_content;
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		$this->assertSame( $pattern_id, (int) get_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID, 0 ) );
		$this->assertSame( 'publish', get_post_status( $pattern_id ) );
		$this->assertSame( $content, get_post( $pattern_id )->post_content, 'The design is what it was.' );
	}

	/**
	 * Withdrawing the opt-in altogether is opting out.
	 */
	public function test_withdrawing_the_opt_in_takes_the_pattern_off_the_site() {
		$pattern_id = $this->opt_in();

		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );

		$this->assertNotSame( 'publish', get_post_status( $pattern_id ) );
	}

	/**
	 * A site whose pattern is gone for good gets a fresh one on the next request
	 * for it, rather than an opt-in that answers with nothing.
	 */
	public function test_opting_back_in_seeds_when_the_pattern_is_gone() {
		$pattern_id = $this->opt_in();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );
		$this->delete_pattern_post( $pattern_id );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		$new = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertGreaterThan( 0, $new );
		$this->assertNotSame( $pattern_id, $new );
		$this->assertSame( 'publish', get_post_status( $new ) );
	}

	/**
	 * Sites that disable the trash delete on trash instead, which the deletion
	 * guard refuses — so the status is written directly there, and the pattern
	 * still leaves the site.
	 */
	public function test_a_site_without_a_trash_unpublishes_the_pattern_in_place() {
		$pattern_id = $this->opt_in();
		add_filter( 'pre_trash_post', '__return_false' );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		remove_filter( 'pre_trash_post', '__return_false' );
		$this->assertSame( 'draft', get_post_status( $pattern_id ) );
	}

	/**
	 * An opted-out site keeps its pattern in the trash, and core purges the trash
	 * on a schedule: losing it there would orphan every instance the site still
	 * carries, so nothing may delete the recorded pattern outright.
	 */
	public function test_the_recorded_pattern_cannot_be_deleted_outright() {
		$pattern_id = $this->opt_in();
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		// What core's scheduled purge calls on a post that has been in the trash
		// for longer than EMPTY_TRASH_DAYS.
		$this->assertFalse( wp_delete_post( $pattern_id ) );
		$this->assertFalse( wp_delete_post( $pattern_id, true ) );
		$this->assertInstanceOf( 'WP_Post', get_post( $pattern_id ) );

		$other = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );
		$this->assertNotFalse( wp_delete_post( $other, true ), 'Other synced patterns stay deletable.' );
	}

	/**
	 * The pattern is not a pattern the publisher composes with, and every action
	 * the list table offers on it is denied, so it is not listed there either.
	 * Other synced patterns are untouched, and the counts the status links are
	 * built from match what is listed.
	 */
	public function test_the_pattern_is_hidden_from_the_patterns_screen() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$pattern_id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$other      = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );
		set_current_screen( 'edit-wp_block' );

		// The screen's own query: only the main query is filtered.
		$previous                = $GLOBALS['wp_the_query'];
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$listed                  = wp_list_pluck( $query->query( [ 'post_type' => 'wp_block' ] ), 'ID' );
		$counts                  = wp_count_posts( 'wp_block' );
		$GLOBALS['wp_the_query'] = $previous;
		set_current_screen( 'front' );

		$this->assertNotContains( $pattern_id, $listed );
		$this->assertContains( $other, $listed, 'Other synced patterns stay listed.' );
		$this->assertSame( count( $listed ), (int) $counts->publish, 'And the count matches what is listed.' );
	}

	/**
	 * A reset is only worth offering where there is an edit to undo. Opening the
	 * pattern and saving it rewrites the markup in the editor's own order —
	 * attributes and class lists — which is not an edit to the design.
	 */
	public function test_a_design_still_as_shipped_reads_as_unmodified() {
		$pattern_id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();

		$this->assertFalse( Newspack_Popups_Contextual_Prompt_Pattern::is_design_modified() );

		// As the editor re-serializes it: same design, different spelling.
		$resaved = get_post( $pattern_id )->post_content;
		$resaved = str_replace( 'has-text-color has-dark-gray-color', 'has-dark-gray-color has-text-color', $resaved );
		$resaved = str_replace( 'has-text-color has-contrast-color', 'has-contrast-color has-text-color', $resaved );
		$resaved = str_replace(
			'{"metadata":{"name":"Contextual Prompt"},"className":"newspack-contextual-prompt"',
			'{"className":"newspack-contextual-prompt","metadata":{"name":"Contextual Prompt"}',
			$resaved
		);
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $pattern_id, $resaved );

		$this->assertFalse( Newspack_Popups_Contextual_Prompt_Pattern::is_design_modified(), 'A re-save is not a design change.' );
	}

	/**
	 * A real edit does read as one.
	 */
	public function test_an_edited_design_reads_as_modified() {
		$pattern_id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$edited     = str_replace( '#f7f7f7', '#ffee00', get_post( $pattern_id )->post_content );
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $pattern_id, $edited );

		$this->assertTrue( Newspack_Popups_Contextual_Prompt_Pattern::is_design_modified() );

		Newspack_Popups_Contextual_Prompt_Pattern::reset_pattern();

		$this->assertFalse( Newspack_Popups_Contextual_Prompt_Pattern::is_design_modified(), 'And the reset settles it.' );
	}

	/**
	 * A design edit that went wrong has no undo in the pattern editor once saved,
	 * and the pattern cannot be deleted and re-created, so the design can be put
	 * back to the one the plugin ships. The copy each prompt carries lives on its
	 * own instance and is untouched.
	 */
	public function test_the_design_can_be_reset() {
		$pattern_id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$seeded     = get_post( $pattern_id )->post_content;
		Newspack_Popups_Contextual_Prompt_Pattern::save_pattern_content( $pattern_id, '<!-- wp:paragraph --><p>Wrecked.</p><!-- /wp:paragraph -->' );
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_excerpt' => 'Edited description.',
			]
		);

		$this->assertTrue( Newspack_Popups_Contextual_Prompt_Pattern::reset_pattern() );

		$post = get_post( $pattern_id );
		$this->assertSame( $seeded, $post->post_content );
		$this->assertStringContainsString( 'The Contextual Prompt design used across the site.', $post->post_excerpt );
		$this->assertSame( $pattern_id, Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id(), 'The same pattern, so instances still resolve.' );
	}

	/**
	 * Block markup survives a write unchanged: the row is written directly, so
	 * the escapes serialize_blocks() emits go in as they are — there is no
	 * unslashing to survive, and slashing them would store the backslashes.
	 */
	public function test_pattern_content_round_trips_block_markup() {
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
		// Core passes the post itself as often as its id.
		$this->assertFalse( current_user_can( 'delete_post', get_post( $id ) ) );

		$other = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );
		$this->assertTrue( current_user_can( 'delete_post', $other ), 'Other synced patterns stay deletable.' );
	}

	/**
	 * The pattern is the design every prompt on the site renders, so editing it is
	 * an administrator's call: core reads the same capability to hide "Edit
	 * original" from an instance and to refuse the editor route outright. Other
	 * synced patterns stay editable by whoever could edit them before.
	 */
	public function test_only_administrators_may_edit_the_pattern() {
		$id    = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$other = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( current_user_can( 'edit_post', $id ) );
		$this->assertFalse( current_user_can( 'edit_post', get_post( $id ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $other ), 'Other synced patterns stay editable.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( current_user_can( 'edit_post', $id ) );
	}

	/**
	 * A post takes one prompt, placed from the Contextual Prompt panel, so the
	 * pattern is not offered in the patterns browser or the inserter. Only the
	 * collection is filtered: instances resolve their content through the
	 * single-item route, as does the editor that opens the pattern.
	 */
	public function test_the_pattern_is_hidden_from_the_patterns_collection() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$pattern_id = Newspack_Popups_Contextual_Prompt_Pattern::get_pattern_id();
		$other      = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );

		$collection = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/blocks' ) );
		$listed     = wp_list_pluck( $collection->get_data(), 'id' );

		$this->assertNotContains( $pattern_id, $listed );
		$this->assertContains( $other, $listed, 'Other synced patterns stay listed.' );

		$single = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/blocks/' . $pattern_id ) );
		$this->assertSame( $pattern_id, $single->get_data()['id'], 'The pattern still resolves by id.' );
	}

	/**
	 * Protection is not the feature's to switch off: with the flag rolled back the
	 * pattern would be deletable, and deleting it orphans every instance a
	 * re-enabled site still carries. So the gated init registers none of it, and
	 * the entry point Newspack_Popups::init() always calls registers all of it.
	 */
	public function test_protection_registers_outside_the_feature_gate() {
		$class = 'Newspack_Popups_Contextual_Prompt_Pattern';
		remove_all_filters( 'map_meta_cap' );
		remove_all_filters( 'block_editor_settings_all' );
		remove_all_filters( 'rest_wp_block_query' );

		Newspack_Popups_Contextual_Prompt_Pattern::init();

		$this->assertFalse( has_filter( 'map_meta_cap', [ $class, 'protect_pattern' ] ), 'The gated init registers no protection.' );
		$this->assertFalse( has_filter( 'block_editor_settings_all', [ $class, 'lock_pattern_editor' ] ) );
		$this->assertFalse( has_filter( 'rest_wp_block_query', [ $class, 'hide_pattern_from_collections' ] ) );

		Newspack_Popups_Contextual_Prompt_Pattern::init_protection();

		$this->assertNotFalse( has_filter( 'map_meta_cap', [ $class, 'protect_pattern' ] ) );
		$this->assertNotFalse( has_filter( 'block_editor_settings_all', [ $class, 'lock_pattern_editor' ] ) );
		$this->assertNotFalse( has_filter( 'rest_wp_block_query', [ $class, 'hide_pattern_from_collections' ] ) );
	}

	/**
	 * Which is only safe because every protection callback reads the record raw
	 * and no-ops without one: a site that never seeded a pattern carries the hooks
	 * and notices nothing.
	 */
	public function test_protection_leaves_a_site_with_no_pattern_alone() {
		delete_option( Newspack_Popups_Contextual_Prompt_Pattern::OPTION_PATTERN_ID );
		$post = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );

		$this->assertSame(
			[ 'delete_posts' ],
			Newspack_Popups_Contextual_Prompt_Pattern::protect_pattern( [ 'delete_posts' ], 'delete_post', 0, [ $post ] )
		);
		$this->assertArrayNotHasKey(
			'post__not_in',
			Newspack_Popups_Contextual_Prompt_Pattern::hide_pattern_from_collections( [ 'post_type' => 'wp_block' ] ),
			'Nothing is excluded from a collection.'
		);
		$this->assertArrayNotHasKey(
			'canLockBlocks',
			Newspack_Popups_Contextual_Prompt_Pattern::lock_pattern_editor( [], new WP_Block_Editor_Context( [ 'post' => get_post( $post ) ] ) )
		);
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
