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
}
