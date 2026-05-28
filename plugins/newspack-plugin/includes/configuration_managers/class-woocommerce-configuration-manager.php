<?php
/**
 * WooCommerce plugin(s) configuration manager.
 *
 * @package Newspack
 */

namespace Newspack;

use WC_Payment_Gateways, WC_Install, WP_Error;

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_ABSPATH . '/includes/configuration_managers/class-configuration-manager.php';

/**
 * Provide an interface for configuring and querying the configuration of WooCommerce.
 */
class WooCommerce_Configuration_Manager extends Configuration_Manager {

	/**
	 * The slug of the plugin.
	 *
	 * @var string
	 */
	public $slug = 'woocommerce';

	/**
	 * Fully-supported payment gateways.
	 *
	 * @var array
	 */
	public $supported_gateways = [
		'ppcp-gateway'         => [
			'name'     => 'PayPal Payments',
			'plugin'   => 'woocommerce-paypal-payments',
			'url'      => 'https://woocommerce.com/products/woocommerce-paypal-payments/',
			'connect'  => 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway&ppcp-tab=ppcp-connection',
			'settings' => 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway',
		],
		'stripe'               => [
			'name'     => 'Stripe',
			'plugin'   => 'woocommerce-gateway-stripe',
			'url'      => 'https://woocommerce.com/document/stripe/',
			'connect'  => 'admin.php?page=wc-settings&tab=checkout&section=stripe',
			'settings' => 'admin.php?page=wc-settings&tab=checkout&section=stripe&panel=settings',
		],
		'woocommerce_payments' => [
			'name'     => 'WooPay',
			'plugin'   => 'woocommerce-payments',
			'url'      => 'https://woocommerce.com/payments/',
			'connect'  => 'admin.php?page=wc-admin&path=%2Fpayments%2Foverview',
			'settings' => 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments',
		],
	];

	/**
	 * Get whether the WooCommerce plugin is active and set up.
	 *
	 * @todo Actually implement this.
	 * @return bool Whether WooCommerce is active and set up.
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Configure WooCommerce for Newspack use.
	 *
	 * @todo Actually implement this and set up Shop page, default settings, etc.
	 * @return bool || WP_Error Return true if successful, or WP_Error if not.
	 */
	public function configure() {
		return true;
	}

	/**
	 * Retrieve WooCommerce country/state fields to populate Select
	 *
	 * @return Array Array of objects formatted for use in a SelectControl.
	 */
	public function country_state_fields() {
		if ( ! function_exists( 'WC' ) ) {
			return [];
		}
		$wc_countries = WC()->countries;
		if ( null == $wc_countries ) {
			return [];
		}
		$countries     = $wc_countries->get_countries();
		$states        = $wc_countries->get_states();
		$location_info = [];
		foreach ( $countries as $country_code => $country ) {
			if ( ! empty( $states[ $country_code ] ) ) {
				foreach ( $states[ $country_code ] as $state_code => $state ) {
					$location_info[] = [
						'value' => $country_code . ':' . $state_code,
						'label' => html_entity_decode( $country . ' – ' . $state ),
					];
				}
			} else {
				$location_info[] = [
					'value' => $country_code,
					'label' => html_entity_decode( $country ),
				];
			}
		}
		return $location_info;
	}

	/**
	 * Retrieve configured payment gateways.
	 *
	 * @param bool $only_enabled Whether to return only the enabled gateways.
	 * @return Array Array of payment gateways.
	 */
	public static function get_payment_gateways( $only_enabled = false ) {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return [];
		}
		$gateways = \WC_Payment_Gateways::instance()->payment_gateways();
		if ( $only_enabled ) {
			return array_filter(
				$gateways,
				function( $gateway ) {
					return 'yes' === $gateway->enabled;
				}
			);
		}
		return $gateways;
	}

	/**
	 * Retrieve location data
	 *
	 * @return Array Array of data.
	 */
	public function location_data() {
		$default = [
			'countrystate' => '',
			'address1'     => '',
			'address2'     => '',
			'city'         => '',
			'postcode'     => '',
			'currency'     => '',
		];
		if ( ! function_exists( 'WC' ) ) {
			return $default;
		}
		$wc_countries = WC()->countries;
		if ( null == $wc_countries ) {
			return $default;
		}
		$countrystate_raw = wc_get_base_location();
		return [
			'countrystate' => ( empty( $countrystate_raw['state'] ) || '*' === $countrystate_raw['state'] ) ? $countrystate_raw['country'] : $countrystate_raw['country'] . ':' . $countrystate_raw['state'],
			'address1'     => $wc_countries->get_base_address(),
			'address2'     => $wc_countries->get_base_address_2(),
			'city'         => $wc_countries->get_base_city(),
			'postcode'     => $wc_countries->get_base_postcode(),
			'currency'     => get_woocommerce_currency(),
		];
	}

	/**
	 * Update WooCommerce Location Data
	 *
	 * @param Array $args Address data.
	 * @return Array The data that was updated.
	 */
	public function update_location( $args ) {
		isset( $args['address1'] ) ? update_option( 'woocommerce_store_address', $args['address1'] ) : null;
		isset( $args['address2'] ) ? update_option( 'woocommerce_store_address_2', $args['address2'] ) : null;
		isset( $args['city'] ) ? update_option( 'woocommerce_store_city', $args['city'] ) : null;
		isset( $args['countrystate'] ) ? update_option( 'woocommerce_default_country', $args['countrystate'] ) : null;
		isset( $args['postcode'] ) ? update_option( 'woocommerce_store_postcode', $args['postcode'] ) : null;
		isset( $args['currency'] ) ? update_option( 'woocommerce_currency', $args['currency'] ) : null;
		return true;
	}

	/**
	 * Get Stripe payment gateway, if available.
	 *
	 * @param bool $only_enabled If true, only return the gateway if enabled.
	 *
	 * @return WC_Gateway_Stripe|bool WC_Gateway_Stripe instance if Stripe payment gateway is available, false if not.
	 */
	public static function get_stripe_gateway( $only_enabled = false ) {
		$gateways = self::get_payment_gateways( $only_enabled );
		return isset( $gateways['stripe'] ) ? $gateways['stripe'] : false;
	}

	/**
	 * Get slugs for supported payment gateways.
	 *
	 * @return array
	 */
	public function get_supported_payment_gateways() {
		return array_keys( $this->supported_gateways );
	}

	/**
	 * Get payment gateway by slug, if available.
	 *
	 * @param string $gateway The slug for the payment gateway to get.
	 * @param bool   $only_enabled If true, only return the gateway if enabled.
	 *
	 * @return WC_Gateway_Stripe|bool WC_Gateway_Stripe instance if Stripe payment gateway is available, false if not.
	 */
	public static function get_payment_gateway( $gateway, $only_enabled = false ) {
		$gateways = self::get_payment_gateways( $only_enabled );
		return isset( $gateways[ $gateway ] ) ? $gateways[ $gateway ] : false;
	}

	/**
	 * Retrieve Stripe data
	 *
	 * @return Array|bool Array of Stripe data, or false if Stripe gateway isn't available.
	 */
	public function stripe_data() {
		$stripe_gateway = self::get_stripe_gateway();
		if ( ! $stripe_gateway || ! class_exists( 'WC_Stripe' ) || ! class_exists( 'WC_Stripe_Feature_Flags' ) ) {
			return false;
		}

		$stripe_connection = \WC_Stripe::get_instance()->connect;
		return [
			'enabled'                 => 'yes' === $stripe_gateway->get_option( 'enabled', false ) ? true : false,
			'testMode'                => 'yes' === $stripe_gateway->get_option( 'testmode', false ) ? true : false,
			'is_connected_api_test'   => $stripe_connection->is_connected( 'test' ),
			'is_connected_api_live'   => $stripe_connection->is_connected( 'live' ),
			'is_connected_oauth_test' => $stripe_connection->is_connected_via_oauth( 'test' ),
			'is_connected_oauth_live' => $stripe_connection->is_connected_via_oauth( 'live' ),
			'legacy_checkout_enabled' => ! \WC_Stripe_Feature_Flags::is_upe_checkout_enabled(),
		];
	}

	/**
	 * Retrieve data for the given payment gateway.
	 *
	 * @param string $gateway_slug The slug for the payment gateway to get.
	 *
	 * @return Array|bool Array of gateway data, or false if gateway isn't available.
	 */
	public function gateway_data( $gateway_slug ) {
		if ( ! isset( $this->supported_gateways[ $gateway_slug ] ) ) {
			return false;
		}
		$gateway      = self::get_payment_gateway( $gateway_slug );
		$is_connected = $gateway && method_exists( $gateway, 'is_connected' ) ? $gateway->is_connected() : false;
		$test_mode    = $gateway && 'yes' === $gateway->get_option( 'test_mode', false ) ? true : false;

		// PayPal gateway doesn't provide helper functions, so we need to use its internal API.
		// Service IDs vary across plugin versions: `onboarding.environment` (≤2.9.6) was
		// renamed to `settings.environment` in 3.0.0, and `onboarding.state` was removed in
		// 4.0.0. Probe with has() before get() and wrap the whole block to keep future
		// container changes from taking the wizard down.
		if ( 'ppcp-gateway' === $gateway_slug && method_exists( 'WooCommerce\PayPalCommerce\PPCP', 'container' ) ) {
			$paypal = \WooCommerce\PayPalCommerce\PPCP::container();
			if ( $paypal ) {
				try {
					$env = null;
					if ( $paypal->has( 'settings.environment' ) ) {
						$env = $paypal->get( 'settings.environment' );
					} elseif ( $paypal->has( 'onboarding.environment' ) ) {
						$env = $paypal->get( 'onboarding.environment' );
					}
					if ( $env && $env->current_environment_is( $env::SANDBOX ) ) {
						$test_mode = true;
					}
					if ( $paypal->has( 'onboarding.state' ) ) {
						$state = $paypal->get( 'onboarding.state' );
						if ( $state && $state::STATE_ONBOARDED <= $state->current_state() ) {
							$is_connected = true;
						}
					}
				} catch ( \Throwable $th ) {
					// Container shape changed unexpectedly; degrade to gateway-derived values
					// so the wizard keeps rendering, and surface the cause for debugging.
					Logger::log(
						sprintf(
							'PayPal container access failed in gateway_data(): %s on line %d',
							$th->getMessage(),
							$th->getLine()
						),
						'error'
					);
				}
			}
		}

		return [
			'enabled'      => $gateway && 'yes' === $gateway->get_option( 'enabled', false ) ? true : false,
			'name'         => esc_html( $this->supported_gateways[ $gateway_slug ]['name'] ),
			'test_mode'    => $test_mode,
			'is_connected' => $is_connected,
			'slug'         => $gateway_slug,
			'url'          => $this->supported_gateways[ $gateway_slug ]['url'],
			'connect'      => \admin_url( $this->supported_gateways[ $gateway_slug ]['connect'] ),
			'settings'     => \admin_url( $this->supported_gateways[ $gateway_slug ]['settings'] ),
		];
	}

	/**
	 * Update WooCommerce Stripe settings
	 *
	 * @param Array $args Address data.
	 * @return Array|WP_Error The data that was updated or an error.
	 */
	public function update_wc_stripe_settings( $args ) {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return false;
		}

		// Get the Stripe payment gateway instance.
		$stripe = self::get_stripe_gateway();

		if ( ! $stripe ) {
			if ( isset( $args['enabled'] ) && $args['enabled'] ) {
				// Stripe gateway is not installed and we want to use it. Install/Activate/Initialize it.
				Plugin_Manager::activate( 'woocommerce-gateway-stripe' );
				do_action( 'plugins_loaded' );
				\WC_Payment_Gateways::instance()->init();
				$stripe = self::get_stripe_gateway();
				if ( ! $stripe ) {
					return new WP_Error( 'newspack_stripe_gateway_error', __( 'Error activating the Stripe payment gateway.', 'newspack-plugin' ) );
				}
			} else {
				// Stripe is not installed and we don't want to use it. No settings needed.
				return true;
			}
		}

		// Update Stripe payment gateway settings.
		if ( isset( $args['enabled'] ) ) {
			$stripe->update_option( 'enabled', $args['enabled'] ? 'yes' : 'no' );
		}
		if ( isset( $args['testMode'] ) ) {
			$stripe->update_option( 'testmode', $args['testMode'] ? 'yes' : 'no' );
		}
		if ( isset( $args['publishableKey'] ) ) {
			$stripe->update_option( 'publishable_key', $args['publishableKey'] );
		}
		if ( isset( $args['secretKey'] ) ) {
			$stripe->update_option( 'secret_key', $args['secretKey'] );
		}
		if ( isset( $args['testPublishableKey'] ) ) {
			$stripe->update_option( 'test_publishable_key', $args['testPublishableKey'] );
		}
		if ( isset( $args['testSecretKey'] ) ) {
			$stripe->update_option( 'test_secret_key', $args['testSecretKey'] );
		}

		// @todo when is the best time to do this?
		$this->set_smart_defaults();

		return true;
	}

	/**
	 * Update settings for the given payment gateway.
	 *
	 * @param string $gateway_slug The slug for the payment gateway to update.
	 * @param Array  $args Settings.
	 * @return Array|WP_Error The data that was updated or an error.
	 */
	public function update_gateway_settings( $gateway_slug, $args ) {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return false;
		}

		if ( ! isset( $this->supported_gateways[ $gateway_slug ] ) ) {
			return false;
		}

		// Get the payment gateway instance.
		$gateway = self::get_payment_gateway( $gateway_slug );
		if ( ! $gateway ) {
			if ( isset( $args['enabled'] ) && $args['enabled'] ) {
				// Gateway is not installed and we want to use it. Install/Activate/Initialize it.
				Plugin_Manager::activate( $this->supported_gateways[ $gateway_slug ]['plugin'] );
				do_action( 'plugins_loaded' );
				\WC_Payment_Gateways::instance()->init();
				$gateway = self::get_payment_gateway( $gateway_slug );
				if ( ! $gateway ) {
					return new WP_Error(
						'newspack_payment_gateway_error',
						sprintf(
							// Translators: %s is the slug of the payment gateway.
							__( 'Error activating %s gateway.', 'newspack-plugin' ),
							$gateway_slug
						)
					);
				}
			} else {
				// Gateway is not installed and we don't want to use it. No settings needed.
				return true;
			}
		}

		// Update gateway settings.
		if ( isset( $args['enabled'] ) ) {
			$gateway->update_option( 'enabled', $args['enabled'] ? 'yes' : 'no' );
		}

		return true;
	}

	/**
	 * Set general settings that our users will want (e.g. no reason for product reviews on a news membership).
	 */
	public function set_smart_defaults() {
		// Create Shop, My Account, etc. pages if not already created.
		WC_Install::create_pages();

		// Disable coupons and reviews.
		update_option( 'woocommerce_enable_coupons', 'no' );
		update_option( 'woocommerce_enable_reviews', 'no' );

		// Enables checkout without login.
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
	}
}
