<?php // phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
/**
 * Tests for the Store API checkout's bot protection.
 *
 * @package Newspack\Tests
 */

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\RateLimits;
use Newspack\WooCommerce_Store_API_Checkout_Protection as Protection;

require_once __DIR__ . '/../../../mocks/store-api-mocks.php';
require_once __DIR__ . '/../../../mocks/store-api-rate-limits-mock.php';
require_once __DIR__ . '/../../../mocks/wc-clean-mock.php';
require_once __DIR__ . '/../../../../includes/plugins/woocommerce/class-woocommerce-store-api-checkout-protection.php';

/**
 * Pins who may place an order through the Store API on a site protected by reCAPTCHA.
 *
 * Runs in separate processes because the code under test reads the global WC(),
 * which a mock may only define in a process of its own.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @group WooCommerce_Store_API_Checkout_Protection
 */
class Newspack_Test_WooCommerce_Store_API_Checkout_Protection extends WP_UnitTestCase {
	/**
	 * Enable reCAPTCHA, and define a cart that needs payment.
	 */
	public function set_up() {
		parent::set_up();
		$credentials = [
			'site_key'    => 'test_key',
			'site_secret' => 'test_secret',
		];
		update_option( 'newspack_recaptcha_use_captcha', true );
		update_option( 'newspack_recaptcha_version', 'v3' );
		update_option( 'newspack_recaptcha_credentials', [ 'v3' => $credentials ] );

		if ( ! function_exists( 'WC' ) ) {
			/**
			 * Mock WooCommerce global.
			 */
			function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global, isolated to a separate test process.
				return $GLOBALS['newspack_test_wc'];
			}
		}
		$GLOBALS['newspack_test_wc'] = (object) [ 'cart' => $this->cart( true ) ];
		RateLimits::$remaining       = [];
		$_SERVER['REMOTE_ADDR']      = '192.0.2.10';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_POST['g-recaptcha-response'] );
		if ( 'test_stripe_client_missing' !== $this->getName( false ) ) {
			$this->stripe();
		}

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, 'recaptcha/api/siteverify' ) ) {
					return $preempt;
				}
				$success = false !== strpos( $url, 'response=good' );
				return [
					'body'     => wp_json_encode(
						[
							'success' => $success,
							'score'   => 0.9,
						]
					),
					'response' => [ 'code' => 200 ],
				];
			},
			10,
			3
		);
	}

	/**
	 * A cart double that remembers whether its totals were recalculated.
	 *
	 * @param bool $needs_payment     What the cart answers before recalculation.
	 * @param bool $needs_after_calc  What it answers after, when it changes.
	 * @return object
	 */
	private function cart( $needs_payment, $needs_after_calc = null ) {
		return new class( $needs_payment, $needs_after_calc ) {
			/**
			 * Current answer.
			 *
			 * @var bool
			 */
			private $needs_payment;
			/**
			 * Answer after recalculation.
			 *
			 * @var bool|null
			 */
			private $needs_after_calc;
			/**
			 * Constructor.
			 *
			 * @param bool      $needs_payment    Current answer.
			 * @param bool|null $needs_after_calc Answer after recalculation.
			 */
			public function __construct( $needs_payment, $needs_after_calc ) {
				$this->needs_payment    = $needs_payment;
				$this->needs_after_calc = $needs_after_calc;
			}
			/**
			 * Whether the cart needs payment.
			 *
			 * @return bool
			 */
			public function needs_payment() {
				return $this->needs_payment;
			}
			/**
			 * Recalculate totals.
			 */
			public function calculate_totals() {
				if ( null !== $this->needs_after_calc ) {
					$this->needs_payment = $this->needs_after_calc;
				}
			}
		};
	}

	/**
	 * Build a checkout request.
	 *
	 * @param string $gateway Gateway id.
	 * @param array  $headers Request headers.
	 * @param array  $details Payment details, key to value.
	 * @param string $route   Route.
	 * @return WP_REST_Request
	 */
	private function request( $gateway = 'cheque', $headers = [], $details = [], $route = '/wc/store/v1/checkout' ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'payment_method', $gateway );
		$payment_data = [];
		foreach ( $details as $key => $value ) {
			$payment_data[] = [
				'key'   => $key,
				'value' => $value,
			];
		}
		$request->set_param( 'payment_data', $payment_data );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return $request;
	}

	/**
	 * Attach a token to a request as the Checkout block does.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param mixed           $token   Token.
	 * @return WP_REST_Request
	 */
	private function with_token( $request, $token ) {
		$request->set_param( 'extensions', [ 'newspack' => [ 'recaptcha_token' => $token ] ] );
		return $request;
	}

	/**
	 * A genuine Stripe wallet request.
	 *
	 * @return array Headers.
	 */
	private function stripe_headers() {
		return [
			'X-WCSTRIPE-EXPRESS-CHECKOUT'       => 'true',
			'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => wp_create_nonce( 'wc_store_api_express_checkout' ),
		];
	}

	/**
	 * A genuine WooPayments wallet request.
	 *
	 * @return array Headers.
	 */
	private function woopayments_headers() {
		return [
			'X-WooPayments-Tokenized-Cart'       => 'true',
			'X-WooPayments-Tokenized-Cart-Nonce' => wp_create_nonce( 'woopayments_tokenized_cart_nonce' ),
		];
	}

	/**
	 * Load the Stripe client mock with the objects the tests look up.
	 */
	private function stripe() {
		require_once __DIR__ . '/../../../mocks/stripe-api-mock.php';
		WC_Stripe_API::$objects  = [
			'pm_apple'      => $this->card( 'apple_pay' ),
			'pm_gpay'       => $this->card( 'google_pay' ),
			'pm_plain'      => $this->card( null ),
			'pm_samsung'    => $this->card( 'samsung_pay' ),
			'pm_cardlink'   => $this->card( 'link' ),
			'pm_link'       => (object) [ 'type' => 'link' ],
			'pm_amazon'     => (object) [ 'type' => 'amazon_pay' ],
			'pm_sepa'       => (object) [ 'type' => 'sepa_debit' ],
			'pm_null'       => null,
			'ctoken_gpay'   => (object) [ 'payment_method_preview' => $this->card( 'google_pay' ) ],
			'ctoken_apple'  => (object) [ 'payment_method_preview' => $this->card( 'apple_pay' ) ],
			'ctoken_amazon' => (object) [ 'payment_method_preview' => (object) [ 'type' => 'amazon_pay' ] ],
			'ctoken_plain'  => (object) [ 'payment_method_preview' => $this->card( null ) ],
			'ctoken_empty'  => (object) [ 'object' => 'confirmation_token' ],
		];
		WC_Stripe_API::$calls    = [];
		WC_Stripe_API::$timeouts = [];
		WC_Stripe_API::$throw    = false;
		WC_Stripe::$enabled      = [ 'card', 'link', 'amazon_pay' ];
		WC_Stripe::$gateway_missing = false;
	}

	/**
	 * A Stripe card payment method, as the API returns it.
	 *
	 * @param string|null $wallet Wallet type, or null for a plain card.
	 * @return object
	 */
	private function card( $wallet ) {
		return (object) [
			'type' => 'card',
			'card' => (object) [ 'wallet' => $wallet ? (object) [ 'type' => $wallet ] : null ],
		];
	}

	/**
	 * Payment details as the Stripe express clients send them.
	 *
	 * @param array $details Details to add or override.
	 * @return array
	 */
	private function stripe_details( $details ) {
		return array_merge( [ 'wc-stripe-is-deferred-intent' => true ], $details );
	}

	/**
	 * A Stripe wallet request carrying the given details.
	 *
	 * @param array $details Details, merged over the deferred-intent flag.
	 * @return WP_REST_Request
	 */
	private function stripe_request( $details ) {
		return $this->request( 'stripe', $this->stripe_headers(), $this->stripe_details( $details ) );
	}

	/**
	 * Run the protection and return the error code of any refusal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string Error code, or '' when the request may proceed.
	 */
	private function code( $request ) {
		try {
			Protection::protect_checkout( null, $request );
		} catch ( RouteException $e ) {
			return $e->getErrorCode();
		}
		return '';
	}

	/**
	 * Without a token, an order is refused whichever gateway it names.
	 */
	public function test_no_token_refused() {
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'cheque' ) ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'stripe' ) ) );
	}

	/**
	 * A valid token passes and an invalid one does not.
	 */
	public function test_token_is_verified() {
		$this->assertSame( '', $this->code( $this->with_token( $this->request(), 'good' ) ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->with_token( $this->request(), 'bad' ) ) );
	}

	/**
	 * A token in a form-encoded body doesn't stand in for the request's own.
	 */
	public function test_form_token_is_not_read() {
		$_POST['g-recaptcha-response'] = 'good';
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request() ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->with_token( $this->request(), [ 'good' ] ) ) );
	}

	/**
	 * The route stays open when reCAPTCHA can't be used.
	 */
	public function test_open_without_recaptcha() {
		update_option( 'newspack_recaptcha_use_captcha', false );
		$this->assertSame( '', $this->code( $this->request() ) );
	}

	/**
	 * The route stays open when the shared filter turns verification off.
	 */
	public function test_open_when_filtered_off() {
		add_filter( 'newspack_recaptcha_verify_captcha', '__return_false' );
		$this->assertSame( '', $this->code( $this->request() ) );
	}

	/**
	 * Paying for an existing order is left alone.
	 */
	public function test_pay_for_order_untouched() {
		$this->assertSame( '', $this->code( $this->request( 'cheque', [], [], '/wc/store/v1/checkout/123' ) ) );
	}

	/**
	 * Only the path decides, in whatever case it arrives.
	 */
	public function test_plain_route_cannot_pose_as_pay_for_order() {
		$request = $this->request();
		$request->set_param( 'id', 123 );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $request ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'cheque', [], [], '/WC/Store/V1/Checkout' ) ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'cheque', [], [], '/wc/store/checkout' ) ) );
	}

	/**
	 * Stripe and WooPayments wallets need no token.
	 */
	public function test_wallets_pass() {
		$this->assertSame( '', $this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] ) ) );
		$this->assertSame(
			'',
			$this->code(
				$this->stripe_request(
					[
						'wc-stripe-payment-method'     => '',
						'wc-stripe-confirmation-token' => 'ctoken_gpay',
					]
				)
			)
		);
		$this->assertSame( [ 'pm_apple', 'ctoken_gpay' ], WC_Stripe_API::$calls );
		$this->assertSame( '', $this->code( $this->request( 'woocommerce_payments', $this->woopayments_headers(), [ 'wcpay-payment-method' => 'pm_1' ] ) ) );
		$this->assertSame( '', $this->code( $this->request( 'woocommerce_payments', $this->woopayments_headers(), [ 'wcpay-confirmation-token' => 'ctoken_1' ] ) ) );
	}

	/**
	 * Each part of the wallet signal is required.
	 */
	public function test_wallet_negatives() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		$details = $this->stripe_details( [ 'wc-stripe-payment-method' => 'pm_apple' ] );
		$cases   = [
			'bad nonce'        => [ 'stripe', array_merge( $this->stripe_headers(), [ 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => 'nope' ] ), $details ],
			'nonce of another' => [ 'stripe', array_merge( $this->stripe_headers(), [ 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) ] ), $details ],
			'header absent'    => [ 'stripe', [ 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => wp_create_nonce( 'wc_store_api_express_checkout' ) ], $details ],
			'header false'     => [ 'stripe', array_merge( $this->stripe_headers(), [ 'X-WCSTRIPE-EXPRESS-CHECKOUT' => 'false' ] ), $details ],
			'no details'       => [ 'stripe', $this->stripe_headers(), $this->stripe_details( [] ) ],
			'empty details'    => [ 'stripe', $this->stripe_headers(), $this->stripe_details( [ 'wc-stripe-payment-method' => '' ] ) ],
			'boolean detail'   => [ 'stripe', $this->stripe_headers(), $this->stripe_details( [ 'wc-stripe-payment-method' => true ] ) ],
			'array detail'     => [ 'stripe', $this->stripe_headers(), $this->stripe_details( [ 'wc-stripe-payment-method' => [ 'pm_apple' ] ] ) ],
			'other gateway'    => [ 'woocommerce_payments', $this->stripe_headers(), [ 'wcpay-payment-method' => 'pm_1' ] ],
			'offline gateway'  => [ 'cheque', $this->stripe_headers(), $details ],
		];
		foreach ( $cases as $name => $case ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( ...$case ) ), $name );
		}
		$this->assertSame( [], WC_Stripe_API::$calls, 'no lookup before the cheap checks pass' );
	}

	/**
	 * A forged signal can't place a free order, which no gateway would ever fail.
	 */
	public function test_zero_total_needs_a_token() {
		$GLOBALS['newspack_test_wc']->cart = $this->cart( false );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] ) ) );
		$this->assertSame( [], WC_Stripe_API::$calls );
	}

	/**
	 * Totals are judged with the address the request brought.
	 */
	public function test_zero_total_after_the_request_address() {
		$GLOBALS['newspack_test_wc']->cart = $this->cart( true, false );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] ) ) );
		$this->assertSame( [], WC_Stripe_API::$calls );
	}

	/**
	 * A sixth attempt in a window is refused, and five pass.
	 */
	public function test_rate_limit() {
		for ( $i = 1; $i <= Protection::RATE_LIMIT; $i++ ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request() ), "attempt $i" );
		}
		$this->assertSame( Protection::ERROR_RATE_LIMIT, $this->code( $this->request() ) );
	}

	/**
	 * The limit doesn't depend on reCAPTCHA.
	 */
	public function test_rate_limit_without_recaptcha() {
		update_option( 'newspack_recaptcha_use_captcha', false );
		for ( $i = 1; $i <= Protection::RATE_LIMIT; $i++ ) {
			$this->code( $this->request() );
		}
		$this->assertSame( Protection::ERROR_RATE_LIMIT, $this->code( $this->request() ) );
	}

	/**
	 * Wallet requests count towards the limit.
	 */
	public function test_rate_limit_counts_wallets() {
		$request = $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] );
		for ( $i = 1; $i <= Protection::RATE_LIMIT; $i++ ) {
			$this->assertSame( '', $this->code( $request ) );
		}
		$this->assertSame( Protection::ERROR_RATE_LIMIT, $this->code( $request ) );
	}

	/**
	 * A limit of zero turns it off.
	 */
	public function test_rate_limit_can_be_disabled() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		for ( $i = 0; $i <= Protection::RATE_LIMIT; $i++ ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request() ) );
		}
	}

	/**
	 * A client can't escape the limit by changing a forwarded header.
	 */
	public function test_rate_limit_ignores_forwarded_headers() {
		for ( $i = 1; $i <= Protection::RATE_LIMIT; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = "203.0.113.$i";
			$this->code( $this->request() );
		}
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.200';
		$this->assertSame( Protection::ERROR_RATE_LIMIT, $this->code( $this->request() ) );
	}

	/**
	 * The limit's options don't outlive the call.
	 */
	public function test_rate_limit_options_filter_is_removed() {
		$this->code( $this->request() );
		$this->assertFalse( has_filter( 'woocommerce_store_api_rate_limit_options' ) );
	}

	/**
	 * Each Stripe wallet the operator accepted passes, after Stripe is asked.
	 */
	public function test_stripe_qualifying_wallets() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		$cases = [
			[ 'wc-stripe-payment-method' => 'pm_apple' ],
			[ 'wc-stripe-payment-method' => 'pm_gpay' ],
			[ 'wc-stripe-payment-method' => 'pm_link' ],
			[ 'wc-stripe-payment-method' => 'pm_amazon' ],
			[ 'wc-stripe-confirmation-token' => 'ctoken_apple' ],
			[ 'wc-stripe-confirmation-token' => 'ctoken_gpay' ],
			[ 'wc-stripe-confirmation-token' => 'ctoken_amazon' ],
		];
		foreach ( $cases as $details ) {
			WC_Stripe_API::$calls = [];
			$this->assertSame( '', $this->code( $this->stripe_request( $details ) ), wp_json_encode( $details ) );
			$this->assertSame( array_values( $details ), WC_Stripe_API::$calls, wp_json_encode( $details ) );
		}
	}

	/**
	 * Link and Amazon Pay are exempt only where the gateway offers them, so the
	 * exemption matches what the express buttons can send. Card wallets don't depend
	 * on the setting.
	 */
	public function test_stripe_link_and_amazon_pay_follow_the_gateway() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		$link   = [ 'wc-stripe-payment-method' => 'pm_link' ];
		$amazon = [ 'wc-stripe-payment-method' => 'pm_amazon' ];
		$token  = [ 'wc-stripe-confirmation-token' => 'ctoken_amazon' ];
		$apple  = [ 'wc-stripe-payment-method' => 'pm_apple' ];

		WC_Stripe::$enabled = [ 'card' ];
		foreach ( [ $link, $amazon, $token ] as $details ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $details ) ), wp_json_encode( $details ) );
		}
		$this->assertSame( '', $this->code( $this->stripe_request( $apple ) ) );

		WC_Stripe::$enabled = [ 'card', 'link' ];
		$this->assertSame( '', $this->code( $this->stripe_request( $link ) ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $amazon ) ) );

		WC_Stripe::$enabled = [ 'card', 'amazon_pay' ];
		$this->assertSame( '', $this->code( $this->stripe_request( $token ) ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $link ) ) );

		// No usable answer from the gateway refuses the exemption, and leaves card wallets alone.
		WC_Stripe::$enabled = null;
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $link ) ) );
		WC_Stripe::$gateway_missing = true;
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $amazon ) ) );
		$this->assertSame( '', $this->code( $this->stripe_request( $apple ) ) );
	}

	/**
	 * Anything Stripe doesn't confirm as an accepted wallet needs a token.
	 */
	public function test_stripe_lookup_refusals() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		$cases = [
			'plain card'              => [ 'wc-stripe-payment-method' => 'pm_plain' ],
			'other wallet'            => [ 'wc-stripe-payment-method' => 'pm_samsung' ],
			'card wallet link'        => [ 'wc-stripe-payment-method' => 'pm_cardlink' ],
			'other type'              => [ 'wc-stripe-payment-method' => 'pm_sepa' ],
			'not found'               => [ 'wc-stripe-payment-method' => 'pm_unknown' ],
			'null answer'             => [ 'wc-stripe-payment-method' => 'pm_null' ],
			'plain token'             => [ 'wc-stripe-confirmation-token' => 'ctoken_plain' ],
			'token without preview'   => [ 'wc-stripe-confirmation-token' => 'ctoken_empty' ],
			'wallet with plain token' => [
				'wc-stripe-payment-method'     => 'pm_apple',
				'wc-stripe-confirmation-token' => 'ctoken_plain',
			],
		];
		foreach ( $cases as $name => $details ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $details ) ), $name );
		}

		// Ids of the wrong shape are refused without asking Stripe.
		$shapes = [
			'source id'             => [ 'wc-stripe-payment-method' => 'src_apple' ],
			'path in id'            => [ 'wc-stripe-payment-method' => 'pm_x/../customers' ],
			'path in token'         => [ 'wc-stripe-confirmation-token' => 'ctoken_x/../customers' ],
			'token in method field' => [ 'wc-stripe-payment-method' => 'ctoken_gpay' ],
			'method in token field' => [ 'wc-stripe-confirmation-token' => 'pm_apple' ],
		];
		WC_Stripe_API::$calls = [];
		foreach ( $shapes as $name => $details ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $details ) ), $name );
		}
		$this->assertSame( [], WC_Stripe_API::$calls );
	}

	/**
	 * The id is looked up as WooCommerce hands it to Stripe, cleaned.
	 */
	public function test_stripe_lookup_uses_cleaned_value() {
		$this->assertSame( '', $this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => " pm_apple\n" ] ) ) );
		$this->assertSame( [ 'pm_apple' ], WC_Stripe_API::$calls );
	}

	/**
	 * Stripe charges only what was checked: the deferred-intent path, and no saved card.
	 */
	public function test_stripe_charge_path() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		foreach ( [
			'missing' => null,
			'empty'   => '',
			'zero'    => '0',
			'false'   => false,
		] as $name => $flag ) {
			$details = [
				'wc-stripe-payment-method'     => 'pm_apple',
				'wc-stripe-is-deferred-intent' => $flag,
			];
			if ( null === $flag ) {
				unset( $details['wc-stripe-is-deferred-intent'] );
				$request = $this->request( 'stripe', $this->stripe_headers(), $details );
			} else {
				$request = $this->stripe_request( $details );
			}
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $request ), "deferred flag $name" );
		}
		$saved = [
			'wc-stripe-payment-method' => 'pm_apple',
			'wc-stripe-payment-token'  => '1',
		];
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( $saved ) ), 'saved card' );
		$saved['wc-stripe-payment-token'] = 'new';
		WC_Stripe_API::$calls             = [];
		$this->assertSame( '', $this->code( $this->stripe_request( $saved ) ), 'new card' );
		$this->assertSame( [ 'pm_apple' ], WC_Stripe_API::$calls );
	}

	/**
	 * A key in another case, or a repeated key, is read as the gateway reads it.
	 */
	public function test_stripe_keys_read_as_the_gateway_reads_them() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		$request = $this->stripe_request(
			[
				'wc-stripe-payment-method'     => 'pm_apple',
				'WC-Stripe-Confirmation-Token' => 'ctoken_plain',
			]
		);
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $request ), 'mixed-case key' );

		$request = $this->stripe_request( [] );
		$request->set_param(
			'payment_data',
			[
				[
					'key'   => 'wc-stripe-is-deferred-intent',
					'value' => true,
				],
				[
					'key'   => 'wc-stripe-payment-method',
					'value' => 'pm_apple',
				],
				[
					'key'   => 'wc-stripe-payment-method',
					'value' => 'pm_plain',
				],
			]
		);
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $request ), 'last duplicate wins' );
	}

	/**
	 * A lookup that throws refuses the exemption and leaves no filter behind.
	 */
	public function test_stripe_lookup_throws() {
		WC_Stripe_API::$throw = true;
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] ) ) );
		$this->assertFalse( has_filter( 'http_request_args' ) );
	}

	/**
	 * The lookup waits ten seconds at most, and only for itself.
	 */
	public function test_stripe_lookup_timeout() {
		$this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] ) );
		$this->assertSame( [ 10 ], WC_Stripe_API::$timeouts );
		$this->assertFalse( has_filter( 'http_request_args' ) );
	}

	/**
	 * Without the Stripe client there is nothing to ask, so no exemption.
	 */
	public function test_stripe_client_missing() {
		$this->assertFalse( class_exists( 'WC_Stripe_API', false ) );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->stripe_request( [ 'wc-stripe-payment-method' => 'pm_apple' ] ) ) );
	}

	/**
	 * WooPayments reads its keys as the gateway does too, and needs no lookup.
	 */
	public function test_woopayments_keys_normalized() {
		$this->assertSame( '', $this->code( $this->request( 'woocommerce_payments', $this->woopayments_headers(), [ 'WCPAY-Payment-Method' => 'pm_1' ] ) ) );
		$this->assertSame( [], WC_Stripe_API::$calls );
	}

	/**
	 * A WooPay request signed with the blog token needs no token and isn't limited.
	 */
	public function test_signed_woopay() {
		$session = '\WCPay\WooPay\WooPay_Session';
		if ( ! class_exists( $session ) ) {
			eval( 'namespace WCPay\WooPay; class WooPay_Session { public static $signed = false; public static function has_valid_request_signature() { return self::$signed; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged, MediaWiki.Usage.ForbiddenFunctions.eval -- A class mock defined only in this test's process.
		}
		$request = $this->request( 'woocommerce_payments' );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $request ) );

		$session::$signed = true;
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'cheque' ) ) );
		for ( $i = 0; $i <= Protection::RATE_LIMIT + 1; $i++ ) {
			$this->assertSame( '', $this->code( $request ) );
		}
	}

	/**
	 * Refusal comes before the listener that expects the account the checkout will create.
	 */
	public function test_runs_before_other_listeners() {
		Protection::init();
		$this->assertSame( 1, has_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ Protection::class, 'protect_checkout' ] ) );
	}

	/**
	 * The Store API drops undeclared extension data, so the token field must be
	 * declared on the checkout endpoint once WooCommerce Blocks has loaded.
	 */
	public function test_token_field_is_declared() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			/**
			 * Mock the Store API's registration helper, recording what it is given.
			 *
			 * @param array $args Registration arguments.
			 */
			function woocommerce_store_api_register_endpoint_data( $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock of a WooCommerce function, isolated to a separate test process.
				$GLOBALS['newspack_test_endpoint_data'][] = $args;
				return true;
			}
		}
		$GLOBALS['newspack_test_endpoint_data'] = [];

		Protection::init();
		$this->assertSame( [], $GLOBALS['newspack_test_endpoint_data'] );
		do_action( 'woocommerce_blocks_loaded' );

		$this->assertCount( 1, $GLOBALS['newspack_test_endpoint_data'] );
		$args = $GLOBALS['newspack_test_endpoint_data'][0];
		$this->assertSame( 'checkout', $args['endpoint'] );
		$this->assertSame( 'newspack', $args['namespace'] );
		$this->assertArrayHasKey( 'recaptcha_token', call_user_func( $args['schema_callback'] ) );
	}
}
