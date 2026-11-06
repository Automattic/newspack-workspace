<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class DonateBlockTest
 *
 * @package Newspack_Blocks
 */

/**
 * Mock WooCommerce functions for testing.
 */
if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * Mock wc_price function.
	 *
	 * @param float $price The price to format.
	 * @param array $args Formatting arguments.
	 * @return string Formatted price.
	 */
	function wc_price( $price, $args = [] ) {
		$decimals = isset( $args['decimals'] ) ? $args['decimals'] : 2;
		$formatted_price = number_format( $price, $decimals );
		return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>' . $formatted_price . '</bdi></span>';
	}
}

if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
	/**
	 * Mock get_woocommerce_currency_symbol function.
	 *
	 * @return string Currency symbol.
	 */
	function get_woocommerce_currency_symbol() {
		return '$';
	}
}

if ( ! function_exists( 'wcs_price_string' ) ) {
	/**
	 * Mock wcs_price_string function for subscriptions.
	 *
	 * @param array $args Price string arguments.
	 * @return string Formatted subscription price string.
	 */
	function wcs_price_string( $args ) {
		// Recorded so other test files can assert on the args this mock received,
		// e.g. the initial_amount (sign-up fee) passed by Checkout_Data::get_price_summary().
		$GLOBALS['newspack_blocks_test_last_wcs_price_string_args'] = $args;

		$recurring_amount = isset( $args['recurring_amount'] ) ? $args['recurring_amount'] : '';
		$period = isset( $args['subscription_period'] ) ? $args['subscription_period'] : 'month';

		if ( 'day' === $period ) {
			return $recurring_amount;
		}

		return $recurring_amount . ' / ' . $period;
	}
}

/**
 * Donate Block.
 */
class DonateBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Test the amount formatting.
	 */
	public function test_donate_block_amount_formatting() {
		$expected_format_wrapper = '<span class="wpbnbd__tiers__amount__value"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>%s</bdi></span></span>';

		$this->assertEquals(
			sprintf( $expected_format_wrapper, '25' ),
			Newspack_Blocks::get_formatted_amount( 25, 'once' )
		);
		$this->assertEquals(
			sprintf( $expected_format_wrapper, '1,000' ),
			Newspack_Blocks::get_formatted_amount( 1000, 'once' )
		);

		// Test float amounts.
		$formatted_float = Newspack_Blocks::get_formatted_amount( 25.50, 'once' );
		$this->assertEquals(
			sprintf( $expected_format_wrapper, '25.50' ),
			Newspack_Blocks::get_formatted_amount( 25.5, 'once' )
		);

		// Test zero amount.
		$formatted_zero = Newspack_Blocks::get_formatted_amount( 0, 'once' );
		$this->assertIsString( $formatted_zero );

		// Returning placeholder format.
		$this->assertEquals(
			$with_placeholder = Newspack_Blocks::get_formatted_amount(),
			'<span class="wpbnbd__tiers__amount__value"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>AMOUNT_PLACEHOLDER</bdi></span> FREQUENCY_PLACEHOLDER</span>'
		);
	}
	/**
	 * Switch theme, skipping when it is not available.
	 *
	 * @param string $slug Theme slug.
	 * @return string Previous stylesheet.
	 */
	private function use_theme( $slug ) {
		$original = get_stylesheet();
		switch_theme( $slug );
		if ( get_stylesheet() !== $slug ) {
			switch_theme( $original );
			$this->markTestSkipped( $slug . ' is not available in this environment.' );
		}
		return $original;
	}

	/**
	 * Call the protected button style helper on a renderer.
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $reverse    Reverse style.
	 * @return string
	 */
	private function get_button_style( $attributes, $reverse = false ) {
		require_once NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/donate/frontend/class-newspack-blocks-donate-renderer-frequency-based.php';
		$method = new ReflectionMethod( 'Newspack_Blocks_Donate_Renderer_Frequency_Based', 'get_button_style' );
		$method->setAccessible( true );
		return $method->invoke( null, $attributes, $reverse );
	}

	/**
	 * The default button colour defers to the site's button on a block theme.
	 */
	public function test_donate_button_uses_site_style_when_default_and_active() {
		$original = $this->use_theme( 'twentytwentyfive' );
		$this->assertTrue( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#dd3333' ] ) );
		$this->assertTrue( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#DD3333' ] ) );
		$this->assertSame( '', $this->get_button_style( [ 'buttonColor' => '#dd3333' ] ) );
		switch_theme( $original );
	}

	/**
	 * A picked colour is kept.
	 */
	public function test_donate_button_keeps_custom_colour() {
		$original = $this->use_theme( 'twentytwentyfive' );
		$this->assertFalse( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#6c6c6c' ] ) );
		$style = $this->get_button_style( [ 'buttonColor' => '#6c6c6c' ] );
		$this->assertStringContainsString( 'background-color: #6c6c6c', $style );
		switch_theme( $original );
	}

	/**
	 * Classic themes keep the inline colours.
	 */
	public function test_donate_button_unchanged_on_classic() {
		$original = $this->use_theme( 'newspack-theme' );
		$this->assertFalse( Newspack_Blocks::donate_button_uses_site_style( [ 'buttonColor' => '#dd3333' ] ) );
		$this->assertSame(
			'border-color: #dd3333; background-color: #dd3333; color: white;',
			$this->get_button_style( [ 'buttonColor' => '#dd3333' ] )
		);
		switch_theme( $original );
	}

	/**
	 * The reverse style outlines the button with the brand token.
	 */
	public function test_reverse_style_uses_brand_token() {
		$original = $this->use_theme( 'twentytwentyfive' );
		$this->assertSame(
			'border-color: var(--newspack-ui-color-btn); color: var(--newspack-ui-color-btn); background: transparent;',
			$this->get_button_style( [ 'buttonColor' => '#dd3333' ], true )
		);
		switch_theme( $original );
	}
}
