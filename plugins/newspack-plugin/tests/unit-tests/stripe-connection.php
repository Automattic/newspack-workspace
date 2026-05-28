<?php
/**
 * Tests Stripe features.
 *
 * @package Newspack\Tests
 */

use Newspack\Stripe_Connection;
use Newspack\Donations;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests Stripe features.
 */
class Newspack_Test_Stripe extends WP_UnitTestCase {
	/**
	 * Set up Stripe.
	 */
	private static function configure_stripe_gateway() {
		Stripe_Connection::update_stripe_data(
			[
				'enabled'        => true,
				'testMode'       => false,
				'secretKey'      => 'sk_live_123',
				'publishableKey' => 'pk_live_123',
			]
		);
	}

	/**
	 * Configuration.
	 */
	public function test_stripe_configuration() {
		$wcpg = WC_Payment_Gateways::instance();
		$wcpg->init();
		$gateways = $wcpg->payment_gateways();
		$gateways['stripe']->reset_testing_options();

		// Enable, so the gateway is initialized.
		Stripe_Connection::update_stripe_data( [ 'enabled' => true ] );

		$expected_data = [
			'enabled'                 => true,
			'testMode'                => false,
			'currency'                => 'USD',
			'location_code'           => 'US',
			'is_connected_api_test'   => false,
			'is_connected_api_live'   => false,
			'is_connected_oauth_test' => false,
			'is_connected_oauth_live' => false,
			'legacy_checkout_enabled' => false,
		];
		self::assertEquals(
			$expected_data,
			Stripe_Connection::get_stripe_data(),
			'When Stripe is not configured, default data is returned, with set used keys.'
		);

		self::configure_stripe_gateway();

		Stripe_Connection::update_stripe_data(
			[
				'currency' => 'CHF',
			]
		);
		self::assertEquals(
			'CHF',
			Stripe_Connection::get_stripe_data()['currency'],
			'Currency can be updated.'
		);
		// Update it back to USD.
		Stripe_Connection::update_stripe_data(
			[
				'currency' => 'USD',
			]
		);

		self::assertEquals(
			'US',
			Stripe_Connection::get_stripe_data()['location_code'],
			'Location code is US by default.'
		);
		update_option( 'woocommerce_default_country', 'FR' );
		self::assertEquals(
			'FR',
			Stripe_Connection::get_stripe_data()['location_code'],
			'Location code is assumed from WC\'s settings.'
		);
		update_option( 'woocommerce_default_country', 'BR:SP' );
		self::assertEquals(
			'BR',
			Stripe_Connection::get_stripe_data()['location_code'],
			'Location code is assumed from WC\'s settings, when a regional code was set.'
		);
	}
}
