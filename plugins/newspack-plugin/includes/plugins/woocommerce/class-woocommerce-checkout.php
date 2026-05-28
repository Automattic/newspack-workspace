<?php
/**
 * WooCommerce Checkout features.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Checkout features.
 */
class WooCommerce_Checkout {
	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		add_action( 'woocommerce_review_order_before_payment', [ __CLASS__, 'newspack_payment_heading' ] );
	}

	/**
	 * Add heading above payment info form.
	 */
	public static function newspack_payment_heading() {
		?>
		<h3><?php esc_html_e( 'Payment info', 'newspack-plugin' ); ?></h3>
		<?php
	}
}

WooCommerce_Checkout::init();
