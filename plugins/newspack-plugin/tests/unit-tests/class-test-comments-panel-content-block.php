<?php
/**
 * Tests for the Comments Panel Content block.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Comments_Panel\Comments_Panel_Content_Block
 */

use Newspack\Blocks\Comments_Panel\Comments_Panel_Content_Block;

/**
 * Test class for the Comments Panel Content block.
 *
 * @group comments-panel-block
 */
class Newspack_Test_Comments_Panel_Content_Block extends WP_UnitTestCase {

	const BLOCK_NAME = 'newspack/comments-panel-content';

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/comments-panel/content/class-comments-panel-content-block.php';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			Comments_Panel_Content_Block::register_block();
		}

		// The one-per-request guard is a private static that persists across tests
		// in the same process; reset it so each test starts with a fresh panel.
		$this->reset_rendered_guard();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$this->reset_rendered_guard();

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			unregister_block_type( self::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * Reset the private static $rendered guard to false.
	 */
	private function reset_rendered_guard(): void {
		$property = ( new ReflectionClass( Comments_Panel_Content_Block::class ) )->getProperty( 'rendered' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	/**
	 * Render the content block with the given attributes and inner content.
	 *
	 * Renders through WP_Block so get_block_wrapper_attributes() has the active
	 * block-render context it depends on.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Inner blocks HTML.
	 * @return string Rendered HTML.
	 */
	private function render( array $attributes = [], string $content = '' ): string {
		$parsed_block = [
			'blockName'    => self::BLOCK_NAME,
			'attrs'        => $attributes,
			'innerBlocks'  => [],
			'innerHTML'    => $content,
			'innerContent' => '' === $content ? [] : [ $content ],
		];

		return ( new WP_Block( $parsed_block ) )->render();
	}

	/**
	 * The panel renders with dialog semantics and the expected ARIA attributes.
	 */
	public function test_renders_dialog_aria_attributes() {
		$output = $this->render();

		$this->assertStringContainsString( 'id="newspack-comments-panel"', $output );
		$this->assertStringContainsString( 'role="dialog"', $output );
		$this->assertStringContainsString( 'aria-modal="true"', $output );
		$this->assertStringContainsString( 'aria-hidden="true"', $output );
		$this->assertStringContainsString( 'aria-label="Comments"', $output );
		$this->assertStringContainsString( 'inert="true"', $output );
	}

	/**
	 * The panel wrapper carries the expected layout/position classes and a close button.
	 */
	public function test_renders_panel_classes_and_close_button() {
		$output = $this->render();

		$this->assertStringContainsString( 'comments-panel__panel', $output );
		$this->assertStringContainsString( 'is-layout-constrained', $output );
		$this->assertStringContainsString( 'comments-panel__panel--right', $output );
		$this->assertStringContainsString( 'comments-panel__close', $output );
	}

	/**
	 * The overlay color attribute is reflected in the data-overlay-color attribute.
	 */
	public function test_overlay_color_attribute() {
		$this->assertStringContainsString( 'data-overlay-color="#123456"', $this->render( [ 'overlayColor' => '#123456' ] ) );
	}

	/**
	 * With no overlay color set, data-overlay-color is rendered empty.
	 */
	public function test_overlay_color_defaults_to_empty() {
		$this->assertStringContainsString( 'data-overlay-color=""', $this->render() );
	}

	/**
	 * Inner blocks content is output inside the panel content wrapper.
	 */
	public function test_inner_content_is_rendered() {
		$output = $this->render( [], '<p class="test-comment">Hello</p>' );

		$this->assertStringContainsString( 'comments-panel__content', $output );
		$this->assertStringContainsString( '<p class="test-comment">Hello</p>', $output );
	}

	/**
	 * Only one panel is output per request: the first render produces markup and
	 * subsequent renders return an empty string.
	 */
	public function test_renders_only_once_per_request() {
		$first  = $this->render();
		$second = $this->render();

		$this->assertStringContainsString( 'newspack-comments-panel', $first );
		$this->assertSame( '', $second );
	}
}
