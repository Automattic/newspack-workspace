<?php
/**
 * Class Block Renderer Overrides Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry;
use Newspack\Newsletters\Email_Renderers\Blocks\Column;

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
}
