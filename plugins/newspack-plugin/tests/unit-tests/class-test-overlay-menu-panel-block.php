<?php
/**
 * Tests for Overlay Menu Panel block.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Overlay_Menu\Overlay_Menu_Panel_Block
 */

use Newspack\Blocks\Overlay_Menu\Overlay_Menu_Panel_Block;

/**
 * Test class for the Overlay Menu Panel Block.
 *
 * @group overlay-menu-panel-block
 */
class Newspack_Test_Overlay_Menu_Panel_Block extends WP_UnitTestCase {

	const BLOCK_NAME = 'newspack/overlay-menu-panel';

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once NEWSPACK_ABSPATH . 'src/blocks/overlay-menu/panel/class-overlay-menu-panel-block.php';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			Overlay_Menu_Panel_Block::register_block();
		}
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			unregister_block_type( self::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * Render the panel block with given attributes and parent context.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render( array $attributes ): string {
		$parsed_block = [
			'blockName'    => self::BLOCK_NAME,
			'attrs'        => $attributes,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
		$block        = new WP_Block( $parsed_block, [ 'newspack-overlay-menu/instanceId' => 'test' ] );

		return $block->render();
	}

	/**
	 * Extract the class attribute value from rendered output.
	 *
	 * @param string $output Rendered HTML.
	 * @return string Class attribute value, or empty string if not found.
	 */
	private function get_class_string( string $output ): string {
		return preg_match( '/class="([^"]*)"/', $output, $matches ) ? $matches[1] : '';
	}

	/**
	 * Default attributes produce a left-direction, small-width panel.
	 */
	public function test_default_attributes() {
		$class = $this->get_class_string( $this->render( [] ) );

		$this->assertStringContainsString( 'overlay-menu__panel', $class );
		$this->assertStringContainsString( 'is-layout-constrained', $class );
		$this->assertStringContainsString( 'overlay-menu__panel--left', $class );
		$this->assertStringContainsString( 'overlay-menu__panel--width--small', $class );
		$this->assertStringNotContainsString( 'overlay-menu__panel--full-screen', $class );
	}

	/**
	 * Valid direction and width combos produce the matching modifier classes.
	 */
	public function test_valid_direction_and_width() {
		$class = $this->get_class_string(
			$this->render(
				[
					'slideDirection' => 'right',
					'panelWidth'     => 'large',
				]
			)
		);

		$this->assertStringContainsString( 'overlay-menu__panel--right', $class );
		$this->assertStringContainsString( 'overlay-menu__panel--width--large', $class );
		$this->assertStringNotContainsString( 'overlay-menu__panel--left', $class );
		$this->assertStringNotContainsString( 'overlay-menu__panel--width--small', $class );
	}

	/**
	 * Full-screen short-circuits direction and width modifiers.
	 */
	public function test_full_screen_short_circuits_direction_and_width() {
		$class = $this->get_class_string(
			$this->render(
				[
					'isFullScreen'   => true,
					'slideDirection' => 'right',
					'panelWidth'     => 'large',
				]
			)
		);

		$this->assertStringContainsString( 'overlay-menu__panel--full-screen', $class );
		$this->assertStringNotContainsString( 'overlay-menu__panel--right', $class );
		$this->assertStringNotContainsString( 'overlay-menu__panel--left', $class );
		$this->assertStringNotContainsString( 'overlay-menu__panel--width--', $class );
	}

	/**
	 * Invalid direction falls back to 'left'.
	 */
	public function test_invalid_direction_falls_back_to_left() {
		$class = $this->get_class_string( $this->render( [ 'slideDirection' => 'diagonal' ] ) );

		$this->assertStringContainsString( 'overlay-menu__panel--left', $class );
		$this->assertStringNotContainsString( 'diagonal', $class );
	}

	/**
	 * Invalid width falls back to 'small'.
	 */
	public function test_invalid_width_falls_back_to_small() {
		$class = $this->get_class_string( $this->render( [ 'panelWidth' => 'gigantic' ] ) );

		$this->assertStringContainsString( 'overlay-menu__panel--width--small', $class );
		$this->assertStringNotContainsString( 'gigantic', $class );
	}

	/**
	 * Allowlist prevents arbitrary strings from being concatenated into the class attribute.
	 */
	public function test_allowlist_blocks_class_injection() {
		$class = $this->get_class_string(
			$this->render(
				[
					'slideDirection' => '" onclick="alert(1)" data-x="',
					'panelWidth'     => 'evil"><script>alert(1)</script>',
				]
			)
		);

		$this->assertStringContainsString( 'overlay-menu__panel--left', $class );
		$this->assertStringContainsString( 'overlay-menu__panel--width--small', $class );
		$this->assertStringNotContainsString( 'onclick', $class );
		$this->assertStringNotContainsString( 'script', $class );
		$this->assertStringNotContainsString( 'evil', $class );
	}

	/**
	 * Non-string attribute values fall back to defaults via the strict allowlist.
	 */
	public function test_non_string_values_fall_back_to_defaults() {
		$class = $this->get_class_string(
			$this->render(
				[
					'slideDirection' => [ 'left' ],
					'panelWidth'     => 42,
				]
			)
		);

		$this->assertStringContainsString( 'overlay-menu__panel--left', $class );
		$this->assertStringContainsString( 'overlay-menu__panel--width--small', $class );
	}

	/**
	 * Boundary width values (x-small, x-large) produce their matching classes.
	 */
	public function test_boundary_width_values() {
		$x_small = $this->get_class_string( $this->render( [ 'panelWidth' => 'x-small' ] ) );
		$this->assertStringContainsString( 'overlay-menu__panel--width--x-small', $x_small );

		$x_large = $this->get_class_string( $this->render( [ 'panelWidth' => 'x-large' ] ) );
		$this->assertStringContainsString( 'overlay-menu__panel--width--x-large', $x_large );
	}
}
