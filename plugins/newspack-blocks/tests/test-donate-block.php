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

require_once __DIR__ . '/class-newspack-donations-stub.php';

/**
 * Donate Block.
 */
class DonateBlockTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Restore the Donations stub's defaults.
	 */
	public function tear_down() {
		\Newspack\Donations::$stub_donation_settings = [];
		\Newspack\Donations::$stub_is_platform_nrh   = false;
		parent::tear_down();
	}

	/**
	 * Block minimums and the minimum the front end should use for each.
	 */
	public function block_minimum_provider() {
		return [
			'saved as a number string' => [ '1200', '1200' ],
			// Earlier editors stored an empty string when the field was cleared.
			'left empty'               => [ '', 20 ],
		];
	}

	/**
	 * A manual block's own minimum applies only when it is a number. Anything
	 * else falls back to the site-wide minimum, so a block can't drop the floor.
	 *
	 * @dataProvider block_minimum_provider
	 *
	 * @param mixed $block_minimum    The block's minimumDonation attribute.
	 * @param mixed $expected_minimum The minimum the front end should use.
	 */
	public function test_manual_block_uses_its_minimum_only_when_numeric( $block_minimum, $expected_minimum ) {
		\Newspack\Donations::$stub_is_platform_nrh   = true;
		\Newspack\Donations::$stub_donation_settings = [
			'amounts'             => [
				'once'  => [ 9, 20, 90, 20 ],
				'month' => [ 7, 15, 30, 15 ],
				'year'  => [ 84, 180, 360, 180 ],
			],
			'tiered'              => false,
			'disabledFrequencies' => [
				'once'  => false,
				'month' => false,
				'year'  => false,
			],
			'platform'            => 'nrh',
			'minimumDonation'     => 20,
		];

		$configuration = Newspack_Blocks_Donate_Renderer_Frequency_Based::get_configuration(
			[
				'manual'              => true,
				'tiered'              => false,
				'amounts'             => \Newspack\Donations::$stub_donation_settings['amounts'],
				'disabledFrequencies' => \Newspack\Donations::$stub_donation_settings['disabledFrequencies'],
				'defaultFrequency'    => 'month',
				'layoutOption'        => 'frequency',
				'tierStyle'           => 'grid',
				'minimumDonation'     => $block_minimum,
			]
		);

		$this->assertSame( $expected_minimum, $configuration['minimumDonation'] );
	}

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
