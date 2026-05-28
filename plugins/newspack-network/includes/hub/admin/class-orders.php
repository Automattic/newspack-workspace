<?php
/**
 * Newspack Hub Orders Admin page
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Admin;

use Newspack_Network\Hub\Admin;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Stores\Woo_Item;

/**
 * Class to handle the Orders admin page by customizing the Custom Post type screen
 */
class Orders extends Woo {

	/**
	 * Modify columns on post type table
	 *
	 * @param array $columns Registered columns.
	 * @return array
	 */
	public static function posts_columns( $columns ) {
		unset( $columns['cb'] );
		unset( $columns['title'] );
		unset( $columns['date'] );
		$columns['order']                     = __( 'Order', 'newspack-network' );
		$columns['date']                      = __( 'Date', 'newspack-network' );
		$columns['status']                    = __( 'Status', 'newspack-network' );
		$columns['subscription_relationship'] = __( 'Subscription Relationship', 'newspack-network' );
		$columns['total']                     = __( 'Total', 'newspack-network' );
		return $columns;
	}

	/**
	 * Add content to the custom column
	 *
	 * @param string $column The current column.
	 * @param int    $post_id The current post ID.
	 * @return void
	 */
	public static function posts_columns_values( $column, $post_id ) {
		
		$store_class_name = str_replace( 'Admin', 'Stores', get_called_class() );
		$item             = $store_class_name::get_item( $post_id );

		switch ( $column ) {
			case 'order':
				$output = sprintf(
					'<a href="%s" target="_blank">%s</a> for %s on <a href="%s" target="_blank">%s</a>',
					$item->get_edit_link(),
					$item->get_title(),
					$item->get_user_name(),
					$item->get_node_url(),
					$item->get_node_url()
				);
				echo $output; // phpcs:ignore
				break;
			case 'status':
				?>
				<mark class="subscription-status order-status status-<?php echo esc_attr( $item->get_status() ); ?>">
					<span><?php echo esc_html( $item->get_status_label() ); ?></span>
				<?php
				break;
			case 'date':
				$value = $item->get_date_created();
				if ( empty( $value ) ) {
					echo '-';
					break;
				}
				echo esc_html(
					gmdate( get_option( 'date_format' ), strtotime( $value ) )
				);
				break;
			case 'subscription_relationship':
				if ( 'parent' === $item->get_subscription_relationship() ) {
					$class = 'subscription_parent_order tips';
					$label = __( 'Parent Order', 'newspack-network' );
				} elseif ( 'renewal' === $item->get_subscription_relationship() ) {
					$class = 'subscription_renewal_order tips';
					$label = __( 'Renewal Order', 'newspack-network' );
				} elseif ( 'renewal' === $item->get_subscription_relationship() ) {
					$class = 'subscription_renewal_order tips';
					$label = __( 'Renewal Order', 'newspack-network' );
				} elseif ( 'resubscribe' === $item->get_subscription_relationship() ) {
					$class = 'subscription_resubscribe_order tips';
					$label = __( 'Resubscribe Order', 'newspack-network' );
				} else {
					$class = 'normal_order';
					$label = __( 'Normal Order', 'newspack-network' );
				}
				echo '<span class="' . esc_attr( $class ) . '"></span>';
				echo esc_html( $label );
				break;
			case 'total':
				echo esc_html( $item->get_formatted_total() );
				break;
		}
	}
}
