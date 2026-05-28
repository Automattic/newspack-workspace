<?php
/**
 * Login form.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.1.0
 *
 * @var WC_Order $order
 */

namespace Newspack_Blocks;

use Newspack_Blocks\Modal_Checkout\Checkout_Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce by default doesn't allow order details to be shown
 * if the order is completed using a customer email address that doesn't match
 * the currently logged in user.
 *
 * Details: https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/shortcodes/class-wc-shortcode-checkout.php#L302-L321
 *
 * For Newspack sites, we don't want to emphasize user account flows outside of
 * RAS. This custom login template replaces the login form that appears on the
 * order-received.php template with an order details summary so the experience
 * matches whether or not the email address used is already associated with an
 * existing customer account.
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */
// When this template replaces global/form-login.php, $order is not provided by WooCommerce.
// Retrieve it from the URL query vars, the same way WooCommerce does in the order-received endpoint.
if ( ! isset( $order ) || ! $order ) {
	$order_id = absint( \get_query_var( 'order-received' ) );
	$order    = $order_id ? \wc_get_order( $order_id ) : false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

$key      = isset( $_GET['key'] ) ? \wc_clean( \sanitize_text_field( \wp_unslash( $_GET['key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$is_valid = $order && is_a( $order, 'WC_Order' ) && hash_equals( $order->get_order_key(), $key ); // Validate order key to prevent CSRF.

if ( ! $is_valid ) {
	return;
}

$is_success             = ! $order->has_status( 'failed' );
$after_success_behavior = isset( $_GET['after_success_behavior'] ) ? \sanitize_text_field( \wp_unslash( $_GET['after_success_behavior'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$after_success_url      = isset( $_GET['after_success_url'] ) ? esc_url( \sanitize_url( \wp_unslash( $_GET['after_success_url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$after_success_label    = isset( $_GET['after_success_button_label'] ) ? \sanitize_text_field( \wp_unslash( $_GET['after_success_button_label'] ) ) : \Newspack_Blocks\Modal_Checkout::get_modal_checkout_labels( 'after_success' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$checkout_data          = Checkout_Data::get_checkout_data( $order );
?>
<div class="woocommerce-order">
<?php if ( $is_success ) : ?>
	<div class="newspack-ui__box newspack-ui__box--success newspack-ui__box--text-center">
		<span class="newspack-ui__icon newspack-ui__icon--success">
			<?php // TODO: Replace with newspack-ui icons when available. ?>
			<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
			</svg>
		</span>
		<p id="modal-checkout-product-details" data-checkout='<?php echo wp_json_encode( $checkout_data ); ?>'>
			<strong>
				<?php
					echo esc_html( Modal_Checkout::get_post_checkout_success_text() );
				?>
			</strong>
		</p>
	</div>
	<form>
		<?php if ( $after_success_behavior ) : ?>
			<input type="hidden" name="after_success_behavior" value="<?php echo esc_attr( $after_success_behavior ); ?>">
		<?php endif; ?>
		<?php if ( $after_success_behavior ) : ?>
			<input type="hidden" name="after_success_url" value="<?php echo esc_attr( $after_success_url ); ?>">
		<?php endif; ?>
		<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide" id="checkout-after-success" onclick="parent.newspackCloseModalCheckout();">
			<?php echo esc_html( $after_success_label ); ?>
		</button>
	</form>
<?php else : ?>
	<div class="newspack-ui__box newspack-ui__box__error newspack-ui__box--text-center">
		<p>
			<?php esc_html_e( 'Unfortunately your order cannot be processed. Please attempt your purchase again.', 'newspack-blocks' ); ?>
		</p>
	</div>
	<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="newspack-blocks-ui__button newspack-ui__button--primary newspack-ui__button--wide"><?php esc_html_e( 'Pay', 'newspack-blocks' ); ?></a>
	<?php if ( is_user_logged_in() ) : ?>
		<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="newspack-blocks-ui__button newspack-ui__button--ghost newspack-ui__button--wide"><?php esc_html_e( 'My account', 'newspack-blocks' ); ?></a>
	<?php endif; ?>
	<?php
endif;
do_action( 'newpack_blocks_modal_checkout_thankyou' );
