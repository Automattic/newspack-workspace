<?php
/**
 * My Subscriptions section on the My Account page
 *
 * This is a modified version of the WooCommerce Subscriptions template.
 * It adds a "Product" column to the subscriptions table and removes the subscription ID column.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<div class="woocommerce_account_subscriptions">

	<?php if ( ! empty( $subscriptions ) ) : ?>
	<table class="my_account_subscriptions my_account_orders woocommerce-orders-table woocommerce-MyAccount-subscriptions shop_table shop_table_responsive woocommerce-orders-table--subscriptions">

	<thead>
		<tr>
			<th class="subscription-product-name woocommerce-orders-table__header woocommerce-orders-table__header-order-product-name woocommerce-orders-table__header-subscription-product-name"><span class="nobr"><?php esc_html_e( 'Product', 'newspack-plugin' ); ?></span></th>
			<th class="subscription-status order-status woocommerce-orders-table__header woocommerce-orders-table__header-order-status woocommerce-orders-table__header-subscription-status"><span class="nobr"><?php esc_html_e( 'Status', 'newspack-plugin' ); ?></span></th>
			<th class="subscription-next-payment order-date woocommerce-orders-table__header woocommerce-orders-table__header-order-date woocommerce-orders-table__header-subscription-next-payment"><span class="nobr"><?php echo esc_html_x( 'Next payment', 'table heading', 'newspack-plugin' ); ?></span></th>
			<th class="subscription-total order-total woocommerce-orders-table__header woocommerce-orders-table__header-order-total woocommerce-orders-table__header-subscription-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'newspack-plugin' ); ?></span></th>
			<th class="subscription-actions order-actions woocommerce-orders-table__header woocommerce-orders-table__header-order-actions woocommerce-orders-table__header-subscription-actions">&nbsp;</th>
		</tr>
	</thead>

	<tbody>
		<?php
		foreach ( $subscriptions as $subscription_id => $subscription ) :
			$is_group_subscription        = Group_Subscription::is_group_subscription( $subscription );
			$is_group_member_subscription = $is_group_subscription && Group_Subscription::user_is_member( get_current_user_id(), $subscription );
			?>
		<tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $subscription->get_status() ); ?>">
			<td class="subscription-product-name woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-product-name woocommerce-orders-table__cell-order-product-name" data-title="<?php esc_attr_e( 'Product', 'newspack-plugin' ); ?>">
				<?php
				$items = $subscription->get_items();
				if ( ! empty( $items ) ) {
					$item = reset( $items );
					echo esc_html( $item->get_name() );
				}
				if ( $is_group_subscription ) :
					?>
					<span class="newspack-ui__badge newspack-ui__badge--secondary">
						<?php echo esc_html( Group_Subscription::get_label( 'singular' ) ); ?>
					</span>
				<?php endif; ?>
			</td>
			<td class="subscription-status order-status woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-status woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'newspack-plugin' ); ?>">
				<?php echo esc_attr( wcs_get_subscription_status_name( $subscription->get_status() ) ); ?>
			</td>
			<td class="subscription-next-payment order-date woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-next-payment woocommerce-orders-table__cell-order-date" data-title="<?php echo esc_attr_x( 'Next Payment', 'table heading', 'newspack-plugin' ); ?>">
				<?php echo esc_attr( $subscription->get_date_to_display( 'next_payment' ) ); ?>
				<?php if ( ! $subscription->is_manual() && $subscription->has_status( 'active' ) && $subscription->get_time( 'next_payment' ) > 0 && ! $is_group_member_subscription ) : ?>
				<br/><small><?php echo esc_html( $subscription->get_payment_method_to_display( 'customer' ) ); ?></small>
				<?php endif; ?>
			</td>
			<td class="subscription-total order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-total woocommerce-orders-table__cell-order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'newspack-plugin' ); ?>">
				<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
			</td>
			<td class="subscription-actions order-actions woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-actions woocommerce-orders-table__cell-order-actions">
				<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>" class="woocommerce-button button view<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>"><?php echo esc_html_x( 'View', 'view a subscription', 'newspack-plugin' ); ?></a>
				<?php do_action( 'woocommerce_my_subscriptions_actions', $subscription ); ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>

	</table>
		<?php if ( 1 < $max_num_pages ) : ?>
			<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
			<?php if ( 1 !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button" href="<?php echo esc_url( wc_get_endpoint_url( 'subscriptions', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'newspack-plugin' ); ?></a>
			<?php endif; ?>

			<?php if ( intval( $max_num_pages ) !== $current_page ) : ?>
				<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button" href="<?php echo esc_url( wc_get_endpoint_url( 'subscriptions', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'newspack-plugin' ); ?></a>
			<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<p class="no_subscriptions woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
			<?php
			if ( 1 < $current_page ) :
				printf(
					// translators: %1$s: opening anchor tag. %2$s: closing anchor tag.
					esc_html__( 'You have reached the end of subscriptions. Go to the %1$sfirst page%2$s.', 'newspack-plugin' ),
					'<a href="' . esc_url( wc_get_endpoint_url( 'subscriptions', 1 ) ) . '">',
					'</a>'
				);
			else :
				esc_html_e( 'You have no active subscriptions.', 'newspack-plugin' );
				?>
				<a class="woocommerce-Button button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
					<?php esc_html_e( 'Browse products', 'newspack-plugin' ); ?>
				</a>
				<?php
			endif;
			?>
		</p>

	<?php endif; ?>

</div>
