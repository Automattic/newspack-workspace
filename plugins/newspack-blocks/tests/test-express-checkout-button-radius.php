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
		if ( property_exists( '\Newspack\Newspack_UI_Theme_Style', 'stub_active' ) ) {
			\Newspack\Newspack_UI_Theme_Style::$stub_active        = false;
			\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '';
		}
		unset( $_REQUEST['modal_checkout'] );
		parent::tear_down();
	}

	/**
	 * Skip when the real adapter class is loaded instead of the stub.
	 */
	private function require_stub() {
		if ( ! property_exists( '\Newspack\Newspack_UI_Theme_Style', 'stub_active' ) ) {
			$this->markTestSkipped( 'Real \Newspack\Newspack_UI_Theme_Style present; stub-based test skipped.' );
		}
	}

	/**
	 * Activate the stub with a button radius.
	 *
	 * @param string $radius Printed button radius.
	 */
	private function set_theme_radius( string $radius ) {
		$this->require_stub();
		\Newspack\Newspack_UI_Theme_Style::$stub_active        = true;
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = $radius;
	}

	/**
	 * Neutral keeps 8.
	 */
	public function test_neutral_keeps_eight() {
		$this->require_stub();
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '24px';

		$this->assertSame( 8, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * A pixel radius under the Theme style is used.
	 */
	public function test_theme_pixel_radius_is_used() {
		$this->set_theme_radius( '24px' );

		$this->assertSame( 24, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * A fractional pixel radius is rounded.
	 */
	public function test_theme_fractional_radius_is_rounded() {
		$this->set_theme_radius( '1.5px' );

		$this->assertSame( 2, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * A rem radius is converted at a 16px root.
	 */
	public function test_theme_rem_radius_is_converted() {
		$this->set_theme_radius( '1.5rem' );

		$this->assertSame( 24, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * A zero radius is a square button.
	 */
	public function test_theme_zero_radius_is_square() {
		$this->set_theme_radius( '0px' );

		$this->assertSame( 0, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * An em radius keeps 8.
	 */
	public function test_theme_em_radius_keeps_eight() {
		$this->set_theme_radius( '2em' );

		$this->assertSame( 8, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * No printed radius keeps 8.
	 */
	public function test_theme_without_radius_keeps_eight() {
		$this->set_theme_radius( '' );

		$this->assertSame( 8, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * The filter callback sets the Theme radius on modal checkout requests.
	 */
	public function test_filter_sets_theme_radius() {
		$this->set_theme_radius( '24px' );
		$_REQUEST['modal_checkout'] = '1';

		$params = apply_filters( 'wc_stripe_express_checkout_params', [ 'button' => [ 'type' => 'buy' ] ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->assertSame( 24, $params['button']['radius'] );
		$this->assertSame( 'buy', $params['button']['type'] );
	}
}
