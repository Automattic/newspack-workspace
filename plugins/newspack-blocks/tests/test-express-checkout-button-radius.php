<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ExpressCheckoutButtonRadiusTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests the radius handed to the express checkout wallet buttons.
 */
class ExpressCheckoutButtonRadiusTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Return the stub to its inactive state.
	 */
	public function tear_down() {
		\Newspack\Newspack_UI_Theme_Style::$stub_active        = false;
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '';
		parent::tear_down();
	}

	/**
	 * Neutral keeps 8.
	 */
	public function test_neutral_keeps_eight() {
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '24px';

		$this->assertSame( 8, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * A pixel radius under the Theme style is used.
	 */
	public function test_theme_pixel_radius_is_used() {
		\Newspack\Newspack_UI_Theme_Style::$stub_active        = true;
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '24px';

		$this->assertSame( 24, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * A zero radius is a square button.
	 */
	public function test_theme_zero_radius_is_square() {
		\Newspack\Newspack_UI_Theme_Style::$stub_active        = true;
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '0px';

		$this->assertSame( 0, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * Relative lengths keep 8.
	 */
	public function test_theme_relative_radius_keeps_eight() {
		\Newspack\Newspack_UI_Theme_Style::$stub_active        = true;
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '0.5rem';

		$this->assertSame( 8, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * No printed radius keeps 8.
	 */
	public function test_theme_without_radius_keeps_eight() {
		\Newspack\Newspack_UI_Theme_Style::$stub_active = true;

		$this->assertSame( 8, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}
}
