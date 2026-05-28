<?php
/**
 * Pay for order form displayed after a customer has clicked the "Change Payment method" button
 * next to a subscription on their My Account page.
 *
 * To be rendered in the modal checkout.
 *
 * @package Newspack_Blocks
 */

namespace Newspack_Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<form id="order_review" class="change-payment-method" method="post">

	<div id="payment">
		<?php
		if ( $subscription->has_payment_gateway() ) {
			$pay_order_button_text = _x( 'Change payment method', 'text on button on checkout page', 'woocommerce-subscriptions' );
		} else {
			$pay_order_button_text = _x( 'Add payment method', 'text on button on checkout page', 'woocommerce-subscriptions' );
		}

		$pay_order_button_text     = apply_filters( 'woocommerce_change_payment_button_text', $pay_order_button_text );
		$customer_subscription_ids = \WCS_Customer_Store::instance()->get_users_subscription_ids( $subscription->get_customer_id() );
		$payment_gateways_handler  = \WC_Subscriptions_Core_Plugin::instance()->get_gateways_handler_class();
		$available_gateways        = \WC()->payment_gateways->get_available_payment_gateways();

		if ( $available_gateways ) :
			?>
			<ul class="wc_payment_methods payment_methods methods">
				<?php

				if ( count( $available_gateways ) ) {
					current( $available_gateways )->set_current();
				}

				foreach ( $available_gateways as $gateway ) {
					require 'payment-method.php';
				}

				?>
			</ul>
		<?php else : ?>
			<div class="woocommerce-error">
				<p> <?php echo esc_html( apply_filters( 'woocommerce_no_available_payment_methods_message', __( 'Sorry, it seems no payment gateways support changing the recurring payment method. Please contact us if you require assistance or to make alternate arrangements.', 'woocommerce-subscriptions' ) ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $available_gateways ) : ?>
			<?php if ( count( $customer_subscription_ids ) > 1 && $payment_gateways_handler::one_gateway_supports( 'subscription_payment_method_change_admin' ) ) : ?>
			<span class="update-all-subscriptions-payment-method-wrap">
				<?php
				// translators: $1: opening <strong> tag, $2: closing </strong> tag.
				$label = sprintf( esc_html__( 'Use this payment method for %1$sall%2$s of my current subscriptions', 'woocommerce-subscriptions' ), '<strong>', '</strong>' );

				woocommerce_form_field(
					'update_all_subscriptions_payment_method',
					array(
						'type'     => 'checkbox',
						'class'    => array( 'form-row-wide' ),
						'label'    => $label,
						'required' => true, // Making the field required to help make it more prominent on the page.
						'default'  => apply_filters( 'wcs_update_all_subscriptions_payment_method_checked', true ),
					)
				);
				?>
			</span>
			<?php endif; ?>
		<div class="form-row">
			<?php wp_nonce_field( 'wcs_change_payment_method', '_wcsnonce', true, true ); ?>

			<?php do_action( 'woocommerce_subscriptions_change_payment_before_submit' ); ?>

			<?php
			echo wp_kses(
				apply_filters( 'woocommerce_change_payment_button_html', '<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide button alt' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" id="place_order">' . esc_html( $pay_order_button_text ) . '</button>' ),
				array(
					'button' => array(
						'type'  => array(),
						'class' => array(),
						'id'    => array(),
					),
				)
			);
			?>
			<?php do_action( 'woocommerce_subscriptions_change_payment_after_submit' ); ?>
			<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide" id="checkout_cancel" type="button"><?php echo esc_html( Modal_Checkout::get_modal_checkout_labels( 'checkout_cancel' ) ); ?></button>

			<input type="hidden" name="woocommerce_change_payment" value="<?php echo esc_attr( $subscription->get_id() ); ?>" />
		</div>
		<?php endif; ?>

	</div>

</form>
