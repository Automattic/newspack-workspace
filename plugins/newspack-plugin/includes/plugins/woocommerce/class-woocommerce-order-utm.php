<?php
/**
 * Add UTM parameters to WooCommerce orders.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Order UTM class.
 */
class WooCommerce_Order_UTM {
	/**
	 * UTM parameters.
	 *
	 * @var string[]
	 */
	public static $params = [
		'source',
		'medium',
		'campaign',
		'term',
		'content',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_before_order_review_heading', [ __CLASS__, 'render_utm_inputs' ] );
		add_action( 'woocommerce_new_order', [ __CLASS__, 'set_utm_to_order' ] );
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ __CLASS__, 'display_utm_parameters' ] );
	}

	/**
	 * Render UTM parameters as checkout form inputs.
	 */
	public static function render_utm_inputs() {
		foreach ( self::$params as $param ) {
			$query_arg = 'utm_' . $param;
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET[ $query_arg ] ) ) {
				?>
				<input type="hidden" name="<?php echo esc_attr( $query_arg ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $query_arg ] ) ) ); ?>" />
				<?php
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Add UTM to order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return void
	 */
	public static function set_utm_to_order( $order_id ) {
		$order = wc_get_order( $order_id );
		$utm   = self::get_utm();
		if ( ! empty( $utm ) ) {
			$order->update_meta_data( 'utm', $utm );
			$order->save();
		}
	}

	/**
	 * Get UTM parameters.
	 */
	private static function get_utm() {
		$utm = [];
		foreach ( self::$params as $param ) {
			$query_arg = 'utm_' . $param;
			if ( ! empty( $_REQUEST[ $query_arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$utm[ $param ] = sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		return $utm;
	}

	/**
	 * Display UTM parameters in order details.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public static function display_utm_parameters( $order ) {
		$utm = $order->get_meta( 'utm' );
		if ( empty( $utm ) ) {
			return;
		}
		echo '<h3>' . esc_html__( 'UTM Parameters', 'newspack-plugin' ) . '</h3>';
		foreach ( $utm as $key => $value ) {
			echo '<p><strong>' . esc_html( ucfirst( $key ) ) . ':</strong> ' . esc_html( $value ) . '</p>';
		}
	}
}
WooCommerce_Order_UTM::init();
