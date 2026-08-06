<?php
/**
 * Tests that Newspack enables the legacy subscription product types once.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;

/**
 * WooCommerce Subscriptions 9.0 defaults its "Subscription product creation"
 * checkboxes to off, which removes `subscription` and `variable-subscription`
 * from the product-type dropdown. Newspack's Audience wizard still creates those
 * types, so we turn them on once — and only when the publisher has never
 * expressed a preference.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_WooCommerce_Subscriptions_Product_Types extends WP_UnitTestCase {
	/**
	 * The options under test.
	 *
	 * @var string[]
	 */
	private $options = [
		'woocommerce_subscriptions_enable_simple_subscription',
		'woocommerce_subscriptions_enable_variable_subscription',
	];

	/**
	 * Start every test with the options genuinely absent, not merely falsy.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( $this->options as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Leave no state behind for other suites.
	 */
	public function tear_down() {
		foreach ( $this->options as $option ) {
			delete_option( $option );
		}
		remove_all_filters( 'newspack_enable_legacy_subscription_product_types' );
		parent::tear_down();
	}

	/**
	 * A site that has never configured the setting gets both types enabled.
	 */
	public function test_absent_options_are_enabled() {
		WooCommerce_Subscriptions::maybe_enable_legacy_product_types();

		foreach ( $this->options as $option ) {
			$this->assertSame( 'yes', get_option( $option ), $option . ' should have been enabled.' );
		}
	}

	/**
	 * A publisher who turned the type off keeps it off. This is the whole point of
	 * checking for an absent row rather than a falsy value: WooCommerce's settings
	 * screen writes 'no' when the box is unticked, and that is a real preference.
	 */
	public function test_explicit_no_is_respected() {
		update_option( 'woocommerce_subscriptions_enable_simple_subscription', 'no' );

		WooCommerce_Subscriptions::maybe_enable_legacy_product_types();

		$this->assertSame(
			'no',
			get_option( 'woocommerce_subscriptions_enable_simple_subscription' ),
			'An explicit "no" must not be overwritten.'
		);
		$this->assertSame(
			'yes',
			get_option( 'woocommerce_subscriptions_enable_variable_subscription' ),
			'The untouched option should still be enabled.'
		);
	}

	/**
	 * An option already set to 'yes' is left alone, and not rewritten.
	 */
	public function test_existing_yes_is_left_alone() {
		foreach ( $this->options as $option ) {
			update_option( $option, 'yes' );
		}

		$writes = 0;
		$count  = function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		foreach ( $this->options as $option ) {
			add_filter( 'pre_update_option_' . $option, $count );
		}

		WooCommerce_Subscriptions::maybe_enable_legacy_product_types();

		foreach ( $this->options as $option ) {
			remove_filter( 'pre_update_option_' . $option, $count );
			$this->assertSame( 'yes', get_option( $option ) );
		}
		$this->assertSame( 0, $writes, 'Nothing should be written when both options already exist.' );
	}

	/**
	 * The filter lets a publisher or custom plugin opt out entirely.
	 */
	public function test_filter_can_opt_out() {
		add_filter( 'newspack_enable_legacy_subscription_product_types', '__return_false' );

		WooCommerce_Subscriptions::maybe_enable_legacy_product_types();

		foreach ( $this->options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' should not have been written.' );
		}
	}
}
