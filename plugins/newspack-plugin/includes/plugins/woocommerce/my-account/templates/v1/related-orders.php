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
			<th class="order-actions woocommerce-orders-table__header woocommerce-orders-table__header-order-actions">&nbsp;</th>
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
		<td class="order-number woocommerce-orders-table__cell woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'Order Number', 'newspack-plugin' ); ?>">
			<?php // translators: placeholder is an order number. ?>
			<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View order number %s', 'newspack-plugin' ), $order->get_order_number() ) ); ?>">
				<?php // translators: placeholder is an order number. ?>
				<?php printf( esc_html_x( '#%s', 'hash before order number', 'newspack-plugin' ), esc_html( $order->get_order_number() ) ); ?>
			</a>
		</td>
		<td class="order-date woocommerce-orders-table__cell woocommerce-orders-table__cell-order-date" data-title="<?php esc_attr_e( 'Date', 'newspack-plugin' ); ?>">
			<time datetime="<?php echo esc_attr( $order_date->date( 'Y-m-d' ) ); ?>" title="<?php echo esc_attr( $order_date->getTimestamp() ); ?>"><?php echo wp_kses_post( $order_date->date_i18n( wc_date_format() ) ); ?></time>
		</td>
		<td class="order-status woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'newspack-plugin' ); ?>" style="white-space:nowrap;">
			<?php
			$order_status = $order->get_status();
			?>
			<span class="newspack-my-account__subscription--order-status-label <?php echo esc_attr( $order_status ); ?>"></span>
			<?php
			if ( $order_status === 'completed' ) {
				$order_status = __( 'Paid', 'newspack-plugin' );
			}
			echo esc_html( wc_get_order_status_name( $order_status ) );
			?>
		</td>
		<td class="order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'newspack-plugin' ); ?>">
			<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
		</td>
		<td class="order-actions woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions">
			<?php
			$actions = array();

			if ( $order->needs_payment() && $order->get_id() === $subscription->get_last_order( 'ids', 'any' ) ) {
				$actions['pay'] = array(
					'url'  => $order->get_checkout_payment_url(),
					'name' => esc_html_x( 'Pay', 'pay for a subscription', 'newspack-plugin' ),
				);
			}

			if ( in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order ) ) ) {
				$redirect = wc_get_page_permalink( 'myaccount' );

				if ( wcs_is_view_subscription_page() ) {
					$redirect = $subscription->get_view_order_url();
				}

				$actions['cancel'] = array(
					'url'  => $order->get_cancel_order_url( $redirect ),
					'name' => esc_html_x( 'Cancel', 'an action on a subscription', 'newspack-plugin' ),
				);
			}

			$actions['view'] = array(
				'url'  => $order->get_view_order_url(),
				'name' => esc_html_x( 'View', 'view a subscription', 'newspack-plugin' ),
			);

			$actions = apply_filters( 'woocommerce_my_account_my_orders_actions', $actions, $order );

			// Order actions dropdown menu.
			if ( $actions ) :
				?>
			<div class="newspack-ui__dropdown">
				<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small newspack-ui__dropdown__toggle newspack-ui__button--icon">
					<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
					<span class="screen-reader-text"><?php \esc_html_e( 'More', 'newspack-plugin' ); ?></span>
				</button>
				<div class="newspack-ui__dropdown__content">
					<ul>
						<?php foreach ( $actions as $key => $action_link ) : ?>
							<?php
							$classes = [
								'newspack-ui__button',
								'newspack-ui__button--ghost',
								\sanitize_html_class( $key ),
							];
							if ( 'cancel' === $key ) {
								$classes[] = 'newspack-ui__button--destructive';
							}
							?>
							<li><a class="<?php echo \esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo \esc_url( $action_link['url'] ); ?>"><?php echo \esc_html( $action_link['name'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
			<?php endif; ?>
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
