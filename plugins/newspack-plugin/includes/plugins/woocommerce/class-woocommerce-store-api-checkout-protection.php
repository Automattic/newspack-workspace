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
 * a token, so a verified wallet request is exempt. Its signal is not proof: a script
 * can read the nonce from a page that shows the buttons. It does force the request
 * onto a gateway that has to take a payment, and the rate limit still applies.
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
	 * request's address is applied, and that address can change them.
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
		if ( ! self::has_payment_details( $request, $wallet['detail_keys'] ) ) {
			return false;
		}
		\WC()->cart->calculate_totals();
		return \WC()->cart->needs_payment();
	}

	/**
	 * Whether the request has a non-empty value under one of the given payment data keys.
	 *
	 * @param \WP_REST_Request $request Checkout request.
	 * @param string[]         $keys    Payment data keys.
	 *
	 * @return bool
	 */
	private static function has_payment_details( $request, $keys ): bool {
		foreach ( (array) $request['payment_data'] as $item ) {
			if ( in_array( $item['key'] ?? '', $keys, true ) && is_string( $item['value'] ?? null ) && '' !== trim( $item['value'] ) ) {
				return true;
			}
		}
		return false;
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
