<?php
/**
 * Tests the Woo_User_Registration data-events watcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Woo_User_Registration;
use Newspack\Reader_Activation;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Test the Woo_User_Registration checkout watcher.
 *
 * The watcher announces accounts WooCommerce creates during checkout by
 * dispatching `newspack_registered_reader_via_woo`, which feeds the
 * `reader_registered` data event and session hydration. It must speak for both
 * WooCommerce checkout pipelines — classic and Store API (the Apple Pay /
 * Google Pay transport) — and stay silent for account creation outside a
 * checkout.
 *
 * The checkout-signal tests run in separate processes because the watcher's
 * signal handler reads WC()->cart, so they stub WC() — which must not leak
 * into the rest of the suite, whose WooCommerce-absent tests rely on
 * function_exists( 'WC' ) staying false. Pattern follows
 * my-account.php::test_woocommerce_delegation.
 *
 * @group data-events
 */
class Newspack_Test_Woo_User_Registration extends WP_UnitTestCase {

	/**
	 * Captured newspack_registered_reader_via_woo dispatches.
	 *
	 * @var array
	 */
	private $fired = [];

	/**
	 * Set up: reset watcher state and capture the action.
	 */
	public function set_up() {
		parent::set_up();
		self::reset_watcher_state();
		$this->fired = [];
		add_action( 'newspack_registered_reader_via_woo', [ $this, 'capture_event' ], 10, 3 );
	}

	/**
	 * Tear down: drop the capture hook, reset watcher state.
	 */
	public function tear_down() {
		remove_action( 'newspack_registered_reader_via_woo', [ $this, 'capture_event' ], 10 );
		self::reset_watcher_state();
		parent::tear_down();
	}

	/**
	 * Record a dispatched registration announcement.
	 *
	 * @param string $email    Email address.
	 * @param int    $user_id  The created user id.
	 * @param array  $metadata Metadata.
	 */
	public function capture_event( $email, $user_id, $metadata ) {
		$this->fired[] = [
			'email'    => $email,
			'user_id'  => $user_id,
			'metadata' => $metadata,
		];
	}

	/**
	 * Reset the watcher's static request-scoped state between tests.
	 */
	private static function reset_watcher_state() {
		$ref = new ReflectionClass( Woo_User_Registration::class );
		foreach ( [
			'processing_checkout' => false,
			'metadata'            => [],
		] as $prop => $value ) {
			$property = $ref->getProperty( $prop );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Stub WC() with a cart, in this process only.
	 *
	 * Only callable from tests annotated `@runInSeparateProcess` — a plain test
	 * calling this would define WC() for the remainder of the main suite
	 * process and flip its WooCommerce-absent gates.
	 *
	 * @param array $cart_contents Cart contents keyed by cart item key.
	 */
	private function stub_wc_with_cart( array $cart_contents = [] ) {
		if ( ! $this->isInIsolation() ) {
			$this->fail( 'stub_wc_with_cart() may only be called from @runInSeparateProcess tests — defining WC() in the main suite process would flip function_exists( "WC" ) gates for every later test in the run.' );
		}
		if ( ! function_exists( 'WC' ) ) {
			/**
			 * Mock WC() function returning a controllable container.
			 *
			 * @return object
			 */
			function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global, isolated to a separate test process.
				global $newspack_test_wc;
				if ( empty( $newspack_test_wc ) ) {
					$newspack_test_wc = new class() {
						/**
						 * Cart double, set by tests.
						 *
						 * @var object|null
						 */
						public $cart = null;
					};
				}
				return $newspack_test_wc;
			}
		}
		WC()->cart = new WC_Cart( $cart_contents );
	}

	/**
	 * A Store API checkout (the wallet transport) announces the customer it
	 * creates: the pre-creation signal arrives, then the account exists.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_api_checkout_announces_created_customer() {
		$this->stub_wc_with_cart();
		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', null, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
		$user_id = self::factory()->user->create( [ 'user_email' => 'store-api-reader@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired, 'The Store API checkout signal should let the watcher announce the new customer.' );
		$this->assertSame( 'store-api-reader@example.test', $this->fired[0]['email'] );
		$this->assertSame( $user_id, $this->fired[0]['user_id'] );
		$this->assertSame( 'woocommerce', $this->fired[0]['metadata']['registration_method'] );
		$this->assertSame( 'woocommerce', get_user_meta( $user_id, Reader_Activation::REGISTRATION_METHOD, true ) );
	}

	/**
	 * The Store API signal harvests campaign metadata from the cart the same
	 * way the classic signal does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_api_checkout_harvests_cart_campaign_metadata() {
		$this->stub_wc_with_cart(
			[
				'item1' => [
					'newspack_popup_id' => 123,
					'referer'           => '/donate/',
				],
			]
		);
		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', null, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
		$user_id = self::factory()->user->create( [ 'user_email' => 'store-api-campaign@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 123, $this->fired[0]['metadata']['newspack_popup_id'] );
		$this->assertSame( '/donate/', $this->fired[0]['metadata']['referer'] );
	}

	/**
	 * Classic checkout keeps announcing (regression control).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classic_checkout_announces_created_customer() {
		$this->stub_wc_with_cart();
		do_action( 'woocommerce_checkout_process' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'classic-reader@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'woocommerce', $this->fired[0]['metadata']['registration_method'] );
	}

	/**
	 * Account creation with no checkout signal stays silent — only
	 * checkout-created accounts announce a reader. Runs in the main process:
	 * it needs no WC() and proves the guard without any WooCommerce present.
	 */
	public function test_created_customer_without_checkout_signal_stays_silent() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'no-checkout@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 0, $this->fired, 'Account creation outside a checkout must not announce a reader.' );
	}
}
