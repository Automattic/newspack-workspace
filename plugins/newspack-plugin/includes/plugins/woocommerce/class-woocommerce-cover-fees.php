<?php
/**
 * Add ability to cover transaction fees for donations completed via supported payment gateways.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Order UTM class.
 */
class WooCommerce_Cover_Fees {
	const CUSTOM_FIELD_NAME = 'newspack-wc-pay-fees';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'add_checkout_fields' ] );
		\add_action( 'woocommerce_checkout_update_order_review', [ __CLASS__, 'persist_fee_selection' ] );
		\add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'add_transaction_fee' ] );
		\add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'add_order_note' ], 1, 3 );
		\add_action( 'newspack_blocks_after_payment_fields', [ __CLASS__, 'render_transaction_fee_input' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'print_checkout_helper_script' ] );
	}

	/**
	 * Add the "cover fees" checkout field.
	 *
	 * @param array $fields Checkout fields.
	 *
	 * @return array
	 */
	public static function add_checkout_fields( $fields ) {
		if ( ! self::should_allow_covering_fees() ) {
			return $fields;
		}
		$fields['newspack'] = [
			self::CUSTOM_FIELD_NAME => [
				'type'    => 'checkbox',
				'default' => intval( get_option( 'newspack_donations_allow_covering_fees_default', false ) ),
			],
		];
		return $fields;
	}

	/**
	 * Persist the transaction fee selection in the Woo sesion when updating the
	 * order review.
	 *
	 * @param string $posted_data Posted posted_data.
	 */
	public static function persist_fee_selection( $posted_data ) {
		$data = [];
		parse_str( $posted_data, $data );
		if ( self::should_apply_fee( $data ) ) {
			\WC()->session->set( self::CUSTOM_FIELD_NAME, 1 );
		} else {
			\WC()->session->set( self::CUSTOM_FIELD_NAME, 0 );
		}
	}

	/**
	 * Add fee.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public static function add_transaction_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! \WC()->session->get( self::CUSTOM_FIELD_NAME ) ) {
			return;
		}
		$cart->add_fee(
			sprintf(
				// Translators: %s is the fee percentage.
				__( 'Transaction fee (%s)', 'newspack-plugin' ),
				self::get_cart_fee_percentage()
			),
			self::get_cart_fee_value()
		);
	}

	/**
	 * Add an order note.
	 *
	 * @param int       $order_id Order ID.
	 * @param array     $posted_data Posted data.
	 * @param \WC_Order $order Order object.
	 */
	public static function add_order_note( $order_id, $posted_data, $order ) {
		if ( \WC()->session->get( self::CUSTOM_FIELD_NAME ) ) {
			$order->add_order_note( __( 'The donor opted to cover transaction fees.', 'newspack-plugin' ) );
		}
	}

	/**
	 * Should this feature be active?
	 *
	 * Some of this code is taken from Newspack Blocks, but can't be reused directly because
	 * WC renders the checkout form before Blocks' code is available.
	 */
	private static function should_allow_covering_fees() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}
		if ( 0 < count( WC()->cart->get_coupon_discount_totals() ) ) {
			// If the checkout has coupons applied, bail. This can be develped in the future,
			// but at this point handling coupons + covering fees is an edge case.
			return false;
		}
		if ( true !== boolval( get_option( 'newspack_donations_allow_covering_fees', true ) ) ) {
			return false;
		}
		if ( ! Donations::is_donation_cart() && true == boolval( get_option( 'newspack_donations_allow_covering_fees_donations_only', true ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether to apply the fee in the current request.
	 *
	 * @param array $data Posted data.
	 *
	 * @return bool
	 */
	private static function should_apply_fee( $data ) {
		if ( ! self::should_allow_covering_fees() ) {
			return false;
		}
		$wc_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'woocommerce' );
		if ( ! isset( $data['payment_method'] ) || ! in_array( $data['payment_method'], $wc_configuration_manager->get_supported_payment_gateways(), true ) ) {
			return false;
		}
		if ( ! isset( $data[ self::CUSTOM_FIELD_NAME ] ) || '1' !== $data[ self::CUSTOM_FIELD_NAME ] ) {
			return false;
		}
		return true;
	}

	/**
	 * Render the "cover transaction fees" input for supported payment gateways.
	 *
	 * @param string $payment_gateway The slug for the payment gateway rendering these payment fields.
	 */
	public static function render_transaction_fee_input( $payment_gateway ) {
		$wc_configuration_manager = Configuration_Managers::configuration_manager_class_for_plugin_slug( 'woocommerce' );
		if ( ! self::should_allow_covering_fees() || ! in_array( $payment_gateway, $wc_configuration_manager->get_supported_payment_gateways(), true ) ) {
			return;
		}
		?>
		<fieldset>
			<p class="form-row newspack-cover-fees" style="display: flex;">
				<input
					id="<?php echo esc_attr( self::CUSTOM_FIELD_NAME . '_' . $payment_gateway ); ?>"
					name="<?php echo esc_attr( self::CUSTOM_FIELD_NAME ); ?>"
					type="checkbox"
					value="1"
					<?php if ( boolval( get_option( 'newspack_donations_allow_covering_fees_default', false ) ) ) : ?>
						checked
					<?php endif; ?>
				/>
				<label for=<?php echo esc_attr( self::CUSTOM_FIELD_NAME . '_' . $payment_gateway ); ?> style="display:inline;">
					<?php
					$custom_message = get_option( 'newspack_donations_allow_covering_fees_label', '' );
					if ( ! empty( $custom_message ) ) {
						echo esc_html( $custom_message );
					} else {
						printf(
							// Translators: %s is the possessive form of the site name.
							esc_html__(
								'I’d like to cover the %1$s transaction fee to ensure my full donation goes towards %2$s mission.',
								'newspack-plugin'
							),
							wp_kses_post( self::get_cart_fee_display_value() ),
							esc_html( self::get_possessive( get_option( 'blogname' ) ) )
						);
					}
					?>
				</label>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Get possessive form of the given string. Proper nouns ending in S should not have a trailing S.
	 *
	 * @param string $string String to modify.
	 * @return string Modified string.
	 */
	private static function get_possessive( $string ) {
		return $string . '’' . ( 's' !== $string[ strlen( $string ) - 1 ] ? 's' : '' );
	}

	/**
	 * Print the checkout helper JS script.
	 */
	public static function print_checkout_helper_script() {
		if ( ! self::should_allow_covering_fees() ) {
			return;
		}
		$handler = 'newspack-wc-cover-fees';
		wp_enqueue_script(
			$handler,
			\Newspack\Newspack::plugin_url() . '/dist/other-scripts/wc-cover-fees.js',
			[ 'jquery' ],
			NEWSPACK_PLUGIN_VERSION,
			[ 'in_footer' => true ]
		);
	}

	/**
	 * Get the fee multiplier value.
	 */
	private static function get_fee_multiplier_value() {
		return floatval( get_option( 'newspack_blocks_donate_fee_multiplier', '2.9' ) );
	}

	/**
	 * Get the fee static portion value.
	 */
	private static function get_fee_static_value() {
		return floatval( get_option( 'newspack_blocks_donate_fee_static', '0.3' ) );
	}

	/**
	 * Get the fee display value.
	 *
	 * @param float $subtotal The subtotal to calculate the fee for.
	 */
	public static function get_fee_display_value( $subtotal ) {
		$total = self::get_total_with_fee( $subtotal );
		if ( ! function_exists( 'wc_price' ) ) {
			$donation_settings = Donations::get_donation_settings();
			// Just one decimal place, please.
			return $donation_settings['currencySymbol'] . ( (float) number_format( $total - $subtotal, 2 ) );
		}
		return \wc_price( $total - $subtotal );
	}

	/**
	 * Get the fee value.
	 *
	 * @param float $subtotal The subtotal to calculate the fee for.
	 */
	public static function get_fee_value( $subtotal ) {
		$fee_multiplier = self::get_fee_multiplier_value();
		$fee_static     = self::get_fee_static_value();
		$fee            = ( ( ( $subtotal + $fee_static ) / ( 100 - $fee_multiplier ) ) * 100 - $subtotal );
		return $fee;
	}

	/**
	 * Get the fee percentage.
	 *
	 * @param float $subtotal The subtotal to calculate the fee for.
	 *
	 * @return string
	 */
	public static function get_fee_percentage( $subtotal ) {
		$flat_percentage = 0;
		if ( is_numeric( $subtotal ) && (float) $subtotal > 0 ) {
			$total = self::get_total_with_fee( $subtotal );
			// Just one decimal place, please.
			$flat_percentage = (float) number_format( ( ( $total - $subtotal ) * 100 ) / $subtotal, 1 );
		}
		return $flat_percentage . '%';
	}

	/**
	 * Calculate the adjusted total, taking the fee into account.
	 *
	 * @param float $subtotal The subtotal to calculate the total for.
	 *
	 * @return float
	 */
	public static function get_total_with_fee( $subtotal ) {
		return $subtotal + self::get_fee_value( $subtotal );
	}

	/**
	 * Get the fee value for the current cart.
	 *
	 * @return float
	 */
	public static function get_cart_fee_value() {
		return self::get_fee_value( WC()->cart->get_subtotal() + ( WC()->cart->get_subtotal_tax() ?? 0 ) );
	}

	/**
	 * Get the fee percentage for the current cart.
	 *
	 * @return float
	 */
	public static function get_cart_fee_percentage() {
		return self::get_fee_percentage( WC()->cart->get_subtotal() + ( WC()->cart->get_subtotal_tax() ?? 0 ) );
	}

	/**
	 * Get the fee display value for the current cart.
	 *
	 * @return string
	 */
	public static function get_cart_fee_display_value() {
		return self::get_fee_display_value( WC()->cart->get_subtotal() + ( WC()->cart->get_subtotal_tax() ?? 0 ) );
	}

	/**
	 * Get the total with fee for the current cart.
	 *
	 * @return float
	 */
	public static function get_cart_total_with_fee() {
		return self::get_total_with_fee( WC()->cart->get_subtotal() + ( WC()->cart->get_subtotal_tax() ?? 0 ) );
	}
}
WooCommerce_Cover_Fees::init();
