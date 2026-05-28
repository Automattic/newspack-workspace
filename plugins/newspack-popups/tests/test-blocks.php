<?php
/**
 * Class Blocks Test
 *
 * @package Newspack_Popups
 */

/**
 * Blocks test case.
 */
class BlocksTest extends WP_UnitTestCase {
	/**
	 * Prompt shortcode render filter callback.
	 *
	 * @var callable|null
	 */
	private $prompt_render_filter = null;

	/**
	 * Custom placement shortcode render filter callback.
	 *
	 * @var callable|null
	 */
	private $custom_render_filter = null;

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();

		// Remove any popups (from previous tests).
		foreach ( Newspack_Popups_Model::retrieve_popups() as $popup ) {
			wp_delete_post( $popup['id'] );
		}
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( $this->prompt_render_filter ) {
			remove_filter( 'newspack_popups_render_prompt_shortcode', $this->prompt_render_filter );
		}
		if ( $this->custom_render_filter ) {
			remove_filter( 'newspack_popups_render_custom_placement_shortcode', $this->custom_render_filter );
		}
		$this->prompt_render_filter = null;
		$this->custom_render_filter = null;

		wp_reset_postdata();
		unset( $GLOBALS['post'] );
		parent::tear_down();
	}

	/**
	 * Force shortcode render behavior in tests.
	 *
	 * @param bool $prompt_value Render prompt shortcode output.
	 * @param bool $custom_value Render custom placement shortcode output.
	 */
	private function set_shortcode_render_filters( $prompt_value, $custom_value ) {
		$this->prompt_render_filter = function() use ( $prompt_value ) {
			return $prompt_value;
		};
		$this->custom_render_filter = function() use ( $custom_value ) {
			return $custom_value;
		};
		add_filter( 'newspack_popups_render_prompt_shortcode', $this->prompt_render_filter, 10, 3 );
		add_filter( 'newspack_popups_render_custom_placement_shortcode', $this->custom_render_filter, 10, 5 );
	}

	/**
	 * Create and set a post as the current global post.
	 *
	 * @return int Post ID.
	 */
	private function set_current_post() {
		$post_id        = self::factory()->post->create();
		$post           = get_post( $post_id );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		return $post_id;
	}

	/**
	 * Create a popup.
	 *
	 * @param object $options Popup options.
	 */
	private function create_popup( $options = [] ) {
		$popup_id = self::factory()->post->create(
			[
				'post_type'    => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_title'   => 'Popup title',
				'post_content' => 'Hello, world.',
			]
		);
		Newspack_Popups_Model::set_popup_options( $popup_id, $options );
		return $popup_id;
	}

	/**
	 * Basic Block rendering - Single Prompt block.
	 */
	public function test_prompt_block_rendering() {
		$this->set_shortcode_render_filters( false, false );

		$inline_popup_id       = self::create_popup( [ 'placement' => 'inline' ] );
		$overlay_popup_id      = self::create_popup( [ 'placement' => 'center' ] );
		$inline_block_content  = Newspack_Popups\Prompt_Block\render_block( [ 'promptId' => $inline_popup_id ] );
		$overlay_block_content = Newspack_Popups\Prompt_Block\render_block( [ 'promptId' => $overlay_popup_id ] );

		self::assertEquals(
			$inline_block_content,
			'<!-- wp:shortcode -->[newspack-popup id="' . $inline_popup_id . '"]<!-- /wp:shortcode -->',
			'Includes inline popup shortcode.'
		);

		self::assertEquals(
			$overlay_block_content,
			'',
			'Overlay prompt not rendered by the Single Prompt block.'
		);

		$inline_block_content = Newspack_Popups\Prompt_Block\render_block(
			[
				'promptId'  => $inline_popup_id,
				'className' => 'custom-class',
			]
		);

		self::assertEquals(
			$inline_block_content,
			'<!-- wp:shortcode -->[newspack-popup id="' . $inline_popup_id . '" class="custom-class"]<!-- /wp:shortcode -->',
			'Includes inline popup shortcode.'
		);
	}

	/**
	 * Basic Block rendering - Custom Placement block.
	 */
	public function test_custom_placement_block_rendering() {
		$this->set_shortcode_render_filters( false, false );

		$custom_placement_id = 'custom1';
		$popup_id            = self::create_popup( [ 'placement' => $custom_placement_id ] );
		$block_content       = Newspack_Popups\Custom_Placement_Block\render_block( [ 'customPlacement' => $custom_placement_id ] );

		self::assertEquals(
			$block_content,
			'<!-- wp:shortcode -->[newspack-popup id="' . $popup_id . '"]<!-- /wp:shortcode -->',
			'Includes popup shortcode.'
		);

		$block_content = Newspack_Popups\Custom_Placement_Block\render_block(
			[
				'customPlacement' => $custom_placement_id,
				'className'       => 'custom-class',
			]
		);

		self::assertEquals(
			$block_content,
			'<!-- wp:shortcode -->[newspack-popup id="' . $popup_id . '" class="custom-class"]<!-- /wp:shortcode -->',
			'Includes popup shortcode.'
		);
	}

	/**
	 * Block rendering with conflicting popups.
	 */
	public function test_block_rendering_with_conflict() {
		$this->set_shortcode_render_filters( false, false );

		$custom_placement_id = 'custom1';
		$popup_id_first      = self::create_popup( [ 'placement' => $custom_placement_id ] );
		sleep( 1 ); // Ensure the creation dates are not the same.
		$popup_id_second = self::create_popup( [ 'placement' => $custom_placement_id ] );
		$block_content   = Newspack_Popups\Custom_Placement_Block\render_block( [ 'customPlacement' => $custom_placement_id ] );

		self::assertEquals(
			$block_content,
			'<!-- wp:shortcode -->[newspack-popup id="' . $popup_id_second . '"]<!-- /wp:shortcode --><!-- wp:shortcode -->[newspack-popup id="' . $popup_id_first . '"]<!-- /wp:shortcode -->',
			'Includes all popup shortcodes in case of a conflict, since the API will decide what to show.'
		);
	}

	/**
	 * Block rendering - Single Prompt block (block themes render shortcode).
	 */
	public function test_prompt_block_rendering_block_theme() {
		$this->set_shortcode_render_filters( true, true );
		$this->set_current_post();

		$inline_popup_id       = self::create_popup( [ 'placement' => 'inline' ] );
		$overlay_popup_id      = self::create_popup( [ 'placement' => 'center' ] );
		$inline_block_content  = Newspack_Popups\Prompt_Block\render_block( [ 'promptId' => $inline_popup_id ] );
		$overlay_block_content = Newspack_Popups\Prompt_Block\render_block( [ 'promptId' => $overlay_popup_id ] );

		self::assertStringContainsString( '<aside', $inline_block_content, 'Renders inline popup HTML.' );
		self::assertStringContainsString( 'newspack-popup-container', $inline_block_content, 'Includes popup container markup.' );
		self::assertStringNotContainsString( '<!-- wp:shortcode -->', $inline_block_content, 'Does not return a shortcode block in block themes.' );
		self::assertEquals( '', $overlay_block_content, 'Overlay prompt not rendered by the Single Prompt block.' );

		$inline_block_content = Newspack_Popups\Prompt_Block\render_block(
			[
				'promptId'  => $inline_popup_id,
				'className' => 'custom-class',
			]
		);

		self::assertStringContainsString( 'class="custom-class"', $inline_block_content, 'Includes custom class on the wrapper element.' );
	}

	/**
	 * Block rendering - Custom Placement block (block themes render shortcode).
	 */
	public function test_custom_placement_block_rendering_block_theme() {
		$this->set_shortcode_render_filters( true, true );
		$this->set_current_post();

		$custom_placement_id = 'custom1';
		$popup_id            = self::create_popup( [ 'placement' => $custom_placement_id ] );
		$block_content       = Newspack_Popups\Custom_Placement_Block\render_block( [ 'customPlacement' => $custom_placement_id ] );

		self::assertStringContainsString( '<aside', $block_content, 'Renders popup HTML.' );
		self::assertStringContainsString( 'newspack-popup-container', $block_content, 'Includes popup container markup.' );
		self::assertStringNotContainsString( '<!-- wp:shortcode -->', $block_content, 'Does not return a shortcode block in block themes.' );

		$block_content = Newspack_Popups\Custom_Placement_Block\render_block(
			[
				'customPlacement' => $custom_placement_id,
				'className'       => 'custom-class',
			]
		);

		self::assertStringContainsString( 'class="custom-class"', $block_content, 'Includes custom class on the wrapper element.' );
	}

	/**
	 * Block rendering with conflicting popups (block themes render shortcode).
	 */
	public function test_block_rendering_with_conflict_block_theme() {
		$this->set_shortcode_render_filters( true, true );
		$this->set_current_post();

		$custom_placement_id = 'custom1';
		$popup_id_first      = self::create_popup( [ 'placement' => $custom_placement_id ] );
		sleep( 1 ); // Ensure the creation dates are not the same.
		$popup_id_second = self::create_popup( [ 'placement' => $custom_placement_id ] );
		$block_content   = Newspack_Popups\Custom_Placement_Block\render_block( [ 'customPlacement' => $custom_placement_id ] );

		self::assertStringContainsString( 'newspack-popup-container', $block_content, 'Includes popup container markup.' );
		self::assertStringContainsString( Newspack_Popups_Model::canonize_popup_id( $popup_id_first ), $block_content, 'Includes first popup markup.' );
		self::assertStringContainsString( Newspack_Popups_Model::canonize_popup_id( $popup_id_second ), $block_content, 'Includes second popup markup.' );
		self::assertStringNotContainsString( '<!-- wp:shortcode -->', $block_content, 'Does not return shortcode blocks in block themes.' );
	}
}
