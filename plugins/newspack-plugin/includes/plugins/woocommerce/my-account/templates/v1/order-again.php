<?php
/**
 * Order again button.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

defined( 'ABSPATH' ) || exit;

$wp_button_class = 'newspack-ui__button newspack-ui__button--secondary';
$contains_subscription = wcs_order_contains_subscription( $order, 'parent' );
$button_text = $contains_subscription ? __( 'Renew subscription', 'newspack-plugin' ) : __( 'Order again', 'newspack-plugin' );
if ( $contains_subscription ) {
	$subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
	if ( empty( $subscriptions ) ) {
		return;
	}
	foreach ( $subscriptions as $subscription ) {
		if ( ! wcs_can_user_resubscribe_to( $subscription, get_current_user_id() ) ) {
			return;
		}
	}
}
?>

<p class="order-again">
	<a href="<?php echo esc_url( $order_again_url ); ?>" class="<?php echo esc_attr( $wp_button_class ); ?>"><?php echo esc_html( $button_text ); ?></a>
</p>
