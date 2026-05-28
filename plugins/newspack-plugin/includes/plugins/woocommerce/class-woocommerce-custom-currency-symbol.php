<?php
/**
 * Add custom currency symbol option to WooCommerce settings.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Custom Currency Symbol class.
 */
class WooCommerce_Custom_Currency_Symbol {
	const OPTION_KEY = 'newspack_woocommerce_custom_currency_symbol';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_filter( 'woocommerce_general_settings', [ __CLASS__, 'add_currency_symbol_setting' ] );
		\add_filter( 'woocommerce_currency_symbol', [ __CLASS__, 'filter_currency_symbol' ], 10, 2 );
	}

	/**
	 * Add custom currency symbol field to WooCommerce currency options.
	 *
	 * @param array $settings WooCommerce general settings.
	 * @return array Modified settings.
	 */
	public static function add_currency_symbol_setting( $settings ) {
		$new_settings = [];

		foreach ( $settings as $setting ) {
			$new_settings[] = $setting;

			if ( isset( $setting['id'] ) && 'woocommerce_price_num_decimals' === $setting['id'] ) {
				$new_settings[] = [
					'title'    => __( 'Custom currency symbol', 'newspack-plugin' ),
					'desc'     => __( 'Enter a custom symbol to override the default currency symbol.', 'newspack-plugin' ),
					'id'       => self::OPTION_KEY,
					'type'     => 'text',
					'default'  => '',
					'css'      => 'width: 100px;',
					'desc_tip' => true,
				];
			}
		}

		return $new_settings;
	}

	/**
	 * Filter the currency symbol if a custom one is set.
	 *
	 * @param string $currency_symbol The currency symbol.
	 * @param string $currency The currency code.
	 * @return string Modified currency symbol.
	 */
	public static function filter_currency_symbol( $currency_symbol, $currency ) {
		$custom_symbol = get_option( self::OPTION_KEY, '' );

		if ( ! empty( $custom_symbol ) ) {
			return $custom_symbol;
		}

		return $currency_symbol;
	}
}
WooCommerce_Custom_Currency_Symbol::init();
