<?php
/**
 * Protects the WooCommerce Store API checkout route from unattended orders.
 *
 * @package Newspack
 */

namespace Newspack;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\RateLimits;

defined( 'ABSPATH' ) || exit;

/**
 * Applies reCAPTCHA and a rate limit to `POST /wc/store/v1/checkout`.
 *
 * The classic checkout verifies reCAPTCHA on `woocommerce_checkout_process`, which
 * the Store API never fires, so a script holding a cart token could place orders on
 * a protected site. The Checkout block supplies a token through its own script.
 *
 * Express wallets post to this route too, and their buttons offer no moment to fetch
 * a token, so a verified wallet request is exempt. Its header and nonce are not proof,
 * since a script can read the nonce from a page that shows the buttons. For Stripe the
 * exemption also needs Stripe to confirm the payment method is an accepted wallet, and
 * for Link and Amazon Pay that the gateway offers that wallet, so the exemption matches
 * what its express buttons can send.
 * WooPayments has no such lookup for its confirmation tokens, so there the signal
 * alone decides. Either way the request must go to a gateway that takes a payment, and
 * the rate limit still applies.
 */
class WooCommerce_Store_API_Checkout_Protection {
	/**
	 * Checkout attempts one client may make in a window. Leaves room for a reader
	 * retrying a declined card.
	 */
	const RATE_LIMIT = 5;

	/**
	 * Length of a rate limit window, in seconds.
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * REST error code for a request that failed reCAPTCHA.
	 */
	const ERROR_CAPTCHA = 'newspack_store_api_checkout_captcha';

	/**
	 * REST error code for a client over the rate limit.
	 */
	const ERROR_RATE_LIMIT = 'newspack_store_api_checkout_rate_limit';

	/**
	 * Seconds to wait for Stripe to describe a wallet payment.
	 */
	const STRIPE_LOOKUP_TIMEOUT = 10;

	/**
	 * Stripe payment method types exempt on their own, where the gateway offers them.
	 */
	const STRIPE_WALLET_TYPES = [ 'link', 'amazon_pay' ];

	/**
	 * Stripe card wallets that are exempt.
	 */
	const STRIPE_CARD_WALLETS = [ 'apple_pay', 'google_pay' ];

	/**
	 * The wallet clients' request signatures, by gateway id. Each gateway verifies
	 * the same headers itself, and always sends one of the detail keys with a value
	 * from the wallet.
	 */
	const WALLETS = [
		'stripe'               => [
			'header'       => 'X-WCSTRIPE-EXPRESS-CHECKOUT',
			'nonce_header' => 'X-WCSTRIPE-EXPRESS-CHECKOUT-NONCE',
			'nonce_action' => 'wc_store_api_express_checkout',
			'detail_keys'  => [ 'wc-stripe-payment-method', 'wc-stripe-confirmation-token' ],
		],
		'woocommerce_payments' => [
			'header'       => 'X-WooPayments-Tokenized-Cart',
			'nonce_header' => 'X-WooPayments-Tokenized-Cart-Nonce',
			'nonce_action' => 'woopayments_tokenized_cart_nonce',
			'detail_keys'  => [ 'wcpay-payment-method', 'wcpay-confirmation-token' ],
		],
	];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		// Fires after the cart is validated and before the draft order exists. Priority 1
		// so a refused request reaches no other listener.
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ __CLASS__, 'protect_checkout' ], 1, 2 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_token_field();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_token_field' ] );
		}
	}

	/**
	 * Declare the token the Checkout block sends. The Store API keeps only the
	 * extension data an endpoint declares and drops the rest before any hook runs,
	 * so without this the token never reaches get_token().
	 */
	public static function register_token_field(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		\woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => 'checkout', // CheckoutSchema::IDENTIFIER.
				'namespace'       => 'newspack',
				'schema_callback' => function () {
					return [
						'recaptcha_token' => [
							'description' => __( 'reCAPTCHA token for the checkout.', 'newspack-plugin' ),
							'type'        => [ 'string', 'null' ],
							'context'     => [],
						],
					];
				},
			]
		);
	}

	/**
	 * Refuse a checkout request that may not place an order.
	 *
	 * @param \WC_Customer|null $customer Customer. Unused.
	 * @param \WP_REST_Request  $request  Checkout request.
	 *
	 * @throws RouteException When the request is refused.
	 */
	public static function protect_checkout( $customer, $request ): void {
		if ( self::is_pay_for_order( $request ) || self::is_signed_woopay_request( $request ) ) {
			return;
		}

		self::check_rate_limit();

		if ( ! Recaptcha::should_verify_checkout() || self::is_wallet_request( $request ) ) {
			return;
		}

		$check = Recaptcha::verify_captcha( self::get_token( $request ) );
		if ( \is_wp_error( $check ) ) {
			// The message travels as JSON and the client renders it, as with WooCommerce's own Store API errors.
			throw new RouteException( self::ERROR_CAPTCHA, $check->get_error_message(), 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Whether this is `POST /checkout/{id}`, which fires the same action and pays for
	 * an order that already exists. The path decides, because a client can add an
	 * `id` parameter to the plain route, and routes match case-insensitively.
	 *
	 * @param \WP_REST_Request $request Checkout request.
	 *
	 * @return bool
	 */
	private static function is_pay_for_order( $request ): bool {
		return 1 === preg_match( '#/checkout/\d+/?$#i', $request->get_route() );
	}

	/**
	 * Whether this is WooPay, which calls the route from its own servers with no
	 * browser. Unlike a wallet nonce, the blog token signature can't be read from a
	 * page. It covers the whole HTTP request, and guests all share WooPay's address,
	 * so it also skips the rate limit.
	 *
	 * @param \WP_REST_Request $request Checkout request.
	 *
	 * @return bool
	 */
	private static function is_signed_woopay_request( $request ): bool {
		$check = [ '\WCPay\WooPay\WooPay_Session', 'has_valid_request_signature' ];
		return 'woocommerce_payments' === $request['payment_method'] && is_callable( $check ) && $check();
	}

	/**
	 * Whether a request carries its gateway's wallet signal, and the cart has to be
	 * paid for. A zero-total order never reaches a gateway, so a forged signal would
	 * place it outright. Totals are recalculated because this action runs after the
	 * request's address is applied, and that address can change them. Stripe is asked
	 * last, so a request that fails the cheap checks costs no API call.
	 *
	 * @param \WP_REST_Request $request Checkout request.
	 *
	 * @return bool
	 */
	private static function is_wallet_request( $request ): bool {
		$wallet = self::WALLETS[ (string) $request['payment_method'] ] ?? null;
		if ( ! $wallet || 'true' !== $request->get_header( $wallet['header'] ) ) {
			return false;
		}
		if ( ! \wp_verify_nonce( (string) $request->get_header( $wallet['nonce_header'] ), $wallet['nonce_action'] ) ) {
			return false;
		}
		$data = self::get_payment_data( $request );
		if ( ! self::has_payment_details( $data, $wallet['detail_keys'] ) ) {
			return false;
		}
		\WC()->cart->calculate_totals();
		if ( ! \WC()->cart->needs_payment() ) {
			return false;
		}
		return 'stripe' !== $request['payment_method'] || self::is_verified_stripe_wallet( $data );
	}

	/**
	 * Get the payment data as WooCommerce hands it to the gateway: keys through
	 * sanitize_key(), values through wc_clean(), the last of a repeated key winning.
	 * Reading it any other way would let a key in another case, or a repeat, carry
	 * something past these checks that the gateway then charges.
	 *
	 * @param \WP_REST_Request $request Checkout request.
	 *
	 * @return array
	 */
	private static function get_payment_data( $request ): array {
		$data = [];
		foreach ( (array) $request['payment_data'] as $item ) {
			if ( is_array( $item ) && isset( $item['key'] ) && is_scalar( $item['key'] ) ) {
				$data[ \sanitize_key( (string) $item['key'] ) ] = \wc_clean( $item['value'] ?? '' );
			}
		}
		return $data;
	}

	/**
	 * Whether the payment data has a non-empty string under one of the given keys.
	 *
	 * @param array    $data Payment data, from get_payment_data().
	 * @param string[] $keys Payment data keys.
	 *
	 * @return bool
	 */
	private static function has_payment_details( $data, $keys ): bool {
		foreach ( $keys as $key ) {
			if ( is_string( $data[ $key ] ?? null ) && '' !== $data[ $key ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether Stripe confirms that what the request will be charged with is an accepted
	 * wallet: Apple Pay or Google Pay, or Link or Amazon Pay where the gateway enables
	 * them. Every payment method and confirmation token in the request must qualify.
	 *
	 * Stripe charges exactly these only on its deferred-intent path, which its express
	 * clients always ask for, and only when no saved card is named. The first two
	 * checks below are Stripe's own tests for those. Any failure to get an answer refuses the exemption, and the
	 * request then needs a reCAPTCHA token like any other.
	 *
	 * @param array $data Payment data, from get_payment_data().
	 *
	 * @return bool
	 */
	private static function is_verified_stripe_wallet( $data ): bool {
		if ( empty( $data['wc-stripe-is-deferred-intent'] ) ) {
			return false;
		}
		if ( isset( $data['wc-stripe-payment-token'] ) && 'new' !== $data['wc-stripe-payment-token'] ) {
			return false;
		}
		if ( ! class_exists( 'WC_Stripe_API' ) ) {
			return false;
		}

		$lookups = [];
		foreach ( [
			'wc-stripe-payment-method'     => 'pm',
			'wc-stripe-confirmation-token' => 'ctoken',
		] as $key => $prefix ) {
			$id = $data[ $key ] ?? '';
			if ( '' === $id ) {
				continue;
			}
			// The id becomes part of an API path, so it must be the kind its field expects and nothing more.
			if ( ! is_string( $id ) || 1 !== preg_match( '/^' . $prefix . '_[A-Za-z0-9]+\z/', $id ) ) {
				return false;
			}
			$lookups[ $prefix ] = $id;
		}
		if ( ! $lookups ) {
			return false;
		}

		$cap_timeout = function ( $args, $url ) {
			if ( 0 === strpos( (string) $url, 'https://api.stripe.com/' ) ) {
				$args['timeout'] = min( (float) ( $args['timeout'] ?? self::STRIPE_LOOKUP_TIMEOUT ), self::STRIPE_LOOKUP_TIMEOUT );
			}
			return $args;
		};
		// Lowers Stripe's own 70 second timeout for this one call; a wallet buyer is waiting on it.
		\add_filter( 'http_request_args', $cap_timeout, PHP_INT_MAX, 2 ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args
		try {
			foreach ( $lookups as $prefix => $id ) {
				if ( 'pm' === $prefix ) {
					$object = \WC_Stripe_API::get_payment_method( $id );
				} else {
					$token  = \WC_Stripe_API::retrieve( 'confirmation_tokens/' . $id );
					$object = is_object( $token ) && empty( $token->error ) ? ( $token->payment_method_preview ?? null ) : null;
				}
				if ( ! self::is_accepted_stripe_wallet( $object ) ) {
					return false;
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			\remove_filter( 'http_request_args', $cap_timeout, PHP_INT_MAX );
		}
	}

	/**
	 * Whether a Stripe payment method, or a confirmation token's preview of one, is
	 * an accepted wallet.
	 *
	 * @param mixed $payment_method What Stripe returned.
	 *
	 * @return bool
	 */
	private static function is_accepted_stripe_wallet( $payment_method ): bool {
		if ( ! is_object( $payment_method ) || ! empty( $payment_method->error ) ) {
			return false;
		}
		$type = $payment_method->type ?? '';
		if ( in_array( $type, self::STRIPE_WALLET_TYPES, true ) ) {
			return self::stripe_gateway_offers( $type );
		}
		return 'card' === $type && in_array( $payment_method->card->wallet->type ?? '', self::STRIPE_CARD_WALLETS, true );
	}

	/**
	 * Whether the Stripe gateway has a payment method type turned on. Its express
	 * buttons check this list before they render, so a buyer who sees a Link or
	 * Amazon Pay button is on a site where it answers true, and a site where it
	 * answers false offers neither button. No answer refuses the exemption.
	 *
	 * @param string $type Stripe payment method type.
	 *
	 * @return bool
	 */
	private static function stripe_gateway_offers( string $type ): bool {
		if ( ! class_exists( 'WC_Stripe' ) || ! method_exists( 'WC_Stripe', 'get_instance' ) ) {
			return false;
		}
		$stripe = \WC_Stripe::get_instance();
		if ( ! is_object( $stripe ) || ! method_exists( $stripe, 'get_main_stripe_gateway' ) ) {
			return false;
		}
		$gateway = $stripe->get_main_stripe_gateway();
		if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'get_upe_enabled_payment_method_ids' ) ) {
			return false;
		}
		$enabled = $gateway->get_upe_enabled_payment_method_ids();
		return is_array( $enabled ) && in_array( $type, $enabled, true );
	}

	/**
	 * Get the token the Checkout block sent. Never null, which would have
	 * verify_captcha() read a form body instead.
	 *
	 * @param \WP_REST_Request $request Checkout request.
	 *
	 * @return string
	 */
	private static function get_token( $request ): string {
		$extensions = $request['extensions'];
		$token      = is_array( $extensions ) ? ( $extensions['newspack']['recaptcha_token'] ?? '' ) : '';
		return is_string( $token ) ? \sanitize_text_field( $token ) : '';
	}

	/**
	 * Count a checkout attempt and refuse a client over the limit.
	 *
	 * The attempt is counted here rather than through WooCommerce's own limiter so
	 * that checkouts inside a batch request count too, and its `rate_limit_checkout`
	 * feature is a store setting. Its storage is reused for the atomic counter:
	 * update_rate_limit() clamps at zero, so the limit is raised by one to tell the
	 * last allowed attempt from the first refused one.
	 *
	 * @throws RouteException When the client is over the limit.
	 */
	private static function check_rate_limit(): void {
		// REMOTE_ADDR is the client on Atomic, and unlike the forwarded headers a client can't set it.
		$client = \get_current_user_id() ? \get_current_user_id() : ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		/**
		 * Filters how many checkout attempts a client may make in a minute.
		 *
		 * Behind a proxy that hides client addresses, every guest counts as one client,
		 * so raise this there. Return 0 to turn the limit off.
		 *
		 * @param int        $limit  Attempts allowed. Default 5.
		 * @param int|string $client User ID, or the IP address of a guest.
		 */
		$limit = (int) \apply_filters( 'newspack_store_api_checkout_rate_limit', self::RATE_LIMIT, $client );
		if ( 0 >= $limit || ! class_exists( RateLimits::class ) ) {
			return;
		}

		$set_options = function ( $options ) use ( $limit ) {
			return array_merge(
				$options,
				[
					'limit'   => $limit + 1,
					'seconds' => self::RATE_LIMIT_WINDOW,
				]
			);
		};
		\add_filter( 'woocommerce_store_api_rate_limit_options', $set_options, PHP_INT_MAX );
		$state = RateLimits::update_rate_limit( 'newspack_checkout_' . md5( (string) $client ) );
		\remove_filter( 'woocommerce_store_api_rate_limit_options', $set_options, PHP_INT_MAX );

		if ( 0 >= (int) $state->remaining ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RouteException(
				self::ERROR_RATE_LIMIT,
				__( 'Please wait a moment before trying to complete this transaction again.', 'newspack-plugin' ),
				429
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}
WooCommerce_Store_API_Checkout_Protection::init();
