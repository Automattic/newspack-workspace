<?php
/**
 * Checkout Form
 *
 * @see https://woo.com/document/template-structure/
 * @package Newspack_Blocks
 */

namespace Newspack_Blocks;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hooks.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'newspack-blocks' ) ) );
	return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">
	<?php wp_nonce_field( 'newspack_modal_checkout_nonce', 'newspack_checkout_nonce' ); ?>
	<?php if ( $checkout->get_checkout_fields() ) : ?>
		<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
		<div id="customer_details">
			<?php do_action( 'woocommerce_checkout_billing' ); ?>
			<?php do_action( 'woocommerce_checkout_shipping' ); ?>
			<?php \Newspack_Blocks\Modal_Checkout::maybe_show_wcs_gifting_fields(); ?>
			<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide" id="checkout_continue" type="submit"><?php esc_html_e( 'Continue', 'newspack-blocks' ); ?></button>
		</div>
		<div id="after_customer_details">
			<?php
			if ( wp_is_block_theme() && function_exists( 'woocommerce_checkout_payment' ) ) {
				woocommerce_checkout_payment();
			}
			?>
			<div class="order-review-wrapper hidden">
				<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
				<div id="order_review" class="woocommerce-checkout-review-order newspack-ui__box">
					<?php
					if ( wp_is_block_theme() && function_exists( 'woocommerce_order_review' ) ) {
						woocommerce_order_review();
					} else {
						do_action( 'woocommerce_checkout_order_review' );
					}
					?>
				</div>
				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
			</div>
			<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
			<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide" id="checkout_edit_billing" type="button"><?php echo esc_html( Modal_Checkout::get_modal_checkout_labels( 'checkout_edit_billing' ) ); ?></button>
		</div>
		<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide" id="checkout_cancel" type="button"><?php echo esc_html( Modal_Checkout::get_modal_checkout_labels( 'checkout_cancel' ) ); ?></button>
	<?php endif; ?>
</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
