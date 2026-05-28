<?php
/**
 * Newspack Hub Membership_Plans Table
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Admin;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Membership_Plans Table
 */
class Membership_Plans_Table extends \WP_List_Table {
	/**
	 * Get the table columns
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = [
			'name' => __( 'Name', 'newspack-network' ),
		];
		$columns['site_url'] = __( 'Site URL', 'newspack-network' );
		$columns['network_pass_id'] = __( 'Network ID', 'newspack-network' );
		if ( \Newspack_Network\Admin::use_experimental_auditing_features() ) {
			$columns['active_memberships_count'] = __( 'Active Memberships', 'newspack-network' );
			$columns['network_pass_discrepancies'] = __( 'Membership Discrepancies', 'newspack-network' );

			$active_subscriptions_sum = array_reduce(
				$this->items,
				function( $carry, $item ) {
					return $carry + ( is_numeric( $item['active_subscriptions_count'] ) ? $item['active_subscriptions_count'] : 0 );
				},
				0
			);
			$subs_info = sprintf(
				' <span class="dashicons dashicons-info-outline" title="%s"></span>',
				__( 'Active Subscriptions tied to this membership plan', 'newspack-network' )
			);
			// translators: %d is the sum of active subscriptions.
			$columns['active_subscriptions_count'] = sprintf( __( 'Active Subscriptions (%d)', 'newspack-network' ), $active_subscriptions_sum ) . $subs_info;
		}
		return $columns;
	}

	/**
	 * Prepare items to be displayed
	 */
	public function prepare_items() {
		$membership_plans_from_network_data = Membership_Plans::get_membership_plans_from_network();

		// Handle table sorting.
		$order = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( $order && $orderby ) {
			usort(
				$membership_plans_from_network_data['plans'],
				function( $a, $b ) use ( $orderby, $order ) {
					if ( $order === 'asc' ) {
						return $a[ $orderby ] <=> $b[ $orderby ];
					}
					return $b[ $orderby ] <=> $a[ $orderby ];
				}
			);
		}

		$this->items = $membership_plans_from_network_data['plans'];
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'id' ];
	}

	/**
	 * Get the value for each column
	 *
	 * @param Abstract_Event_Log_Item $item The line item.
	 * @param string                  $column_name The column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		$memberships_list_url = sprintf( '%s/wp-admin/edit.php?s&post_status=wcm-active&post_type=wc_user_membership&post_parent=%d', $item['site_url'], $item['id'] );

		if ( $column_name === 'name' ) {
			$edit_url = sprintf( '%s/wp-admin/post.php?post=%d&action=edit', $item['site_url'], $item['id'] );
			return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), $item[ $column_name ] . ' (#' . $item['id'] . ')' );
		}
		if ( $column_name === 'network_pass_id' && $item[ $column_name ] ) {
			return sprintf( '<code>%s</code>', $item[ $column_name ] );
		}
		if ( $column_name === 'network_pass_discrepancies' && isset( $item['network_pass_discrepancies'] ) && $item['network_pass_id'] ) {
			$discrepancies = $item['network_pass_discrepancies'];
			$count = count( $discrepancies );
			if ( $count === 0 ) {
				return esc_html__( 'None', 'newspack-network' );
			}

			$memberships_list_url_with_emails_url = add_query_arg(
				\Newspack_Network\Woocommerce_Memberships\Admin::MEMBERSHIPS_TABLE_EMAILS_QUERY_PARAM,
				implode(
					',',
					array_map(
						function( $email_address ) {
							return urlencode( $email_address );
						},
						$discrepancies
					)
				),
				$memberships_list_url
			);
			$message = sprintf(
				/* translators: %d is the number of members */
				_n(
					'%d member doesn\'t match the shared member pool',
					'%d members don\'t match the shared member pool',
					$count,
					'newspack-plugin'
				),
				$count
			);
			return sprintf( '<a href="%s">%s</a>', esc_url( $memberships_list_url_with_emails_url ), esc_html( $message ) );
		}
		if ( $column_name === 'active_memberships_count' && isset( $item[ $column_name ] ) ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( $memberships_list_url ), $item[ $column_name ] );
		}
		return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
	}

	/**
	 * Get sortable columns.
	 */
	public function get_sortable_columns() {
		return [
			'network_pass_id' => [ 'network_pass_id', false, __( 'Network Pass ID' ), __( 'Table ordered by Network Pass ID.' ) ],
		];
	}
}
