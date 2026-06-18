<?php
/**
 * Class Block Renderer Overrides Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry;
use Newspack\Newsletters\Email_Renderers\Blocks\Column;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;

/**
 * Block Renderer Overrides Test.
 *
 * Covers the override harness that swaps the package's per-block
 * `render_email_callback` for Newspack's renderers, plus the columns
 * percentage-width fix that the override restores.
 */
class Test_Block_Renderer_Overrides extends WP_UnitTestCase {
	/**
	 * The width helper restores a percentage width the package stripped to px.
	 *
	 * The package's Column renderer runs `Styles_Helper::parse_value( '70%' )`,
	 * which strips the `%` and emits `width="70"` (= 70px). The helper restores
	 * the percent so the wrapper cell reads `width="70%"` again.
	 */
	public function test_width_helper_restores_percent() {
		$html   = '<td class="x" width="70"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '70%' );
		$this->assertStringContainsString( 'width="70%"', $result, 'Expected the percentage width to be restored on the wrapper cell.' );
		$this->assertStringNotContainsString( 'width="70"', str_replace( 'width="70%"', '', $result ), 'Expected no bare width="70" to remain once the percent is restored.' );
	}

	/**
	 * The width helper leaves non-percentage widths untouched.
	 *
	 * A pixel width never lost information to `parse_value`, so the helper must
	 * be a no-op and return the HTML byte-for-byte.
	 */
	public function test_width_helper_ignores_non_percent() {
		$html = '<td class="x" width="200"><table width="100%"></table></td>';
		$this->assertSame( $html, Column::preserve_percentage_width( $html, '200px' ), 'Expected a non-percentage width to return the HTML unchanged.' );
	}

	/**
	 * The width helper leaves an empty width untouched.
	 *
	 * With no width attribute the package falls back to the layout width, so
	 * there is nothing to restore and the HTML must pass through unchanged.
	 */
	public function test_width_helper_ignores_empty_width() {
		$html = '<td class="x" width="600"><table width="100%"></table></td>';
		$this->assertSame( $html, Column::preserve_percentage_width( $html, '' ), 'Expected an empty width to return the HTML unchanged.' );
	}

	/**
	 * The width helper restores a decimal percentage on the wrapper cell.
	 *
	 * The package emits `parse_value( '33.33%' )` = `33.33`, so the wrapper cell
	 * reads `width="33.33"`. The helper must target that canonical numeric and
	 * restore the percent to `width="33.33%"`.
	 */
	public function test_width_helper_restores_decimal_percent() {
		$html   = '<td class="x" width="33.33"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '33.33%' );
		$this->assertStringContainsString( 'width="33.33%"', $result, 'Expected the decimal percentage width to be restored.' );
		$this->assertStringNotContainsString( 'width="33.33"', str_replace( 'width="33.33%"', '', $result ), 'Expected no bare width="33.33" to remain.' );
	}

	/**
	 * The width helper normalizes a trailing-zero percentage to the package value.
	 *
	 * A `30.0%` attribute is emitted by the package as `parse_value( '30.0%' )` =
	 * `30`, i.e. `width="30"`. The helper must compute the same canonical numeric
	 * and restore the percent on `width="30"` (the value the package actually
	 * emitted), not look for a literal `width="30.0"` that never exists.
	 */
	public function test_width_helper_normalizes_trailing_zero_percent() {
		$html   = '<td class="x" width="30"><table width="100%"></table></td>';
		$result = Column::preserve_percentage_width( $html, '30.0%' );
		$this->assertStringContainsString( 'width="30%"', $result, 'Expected the normalized percentage width to be restored on width="30".' );
		$this->assertStringNotContainsString( 'width="30"', str_replace( 'width="30%"', '', $result ), 'Expected no bare width="30" to remain.' );
	}

	/**
	 * The width helper only rewrites the first (wrapper cell) width occurrence.
	 *
	 * The wrapper `<td>` is the only cell carrying the column's numeric width and
	 * it is the first cell in the column output; any later identical numeric width
	 * must be left untouched so unrelated cells are never rewritten.
	 */
	public function test_width_helper_restores_first_occurrence_only() {
		$html   = '<td width="70"></td><td width="70"></td>';
		$result = Column::preserve_percentage_width( $html, '70%' );
		$this->assertSame( '<td width="70%"></td><td width="70"></td>', $result, 'Expected only the first width="70" to be rewritten.' );
	}

	/**
	 * The registry swaps the render callback for a mapped block.
	 *
	 * For `core/column` the registry must set a callable `render_email_callback`
	 * bound to the Newspack Column renderer instance.
	 */
	public function test_registry_overrides_mapped_block() {
		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'core/column' ] );
		$this->assertArrayHasKey( 'render_email_callback', $settings, 'Expected a render_email_callback to be set for the mapped block.' );
		$this->assertIsCallable( $settings['render_email_callback'], 'The render_email_callback should be callable.' );
		$this->assertInstanceOf( Column::class, $settings['render_email_callback'][0], 'The callback should be bound to the Newspack Column renderer.' );
	}

	/**
	 * The registry leaves an unmapped block untouched.
	 *
	 * A block with no override (e.g. core/paragraph) must pass through with no
	 * `render_email_callback` injected.
	 */
	public function test_registry_leaves_unmapped_block_untouched() {
		$settings = Block_Renderer_Registry::update_block_settings( [ 'name' => 'core/paragraph' ] );
		$this->assertArrayNotHasKey( 'render_email_callback', $settings, 'Expected no render_email_callback to be added for an unmapped block.' );
	}

	/**
	 * The Newspack Column renderer extends the package Column renderer.
	 *
	 * This is the structural lock for the double-wrapper regression: the package
	 * Column overrides `add_spacer()` to a no-op because columns render side by
	 * side. The override MUST inherit that behavior — extending the abstract base
	 * instead re-applies the abstract `add_spacer()` (an extra
	 * `email-block-layout` wrapper) around each already-wrapped column.
	 */
	public function test_column_renderer_extends_package_column() {
		$this->assertTrue(
			is_subclass_of( Column::class, Package_Column::class ),
			'The Newspack Column renderer must extend the package Column so it inherits the no-op add_spacer().'
		);
	}

	/**
	 * A real two-column render restores both percentages with no double-wrapper.
	 *
	 * Renders a real `core/columns` with 70% / 30% columns through the WC
	 * pipeline (Renderer_Controller::render_wc) and asserts both that the
	 * percentage widths survive (no bare px) and that no column `<td>` is wrapped
	 * in an extra `<div class="email-block-layout">` — the f1 double-wrapper.
	 *
	 * Before the f1 fix each column cell is spacer-wrapped (one
	 * div.email-block-layout immediately wrapping the column td per column); after
	 * the fix the package's no-op add_spacer() is inherited and the wrappers are
	 * gone.
	 */
	public function test_two_column_render_preserves_percentages_without_double_wrapper() {
		Editor_Bootstrap::init();

		$content = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"70%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Left at 70</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '<!-- wp:column {"width":"30%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Right at 30</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Two column newsletter',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		// Percentages survive; no bare-pixel widths leak through.
		$this->assertStringContainsString( 'width="70%"', $html, 'Expected the 70% column width to be preserved.' );
		$this->assertStringContainsString( 'width="30%"', $html, 'Expected the 30% column width to be preserved.' );
		$this->assertStringNotContainsString( 'width="70"', str_replace( 'width="70%"', '', $html ), 'Expected no bare width="70" pixel width to remain.' );
		$this->assertStringNotContainsString( 'width="30"', str_replace( 'width="30%"', '', $html ), 'Expected no bare width="30" pixel width to remain.' );

		// No per-column double-wrapper: a div.email-block-layout must never wrap a column td.
		$double_wrappers = preg_match_all( '/<div class="email-block-layout"[^>]*>\s*<td class="block wp-block-column/', $html );
		$this->assertSame( 0, $double_wrappers, 'Expected no column <td> to be wrapped in an extra div.email-block-layout (the f1 double-wrapper).' );
	}
}
