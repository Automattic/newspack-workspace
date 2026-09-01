<?php
/**
 * Newspack Hub Subscriptions Admin page
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Admin;

use Newspack_Network\Hub\Admin;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Stores\Woo_Item;

/**
 * Class to handle the Subscriptions admin page by customizing the Custom Post type screen
 */
class Subscriptions extends Woo {
	/**
	 * Runs the initialization.
	 */
	public static function init() {
		parent::init();
		add_filter( 'manage_edit-' . \Newspack_Network\Hub\Database\Subscriptions::POST_TYPE_SLUG . '_sortable_columns', [ __CLASS__, 'subscription_sortable_columns' ] );
		add_filter( 'request', [ __CLASS__, 'request_query' ] );
	}

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
		$columns['status']            = __( 'Status', 'newspack-network' );
		$columns['subscription']      = __( 'Subscription', 'newspack-network' );
		$columns['items']             = __( 'Items', 'newspack-network' );
		$columns['total']             = __( 'Total', 'newspack-network' );
		$columns['start_date']        = __( 'Start Date', 'newspack-network' );
		$columns['trial_end_date']    = __( 'Trial End', 'newspack-network' );
		$columns['next_payment_date'] = __( 'Next Payment', 'newspack-network' );
		$columns['last_payment_date'] = __( 'Last Order Date', 'newspack-network' );
		$columns['end_date']          = __( 'End Date', 'newspack-network' );
		$columns['orders']            = __( 'Orders', 'newspack-network' );
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
			case 'status':
				?>
				<mark class="subscription-status order-status status-<?php echo esc_attr( $item->get_status() ); ?>">
					<span><?php echo esc_html( $item->get_status_label() ); ?></span>
				<?php
				break;
			case 'start_date':
			case 'trial_end_date':
			case 'next_payment_date':
			case 'last_payment_date':
			case 'end_date':
				$method_name = 'get_' . $column;
				$value       = $item->$method_name();
				if ( empty( $value ) ) {
					echo '-';
					break;
				}
				echo esc_html(
					gmdate( get_option( 'date_format' ), strtotime( $value ) )
				);
				break;
			case 'subscription':
				self::print_link( $item->get_edit_link(), $item->get_title() );
				printf( ' for %s on ', esc_html( $item->get_user_name() ) );
				self::print_link( $item->get_node_url(), $item->get_node_url() );
				break;
			case 'items':
				foreach ( $item->get_products() as $product ) {
					self::print_link(
						sprintf( '%s/wp-admin/post.php?post=%d&action=edit', $item->get_node_url(), $product['id'] ?? '' ),
						$product['name'] ?? ''
					);
					echo '<br />';
				}
				break;
			case 'orders':
				$link = sprintf(
					'%s/wp-admin/edit.php?post_status=all&post_type=shop_order&_subscription_related_orders=%d',
					$item->get_node_url(),
					$item->get_remote_id()
				);
				self::print_link( $link, $item->get_payment_count() );
				break;
			case 'total':
				printf(
					'%s<br/>Via %s',
					esc_html( $item->get_formatted_total() ),
					esc_html( $item->get_payment_method_title() )
				);
				break;
		}
	}

	/**
	 * Add sortable columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function subscription_sortable_columns( $columns ) {
		$sortable_columns = [
			'start_date'        => 'start_date',
			'trial_end_date'    => 'trial_end_date',
			'next_payment_date' => 'next_payment_date',
			'last_payment_date' => 'last_payment_date',
			'end_date'          => 'end_date',
		];

		return wp_parse_args( $sortable_columns, $columns );
	}

	/**
	 * Sorts the request for subscriptions stored in WP Post tables.
	 *
	 * @param array $vars Query variables.
	 *
	 * @return array
	 */
	public static function request_query( $vars ) {
		global $typenow;

		if ( \Newspack_Network\Hub\Database\Subscriptions::POST_TYPE_SLUG === $typenow ) {
			if ( isset( $vars['orderby'] ) ) {
				switch ( $vars['orderby'] ) {
					case 'start_date':
					case 'trial_end_date':
					case 'next_payment_date':
					case 'last_payment_date':
					case 'end_date':
						$vars = array_merge(
							$vars,
							[
								'meta_key'  => $vars['orderby'],
								'meta_type' => 'DATETIME',
								'orderby'   => 'meta_value',
							]
						);
						break;
				}
			}
		}

		return $vars;
	}
}
