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
	 * With the Theme style off, as on classic themes, the radius is newspack-ui's own default button radius.
	 */
	public function test_neutral_uses_newspack_ui_button_radius() {
		$this->require_stub();
		$variables_file = dirname( __DIR__, 2 ) . '/newspack-plugin/src/newspack-ui/scss/variables/_index.scss';
		if ( ! file_exists( $variables_file ) ) {
			$this->markTestSkipped( 'newspack-plugin is not checked out next to newspack-blocks.' );
		}
		preg_match_all( '/(--newspack-ui-[\w-]+):\s*([^;]+);/', file_get_contents( $variables_file ), $declarations );
		$newspack_ui_variables = array_combine( $declarations[1], array_map( 'trim', $declarations[2] ) );
		$button_radius         = $newspack_ui_variables['--newspack-ui-radius-button'];
		while ( 1 === preg_match( '/^var\((--[\w-]+)\)$/', $button_radius, $reference ) ) {
			$button_radius = $newspack_ui_variables[ $reference[1] ];
		}
		\Newspack\Newspack_UI_Theme_Style::$stub_button_radius = '24px';

		$this->assertSame( $button_radius, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() . 'px' );
	}

	/**
	 * Theme radii resolve to a pixel count, or keep newspack-ui's default when unusable.
	 *
	 * @dataProvider theme_radius_provider
	 *
	 * @param string $radius   Printed button radius.
	 * @param int    $expected Expected pixel radius.
	 */
	public function test_theme_radius_resolution( string $radius, int $expected ) {
		$this->set_theme_radius( $radius );

		$this->assertSame( $expected, \Newspack_Blocks\Modal_Checkout::get_express_checkout_button_radius() );
	}

	/**
	 * Radius fixtures.
	 *
	 * @return array
	 */
	public function theme_radius_provider() {
		return [
			'pixel'      => [ '24px', 24 ],
			'fractional' => [ '1.5px', 2 ],
			'rem'        => [ '1.5rem', 24 ],
			'zero'       => [ '0px', 0 ],
			'em'         => [ '2em', \Newspack_Blocks\Modal_Checkout::NEWSPACK_UI_BUTTON_RADIUS ],
			'empty'      => [ '', \Newspack_Blocks\Modal_Checkout::NEWSPACK_UI_BUTTON_RADIUS ],
		];
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
