<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Block Theme Header Insertion Test
 *
 * @package Newspack_Popups
 */

/**
 * Block theme header insertion test case.
 */
class BlockThemeHeaderInsertionTest extends WP_UnitTestCase_PageWithPopups {
	/**
	 * Original theme stylesheet.
	 *
	 * @var string
	 */
	private $original_theme_stylesheet;

	/**
	 * Reflection helper for protected inserter popups cache.
	 *
	 * @var ReflectionProperty
	 */
	private static $inserter_popups_property;

	/**
	 * Reflection helper for private before-header render guard.
	 *
	 * @var ReflectionProperty
	 */
	private static $header_template_part_has_rendered_property;


	/**
	 * Set up each test.
	 */
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();

		$this->original_theme_stylesheet = get_stylesheet();
		$block_theme_stylesheet          = $this->get_any_block_theme_stylesheet();
		if ( ! $block_theme_stylesheet ) {
			$this->markTestSkipped( 'No block theme is available in this test environment.' );
		}
		switch_theme( $block_theme_stylesheet );

		if ( ! self::$inserter_popups_property ) {
			self::$inserter_popups_property = new ReflectionProperty( 'Newspack_Popups_Inserter', 'popups' );
			self::$inserter_popups_property->setAccessible( true );
		}
		if ( ! self::$header_template_part_has_rendered_property ) {
			self::$header_template_part_has_rendered_property = new ReflectionProperty( 'Newspack_Popups_Inserter', 'header_template_part_has_rendered' );
			self::$header_template_part_has_rendered_property->setAccessible( true );
		}
		self::$inserter_popups_property->setValue( null, [] );
		self::$header_template_part_has_rendered_property->setValue( null, false );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		if ( $this->original_theme_stylesheet ) {
			switch_theme( $this->original_theme_stylesheet );
		}
		if ( self::$inserter_popups_property ) {
			self::$inserter_popups_property->setValue( null, [] );
		}
		if ( self::$header_template_part_has_rendered_property ) {
			self::$header_template_part_has_rendered_property->setValue( null, false );
		}
		parent::tear_down();
	}

	/**
	 * Find any installed block theme stylesheet slug.
	 *
	 * @return string|null Theme stylesheet, or null if unavailable.
	 */
	private function get_any_block_theme_stylesheet() {
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme() ) {
				return $stylesheet;
			}
		}
		return null;
	}

	/**
	 * Build a basic header template-part block shape.
	 *
	 * @return array
	 */
	private function get_header_template_part_block() {
		return [
			'blockName' => 'core/template-part',
			'attrs'     => [
				'area' => 'header',
				'slug' => 'header',
			],
		];
	}

	/**
	 * Prepare inserter cache with popup objects for deterministic tests.
	 *
	 * @param array $popups Popup objects.
	 */
	private function seed_inserter_popups( $popups ) {
		self::$inserter_popups_property->setValue( null, $popups );
		self::$header_template_part_has_rendered_property->setValue( null, false );
	}

	/**
	 * Block themes should prepend the prompt to header template-part render output once.
	 */
	public function test_inserts_once_for_header_template_part() {
		$popup_id = self::createPopup(
			'Block theme header prompt',
			[
				'placement' => 'above_header',
				'frequency' => 'always',
			]
		);
		$popup    = Newspack_Popups_Model::retrieve_popup_by_id( $popup_id );
		$this->seed_inserter_popups( [ $popup ] );

		$block_content = '<div class="wp-block-template-part">header content</div>';
		$block         = $this->get_header_template_part_block();

		$first = Newspack_Popups_Inserter::insert_before_header_in_template_part( $block_content, $block );
		$this->assertStringContainsString( 'Block theme header prompt', $first, 'Header template part is prepended with prompt markup.' );
		$this->assertStringEndsWith( $block_content, $first, 'Original header template-part output remains after prepended markup.' );

		$second = Newspack_Popups_Inserter::insert_before_header_in_template_part( $block_content, $block );
		$this->assertSame( $block_content, $second, 'Prompt is not inserted again after first render.' );
	}

	/**
	 * Non-header template parts should not be modified.
	 */
	public function test_does_not_insert_for_non_header_template_part() {
		$popup_id = self::createPopup(
			'Should not render here',
			[
				'placement' => 'above_header',
				'frequency' => 'always',
			]
		);
		$popup    = Newspack_Popups_Model::retrieve_popup_by_id( $popup_id );
		$this->seed_inserter_popups( [ $popup ] );

		$block_content = '<div class="wp-block-template-part">footer content</div>';
		$block         = [
			'blockName' => 'core/template-part',
			'attrs'     => [
				'area' => 'footer',
				'slug' => 'footer',
			],
		];

		$result = Newspack_Popups_Inserter::insert_before_header_in_template_part( $block_content, $block );
		$this->assertSame( $block_content, $result, 'Only header template-part blocks should be modified.' );
	}

	/**
	 * Header-like slug fallback should insert when area is missing.
	 */
	public function test_inserts_for_slug_only_header_match() {
		$popup_id = self::createPopup(
			'Slug fallback prompt',
			[
				'placement' => 'above_header',
				'frequency' => 'always',
			]
		);
		$popup    = Newspack_Popups_Model::retrieve_popup_by_id( $popup_id );
		$this->seed_inserter_popups( [ $popup ] );

		$block_content = '<div class="wp-block-template-part">header content</div>';
		$variants      = [ 'header-post', 'site-header' ];

		foreach ( $variants as $slug ) {
			self::$header_template_part_has_rendered_property->setValue( null, false );

			$block = [
				'blockName' => 'core/template-part',
				'attrs'     => [
					'slug' => $slug,
				],
			];

			$result = Newspack_Popups_Inserter::insert_before_header_in_template_part( $block_content, $block );
			$this->assertStringContainsString(
				'Slug fallback prompt',
				$result,
				sprintf( 'Slug "%s" should match header fallback regex.', $slug )
			);
		}
	}

	/**
	 * Slugs that merely contain "headers" should not match the header fallback regex.
	 */
	public function test_does_not_insert_for_slug_only_non_match() {
		$popup_id = self::createPopup(
			'Should not render for headers slug',
			[
				'placement' => 'above_header',
				'frequency' => 'always',
			]
		);
		$popup    = Newspack_Popups_Model::retrieve_popup_by_id( $popup_id );
		$this->seed_inserter_popups( [ $popup ] );

		$block_content = '<div class="wp-block-template-part">not header content</div>';
		$block         = [
			'blockName' => 'core/template-part',
			'attrs'     => [
				'slug' => 'my-headers-archive',
			],
		];

		$result = Newspack_Popups_Inserter::insert_before_header_in_template_part( $block_content, $block );
		$this->assertSame( $block_content, $result, 'Slug "my-headers-archive" should not match header fallback regex.' );
	}

	/**
	 * Overlay specificity ordering should be preserved in block theme path.
	 */
	public function test_overlay_specificity_order_is_preserved() {
		$generic_overlay_id = self::createPopup(
			'Generic overlay',
			[
				'placement'    => 'center',
				'trigger_type' => 'time',
				'frequency'    => 'always',
			]
		);
		$segment_overlay_id = self::createPopup(
			'Segment overlay',
			[
				'placement'    => 'center',
				'trigger_type' => 'time',
				'frequency'    => 'always',
			]
		);

		$generic_overlay              = Newspack_Popups_Model::retrieve_popup_by_id( $generic_overlay_id );
		$segment_specific_overlay     = Newspack_Popups_Model::retrieve_popup_by_id( $segment_overlay_id );
		$segment_specific_overlay['segments'] = [ [ 'id' => 123 ] ];

		// Seed in reverse order; segment-specific should still render first.
		$this->seed_inserter_popups( [ $generic_overlay, $segment_specific_overlay ] );

		$block_content = '<div class="wp-block-template-part">header content</div>';
		$block         = $this->get_header_template_part_block();
		$result        = Newspack_Popups_Inserter::insert_before_header_in_template_part( $block_content, $block );

		$segment_pos = strpos( $result, 'Segment overlay' );
		$generic_pos = strpos( $result, 'Generic overlay' );

		$this->assertNotFalse( $segment_pos, 'Segment-specific overlay should be rendered.' );
		$this->assertNotFalse( $generic_pos, 'Generic overlay should be rendered.' );
		$this->assertLessThan( $generic_pos, $segment_pos, 'Segment-specific overlay should render before generic overlay.' );
	}
}
