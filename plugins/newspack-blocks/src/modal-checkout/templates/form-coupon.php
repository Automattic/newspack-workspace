<?php
/**
 * Checkout coupon form
 *
 * @see https://woo.com/document/template-structure/
 * @package Newspack_Blocks
 */

defined( 'ABSPATH' ) || exit;

if ( ! wc_coupons_enabled() ) { // @codingStandardsIgnoreLine.
	return;
}

?>
<form class="modal_checkout_coupon woocommerce-form-coupon" method="post">
	<h3><?php esc_html_e( 'Apply a coupon code', 'newspack-blocks' ); ?></h3>
	<p>
		<input type="text" name="coupon_code" class="input-text" placeholder="<?php esc_attr_e( 'Coupon code', 'newspack-blocks' ); ?>" id="coupon_code" value="" />
		<button type="submit" class="<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?> newspack-ui__button newspack-ui__button--outline" name="apply_coupon" value="<?php esc_attr_e( 'Apply coupon', 'newspack-blocks' ); ?>"><?php esc_html_e( 'Apply', 'newspack-blocks' ); ?></button>
	</p>
</form>
