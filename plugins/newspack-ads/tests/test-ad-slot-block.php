<?php
/**
 * Tests for the Ad Slot block.
 *
 * @package Newspack_Ads\Tests
 */

/**
 * Ad Slot block tests.
 */
class AdSlotBlockTest extends WP_UnitTestCase {

	/**
	 * The block should be registered with WordPress.
	 */
	public function test_block_is_registered() {
		$registry = WP_Block_Type_Registry::get_instance();
		self::assertTrue(
			$registry->is_registered( 'newspack-ads/ad-slot' ),
			'newspack-ads/ad-slot block should be registered'
		);
	}

	/**
	 * An empty placement attribute renders nothing.
	 */
	public function test_render_with_empty_placement() {
		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => '' ] );
		self::assertSame( '', $html );
	}

	/**
	 * An unknown placement key renders nothing.
	 */
	public function test_render_with_unknown_placement() {
		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => 'does_not_exist' ] );
		self::assertSame( '', $html );
	}

	/**
	 * A registered placement with no ad unit bound renders nothing.
	 */
	public function test_render_with_registered_placement_no_assignment() {
		$this->register_block_placements();

		$html = \Newspack_Ads\Ad_Slot_Block::render_block( [ 'placement' => 'global_above_header' ] );
		self::assertSame( '', $html );

		remove_filter( 'newspack_ads_is_block_theme', '__return_true' );
	}

	/**
	 * Force the block-theme branch and re-register the default placements so the
	 * synthetic block-placement hooks exist for the duration of a test. Callers
	 * are responsible for removing the filter afterwards.
	 */
	private function register_block_placements() {
		add_filter( 'newspack_ads_is_block_theme', '__return_true' );

		$ref  = new \ReflectionClass( \Newspack_Ads\Placements::class );
		$prop = $ref->getProperty( 'placements' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
		\Newspack_Ads\Placements::register_default_placements();
	}

	/**
	 * Render the block with a placement whose synthetic hook echoes the given
	 * markup, standing in for whatever a bound ad unit/provider would emit.
	 *
	 * @param array  $attrs       Block attributes.
	 * @param string $output_html Markup the planted listener should echo.
	 *
	 * @return string Rendered block HTML.
	 */
	private function render_with_output( $attrs, $output_html ) {
		$this->register_block_placements();

		$listener = function () use ( $output_html ) {
			echo $output_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		add_action( 'newspack_ads_block_placement_global_above_header', $listener, 999 );

		$html = \Newspack_Ads\Ad_Slot_Block::render_block( array_merge( [ 'placement' => 'global_above_header' ], $attrs ) );

		remove_action( 'newspack_ads_block_placement_global_above_header', $listener, 999 );
		remove_filter( 'newspack_ads_is_block_theme', '__return_true' );

		return $html;
	}

	/**
	 * Spacing padding/margin values merge onto the first element of the output.
	 */
	public function test_render_merges_spacing_onto_first_element() {
		$attrs = [
			'style' => [
				'spacing' => [
					'padding' => [
						'top'    => '10px',
						'bottom' => '20px',
					],
					'margin'  => [
						'top' => '30px',
					],
				],
			],
		];

		$html = $this->render_with_output( $attrs, "<div id='div-gpt-ad-test-0'></div>" );

		self::assertStringContainsString( 'padding-top:10px', $html );
		self::assertStringContainsString( 'padding-bottom:20px', $html );
		self::assertStringContainsString( 'margin-top:30px', $html );
		self::assertStringContainsString( 'div-gpt-ad-test-0', $html );
	}

	/**
	 * An existing inline style on the output element is preserved, not overwritten.
	 */
	public function test_render_preserves_existing_style() {
		$attrs = [
			'style' => [
				'spacing' => [
					'padding' => [ 'top' => '10px' ],
				],
			],
		];

		$html = $this->render_with_output( $attrs, '<div class="newspack-broadstreet-ad" style="width: 300px;"></div>' );

		self::assertStringContainsString( 'width: 300px', $html );
		self::assertStringContainsString( 'padding-top:10px', $html );
	}

	/**
	 * Output with a leading HTML comment still has spacing applied to the element.
	 */
	public function test_render_applies_spacing_past_leading_comment() {
		$attrs = [
			'style' => [
				'spacing' => [
					'margin' => [ 'bottom' => '15px' ],
				],
			],
		];

		$html = $this->render_with_output( $attrs, "<!-- /network/code --><div id='div-gpt-ad-test-0'></div>" );

		self::assertStringContainsString( '<!-- /network/code -->', $html );
		self::assertStringContainsString( 'margin-bottom:15px', $html );
	}

	/**
	 * Spacing is applied to the first rendered element, skipping a leading
	 * <style> block (e.g. the fixed-height CSS emitted on
	 * newspack_ads_before_placement_ad) so it lands on the visible ad container.
	 */
	public function test_render_skips_leading_style_block() {
		$attrs = [
			'style' => [
				'spacing' => [
					'margin' => [ 'top' => '30px' ],
				],
			],
		];

		$output = '<style>@media ( min-width: 320px ) { .newspack_global_ad.x { min-height: 100px; } }</style><div class="newspack_global_ad"><div id="div-gpt-ad-test-0"></div></div>';

		$html = $this->render_with_output( $attrs, $output );

		// Margin must land on the visible container, not the <style> tag.
		self::assertMatchesRegularExpression( '/<div\b(?=[^>]*\bclass="newspack_global_ad")(?=[^>]*margin-top:30px)[^>]*>/', $html );
		self::assertDoesNotMatchRegularExpression( '/<style[^>]*margin-top/', $html );
	}

	/**
	 * When no spacing is set, the output is returned unchanged.
	 */
	public function test_render_without_spacing_is_unchanged() {
		$output = "<div id='div-gpt-ad-test-0'></div>";
		$html   = $this->render_with_output( [], $output );
		self::assertSame( $output, $html );
	}

	/**
	 * Non-empty output with no element to attach to (e.g. a comment) is returned
	 * unchanged even when spacing is set.
	 */
	public function test_render_with_no_matchable_element_is_unchanged() {
		$attrs = [
			'style' => [
				'spacing' => [
					'margin' => [ 'top' => '30px' ],
				],
			],
		];

		$output = '<!-- ad failed to render -->';
		$html   = $this->render_with_output( $attrs, $output );

		self::assertSame( $output, $html );
		self::assertStringNotContainsString( 'style=', $html );
	}

	/**
	 * An existing inline style without a trailing semicolon still merges cleanly
	 * (the separator is added between the existing declarations and the spacing).
	 */
	public function test_render_merges_onto_style_without_trailing_semicolon() {
		$attrs = [
			'style' => [
				'spacing' => [
					'margin' => [ 'top' => '30px' ],
				],
			],
		];

		$html = $this->render_with_output( $attrs, '<div style="width:300px"></div>' );

		self::assertStringContainsString( 'width:300px;margin-top:30px', $html );
	}

	/**
	 * When a registered placement's hook is fired, the block's render callback
	 * captures the hook's output.
	 */
	public function test_render_captures_hook_output() {
		// This stands in for whatever inject_placement_ad would normally emit when
		// an ad unit is bound and a provider is active.
		$marker = 'PLACEMENT_RENDERED_MARKER';

		$html = $this->render_with_output( [], esc_html( $marker ) );
		self::assertStringContainsString( $marker, $html );
	}
}
