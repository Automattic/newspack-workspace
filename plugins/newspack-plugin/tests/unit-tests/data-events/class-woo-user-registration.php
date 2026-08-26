<?php
/**
 * Tests the Woo_User_Registration data-events watcher.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Woo_User_Registration;
use Newspack\Reader_Activation;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

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
class Newspack_Test_Data_Events_Woo_User_Registration extends WP_UnitTestCase {

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
	 * Fire the Store API checkout signal.
	 *
	 * @param string|null $billing_email Billing email the checkout carries, or
	 *                                   null to fire the signal without a
	 *                                   customer at all.
	 */
	private function store_api_signal( $billing_email = null ) {
		$customer = null;
		if ( null !== $billing_email ) {
			$customer = new WC_Customer( 0 );
			$customer->set_billing_email( $billing_email );
		}
		do_action( 'woocommerce_store_api_checkout_update_customer_from_request', $customer, new WP_REST_Request( 'POST', '/wc/store/v1/checkout' ) );
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
		$this->store_api_signal( 'store-api-reader@example.test' );
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
		$this->store_api_signal( 'store-api-campaign@example.test' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'store-api-campaign@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 123, $this->fired[0]['metadata']['newspack_popup_id'] );
		$this->assertSame( '/donate/', $this->fired[0]['metadata']['referer'] );
	}

	/**
	 * A checkout signal that arrives with no cart still announces the customer.
	 *
	 * Both Store API routes load a cart before firing the signal, so an absent
	 * cart is a guard against the unexpected rather than a path taken today.
	 * What it pins down is the priority: harvesting campaign metadata is
	 * best-effort, announcing the reader is not. Without the guard this test
	 * dies on get_cart(), and the account goes unannounced.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_checkout_signal_without_cart_still_announces() {
		$this->stub_wc_with_cart();
		WC()->cart = null;
		$this->store_api_signal( 'no-cart@example.test' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'no-cart@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired, 'A checkout signal with no cart must still let the watcher announce the new customer.' );
		$this->assertSame( 'woocommerce', $this->fired[0]['metadata']['registration_method'] );
	}

	/**
	 * Each checkout signal harvests its own campaign metadata. The Store API
	 * batch route serves up to 25 requests in one PHP process, so a second
	 * checkout must not inherit the first one's attribution.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_second_checkout_does_not_inherit_campaign_metadata() {
		$this->stub_wc_with_cart(
			[
				'item1' => [
					'newspack_popup_id' => 123,
					'referer'           => '/donate/',
				],
			]
		);
		$this->store_api_signal( 'batch-first@example.test' );
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'batch-first@example.test' ] ) );

		// Second checkout in the same process, with nothing to harvest.
		WC()->cart = new WC_Cart( [] );
		$this->store_api_signal( 'batch-second@example.test' );
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'batch-second@example.test' ] ) );

		$this->assertCount( 2, $this->fired );
		$this->assertArrayNotHasKey( 'newspack_popup_id', $this->fired[1]['metadata'], 'The second checkout should not inherit the first one\'s campaign attribution.' );
		$this->assertArrayNotHasKey( 'referer', $this->fired[1]['metadata'] );
		$this->assertSame( 123, $this->fired[0]['metadata']['newspack_popup_id'], 'The first checkout keeps its own attribution.' );
	}

	/**
	 * A checkout announces the one account it creates. The Store API batch
	 * route serves several sub-requests in a single PHP process, so the state a
	 * checkout raises must not still be standing when an account is created
	 * later in that process without a checkout of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_checkout_state_is_consumed_by_the_account_it_announces() {
		$this->stub_wc_with_cart();
		$this->store_api_signal( 'checkout-account@example.test' );
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'checkout-account@example.test' ] ) );

		// No second checkout signal: nothing announces this account.
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'unrelated-account@example.test' ] ) );

		$this->assertCount( 1, $this->fired, 'Only the account its checkout created should be announced.' );
		$this->assertSame( 'checkout-account@example.test', $this->fired[0]['email'] );
	}

	/**
	 * Classic checkout keeps announcing, campaign attribution intact.
	 *
	 * The control for the claim that this branch leaves the classic pipeline
	 * alone, so it asserts something the classic path alone produces. Asserting
	 * registration_method would not: created_customer() sets that for whichever
	 * pipeline announced. newspack_popup_id only survives if the classic signal
	 * harvested the cart, which is the behaviour at risk.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classic_checkout_announces_created_customer() {
		$this->stub_wc_with_cart(
			[
				'item1' => [
					'newspack_popup_id' => 456,
					'prompt_title'      => 'Winter drive',
					'referer'           => '/support-us/',
				],
			]
		);
		do_action( 'woocommerce_checkout_process' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'classic-reader@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'woocommerce', $this->fired[0]['metadata']['registration_method'] );
		$this->assertSame( 456, $this->fired[0]['metadata']['newspack_popup_id'], 'The classic signal must still harvest the cart it checks out.' );
		$this->assertSame( 'Winter drive', $this->fired[0]['metadata']['prompt_title'] );
		$this->assertSame( '/support-us/', $this->fired[0]['metadata']['referer'] );
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

	/**
	 * A Store API checkout announces only the account it is creating.
	 *
	 * The signal fires on every block-checkout POST, including a logged-in
	 * shopper's, where no account follows it. Keying the state to the email the
	 * checkout expects stops that standing state from speaking for an unrelated
	 * account created later in the same process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_api_checkout_does_not_announce_a_different_account() {
		$this->stub_wc_with_cart();
		$this->store_api_signal( 'expected-buyer@example.test' );

		// An account that is not this checkout's buyer — a gift recipient, or any
		// creation that happens to land in the same process.
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'someone-else@example.test' ] ) );

		$this->assertCount( 0, $this->fired, 'A Store API checkout must not announce an account it did not create.' );

		// A non-match must not disarm the checkout: the buyer's own account
		// arriving afterwards is still this checkout's to announce.
		$buyer_id = self::factory()->user->create( [ 'user_email' => 'expected-buyer@example.test' ] );
		do_action( 'woocommerce_created_customer', $buyer_id );

		$this->assertCount( 1, $this->fired, 'The expected account must still announce when it arrives after an unrelated one.' );
		$this->assertSame( $buyer_id, $this->fired[0]['user_id'] );
	}

	/**
	 * Classic checkout announces every account it creates.
	 *
	 * Subscriptions Gifting creates a second account during a classic checkout —
	 * the gift recipient — on the order-status transition. Both reached the
	 * announcement before this branch, and the Store API fix must not narrow
	 * that to the first one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classic_checkout_announces_every_account_it_creates() {
		$this->stub_wc_with_cart();
		do_action( 'woocommerce_checkout_process' );
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'classic-buyer@example.test' ] ) );
		do_action( 'woocommerce_created_customer', self::factory()->user->create( [ 'user_email' => 'classic-recipient@example.test' ] ) );

		$this->assertCount( 2, $this->fired, 'Classic checkout announced both accounts before this branch; it must still do so.' );
		$this->assertSame( 'classic-buyer@example.test', $this->fired[0]['email'] );
		$this->assertSame( 'classic-recipient@example.test', $this->fired[1]['email'] );
	}

	/**
	 * The expected email matches the created account regardless of case.
	 *
	 * The customer object carries the address as it was posted; the user record
	 * carries it as WordPress saved it, and email_exists() treats the two as the
	 * same account case-insensitively. Comparing them raw would reject a
	 * legitimate account and put the original bug back with the suite green.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_api_checkout_matches_the_account_regardless_of_email_case() {
		$this->stub_wc_with_cart();
		$this->store_api_signal( '  Mixed.Case@Example.test ' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'mixed.case@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 1, $this->fired, 'A case or whitespace difference must not stop the checkout announcing its own buyer.' );
		$this->assertSame( $user_id, $this->fired[0]['user_id'] );
	}

	/**
	 * A Store API signal carrying no email announces nothing.
	 *
	 * WooCommerce refuses to create a customer without a valid billing address,
	 * so a signal with no email is followed by no account of its own. Anything
	 * created while it stands belongs to something else in the request, and
	 * announcing it would name the wrong reader.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_store_api_signal_without_an_email_announces_nothing() {
		$this->stub_wc_with_cart();
		$this->store_api_signal( null );
		$user_id = self::factory()->user->create( [ 'user_email' => 'unrelated@example.test' ] );
		do_action( 'woocommerce_created_customer', $user_id );

		$this->assertCount( 0, $this->fired, 'A signal with no email must not announce an account that cannot be its own.' );
	}
}
