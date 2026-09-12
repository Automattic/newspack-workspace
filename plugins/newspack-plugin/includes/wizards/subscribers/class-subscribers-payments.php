<?php
/**
 * Subscribers wizard payment actions.
 *
 * The admin-on-behalf money endpoints behind the person profile's subscription
 * cards: re-point a subscription at another saved card, refund and/or cancel,
 * change the plan, and set a customer's default card. Everything here operates
 * on payment-token REFERENCES — no card data ever passes through these routes,
 * so they add no PCI scope.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Payment actions for the Subscribers wizard.
 */
class Subscribers_Payments {

	/**
	 * REST base under the Newspack namespace, matching the wizard's slug.
	 *
	 * @var string
	 */
	const REST_BASE = '/wizard/newspack-subscribers';

	/**
	 * Register the payment-action routes.
	 *
	 * Called from Subscribers_Wizard::register_api_endpoints(), so these routes
	 * exist exactly when the wizard's own routes do (feature flag on) and share
	 * its `manage_options` permission check.
	 *
	 * @param callable $permission_callback The wizard's api_permissions_check.
	 */
	public static function register_routes( $permission_callback ) {
		// The path `id` — a subscription ID on the /subscriptions/ routes, a
		// payment-token ID on /payment-methods/.
		$id_arg = [
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
		// The subscriber the admin believes they are acting on. The server
		// cross-checks it against the target's real owner, so a stale tab or a
		// copied ID cannot move the wrong reader's money (see assert_customer).
		$customer_arg = [
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE . '/subscriptions/(?P<id>\d+)/plan-options',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_plan_options' ],
				'permission_callback' => $permission_callback,
				'args'                => [ 'id' => $id_arg ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE . '/subscriptions/(?P<id>\d+)/payment-method',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_change_payment_method' ],
				'permission_callback' => $permission_callback,
				'args'                => [
					'id'          => $id_arg,
					'customer_id' => $customer_arg,
					'token_id'    => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE . '/subscriptions/(?P<id>\d+)/refund',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_refund' ],
				'permission_callback' => $permission_callback,
				'args'                => [
					'id'              => $id_arg,
					'customer_id'     => $customer_arg,
					'refund'          => [
						'type'    => 'boolean',
						'default' => false,
					],
					'cancel'          => [
						'type'    => 'boolean',
						'default' => false,
					],
					// The amount the client promised the admin; the refund is
					// refused if the real balance has drifted since (see api_refund).
					'expected_amount' => [
						'type'              => 'number',
						'required'          => false,
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE . '/subscriptions/(?P<id>\d+)/plan',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_change_plan' ],
				'permission_callback' => $permission_callback,
				'args'                => [
					'id'          => $id_arg,
					'customer_id' => $customer_arg,
					'product_id'  => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_BASE . '/payment-methods/(?P<id>\d+)/default',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_set_default_payment_method' ],
				'permission_callback' => $permission_callback,
				'args'                => [
					'id'          => $id_arg,
					'customer_id' => $customer_arg,
				],
			]
		);
	}

	/**
	 * A customer's saved payment methods, shaped for the profile's card list.
	 *
	 * Expiry state is resolved server-side so "expired" means one thing: the
	 * same rule refuses an expired card in the write endpoints below, and the
	 * client only ever renders the verdict.
	 *
	 * @param int $user_id The customer user ID.
	 *
	 * @return array<int,array>
	 */
	public static function payment_methods_for_user( $user_id ) {
		$out = [];
		foreach ( self::customer_tokens( $user_id ) as $token ) {
			$entry = [
				'id'        => (int) $token->get_id(),
				'gatewayId' => (string) $token->get_gateway_id(),
				'isDefault' => (bool) $token->is_default(),
				'label'     => method_exists( $token, 'get_display_name' ) ? (string) $token->get_display_name() : '',
				'brand'     => null,
				'last4'     => null,
				'expiry'    => null,
				'isExpired' => false,
			];
			if ( $token instanceof \WC_Payment_Token_CC ) {
				$month          = (int) $token->get_expiry_month();
				$year           = self::normalize_expiry_year( $token->get_expiry_year() );
				$entry['brand'] = (string) $token->get_card_type();
				$entry['last4'] = (string) $token->get_last4();
				// A token with no stored expiry gets no string (never "00/00") and,
				// per is_token_expired(), never counts as expired.
				$entry['expiry']    = ( $month && $year ) ? sprintf( '%02d/%02d', $month, $year % 100 ) : null;
				$entry['isExpired'] = self::is_token_expired( $token );
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * A customer's saved tokens, through WCS's per-request memo when available.
	 *
	 * WC_Payment_Tokens::get_customer_tokens() fires the
	 * `woocommerce_get_customer_payment_tokens` filter, which gateways like
	 * Stripe use to sync tokens against their remote API — so uncached calls can
	 * each cost a network round-trip. WCS_Payment_Tokens exists to memoize
	 * exactly this per request; the profile read calls this once per
	 * subscription plus once for the card list.
	 *
	 * @param int    $user_id    The customer user ID.
	 * @param string $gateway_id Optional gateway to filter by.
	 *
	 * @return \WC_Payment_Token[]
	 */
	private static function customer_tokens( $user_id, $gateway_id = '' ) {
		if ( class_exists( '\WCS_Payment_Tokens' ) ) {
			return \WCS_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );
		}
		if ( class_exists( '\WC_Payment_Tokens' ) ) {
			return \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );
		}
		return [];
	}

	/**
	 * The payment fields the person profile renders per subscription card.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return array
	 */
	public static function subscription_payment_fields( $subscription ) {
		// What a refund would actually give back: the remaining balance of the
		// latest paid order — which is NOT the subscription's current price (a
		// plan change or partial refund can leave the two different). The refund
		// flow's copy promises this number, so it must be the same number the
		// refund endpoint will move. Only a live subscription can offer a refund,
		// so the related-order scan (which grows with account age) is skipped for
		// everything else.
		$refundable_order = $subscription->has_status( [ 'active', 'pending-cancel' ] )
			? self::latest_refundable_order( $subscription )
			: null;
		return [
			'paymentGatewayId'   => (string) $subscription->get_payment_method(),
			'paymentTokenId'     => self::subscription_payment_token_id( $subscription ),
			'paymentMethodTitle' => (string) $subscription->get_payment_method_title(),
			'isManual'           => (bool) $subscription->is_manual(),
			'refundableAmount'   => $refundable_order ? self::order_remaining( $refundable_order ) : null,
			'refundableCurrency' => $refundable_order ? (string) $refundable_order->get_currency() : null,
			// The wizard's "Active" badge also covers WCS pending-cancel; the
			// refund flow needs the distinction so its copy never promises a
			// renewal that will not happen.
			'isPendingCancel'    => (bool) $subscription->has_status( [ 'pending-cancel' ] ),
			// The client's plan-change menu item is gated on the same rule the
			// endpoint enforces, so a card badged Active (which includes WCS
			// pending-cancel) never offers an action the server will refuse.
			'canChangePlan'      => self::can_change_plan( $subscription ),
		];
	}

	/**
	 * Whether the plan-change action applies to a subscription: strictly active
	 * (pending-cancel maps to "Active" in the wizard but will never renew, so
	 * there is no plan to change), and carrying nothing but plain line items —
	 * coupons, fees and shipping would not survive a swap intact.
	 *
	 * The group-subscription case needs no check here: the wizard only feeds
	 * individual subscriptions to this serializer. The endpoint, which takes a
	 * bare ID, checks it explicitly.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return bool
	 */
	private static function can_change_plan( $subscription ) {
		if ( ! $subscription->has_status( [ 'active' ] ) || ! empty( $subscription->get_items( [ 'coupon', 'fee', 'shipping' ] ) ) ) {
			return false;
		}
		$line_items = $subscription->get_items();
		$first_item = reset( $line_items );
		return 1 === count( $line_items )
			&& ( ! $first_item || ! method_exists( $first_item, 'get_quantity' ) || 1 === (int) $first_item->get_quantity() );
	}

	/**
	 * An order's remaining refundable balance, in the store's price precision.
	 *
	 * Money travels as decimal strings in WooCommerce; comparing raw float
	 * subtraction against zero can leave a sub-cent residue that reads as
	 * "refundable" and would send the gateway an amount like 1.0e-13.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return float
	 */
	private static function order_remaining( $order ) {
		return round( (float) $order->get_total() - (float) $order->get_total_refunded(), self::price_decimals() );
	}

	/**
	 * The store's price precision, shared by every money comparison here so the
	 * promise check and the refund itself can never disagree by a rounding step.
	 *
	 * @return int
	 */
	private static function price_decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
	}

	/**
	 * Whether a credit-card token is past its expiry month.
	 *
	 * A card expiring this month is still chargeable through the end of the
	 * month, so only a strictly earlier month counts as expired. Non-CC tokens
	 * (no expiry) never expire.
	 *
	 * @param \WC_Payment_Token $token The token.
	 *
	 * @return bool
	 */
	public static function is_token_expired( $token ) {
		if ( ! $token instanceof \WC_Payment_Token_CC ) {
			return false;
		}
		$month = (int) $token->get_expiry_month();
		$year  = self::normalize_expiry_year( $token->get_expiry_year() );
		if ( ! $month || ! $year ) {
			return false;
		}
		$current_year  = (int) wp_date( 'Y' );
		$current_month = (int) wp_date( 'n' );
		return $year < $current_year || ( $year === $current_year && $month < $current_month );
	}

	/**
	 * Resolve which saved token a subscription currently charges.
	 *
	 * WCS has no forward pointer from a subscription to a token; the link lives
	 * in the gateway's declared payment meta (the same table
	 * WCS_Payment_Tokens::update_subscription_token() writes through). A token
	 * whose value appears in that meta is the card on file.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return int|null The token ID, or null when none resolves (e.g. manual renewal).
	 */
	public static function subscription_payment_token_id( $subscription ) {
		if ( ! class_exists( '\WCS_Payment_Tokens' ) || ! class_exists( '\WC_Payment_Tokens' ) ) {
			return null;
		}
		$gateway_id = (string) $subscription->get_payment_method();
		if ( '' === $gateway_id ) {
			return null;
		}
		$meta_table = \WCS_Payment_Tokens::get_subscription_payment_meta( $subscription, $gateway_id );
		if ( ! is_array( $meta_table ) ) {
			return null;
		}
		$values = [];
		foreach ( $meta_table as $section ) {
			foreach ( $section as $meta ) {
				if ( ! empty( $meta['value'] ) && is_string( $meta['value'] ) ) {
					$values[] = $meta['value'];
				}
			}
		}
		if ( empty( $values ) ) {
			return null;
		}
		foreach ( self::customer_tokens( (int) $subscription->get_customer_id(), $gateway_id ) as $token ) {
			if ( in_array( $token->get_token(), $values, true ) ) {
				return (int) $token->get_id();
			}
		}
		return null;
	}

	/**
	 * POST: re-point a subscription at another of the customer's saved cards.
	 *
	 * A pure token swap — the strongest guards are about whose card and which
	 * gateway, because a renewal will charge whatever this writes.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_change_payment_method( $request ) {
		$subscription = self::get_subscription( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}
		$customer_check = self::assert_customer( $request, (int) $subscription->get_customer_id() );
		if ( is_wp_error( $customer_check ) ) {
			return $customer_check;
		}
		// The same rule the profile menu mirrors: only a live subscription's card
		// is worth re-pointing.
		if ( ! $subscription->has_status( [ 'active', 'on-hold', 'pending-cancel' ] ) ) {
			return new \WP_Error(
				'newspack_payments_not_changeable',
				__( 'Only a live subscription can change its payment method.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$token = class_exists( '\WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( (int) $request->get_param( 'token_id' ) ) : null;
		if ( ! $token ) {
			return new \WP_Error(
				'newspack_payments_token_not_found',
				__( 'That saved payment method could not be found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		if ( (int) $token->get_user_id() !== (int) $subscription->get_customer_id() ) {
			return new \WP_Error(
				'newspack_payments_token_mismatch',
				__( 'That card belongs to a different customer.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		if ( (string) $token->get_gateway_id() !== (string) $subscription->get_payment_method() ) {
			return new \WP_Error(
				'newspack_payments_gateway_mismatch',
				__( 'That card is saved on a different payment gateway than this subscription uses.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		if ( self::is_token_expired( $token ) ) {
			return new \WP_Error(
				'newspack_payments_token_expired',
				__( 'An expired card cannot be charged.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$current_token_id = self::subscription_payment_token_id( $subscription );
		if ( $current_token_id === (int) $token->get_id() ) {
			return new \WP_Error(
				'newspack_payments_same_token',
				__( 'This is already the card the subscription charges.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$current_token = $current_token_id ? \WC_Payment_Tokens::get( $current_token_id ) : null;
		if ( ! $current_token ) {
			// Without a resolvable current token there is no meta slot to swap;
			// pretending otherwise could write the token into the wrong key.
			return new \WP_Error(
				'newspack_payments_current_unresolved',
				__( 'The subscription’s current payment method does not match a saved card, so it cannot be changed here. Use the subscription edit screen instead.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$updated = \WCS_Payment_Tokens::update_subscription_token( $subscription, $token, $current_token );
		if ( ! $updated ) {
			// A third-party filter vetoed the swap; claiming success would leave
			// an order note describing a change that did not happen.
			return new \WP_Error(
				'newspack_payments_update_failed',
				__( 'The payment method could not be updated.', 'newspack-plugin' ),
				[ 'status' => 500 ]
			);
		}
		$subscription->add_order_note(
			sprintf(
				/* translators: 1: old card label, 2: new card label. */
				__( 'Payment method changed from %1$s to %2$s via the Subscribers wizard.', 'newspack-plugin' ),
				self::token_label( $current_token ),
				self::token_label( $token )
			),
			0,
			true
		);
		return rest_ensure_response( [ 'updated' => true ] );
	}

	/**
	 * POST: refund the latest paid order and/or cancel the subscription.
	 *
	 * When both are requested the refund runs first and a refund failure stops
	 * the cancel: a cancelled-but-unrefunded reader is the worst half-state this
	 * endpoint could produce. Cancel-ability is checked up front for the same
	 * reason, so a doomed request fails before any money moves.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_refund( $request ) {
		$subscription = self::get_subscription( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}
		$customer_check = self::assert_customer( $request, (int) $subscription->get_customer_id() );
		if ( is_wp_error( $customer_check ) ) {
			return $customer_check;
		}
		$do_refund = (bool) $request->get_param( 'refund' );
		$do_cancel = (bool) $request->get_param( 'cancel' );
		if ( ! $do_refund && ! $do_cancel ) {
			return new \WP_Error(
				'newspack_payments_no_action',
				__( 'Nothing to do: choose a refund, a cancellation, or both.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		// Cancel-ability is checked before any money moves, so a doomed request
		// fails clean. An already-cancelled subscription is exempt: cancelling it
		// is an idempotent success, not a failed transition — and a full refund
		// can cancel mid-request through WCS's own hooks (see below).
		if ( $do_cancel && ! $subscription->has_status( [ 'cancelled' ] ) && ! $subscription->can_be_updated_to( 'cancelled' ) ) {
			return new \WP_Error(
				'newspack_payments_cannot_cancel',
				__( 'This subscription cannot be cancelled in its current state.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$result = [
			'refunded'      => null,
			'gatewayRefund' => false,
			'cancelled'     => false,
		];

		if ( $do_refund && ! $subscription->has_status( [ 'active', 'pending-cancel' ] ) ) {
			// Matches the serializer: refundableAmount is only offered for a live
			// subscription, and the server enforces the same rule the menu mirrors.
			return new \WP_Error(
				'newspack_payments_not_refundable',
				__( 'Only an active subscription can be refunded from here.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		if ( $do_refund ) {
			$order = self::latest_refundable_order( $subscription );
			if ( ! $order ) {
				return new \WP_Error(
					'newspack_payments_no_refundable_order',
					__( 'There is no paid order left to refund on this subscription.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
			$amount = self::order_remaining( $order );
			// The modal promised the reader a specific number at profile-load time;
			// if a renewal, a manual refund, or another admin changed the balance in
			// between, refuse rather than silently move a different amount than the
			// one the admin confirmed.
			$expected = $request->get_param( 'expected_amount' );
			if ( null !== $expected && round( (float) $expected, self::price_decimals() ) !== round( $amount, self::price_decimals() ) ) {
				return new \WP_Error(
					'newspack_payments_amount_changed',
					__( 'The refundable amount has changed since this page was loaded. Reload the profile and try again.', 'newspack-plugin' ),
					[ 'status' => 409 ]
				);
			}
			$gateway        = function_exists( 'wc_get_payment_gateway_by_order' ) ? wc_get_payment_gateway_by_order( $order ) : false;
			$gateway_refund = $gateway && $gateway->supports( 'refunds' );
			$refund         = wc_create_refund(
				[
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => __( 'Refunded from the Subscribers wizard.', 'newspack-plugin' ),
					'refund_payment' => $gateway_refund,
					'restock_items'  => false,
				]
			);
			if ( is_wp_error( $refund ) ) {
				// The gateway knows why it said no; its message travels verbatim.
				return new \WP_Error( $refund->get_error_code(), $refund->get_error_message(), [ 'status' => 400 ] );
			}
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: refunded amount with currency, 2: order ID. */
					__( 'Refunded %1$s against order #%2$d via the Subscribers wizard.', 'newspack-plugin' ),
					self::format_note_amount( $amount, $order ),
					$order->get_id()
				),
				0,
				true
			);
			$result['refunded']      = $amount;
			$result['gatewayRefund'] = (bool) $gateway_refund;
			// A full refund can cancel the subscription through WCS's own hooks;
			// report what actually happened, not what was requested.
			$result['cancelled'] = $subscription->has_status( [ 'cancelled' ] );
		}

		if ( $do_cancel ) {
			// A full refund can already have cancelled the subscription: WCS
			// auto-cancels a pending-cancel subscription when its latest order is
			// fully refunded. Both requested effects happened, so that is success,
			// not a failed transition.
			if ( $subscription->has_status( [ 'cancelled' ] ) ) {
				$result['cancelled'] = true;
				return rest_ensure_response( $result );
			}
			try {
				$subscription->update_status( 'cancelled', __( 'Cancelled via the Subscribers wizard.', 'newspack-plugin' ) );
			} catch ( \Exception $e ) {
				// Attach what already happened (the refund half) so the client can
				// tell the admin money moved even though the cancel failed.
				return new \WP_Error(
					'newspack_payments_cancel_failed',
					$e->getMessage(),
					array_merge( [ 'status' => 500 ], $result )
				);
			}
			$result['cancelled'] = true;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * A money amount formatted for an order note, e.g. "$100.00" — the audit
	 * trail gets the same presentation the admin screens use.
	 *
	 * @param float     $amount The amount.
	 * @param \WC_Order $order  The order whose currency applies.
	 *
	 * @return string
	 */
	private static function format_note_amount( $amount, $order ) {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) );
		}
		return trim( number_format( $amount, 2 ) . ' ' . $order->get_currency() );
	}

	/**
	 * POST: change the subscription's plan to another subscription product.
	 *
	 * The line item is swapped and the billing schedule adopts the new
	 * product's period, but no dates move and no money moves now: the new price
	 * bills at the already-scheduled next renewal. This is deliberately not a
	 * WCS "switch" — a switch is a checkout flow that charges the customer a
	 * prorated difference, which an admin cannot consent to on their behalf.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_change_plan( $request ) {
		$subscription = self::get_subscription( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}
		$customer_check = self::assert_customer( $request, (int) $subscription->get_customer_id() );
		if ( is_wp_error( $customer_check ) ) {
			return $customer_check;
		}
		if ( ! $subscription->has_status( [ 'active' ] ) ) {
			return new \WP_Error(
				'newspack_payments_not_active',
				__( 'Only an active subscription can change plans.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		// The route takes a bare subscription ID, so the group check cannot be
		// left to the UI (which never offers this on a group card): swapping a
		// group product's line item would dismantle the group irreversibly —
		// every seat holder loses access and there is nothing to restore from.
		if ( class_exists( '\Newspack\Group_Subscription_Settings' ) ) {
			$group_settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
			if ( ! empty( $group_settings['enabled'] ) ) {
				return new \WP_Error(
					'newspack_payments_group_subscription',
					__( 'Group subscriptions are managed from the group screen and cannot change plans here.', 'newspack-plugin' ),
					[ 'status' => 400 ]
				);
			}
		}
		// A coupon, fee or shipping line would not survive the swap intact —
		// calculate_totals() does not re-apply coupons, so a recurring discount
		// would silently die while its line stayed on the subscription. Refuse
		// rather than half-migrate; those subscriptions change plans from the
		// subscription edit screen, where the items are visible.
		if ( ! empty( $subscription->get_items( [ 'coupon', 'fee', 'shipping' ] ) ) ) {
			return new \WP_Error(
				'newspack_payments_items_not_swappable',
				__( 'This subscription has coupons, fees or shipping attached, so its plan cannot be changed here. Use the subscription edit screen instead.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		// The swap replaces the items wholesale with one quantity-1 line, so
		// only that exact shape can be migrated without silently dropping an
		// extra recurring product or a quantity from future renewals.
		$line_items = $subscription->get_items();
		$first_item = reset( $line_items );
		if ( 1 !== count( $line_items ) || ( $first_item && method_exists( $first_item, 'get_quantity' ) && 1 !== (int) $first_item->get_quantity() ) ) {
			return new \WP_Error(
				'newspack_payments_items_not_swappable',
				__( 'This subscription is not a single standard plan, so its plan cannot be changed here. Use the subscription edit screen instead.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $request->get_param( 'product_id' ) ) : false;
		if ( ! $product || ! self::is_eligible_plan_product( $product ) ) {
			return new \WP_Error(
				'newspack_payments_invalid_plan',
				__( 'That product is not an individual subscription plan.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		$current_product_id = WooCommerce_Subscriptions::get_subscription_product_id( $subscription );
		if ( (int) $current_product_id === (int) $product->get_id() ) {
			return new \WP_Error(
				'newspack_payments_same_plan',
				__( 'The subscription is already on that plan.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		// The end date is an entitlement, not a schedule choice: recompute it
		// from the new product so a length-limited plan gains its expiry and a
		// move to an ongoing plan clears a stale one. This runs BEFORE any item
		// mutates because WCS refuses an end date that lands before the next
		// renewal — a length-limited plan on an older subscription — and a
		// refusal after the swap would leave the subscription half-migrated.
		if ( method_exists( '\WC_Subscriptions_Product', 'get_expiration_date' ) ) {
			$end_date = \WC_Subscriptions_Product::get_expiration_date( $product->get_id(), $subscription->get_date( 'start' ) );
			try {
				$subscription->update_dates( [ 'end' => $end_date ? $end_date : 0 ] );
			} catch ( \Exception $e ) {
				return new \WP_Error(
					'newspack_payments_end_date_invalid',
					sprintf(
						/* translators: %s: the scheduling error from WooCommerce Subscriptions. */
						__( 'That plan’s fixed term cannot be applied to this subscription: %s', 'newspack-plugin' ),
						$e->getMessage()
					),
					[ 'status' => 400 ]
				);
			}
		}
		$old_names = [];
		foreach ( $subscription->get_items() as $item_id => $item ) {
			$old_names[] = $item->get_name();
			$subscription->remove_item( $item_id );
		}
		$subscription->add_product( $product, 1 );
		$subscription->set_billing_period( \WC_Subscriptions_Product::get_period( $product ) );
		$subscription->set_billing_interval( \WC_Subscriptions_Product::get_interval( $product ) );
		$subscription->calculate_totals();
		$subscription->add_order_note(
			sprintf(
				/* translators: 1: old plan name(s), 2: new plan name. */
				__( 'Plan changed from %1$s to %2$s via the Subscribers wizard. The new price applies from the next renewal.', 'newspack-plugin' ),
				implode( ', ', array_filter( $old_names ) ),
				$product->get_name()
			),
			0,
			true
		);
		$subscription->save();
		return rest_ensure_response( [ 'updated' => true ] );
	}

	/**
	 * GET: the plans a subscription could change to.
	 *
	 * Simple subscription products only: a variable subscription needs a
	 * variation choice this picker does not offer, and a group product would
	 * silently turn a personal subscription into a group.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_get_plan_options( $request ) {
		$subscription = self::get_subscription( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}
		$current_product_id = (int) WooCommerce_Subscriptions::get_subscription_product_id( $subscription );
		$options            = [];
		$products           = function_exists( 'wc_get_products' )
			? wc_get_products(
				[
					'type'   => [ 'subscription' ],
					'status' => 'publish',
					'limit'  => 200,
				]
			)
			: [];
		foreach ( $products as $product ) {
			if ( (int) $product->get_id() === $current_product_id || ! self::is_eligible_plan_product( $product ) ) {
				continue;
			}
			$options[] = [
				'id'       => (int) $product->get_id(),
				'name'     => (string) $product->get_name(),
				'amount'   => (float) \WC_Subscriptions_Product::get_price( $product ),
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'period'   => (string) \WC_Subscriptions_Product::get_period( $product ),
				'interval' => (int) \WC_Subscriptions_Product::get_interval( $product ),
			];
		}
		return rest_ensure_response( [ 'options' => $options ] );
	}

	/**
	 * POST: make a saved card the customer's default.
	 *
	 * The default is what renewals fall back to, so an expired card is refused
	 * here with the same rule the change-payment endpoint applies.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function api_set_default_payment_method( $request ) {
		$token = class_exists( '\WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( (int) $request->get_param( 'id' ) ) : null;
		if ( ! $token ) {
			return new \WP_Error(
				'newspack_payments_token_not_found',
				__( 'That saved payment method could not be found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		$customer_check = self::assert_customer( $request, (int) $token->get_user_id() );
		if ( is_wp_error( $customer_check ) ) {
			return $customer_check;
		}
		if ( self::is_token_expired( $token ) ) {
			return new \WP_Error(
				'newspack_payments_token_expired',
				__( 'An expired card cannot be the default payment method.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}
		\WC_Payment_Tokens::set_users_default( (int) $token->get_user_id(), (int) $token->get_id() );
		return rest_ensure_response( [ 'updated' => true ] );
	}

	/**
	 * Assert the target belongs to the subscriber the request claims to act on.
	 *
	 * Every write route requires the profile's user ID alongside the target ID,
	 * so a stale tab, a copied ID, or a client bug cannot move a different
	 * reader's money: the server refuses instead of trusting the caller to have
	 * picked the right target.
	 *
	 * @param \WP_REST_Request $request        The request (carries `customer_id`).
	 * @param int              $actual_user_id The target's real owner.
	 *
	 * @return true|\WP_Error
	 */
	private static function assert_customer( $request, $actual_user_id ) {
		if ( (int) $request->get_param( 'customer_id' ) !== (int) $actual_user_id ) {
			return new \WP_Error(
				'newspack_payments_customer_mismatch',
				__( 'The target does not belong to the subscriber being viewed. Reload the profile and try again.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}
		return true;
	}

	/**
	 * Load a subscription or produce the 404 the routes share.
	 *
	 * @param int $subscription_id The subscription ID.
	 *
	 * @return \WC_Subscription|\WP_Error
	 */
	private static function get_subscription( $subscription_id ) {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : false;
		if ( ! $subscription ) {
			return new \WP_Error(
				'newspack_payments_subscription_not_found',
				__( 'Subscription not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		return $subscription;
	}

	/**
	 * The most recent related order that still has money to give back.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return \WC_Order|null
	 */
	private static function latest_refundable_order( $subscription ) {
		// IDs only: hydrating the whole related-order history would cost one full
		// order load per renewal ever made, and this runs per subscription on
		// every detailed profile read. Order IDs are creation-ordered, and the
		// refundable order is almost always the newest — so the common case
		// hydrates exactly one order.
		$order_ids = $subscription->get_related_orders( 'ids', 'any' );
		if ( ! is_array( $order_ids ) || empty( $order_ids ) ) {
			return null;
		}
		$order_ids = array_map( 'intval', array_values( $order_ids ) );
		rsort( $order_ids );
		foreach ( $order_ids as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			if ( $order && $order->is_paid() && self::order_remaining( $order ) > 0 ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Whether a product is offerable as an individual plan.
	 *
	 * @param \WC_Product $product The product.
	 *
	 * @return bool
	 */
	private static function is_eligible_plan_product( $product ) {
		if ( ! class_exists( '\WC_Subscriptions_Product' ) || ! \WC_Subscriptions_Product::is_subscription( $product ) ) {
			return false;
		}
		if ( ! $product->is_type( 'subscription' ) ) {
			return false;
		}
		// The picker queries published products; the write path must hold the
		// same line or a stale/direct POST could move a reader onto a draft plan.
		if ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() ) {
			return false;
		}
		if ( class_exists( '\Newspack\Group_Subscription_Settings' ) ) {
			$group_settings = Group_Subscription_Settings::get_product_settings( $product );
			if ( ! empty( $group_settings['enabled'] ) ) {
				return false;
			}
		}
		// Newspack's recurring donation products are published subscription
		// products too; offering one here would move a reader's paid plan onto a
		// name-your-price donation and destroy the entitlement.
		if ( class_exists( '\Newspack\Donations' ) && method_exists( '\Newspack\Donations', 'is_donation_product' ) && Donations::is_donation_product( $product->get_id() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * A short human label for a token, e.g. "visa •••• 4242".
	 *
	 * @param \WC_Payment_Token $token The token.
	 *
	 * @return string
	 */
	private static function token_label( $token ) {
		if ( $token instanceof \WC_Payment_Token_CC ) {
			return trim( $token->get_card_type() . ' •••• ' . $token->get_last4() );
		}
		return method_exists( $token, 'get_display_name' ) ? (string) $token->get_display_name() : (string) $token->get_id();
	}

	/**
	 * Normalize a token expiry year to four digits (gateways store both forms).
	 *
	 * @param string|int $year The stored expiry year.
	 *
	 * @return int
	 */
	private static function normalize_expiry_year( $year ) {
		$year = (int) $year;
		if ( $year > 0 && $year < 100 ) {
			$year += 2000;
		}
		return $year;
	}
}
