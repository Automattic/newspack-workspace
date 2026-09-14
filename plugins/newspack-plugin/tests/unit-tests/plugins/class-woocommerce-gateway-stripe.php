<?php
/**
 * Tests for the WooCommerce Gateway Stripe integration class.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\WooCommerce_Gateway_Stripe;

require_once __DIR__ . '/../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../mocks/newspack-blocks-mocks.php';

/**
 * Tests WooCommerce_Gateway_Stripe dual-gateway guard methods.
 *
 * @group WooCommerce_Gateway_Stripe
 */
class Newspack_Test_WooCommerce_Gateway_Stripe extends WP_UnitTestCase {

	/**
	 * Reset the global order and subscriptions databases before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $orders_database, $subscriptions_database;
		$orders_database        = [];
		$subscriptions_database = [];
		WC_Stripe_Helper::reset_testing_settings();
		WC_Payment_Tokens::$tokens = [];
		unset( $_REQUEST['modal_checkout'] );
		delete_option( Donations::DONATION_BILLING_FIELDS_OPTION );
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		unset( $_REQUEST['modal_checkout'] );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Part 1: woocommerce_before_subscription_object_save guard
	// -------------------------------------------------------------------------

	/**
	 * A WooPayments subscription with _stripe_customer_id in-memory should have
	 * the meta removed before save.
	 */
	public function test_stripe_customer_id_stripped_for_woopayments_subscription() {
		$subscription = wcs_create_subscription(
			[
				'payment_method' => 'woocommerce_payments',
				'meta'           => [ '_stripe_customer_id' => 'cus_stale123' ],
			]
		);

		WooCommerce_Gateway_Stripe::maybe_strip_stripe_customer_id_before_save( $subscription );

		$this->assertSame(
			'',
			$subscription->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be stripped from in-memory meta for WooPayments subscriptions.'
		);
	}

	/**
	 * A Stripe subscription should NOT have _stripe_customer_id removed before save.
	 */
	public function test_stripe_customer_id_preserved_for_stripe_subscription() {
		$subscription = wcs_create_subscription(
			[
				'payment_method' => 'stripe',
				'meta'           => [ '_stripe_customer_id' => 'cus_live456' ],
			]
		);

		WooCommerce_Gateway_Stripe::maybe_strip_stripe_customer_id_before_save( $subscription );

		$this->assertSame(
			'cus_live456',
			$subscription->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be preserved for Stripe subscriptions.'
		);
	}

	/**
	 * Stripe variant gateways (stripe_sepa, stripe_klarna, etc.) should also be preserved.
	 */
	public function test_stripe_customer_id_preserved_for_stripe_variant_gateways() {
		foreach ( [ 'stripe_sepa', 'stripe_klarna', 'stripe_afterpay' ] as $gateway ) {
			$subscription = wcs_create_subscription(
				[
					'payment_method' => $gateway,
					'meta'           => [ '_stripe_customer_id' => 'cus_variant789' ],
				]
			);

			WooCommerce_Gateway_Stripe::maybe_strip_stripe_customer_id_before_save( $subscription );

			$this->assertSame(
				'cus_variant789',
				$subscription->get_meta( '_stripe_customer_id' ),
				"_stripe_customer_id should be preserved for {$gateway} subscriptions."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Part 2: update_post_metadata filter guard
	// -------------------------------------------------------------------------

	/**
	 * The post meta filter should return true (block write) for a WooPayments subscription.
	 */
	public function test_post_meta_filter_blocks_woopayments_subscription() {
		$subscription = wcs_create_subscription( [ 'payment_method' => 'woocommerce_payments' ] );

		$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
			null,
			$subscription->get_id(),
			'_stripe_customer_id'
		);

		$this->assertTrue(
			$result,
			'Filter should return true (blocking write) for a WooPayments subscription.'
		);
	}

	/**
	 * The post meta filter should return null (allow write) for a Stripe subscription.
	 */
	public function test_post_meta_filter_allows_stripe_subscription() {
		$subscription = wcs_create_subscription( [ 'payment_method' => 'stripe' ] );

		$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
			null,
			$subscription->get_id(),
			'_stripe_customer_id'
		);

		$this->assertNull(
			$result,
			'Filter should return null (allowing write) for a Stripe subscription.'
		);
	}

	/**
	 * The post meta filter should return null (allow write) for Stripe variant gateway subscriptions.
	 */
	public function test_post_meta_filter_allows_stripe_variant_gateways() {
		foreach ( [ 'stripe_sepa', 'stripe_klarna', 'stripe_afterpay' ] as $gateway ) {
			$subscription = wcs_create_subscription( [ 'payment_method' => $gateway ] );

			$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
				null,
				$subscription->get_id(),
				'_stripe_customer_id'
			);

			$this->assertNull(
				$result,
				"Filter should return null (allowing write) for {$gateway} subscriptions."
			);
		}
	}

	/**
	 * The post meta filter should be a no-op for non-subscription post IDs.
	 */
	public function test_post_meta_filter_ignores_non_subscription() {
		// Use an ID that is not in $subscriptions_database.
		$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
			null,
			99999,
			'_stripe_customer_id'
		);

		$this->assertNull(
			$result,
			'Filter should return null (pass-through) for non-subscription post IDs.'
		);
	}

	/**
	 * The post meta filter should be a no-op for meta keys other than _stripe_customer_id.
	 */
	public function test_post_meta_filter_ignores_other_meta_keys() {
		$subscription = wcs_create_subscription( [ 'payment_method' => 'woocommerce_payments' ] );

		$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
			null,
			$subscription->get_id(),
			'_some_other_key'
		);

		$this->assertNull(
			$result,
			'Filter should return null for meta keys other than _stripe_customer_id.'
		);
	}

	/**
	 * The post meta filter should pass through when $check is already non-null
	 * (a previous filter already short-circuited the write).
	 */
	public function test_post_meta_filter_respects_earlier_short_circuit() {
		$subscription = wcs_create_subscription( [ 'payment_method' => 'woocommerce_payments' ] );

		// Simulate a previous filter returning true (blocking) or false (allowing with override).
		foreach ( [ true, false ] as $prior_check ) {
			$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
				$prior_check,
				$subscription->get_id(),
				'_stripe_customer_id'
			);

			$this->assertSame(
				$prior_check,
				$result,
				'Filter should pass through a non-null $check unchanged, regardless of subscription type.'
			);
		}
	}

	/**
	 * Subscriptions with an empty payment method are not a WooPayments subscription
	 * and should NOT have _stripe_customer_id touched by any of the three guards.
	 */
	public function test_stripe_customer_id_preserved_for_empty_payment_method() {
		$subscription = wcs_create_subscription(
			[
				'payment_method' => '',
				'meta'           => [ '_stripe_customer_id' => 'cus_orphan' ],
			]
		);

		WooCommerce_Gateway_Stripe::maybe_strip_stripe_customer_id_before_save( $subscription );

		$this->assertSame(
			'cus_orphan',
			$subscription->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be preserved when payment_method is empty (not a WooPayments subscription).'
		);
	}

	/**
	 * The post meta filter should return null (allow write) for a subscription with
	 * an empty payment method — empty string does not start with woocommerce_payments.
	 */
	public function test_post_meta_filter_allows_empty_payment_method_subscription() {
		$subscription = wcs_create_subscription( [ 'payment_method' => '' ] );

		$result = WooCommerce_Gateway_Stripe::maybe_block_stripe_customer_id_post_meta_update(
			null,
			$subscription->get_id(),
			'_stripe_customer_id'
		);

		$this->assertNull(
			$result,
			'Filter should return null (allow write) for a subscription with an empty payment method.'
		);
	}

	/**
	 * On renewal for a subscription with an empty payment method, _stripe_customer_id
	 * should not be cleared — empty string does not start with woocommerce_payments.
	 */
	public function test_renewal_preserves_customer_id_for_empty_payment_method() {
		$renewal_order = new WC_Order(
			[
				'status' => 'pending',
				'meta'   => [ '_stripe_customer_id' => 'cus_orphan' ],
			]
		);
		$subscription  = wcs_create_subscription(
			[
				'payment_method' => '',
				'meta'           => [ '_stripe_customer_id' => 'cus_orphan' ],
			]
		);

		WooCommerce_Gateway_Stripe::clear_stripe_customer_id_on_renewal( $renewal_order, $subscription );

		$this->assertSame(
			'cus_orphan',
			$renewal_order->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be preserved on the renewal order when payment_method is empty.'
		);
		$this->assertSame(
			'cus_orphan',
			$subscription->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be preserved on the subscription when payment_method is empty.'
		);
	}

	// -------------------------------------------------------------------------
	// Part 3: wcs_renewal_order_created guard
	// -------------------------------------------------------------------------

	/**
	 * On renewal for a WooPayments subscription, stale _stripe_customer_id should
	 * be cleared from both the renewal order and the subscription.
	 */
	public function test_renewal_clears_stale_customer_id_for_woopayments() {
		$renewal_order = new WC_Order(
			[
				'status' => 'pending',
				'meta'   => [ '_stripe_customer_id' => 'cus_stale123' ],
			]
		);
		$subscription  = wcs_create_subscription(
			[
				'payment_method' => 'woocommerce_payments',
				'meta'           => [ '_stripe_customer_id' => 'cus_stale123' ],
			]
		);

		WooCommerce_Gateway_Stripe::clear_stripe_customer_id_on_renewal( $renewal_order, $subscription );

		$this->assertSame(
			'',
			$renewal_order->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be cleared from the renewal order for a WooPayments subscription.'
		);
		$this->assertSame(
			'',
			$subscription->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be cleared from subscription in-memory meta on renewal (HPOS cleaned on next natural save).'
		);
	}

	/**
	 * Filter callback must return the renewal order to pass through the
	 * wcs_renewal_order_created apply_filters chain without nulling it out.
	 */
	public function test_renewal_filter_returns_renewal_order() {
		$renewal_order = new WC_Order(
			[
				'status' => 'pending',
				'meta'   => [ '_stripe_customer_id' => 'cus_stale123' ],
			]
		);
		$subscription  = wcs_create_subscription( [ 'payment_method' => 'woocommerce_payments' ] );

		$result = WooCommerce_Gateway_Stripe::clear_stripe_customer_id_on_renewal( $renewal_order, $subscription );

		$this->assertSame(
			$renewal_order,
			$result,
			'Filter callback must return $renewal_order to avoid nulling it out for subsequent apply_filters listeners.'
		);
	}

	/**
	 * On renewal for a Stripe subscription, _stripe_customer_id should not be
	 * touched on either the renewal order or the subscription.
	 */
	public function test_renewal_preserves_customer_id_for_stripe() {
		$renewal_order = new WC_Order(
			[
				'status' => 'pending',
				'meta'   => [ '_stripe_customer_id' => 'cus_live456' ],
			]
		);
		$subscription  = wcs_create_subscription(
			[
				'payment_method' => 'stripe',
				'meta'           => [ '_stripe_customer_id' => 'cus_live456' ],
			]
		);

		WooCommerce_Gateway_Stripe::clear_stripe_customer_id_on_renewal( $renewal_order, $subscription );

		$this->assertSame(
			'cus_live456',
			$renewal_order->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be preserved on renewal order for a Stripe subscription.'
		);
		$this->assertSame(
			'cus_live456',
			$subscription->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be preserved on subscription for a Stripe subscription.'
		);
	}

	/**
	 * On renewal for a non-Stripe subscription where only the order carries the
	 * stale value (subscription already clean), the order should be cleared
	 * without errors.
	 */
	public function test_renewal_clears_order_when_subscription_already_clean() {
		$renewal_order = new WC_Order(
			[
				'status' => 'pending',
				'meta'   => [ '_stripe_customer_id' => 'cus_stale123' ],
			]
		);
		$subscription  = wcs_create_subscription( [ 'payment_method' => 'woocommerce_payments' ] );

		WooCommerce_Gateway_Stripe::clear_stripe_customer_id_on_renewal( $renewal_order, $subscription );

		$this->assertSame(
			'',
			$renewal_order->get_meta( '_stripe_customer_id' ),
			'_stripe_customer_id should be cleared from renewal order even when subscription meta is already clean.'
		);
	}

	/**
	 * Stripe variant gateways should not have _stripe_customer_id cleared on renewal.
	 */
	public function test_renewal_preserves_customer_id_for_stripe_variant_gateways() {
		foreach ( [ 'stripe_sepa', 'stripe_klarna', 'stripe_afterpay' ] as $gateway ) {
			$renewal_order = new WC_Order(
				[
					'status' => 'pending',
					'meta'   => [ '_stripe_customer_id' => 'cus_variant789' ],
				]
			);
			$subscription  = wcs_create_subscription(
				[
					'payment_method' => $gateway,
					'meta'           => [ '_stripe_customer_id' => 'cus_variant789' ],
				]
			);

			WooCommerce_Gateway_Stripe::clear_stripe_customer_id_on_renewal( $renewal_order, $subscription );

			$this->assertSame(
				'cus_variant789',
				$renewal_order->get_meta( '_stripe_customer_id' ),
				"_stripe_customer_id should not be cleared from renewal order for {$gateway}."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Part 4: Adaptive Pricing guard
	// -------------------------------------------------------------------------

	/**
	 * Adaptive Pricing should be disabled when the configured billing fields
	 * omit billing_country.
	 */
	public function test_adaptive_pricing_disabled_when_country_field_omitted() {
		update_option( Donations::DONATION_BILLING_FIELDS_OPTION, [ 'billing_first_name', 'billing_last_name', 'billing_email' ] );
		WC_Stripe_Helper::$settings = [ 'adaptive_pricing' => 'yes' ];

		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_without_country_field();

		$this->assertSame(
			'no',
			WC_Stripe_Helper::$settings['adaptive_pricing'],
			'Adaptive Pricing should be disabled when billing fields omit billing_country.'
		);
	}

	/**
	 * Adaptive Pricing should be left alone when no custom billing fields are
	 * configured (the default WooCommerce field set includes billing_country).
	 */
	public function test_adaptive_pricing_untouched_for_default_billing_fields() {
		WC_Stripe_Helper::$settings = [ 'adaptive_pricing' => 'yes' ];

		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_without_country_field();

		$this->assertSame(
			'yes',
			WC_Stripe_Helper::$settings['adaptive_pricing'],
			'Adaptive Pricing should be left enabled when billing fields are the default set.'
		);
		$this->assertSame(
			0,
			WC_Stripe_Helper::$update_calls,
			'Settings should not be written at all when billing fields are the default set.'
		);
	}

	/**
	 * Adaptive Pricing should be left alone when the configured billing fields
	 * include billing_country.
	 */
	public function test_adaptive_pricing_untouched_when_country_field_present() {
		update_option( Donations::DONATION_BILLING_FIELDS_OPTION, [ 'billing_first_name', 'billing_country', 'billing_email' ] );
		WC_Stripe_Helper::$settings = [ 'adaptive_pricing' => 'yes' ];

		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_without_country_field();

		$this->assertSame(
			'yes',
			WC_Stripe_Helper::$settings['adaptive_pricing'],
			'Adaptive Pricing should be left enabled when billing fields include billing_country.'
		);
		$this->assertSame(
			0,
			WC_Stripe_Helper::$update_calls,
			'Settings should not be written at all when billing fields include billing_country.'
		);
	}

	/**
	 * No settings write should happen when Adaptive Pricing is already off,
	 * or when the setting is absent entirely.
	 */
	public function test_adaptive_pricing_noop_when_already_disabled() {
		update_option( Donations::DONATION_BILLING_FIELDS_OPTION, [ 'billing_first_name', 'billing_email' ] );

		WC_Stripe_Helper::$settings = [ 'adaptive_pricing' => 'no' ];
		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_without_country_field();
		$this->assertSame(
			0,
			WC_Stripe_Helper::$update_calls,
			'Settings should not be written when Adaptive Pricing is already disabled.'
		);

		WC_Stripe_Helper::$settings = [];
		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_without_country_field();
		$this->assertSame(
			0,
			WC_Stripe_Helper::$update_calls,
			'Settings should not be written when the Adaptive Pricing setting is absent.'
		);
	}

	/**
	 * The wp_loaded wrapper should only act on modal checkout requests.
	 */
	public function test_adaptive_pricing_wrapper_only_acts_on_modal_checkout_requests() {
		update_option( Donations::DONATION_BILLING_FIELDS_OPTION, [ 'billing_first_name', 'billing_email' ] );
		WC_Stripe_Helper::$settings = [ 'adaptive_pricing' => 'yes' ];

		unset( $_REQUEST['modal_checkout'] );
		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_on_modal_checkout_request();
		$this->assertSame(
			'yes',
			WC_Stripe_Helper::$settings['adaptive_pricing'],
			'The wrapper should not act outside modal checkout requests.'
		);

		$_REQUEST['modal_checkout'] = '1';
		WooCommerce_Gateway_Stripe::maybe_disable_adaptive_pricing_on_modal_checkout_request();
		$this->assertSame(
			'no',
			WC_Stripe_Helper::$settings['adaptive_pricing'],
			'The wrapper should disable Adaptive Pricing on modal checkout requests.'
		);
	}

	// -------------------------------------------------------------------------
	// Part 5: saved-card metadata refresh after re-adding the same card (NPPM-3244)
	// -------------------------------------------------------------------------

	/**
	 * Build a Stripe PaymentMethod object the way the gateway passes it to
	 * the woocommerce_stripe_add_payment_method action.
	 *
	 * @param string $id        PaymentMethod ID.
	 * @param array  $card      Card fields to override.
	 * @return object
	 */
	private function stripe_card_payment_method( $id, $card = [] ) {
		return (object) [
			'id'   => $id,
			'type' => 'card',
			'card' => (object) array_merge(
				[
					'brand'       => 'visa',
					'last4'       => '4242',
					'exp_month'   => 12,
					'exp_year'    => 2034,
					'fingerprint' => 'fp_same_card',
				],
				$card
			),
		];
	}

	/**
	 * Register a saved Stripe card token for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Stripe PaymentMethod ID the token points at.
	 * @param string $month   Expiry month.
	 * @param string $year    Expiry year.
	 * @return WC_Payment_Token_CC
	 */
	private function saved_card_token( $user_id, $token, $month, $year ) {
		$wc_token = new WC_Payment_Token_CC( 'visa', '4242', $token, $user_id, 'stripe' );
		$wc_token->set_expiry_month( $month );
		$wc_token->set_expiry_year( $year );
		WC_Payment_Tokens::$tokens[] = $wc_token;
		return $wc_token;
	}

	/**
	 * Re-adding the same card with a new expiry re-points the Woo token at the
	 * new PaymentMethod but leaves the expiry meta behind. The refresh should
	 * bring the meta in line with the connected PaymentMethod.
	 */
	public function test_card_token_expiry_is_refreshed_from_connected_payment_method() {
		$user_id = self::factory()->user->create();
		$token   = $this->saved_card_token( $user_id, 'pm_renewed', '12', '2028' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			$this->stripe_card_payment_method(
				'pm_renewed',
				[
					'exp_month' => 12,
					'exp_year'  => 2034,
				]
			)
		);

		$this->assertSame( '12', $token->get_expiry_month() );
		$this->assertSame( '2034', $token->get_expiry_year() );
		$this->assertSame( 1, $token->save_calls, 'The refreshed token should be saved once.' );
	}

	/**
	 * A token whose metadata already matches the PaymentMethod is left alone,
	 * so the normal new-card path costs no extra write.
	 */
	public function test_card_token_is_not_saved_when_metadata_already_matches() {
		$user_id = self::factory()->user->create();
		$token   = $this->saved_card_token( $user_id, 'pm_same', '02', '2034' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			$this->stripe_card_payment_method(
				'pm_same',
				[
					'exp_month' => 2,
					'exp_year'  => 2034,
				]
			)
		);

		$this->assertSame( 0, $token->save_calls, 'Matching metadata should not trigger a save.' );
	}

	/**
	 * Only the token that points at the PaymentMethod is refreshed; other saved
	 * cards for the same user are untouched.
	 */
	public function test_only_the_matching_token_is_refreshed() {
		$user_id = self::factory()->user->create();
		$other   = $this->saved_card_token( $user_id, 'pm_other_card', '01', '2027' );
		$target  = $this->saved_card_token( $user_id, 'pm_target', '12', '2028' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			$this->stripe_card_payment_method(
				'pm_target',
				[
					'exp_month' => 12,
					'exp_year'  => 2034,
				]
			)
		);

		$this->assertSame( '2034', $target->get_expiry_year() );
		$this->assertSame( '2027', $other->get_expiry_year() );
		$this->assertSame( 0, $other->save_calls );
	}

	/**
	 * Non-card PaymentMethods and bare PaymentMethod IDs (which the checkout
	 * path can pass) are ignored without error.
	 */
	public function test_non_card_payment_methods_are_ignored() {
		$user_id = self::factory()->user->create();
		$token   = $this->saved_card_token( $user_id, 'pm_sepa', '12', '2028' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			(object) [
				'id'         => 'pm_sepa',
				'type'       => 'sepa_debit',
				'sepa_debit' => (object) [ 'last4' => '3000' ],
			]
		);

		$this->assertSame( '2028', $token->get_expiry_year() );
		$this->assertSame( 0, $token->save_calls );
	}

	/**
	 * A guest or missing user ID must never reach the token query: WooCommerce
	 * drops the user predicate for a falsy user_id, so the lookup would span
	 * every customer's tokens and could rewrite someone else's card.
	 */
	public function test_non_positive_user_id_never_touches_another_users_token() {
		$other_user = self::factory()->user->create();
		$token      = $this->saved_card_token( $other_user, 'pm_shared_id', '12', '2028' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			0,
			$this->stripe_card_payment_method( 'pm_shared_id', [ 'exp_year' => 2034 ] )
		);

		$this->assertSame( '2028', $token->get_expiry_year() );
		$this->assertSame( 0, $token->save_calls );
	}

	/**
	 * Brand and last4 are refreshed from the PaymentMethod too, not only expiry.
	 */
	public function test_card_type_and_last4_are_refreshed_from_connected_payment_method() {
		$user_id = self::factory()->user->create();
		$token   = new WC_Payment_Token_CC( 'mastercard', '1111', 'pm_rebranded', $user_id, 'stripe' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2034' );
		WC_Payment_Tokens::$tokens[] = $token;

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			$this->stripe_card_payment_method( 'pm_rebranded', [ 'brand' => 'visa', 'last4' => '4242' ] )
		);

		$this->assertSame( 'visa', $token->get_card_type() );
		$this->assertSame( '4242', $token->get_last4() );
		$this->assertSame( 1, $token->save_calls );
	}

	/**
	 * The checkout path can pass a bare PaymentMethod ID instead of an object;
	 * that is ignored without error.
	 */
	public function test_bare_payment_method_id_is_ignored() {
		$user_id = self::factory()->user->create();
		$token   = $this->saved_card_token( $user_id, 'pm_bare', '12', '2028' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata( $user_id, 'pm_bare' );

		$this->assertSame( '2028', $token->get_expiry_year() );
		$this->assertSame( 0, $token->save_calls );
	}

	/**
	 * A co-badged card reports its brand under display_brand; that wins over
	 * the plain brand, the same way the gateway derives card_type.
	 */
	public function test_card_type_takes_display_brand_over_brand() {
		$user_id = self::factory()->user->create();
		$token   = $this->saved_card_token( $user_id, 'pm_cobadged', '12', '2034' );

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			$this->stripe_card_payment_method(
				'pm_cobadged',
				[
					'brand'         => 'visa',
					'display_brand' => 'cartes_bancaires',
				]
			)
		);

		$this->assertSame( 'cartes_bancaires', $token->get_card_type() );
		$this->assertSame( 1, $token->save_calls );
	}

	/**
	 * A PaymentMethod with no expiry month leaves the stored month alone rather
	 * than writing a zero-padded empty value.
	 */
	public function test_missing_exp_month_leaves_the_stored_month_alone() {
		$user_id = self::factory()->user->create();
		$token   = $this->saved_card_token( $user_id, 'pm_nomonth', '12', '2028' );

		$payment_method = $this->stripe_card_payment_method( 'pm_nomonth', [ 'exp_year' => 2034 ] );
		unset( $payment_method->card->exp_month );
		WooCommerce_Gateway_Stripe::refresh_card_token_metadata( $user_id, $payment_method );

		$this->assertSame( '12', $token->get_expiry_month() );
		$this->assertSame( '2034', $token->get_expiry_year() );
	}

	/**
	 * A token that fails WooCommerce's validation on save must not throw into
	 * the gateway's checkout flow; the refresh is skipped instead.
	 */
	public function test_failed_token_save_is_contained() {
		$user_id              = self::factory()->user->create();
		$token                = $this->saved_card_token( $user_id, 'pm_bad_row', '12', '2028' );
		$token->throw_on_save = true;

		WooCommerce_Gateway_Stripe::refresh_card_token_metadata(
			$user_id,
			$this->stripe_card_payment_method( 'pm_bad_row', [ 'exp_year' => 2034 ] )
		);

		$this->assertSame( 0, $token->save_calls, 'No exception should escape and no save should be recorded.' );
	}
}
