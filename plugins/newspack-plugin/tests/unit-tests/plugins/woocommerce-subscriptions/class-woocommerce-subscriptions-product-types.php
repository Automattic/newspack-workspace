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
		delete_option( WooCommerce_Subscriptions::PRODUCT_TYPES_ENABLED_OPTION );
	}

	/**
	 * Leave no state behind for other suites.
	 */
	public function tear_down() {
		foreach ( $this->options as $option ) {
			delete_option( $option );
		}
		delete_option( WooCommerce_Subscriptions::PRODUCT_TYPES_ENABLED_OPTION );
		remove_all_filters( 'newspack_subscriptions_enable_legacy_product_types' );
		remove_all_actions( 'newspack_log' );
		parent::tear_down();
	}

	/**
	 * The write is wired to `admin_init`, not to `plugins_loaded` — it must not run
	 * on anonymous front-end requests, REST probes or cron. Asserted here because
	 * the guarded entry point is the entire reason this ships, and every other test
	 * calls past it.
	 */
	public function test_is_registered_on_admin_init() {
		$this->assertSame(
			10,
			has_action( 'admin_init', [ WooCommerce_Subscriptions::class, 'maybe_enable_legacy_product_types' ] ),
			'The product-type write should run on admin_init.'
		);
		$this->assertFalse(
			has_action( 'plugins_loaded', [ WooCommerce_Subscriptions::class, 'maybe_enable_legacy_product_types' ] ),
			'The product-type write should not run on every request.'
		);
	}

	/**
	 * Nothing is written when WooCommerce Subscriptions is not active. The suite has
	 * no `WC()` function, so `is_active()` is genuinely false here and the guarded
	 * entry point is exercised for real.
	 */
	public function test_inactive_subscriptions_writes_nothing() {
		$this->assertFalse( WooCommerce_Subscriptions::is_active(), 'Precondition: Subscriptions is inactive in the suite.' );

		WooCommerce_Subscriptions::maybe_enable_legacy_product_types();

		foreach ( $this->options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' should not have been written.' );
		}
	}

	/**
	 * A site that has never configured the setting gets both types enabled.
	 */
	public function test_absent_options_are_enabled() {
		WooCommerce_Subscriptions::enable_legacy_product_types();

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

		WooCommerce_Subscriptions::enable_legacy_product_types();

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

		WooCommerce_Subscriptions::enable_legacy_product_types();

		foreach ( $this->options as $option ) {
			remove_filter( 'pre_update_option_' . $option, $count );
			$this->assertSame( 'yes', get_option( $option ) );
		}
		$this->assertSame( 0, $writes, 'Nothing should be written when both options already exist.' );
	}

	/**
	 * The write is answerable afterwards: the marker option names exactly the rows
	 * Newspack created, so a corrective release could reverse those and leave a
	 * publisher's own choice alone.
	 */
	public function test_write_is_recorded_and_logged() {
		update_option( 'woocommerce_subscriptions_enable_simple_subscription', 'no' );

		$logged = [];
		add_action(
			'newspack_log',
			function ( $code, $message, $data ) use ( &$logged ) {
				$logged[] = [ $code, $data ];
			},
			10,
			3
		);

		WooCommerce_Subscriptions::enable_legacy_product_types();

		$this->assertSame(
			[ 'woocommerce_subscriptions_enable_variable_subscription' ],
			get_option( WooCommerce_Subscriptions::PRODUCT_TYPES_ENABLED_OPTION ),
			'Only the option Newspack created should be recorded.'
		);
		$this->assertCount( 1, $logged, 'The write should be logged once.' );
		$this->assertSame( 'newspack_subscriptions_product_types_enabled', $logged[0][0] );
		$this->assertSame(
			[ 'woocommerce_subscriptions_enable_variable_subscription' ],
			$logged[0][1]['data']['options'],
			'The log entry should name the options written.'
		);
	}

	/**
	 * Nothing is recorded or logged when there was nothing to write.
	 */
	public function test_nothing_is_recorded_when_no_write_happens() {
		foreach ( $this->options as $option ) {
			update_option( $option, 'no' );
		}

		$logged = 0;
		add_action(
			'newspack_log',
			function () use ( &$logged ) {
				++$logged;
			}
		);

		WooCommerce_Subscriptions::enable_legacy_product_types();

		$this->assertFalse( get_option( WooCommerce_Subscriptions::PRODUCT_TYPES_ENABLED_OPTION ) );
		$this->assertSame( 0, $logged );
	}

	/**
	 * The filter lets a publisher or custom plugin opt out entirely.
	 */
	public function test_filter_can_opt_out() {
		add_filter( 'newspack_subscriptions_enable_legacy_product_types', '__return_false' );

		WooCommerce_Subscriptions::enable_legacy_product_types();

		foreach ( $this->options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' should not have been written.' );
		}
		$this->assertFalse( get_option( WooCommerce_Subscriptions::PRODUCT_TYPES_ENABLED_OPTION ) );
	}
}
