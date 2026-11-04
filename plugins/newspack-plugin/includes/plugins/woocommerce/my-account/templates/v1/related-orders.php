<?php
/**
 * Custom related orders table template.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

$is_group_member_subscription = Group_Subscription::is_group_subscription( $subscription ) && Group_Subscription::user_is_member( get_current_user_id(), $subscription );

if ( $is_group_member_subscription ) {
	return;
}
?>
<header>
	<h2><?php esc_html_e( 'Billing history', 'newspack-plugin' ); ?></h2>
</header>

<table id="woocommerce-subscriptions-related-orders-table" class="shop_table shop_table_responsive my_account_orders woocommerce-orders-table woocommerce-MyAccount-orders woocommerce-orders-table--orders">

	<thead>
		<tr>
			<th class="order-number woocommerce-orders-table__header woocommerce-orders-table__header-order-number"><span class="nobr"><?php esc_html_e( 'Order', 'newspack-plugin' ); ?></span></th>
			<th class="order-date woocommerce-orders-table__header woocommerce-orders-table__header-order-date woocommerce-orders-table__header-order-date"><span class="nobr"><?php esc_html_e( 'Date', 'newspack-plugin' ); ?></span></th>
			<th class="order-status woocommerce-orders-table__header woocommerce-orders-table__header-order-status"><span class="nobr"><?php esc_html_e( 'Status', 'newspack-plugin' ); ?></span></th>
			<th class="order-total woocommerce-orders-table__header woocommerce-orders-table__header-order-total"><span class="nobr"><?php echo esc_html_x( 'Amount', 'table heading', 'newspack-plugin' ); ?></span></th>
		</tr>
	</thead>

	<tbody>
	<?php
	foreach ( $subscription_orders as $subscription_order ) :
		$order = wc_get_order( $subscription_order ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		if ( ! $order ) {
			continue;
		}

		$order_date = $order->get_date_created();

		?>
		<tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?>">
		<td class="order-number woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Order', 'newspack-plugin' ); ?>">
			<?php // translators: placeholder is an order number. ?>
			<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View order number %s', 'newspack-plugin' ), $order->get_order_number() ) ); ?>">
				<?php // translators: placeholder is an order number. ?>
				<?php printf( esc_html_x( '#%s', 'hash before order number', 'newspack-plugin' ), esc_html( $order->get_order_number() ) ); ?>
				<?php Newspack_UI_Icons::print_svg( 'chevronRight' ); ?>
			</a>
		</td>
		<td class="order-date woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( 'Date', 'newspack-plugin' ); ?>">
			<time datetime="<?php echo esc_attr( $order_date->date( 'Y-m-d' ) ); ?>" title="<?php echo esc_attr( $order_date->getTimestamp() ); ?>"><?php echo wp_kses_post( $order_date->date_i18n( wc_date_format() ) ); ?></time>
		</td>
		<td class="order-status woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'newspack-plugin' ); ?>">
			<?php My_Account_UI_V1::render_order_status( $order, My_Account_UI_V1::get_order_status_label( $order ), My_Account_UI_V1::order_can_be_paid( $order ) ); ?>
		</td>
		<td class="order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'newspack-plugin' ); ?>">
			<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
		</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php
/**
 * Allows additional content to be added following the related orders table.
 *
 * @since 2.0.0 Hook added.
 * @since 7.5.0 Additional params $subscription_orders, $page and $max_num_pages added.
 *
 * @param WC_Subscription $subscription
 * @param int[]           $subscription_orders
 * @param int             $page
 * @param int             $max_num_pages
 */
do_action( 'woocommerce_subscription_details_after_subscription_related_orders_table', $subscription, $subscription_orders, $page, $max_num_pages );
?>
