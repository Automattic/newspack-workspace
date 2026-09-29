<?php
/**
 * Class Model Test
 *
 * @package Newspack_Popups
 */

/**
 * Model test case.
 */
class ModelTest extends WP_UnitTestCase {
	private static $popup_id = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public static function wpSetUpBeforeClass() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		self::$popup_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => 'Platea fames',
				'post_content' => 'Faucibus placerat senectus.',
			]
		);
	}

	/**
	 * Test popup object creation.
	 */
	public function test_popup_object_creation() {
		$popup_object_default = Newspack_Popups_Model::create_popup_object( get_post( self::$popup_id ) );
		self::assertEquals(
			$popup_object_default['options'],
			[
				'background_color'               => '#FFFFFF',
				'hide_border'                    => false,
				'large_border'                   => false,
				'no_padding'                     => false,
				'frequency'                      => 'always',
				'frequency_max'                  => 0,
				'frequency_start'                => 0,
				'frequency_between'              => 0,
				'frequency_reset'                => 'month',
				'close_button_background_color'  => '#00000000',
				'enable_close_button_background' => false,
				'overlay_color'                  => '#000000',
				'overlay_opacity'                => '30',
				'overlay_size'                   => 'medium',
				'no_overlay_background'          => false,
				'placement'                      => 'inline',
				'trigger_type'                   => 'scroll',
				'trigger_delay'                  => '3',
				'trigger_scroll_progress'        => '30',
				'trigger_blocks_count'           => 0,
				'archive_insertion_posts_count'  => 1,
				'archive_insertion_is_repeating' => false,
				'utm_suppression'                => null,
				'post_types'                     => [ 'post', 'page' ],
				'archive_page_types'             => [ 'home', 'category', 'tag', 'author', 'date', 'post-type', 'taxonomy' ],
				'additional_classes'             => '',
				'excluded_categories'            => [],
				'excluded_tags'                  => [],
			],
			'Default options are as expected.'
		);

		$popup_object = Newspack_Popups_Model::create_popup_object(
			get_post( self::$popup_id ),
			false,
			[
				'trigger_type'            => 'scroll',
				'trigger_scroll_progress' => '42',
			]
		);
		self::assertEquals(
			$popup_object['options']['trigger_scroll_progress'],
			'42',
			'Sets options when passed as argument.'
		);

		$popup_object_blocks_count_basis = Newspack_Popups_Model::create_popup_object(
			get_post( self::$popup_id ),
			false,
			[
				'trigger_type'         => 'blocks_count',
				'trigger_blocks_count' => '5',
			]
		);
		self::assertEquals(
			$popup_object_blocks_count_basis['options']['trigger_blocks_count'],
			'5',
			'Sets options when passed as argument.'
		);
	}

	/**
	 * Test popup markup generation.
	 */
	public function test_markup_generation() {
		Newspack_Popups_Model::set_popup_options(
			self::$popup_id,
			[
				'placement'    => 'center',
				'trigger_type' => 'time',
			]
		);

		$popup_object_default = Newspack_Popups_Model::create_popup_object( get_post( self::$popup_id ) );

		$dom = new DomDocument();
		@$dom->loadHTML( Newspack_Popups_Model::generate_popup( $popup_object_default ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$xpath = new DOMXpath( $dom );

		self::assertEquals(
			0,
			$xpath->query( '//*[starts-with(@id,"page-position-marker")]' )->length,
			'The page position marker is not output for a default (time-triggered) popup.'
		);

		$popup_object_with_just_scroll = Newspack_Popups_Model::create_popup_object(
			get_post( self::$popup_id ),
			false,
			[
				'placement'    => 'center',
				'trigger_type' => 'scroll',
			]
		);

		$dom = new DomDocument();
		@$dom->loadHTML( Newspack_Popups_Model::generate_popup( $popup_object_with_just_scroll ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$xpath = new DOMXpath( $dom );

		self::assertStringContainsString(
			'top: 0%',
			$xpath->query( '//*[starts-with(@id,"page-position-marker")]' )->item( 0 )->getAttribute( 'style' ),
			'The position marker is set at 0% by default.'
		);

		$popup_object_with_set_scroll_progress = Newspack_Popups_Model::create_popup_object(
			get_post( self::$popup_id ),
			false,
			[
				'placement'               => 'center',
				'trigger_type'            => 'scroll',
				'trigger_scroll_progress' => 42,
			]
		);

		$dom = new DomDocument();
		@$dom->loadHTML( Newspack_Popups_Model::generate_popup( $popup_object_with_set_scroll_progress ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$xpath = new DOMXpath( $dom );

		self::assertStringContainsString(
			'top: 42%',
			$xpath->query( '//*[starts-with(@id,"page-position-marker")]' )->item( 0 )->getAttribute( 'style' ),
			'The position marker is set at position passed in options.'
		);

		Newspack_Popups_Model::set_block_theme_override( true );
		try {
			$popup_object_inline_block_theme = Newspack_Popups_Model::create_popup_object(
				get_post( self::$popup_id ),
				false,
				[
					'placement'    => 'inline',
					'trigger_type' => 'time',
				]
			);

			$dom = new DomDocument();
			@$dom->loadHTML( Newspack_Popups_Model::generate_popup( $popup_object_inline_block_theme ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$xpath = new DOMXpath( $dom );

			$inline_container = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " newspack-inline-popup ")]' )->item( 0 );
			self::assertNotNull(
				$inline_container,
				'Inline popup container is present for block theme.'
			);
			self::assertStringContainsString(
				'is-layout-constrained',
				$inline_container->getAttribute( 'class' ),
				'Inline popups receive block theme layout class.'
			);

			$popup_object_overlay_block_theme = Newspack_Popups_Model::create_popup_object(
				get_post( self::$popup_id ),
				false,
				[
					'placement'    => 'center',
					'trigger_type' => 'time',
				]
			);

			$dom = new DomDocument();
			@$dom->loadHTML( Newspack_Popups_Model::generate_popup( $popup_object_overlay_block_theme ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$xpath = new DOMXpath( $dom );

			$content = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " newspack-popup__content ")]' )->item( 0 );
			self::assertNotNull(
				$content,
				'Overlay popup content container is present for block theme.'
			);
			self::assertStringContainsString(
				'is-layout-flow',
				$content->getAttribute( 'class' ),
				'Overlay popup content receives block theme layout class.'
			);
		} finally {
			Newspack_Popups_Model::set_block_theme_override( null );
		}
	}

	/**
	 * Tests retrieve_popup_by_id
	 */
	public function test_retrieve_popup_by_id() {
		$popup = Newspack_Popups_Model::retrieve_popup_by_id( self::$popup_id );
		self::assertSame( self::$popup_id, $popup['id'], 'Unable to retrieve popup by id.' );

		$popup = Newspack_Popups_Model::retrieve_popup_by_id( self::$popup_id, true );
		self::assertSame( self::$popup_id, $popup['id'], 'Unable to retrieve popup by id.' );

		$popup = Newspack_Popups_Model::retrieve_popup_by_id( self::$popup_id, false, true );
		self::assertSame( self::$popup_id, $popup['id'], 'Unable to retrieve popup by id.' );

		$draf_prompt = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => 'Platea fames',
				'post_content' => 'Faucibus placerat senectus.',
				'post_status'  => 'draft',
			]
		);

		$popup = Newspack_Popups_Model::retrieve_popup_by_id( $draf_prompt );
		self::assertNull( $popup, 'Draft prompt should not be returned unless explictly required.' );

		$popup = Newspack_Popups_Model::retrieve_popup_by_id( $draf_prompt, false, true );
		self::assertNotNull( $popup, 'Unable to retrieve popup by id.' );
		self::assertSame( $draf_prompt, $popup['id'], 'Unable to retrieve popup by id.' );
	}

	/**
	 * Tests retrieve_preview_popup with a post id that does not resolve to a post.
	 *
	 * Production sites get hit with bot traffic carrying garbage `pp` query params,
	 * which previously fataled at `$post_object->ID = $post_id` when both
	 * wp_get_post_autosave() and get_post() returned null.
	 */
	public function test_retrieve_preview_popup_with_invalid_id() {
		self::assertNull(
			Newspack_Popups_Model::retrieve_preview_popup( 'definitely-not-a-post-id' ),
			'Invalid preview ids return null instead of fataling.'
		);
		self::assertNull(
			Newspack_Popups_Model::retrieve_preview_popup( 999999999 ),
			'Numeric ids that do not match any post return null.'
		);
	}

	/**
	 * Creates an autosave revision for a prompt with the given content and date.
	 *
	 * WordPress's wp_insert_post() sets a new post's post_modified to its post_date, so the
	 * date passed here also becomes the autosave's modified time — letting tests
	 * control autosave-vs-saved ordering deterministically without sleeping or
	 * writing to the database directly.
	 *
	 * @param int    $popup_id Parent prompt ID.
	 * @param string $content  Autosave post_content.
	 * @param string $date_gmt GMT datetime string for the autosave.
	 * @return int Autosave revision ID.
	 */
	private function create_autosave( $popup_id, $content, $date_gmt ) {
		return wp_insert_post(
			[
				'post_type'     => 'revision',
				'post_status'   => 'inherit',
				'post_parent'   => $popup_id,
				'post_name'     => "{$popup_id}-autosave-v1",
				'post_content'  => $content,
				'post_author'   => get_current_user_id(),
				'post_date'     => $date_gmt,
				'post_date_gmt' => $date_gmt,
			]
		);
	}

	/**
	 * NPPM-2940: after a full save, the editor's stale autosave can be OLDER
	 * than the saved post (Gutenberg's autosave() no-ops on a clean post). The
	 * preview must render the freshly-saved post, not the older autosave whose
	 * blocks were since removed.
	 */
	public function test_preview_prefers_saved_post_when_newer_than_autosave() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$popup_id = self::factory()->post->create(
			[
				'post_type'     => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_content'  => '<!-- wp:paragraph --><p>Kept body.</p><!-- /wp:paragraph -->',
				'post_date'     => '2021-01-02 00:00:00',
				'post_date_gmt' => '2021-01-02 00:00:00',
			]
		);

		$this->create_autosave(
			$popup_id,
			'<!-- wp:heading --><h3>Removed heading</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Kept body.</p><!-- /wp:paragraph -->',
			'2021-01-01 00:00:00'
		);

		$preview = Newspack_Popups_Model::retrieve_preview_popup( $popup_id );
		self::assertStringNotContainsString(
			'Removed heading',
			$preview['content'],
			'Preview must render the freshly-saved post, not a stale (older) autosave whose block was removed (NPPM-2940).'
		);
	}

	/**
	 * Guard the normal preview flow: while the editor has unsaved changes the
	 * autosave is NEWER than the saved post, so the preview must show it.
	 */
	public function test_preview_uses_autosave_when_newer_than_saved_post() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$popup_id = self::factory()->post->create(
			[
				'post_type'     => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_content'  => '<!-- wp:paragraph --><p>Saved body.</p><!-- /wp:paragraph -->',
				'post_date'     => '2021-01-01 00:00:00',
				'post_date_gmt' => '2021-01-01 00:00:00',
			]
		);

		$this->create_autosave(
			$popup_id,
			'<!-- wp:paragraph --><p>Unsaved edit.</p><!-- /wp:paragraph -->',
			'2021-01-02 00:00:00'
		);

		$preview = Newspack_Popups_Model::retrieve_preview_popup( $popup_id );
		self::assertStringContainsString(
			'Unsaved edit.',
			$preview['content'],
			'Preview must render the newer autosave so editors see their unsaved changes.'
		);
	}

	/**
	 * NPPM-2940 boundary: when the autosave and the saved post share the same
	 * (one-second-resolution) post_modified_gmt, the saved post wins. Preferring
	 * the saved post on a tie is the conservative choice — it can never resurface
	 * removed-then-saved content, which is the bug this fix targets.
	 */
	public function test_preview_prefers_saved_post_on_timestamp_tie() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$popup_id = self::factory()->post->create(
			[
				'post_type'     => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_content'  => '<!-- wp:paragraph --><p>Saved tie body.</p><!-- /wp:paragraph -->',
				'post_date'     => '2021-01-01 00:00:00',
				'post_date_gmt' => '2021-01-01 00:00:00',
			]
		);

		$this->create_autosave(
			$popup_id,
			'<!-- wp:heading --><h3>Tie autosave heading</h3><!-- /wp:heading -->',
			'2021-01-01 00:00:00'
		);

		$preview = Newspack_Popups_Model::retrieve_preview_popup( $popup_id );
		self::assertStringContainsString(
			'Saved tie body.',
			$preview['content'],
			'On an equal post_modified_gmt tie, the preview must render the saved post.'
		);
		self::assertStringNotContainsString(
			'Tie autosave heading',
			$preview['content'],
			'On a tie the autosave must not win, to avoid resurfacing removed-then-saved content (NPPM-2940).'
		);
	}

	/**
	 * An orphaned autosave — one whose saved post no longer resolves — yields no
	 * preview. The post-type gate runs first and cannot confirm a missing post is
	 * a prompt, so it denies before the autosave fallback is reached. The gate is
	 * the stronger claim of the two and deliberately wins here.
	 */
	public function test_preview_denies_orphan_autosave_without_saved_post() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// Advance the auto-increment, then target an id with no corresponding post.
		$existing_id = self::factory()->post->create( [ 'post_type' => Newspack_Popups::NEWSPACK_POPUPS_CPT ] );
		$ghost_id    = $existing_id + 100000;

		$this->create_autosave(
			$ghost_id,
			'<!-- wp:paragraph --><p>Orphan autosave body.</p><!-- /wp:paragraph -->',
			'2021-01-01 00:00:00'
		);

		self::assertNull( get_post( $ghost_id ), 'Precondition: the saved post must not resolve.' );

		self::assertNull(
			Newspack_Popups_Model::retrieve_preview_popup( $ghost_id ),
			'A prompt whose saved post is gone cannot be confirmed as a prompt, so no preview is rendered.'
		);
	}

	/**
	 * The common case: a saved prompt with no autosave at all. wp_get_post_autosave()
	 * returns false, so the preview renders the saved post unconditionally.
	 */
	public function test_preview_uses_saved_post_when_no_autosave_exists() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$popup_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_content' => '<!-- wp:paragraph --><p>Saved body.</p><!-- /wp:paragraph -->',
			]
		);
		self::assertFalse( wp_get_post_autosave( $popup_id ), 'Precondition: the prompt must have no autosave.' );

		$preview = Newspack_Popups_Model::retrieve_preview_popup( $popup_id );
		self::assertStringContainsString(
			'Saved body.',
			$preview['content'],
			'With no autosave, the preview must render the saved post.'
		);
	}

	/**
	 * A preview should only load the prompts CPT, not arbitrary post types.
	 */
	public function test_retrieve_preview_popup_denies_non_prompt_post_type() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$draft_id = self::factory()->post->create(
			[
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_content' => 'Unpublished draft body.',
			]
		);
		self::assertNull(
			Newspack_Popups_Model::retrieve_preview_popup( $draft_id ),
			'A preview must not load a non-prompt post, even for an admin.'
		);
	}

	/**
	 * A user who can manage prompts can still preview a prompt draft.
	 */
	public function test_retrieve_preview_popup_allows_admin_for_prompt() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$popup_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'  => 'draft',
				'post_content' => 'Prompt draft body.',
			]
		);
		self::assertNotNull(
			Newspack_Popups_Model::retrieve_preview_popup( $popup_id ),
			'A user who can manage prompts must be able to preview a prompt draft.'
		);
	}

	/**
	 * A logged-out visitor must not preview an unpublished prompt.
	 *
	 * Isolates the capability gate: the post is the prompts CPT, so only the
	 * capability check (not the post-type check) can deny it.
	 */
	public function test_retrieve_preview_popup_denies_logged_out_user_for_prompt() {
		wp_set_current_user( 0 );
		$prompt_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'  => 'draft',
				'post_content' => 'Unpublished prompt body.',
			]
		);
		self::assertNull(
			Newspack_Popups_Model::retrieve_preview_popup( $prompt_id ),
			'A logged-out visitor must not be able to preview an unpublished prompt.'
		);
	}

	/**
	 * A non-admin-role user who can manage prompts can still preview a prompt draft.
	 */
	public function test_retrieve_preview_popup_allows_non_admin_prompt_manager() {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );
		$prompt_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'  => 'draft',
				'post_content' => 'Prompt draft body.',
			]
		);
		self::assertNotNull(
			Newspack_Popups_Model::retrieve_preview_popup( $prompt_id ),
			'A non-admin user who can manage prompts must be able to preview a prompt draft.'
		);
	}

	/**
	 * Tests that an invalid `pp` query param does not produce a popup list with null entries,
	 * which would cascade to "Trying to access array offset on null" warnings downstream.
	 */
	public function test_popups_for_post_with_invalid_preview_id() {
		$_GET['pp'] = 'definitely-not-a-post-id';
		try {
			self::assertSame(
				[],
				Newspack_Popups_Inserter::popups_for_post(),
				'Invalid preview ids result in an empty popup list, not [ null ].'
			);
		} finally {
			unset( $_GET['pp'] );
		}
	}

	/**
	 * Tests fetching default settings.
	 */
	public function test_settings() {
		\delete_option( 'newspack_popups_mc_donor_merge_field' );
		$setting = Newspack_Popups_Settings::get_setting( 'newspack_popups_mc_donor_merge_field' );

		self::assertSame( $setting, 'DONAT' );
	}
}
