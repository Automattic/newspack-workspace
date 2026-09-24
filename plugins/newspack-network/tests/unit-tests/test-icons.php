<?php
/**
 * Class TestIcons
 *
 * @package Newspack_Network
 */

namespace Test;

use Newspack_Network\Utils\Icons;

/**
 * Test the Icons class.
 */
class TestIcons extends \WP_UnitTestCase {
	/**
	 * The broadcast icon is an inline SVG that inherits its colour.
	 */
	public function test_broadcast_is_inline_svg() {
		$svg = Icons::broadcast();

		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 24 24"', $svg );
		$this->assertStringContainsString( 'fill="currentColor"', $svg );
		$this->assertStringContainsString( 'aria-hidden="true"', $svg );
		$this->assertStringContainsString( 'focusable="false"', $svg );
	}

	/**
	 * The icon never carries a hardcoded colour.
	 */
	public function test_broadcast_has_no_hardcoded_colour() {
		$this->assertDoesNotMatchRegularExpression( '/fill="#[0-9a-fA-F]+"/', Icons::broadcast() );
	}

	/**
	 * The size argument drives both dimensions.
	 */
	public function test_broadcast_size() {
		$svg = Icons::broadcast( 24 );

		$this->assertStringContainsString( 'width="24"', $svg );
		$this->assertStringContainsString( 'height="24"', $svg );
	}

	/**
	 * The default size matches the admin bar.
	 */
	public function test_broadcast_default_size() {
		$svg = Icons::broadcast();

		$this->assertStringContainsString( 'width="20"', $svg );
		$this->assertStringContainsString( 'height="20"', $svg );
	}
}
