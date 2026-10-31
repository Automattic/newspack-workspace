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
		$this->assertSame( '', $this->code( $this->request( 'stripe', $this->stripe_headers(), [ 'wc-stripe-payment-method' => 'pm_1' ] ) ) );
		$this->assertSame(
			'',
			$this->code(
				$this->request(
					'stripe',
					$this->stripe_headers(),
					[
						'wc-stripe-payment-method'     => '',
						'wc-stripe-confirmation-token' => 'ctoken_1',
					]
				)
			)
		);
		$this->assertSame( '', $this->code( $this->request( 'woocommerce_payments', $this->woopayments_headers(), [ 'wcpay-payment-method' => 'pm_1' ] ) ) );
		$this->assertSame( '', $this->code( $this->request( 'woocommerce_payments', $this->woopayments_headers(), [ 'wcpay-confirmation-token' => 'ctoken_1' ] ) ) );
	}

	/**
	 * Each part of the wallet signal is required.
	 */
	public function test_wallet_negatives() {
		add_filter( 'newspack_store_api_checkout_rate_limit', '__return_zero' );
		$details = [ 'wc-stripe-payment-method' => 'pm_1' ];
		$cases   = [
			'bad nonce'        => [ 'stripe', array_merge( $this->stripe_headers(), [ 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => 'nope' ] ), $details ],
			'nonce of another' => [ 'stripe', array_merge( $this->stripe_headers(), [ 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) ] ), $details ],
			'header absent'    => [ 'stripe', [ 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE' => wp_create_nonce( 'wc_store_api_express_checkout' ) ], $details ],
			'header false'     => [ 'stripe', array_merge( $this->stripe_headers(), [ 'X-WCSTRIPE-EXPRESS-CHECKOUT' => 'false' ] ), $details ],
			'no details'       => [ 'stripe', $this->stripe_headers(), [] ],
			'empty details'    => [ 'stripe', $this->stripe_headers(), [ 'wc-stripe-payment-method' => '' ] ],
			'boolean detail'   => [ 'stripe', $this->stripe_headers(), [ 'wc-stripe-payment-method' => true ] ],
			'other gateway'    => [ 'woocommerce_payments', $this->stripe_headers(), [ 'wcpay-payment-method' => 'pm_1' ] ],
			'offline gateway'  => [ 'cheque', $this->stripe_headers(), $details ],
		];
		foreach ( $cases as $name => $case ) {
			$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( ...$case ) ), $name );
		}
	}

	/**
	 * A forged signal can't place a free order, which no gateway would ever fail.
	 */
	public function test_zero_total_needs_a_token() {
		$GLOBALS['newspack_test_wc']->cart = $this->cart( false );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'stripe', $this->stripe_headers(), [ 'wc-stripe-payment-method' => 'pm_1' ] ) ) );
	}

	/**
	 * Totals are judged with the address the request brought.
	 */
	public function test_zero_total_after_the_request_address() {
		$GLOBALS['newspack_test_wc']->cart = $this->cart( true, false );
		$this->assertSame( Protection::ERROR_CAPTCHA, $this->code( $this->request( 'stripe', $this->stripe_headers(), [ 'wc-stripe-payment-method' => 'pm_1' ] ) ) );
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
		$request = $this->request( 'stripe', $this->stripe_headers(), [ 'wc-stripe-payment-method' => 'pm_1' ] );
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
}
