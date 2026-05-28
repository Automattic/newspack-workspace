<?php
/**
 * All things Stripe.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * All things Stripe.
 */
class Stripe_Connection {
	/**
	 * Is a value not empty?
	 *
	 * @param mixed $value Value to check.
	 */
	private static function is_value_non_empty( $value ) {
		if ( 'boolean' === gettype( $value ) ) {
			return true;
		} else {
			return ! empty( $value );
		}
	}

	/**
	 * Get Stripe data.
	 */
	public static function get_stripe_data() {
		$wc_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'woocommerce' );
		$stripe_data              = $wc_configuration_manager->stripe_data();
		if ( ! $stripe_data ) {
			return $stripe_data;
		}

		$location_code = 'US';
		$currency      = 'USD';

		$wc_country = get_option( 'woocommerce_default_country', false );
		if ( $wc_country ) {
			// Remove region code.
			$wc_country = explode( ':', $wc_country )[0];
		}

		$valid_country_codes = wp_list_pluck( newspack_get_countries(), 'value' );
		if ( $wc_country && in_array( $wc_country, $valid_country_codes ) ) {
			$location_code = $wc_country;
		}
		$wc_currency      = get_option( 'woocommerce_currency', false );
		$valid_currencies = wp_list_pluck( newspack_get_currencies_options(), 'value' );
		if ( $wc_currency && in_array( $wc_currency, $valid_currencies ) ) {
			$currency = $wc_currency;
		}
		$stripe_data = array_merge(
			$stripe_data,
			[
				'currency'      => $currency,
				'location_code' => $location_code,
			]
		);
		return $stripe_data;
	}

	/**
	 * Update Stripe data.
	 *
	 * @param object $updated_stripe_data Updated Stripe data to be saved.
	 */
	public static function update_stripe_data( $updated_stripe_data ) {
		$wc_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'woocommerce' );
		$wc_configuration_manager->update_wc_stripe_settings( $updated_stripe_data );

		if ( isset( $updated_stripe_data['currency'] ) ) {
			update_option( 'woocommerce_currency', $updated_stripe_data['currency'] );
		}
		if ( isset( $updated_stripe_data['location_code'] ) ) {
			update_option( 'woocommerce_default_country', $updated_stripe_data['location_code'] );
		}
	}

	/**
	 * Update stripe customer data.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Stripe customer data.
	 *
	 * @return bool|WP_Error
	 */
	public static function update_customer_data( $user_id, $data ) {
		if ( ! class_exists( 'WC_Stripe_API' ) ) {
			return false;
		}
		$stripe_customer_id = \get_user_option( '_stripe_customer_id', $user_id );
		if ( ! $stripe_customer_id ) {
			return false;
		}
		$result = \WC_Stripe_API::request( $data, 'customers/' . $stripe_customer_id );
		return \is_wp_error( $result ) ? $result : true;
	}
}
