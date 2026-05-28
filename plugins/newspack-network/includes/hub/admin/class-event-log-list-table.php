<?php
/**
 * Newspack Hub Event Log List Table
 *
 * @package Newspack
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended

namespace Newspack_Network\Hub\Admin;

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Hub\Admin;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Stores\Event_Log as Event_Log_Store;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Event Log List Table
 */
class Event_Log_List_Table extends \WP_List_Table {


	/**
	 * Get the table columns
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = [
			'id'          => __( 'ID', 'newspack-network' ),
			'date'        => __( 'Date', 'newspack-network' ),
			'summary'     => __( 'Summary', 'newspack-network' ),
			'node'        => __( 'Node', 'newspack-network' ),
			'action_name' => __( 'Action name', 'newspack-network' ),
			'data'        => __( 'Data', 'newspack-network' ),
		];
		return $columns;
	}

	/**
	 * Prepare items to be displayed
	 */
	public function prepare_items() {

		$args = [];

		if ( isset( $_GET['s'] ) ) {
			$args['search'] = sanitize_text_field( $_GET['s'] );
		}

		if ( isset( $_GET['action_name'] ) ) {
			$args['action_name'] = sanitize_text_field( $_GET['action_name'] );
		}

		if ( isset( $_GET['node_id'] ) ) {
			$args['node_id'] = sanitize_text_field( $_GET['node_id'] );
		}

		if ( isset( $_GET['email'] ) ) {
			$args['email'] = sanitize_text_field( $_GET['email'] );
		}

		$columns               = $this->get_columns();
		$primary               = 'id';
		$this->_column_headers = array( $columns, [], [], $primary );

		$per_page     = $this->get_items_per_page( 'elements_per_page', 10 );
		$current_page = $this->get_pagenum();
		$total_items  = Event_Log_Store::get_total_items( $args );

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			]
		);

		$this->items = Event_Log_Store::get( $args, $per_page, $current_page );
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @param string $which Which table nave, top or bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current_action = isset( $_GET['action_name'] ) ? sanitize_text_field( $_GET['action_name'] ) : '';
		$current_node   = isset( $_GET['node_id'] ) ? sanitize_text_field( $_GET['node_id'] ) : '';
		$current_email  = isset( $_GET['email'] ) ? sanitize_text_field( $_GET['email'] ) : '';
		$all_actions    = array_keys( Accepted_Actions::ACTIONS );
		$all_emails     = Event_Log_Store::get_all_emails();
		?>

		<select name="action_name" id="action_name">
			<option value=""><?php _e( 'All Actions', 'newspack-network' ); ?></option>
			<?php foreach ( $all_actions as $action ) : ?>
				<option value="<?php echo esc_attr( $action ); ?>" <?php selected( $current_action, $action ); ?>><?php echo esc_html( $action ); ?></option>
			<?php endforeach; ?>
		</select>

		<?php
		/**
		 * Shows the users filter dropdown in the Network event log admin page.
		 * May impact performance with large numbers of users.
		 *
		 * @constant NEWSPACK_NETWORK_EVENT_LOG_SHOW_USERS_FILTER
		 * @type     bool
		 * @default  Users filter hidden
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NETWORK_EVENT_LOG_SHOW_USERS_FILTER', true );
		 */
		if ( defined( 'NEWSPACK_NETWORK_EVENT_LOG_SHOW_USERS_FILTER' ) && NEWSPACK_NETWORK_EVENT_LOG_SHOW_USERS_FILTER ) :
			?>
		<select name="email" id="email">
			<option value=""><?php _e( 'All users', 'newspack-network' ); ?></option>
			<?php foreach ( $all_emails as $email ) : ?>
				<option value="<?php echo esc_attr( $email ); ?>" <?php selected( $current_email, $email ); ?>><?php echo esc_html( $email ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php endif; ?>

		<?php Nodes::nodes_dropdown( $current_node ); ?>

		<input type="submit" name="filter_action" class="button" value="<?php esc_attr_e( 'Filter', 'newspack-network' ); ?>">

		<?php
	}

	/**
	 * Get a data column for rendering
	 *
	 * @param Abstract_Event_Log_Item $item The line item.
	 *
	 * @return string
	 */
	protected function get_data_column( $item ) {
		$str = wp_json_encode( $item->get_data(), JSON_PRETTY_PRINT );
		if ( 300 > strlen( $str ) ) {
			return sprintf(
				'<div class="newspack-network-data-column"><pre><code>%1$s</code></pre></div>',
				$str
			);
		}
		return sprintf(
			'<div class="newspack-network-data-column"><textarea disabled>%1$s</textarea><button type="button" class="button button-secondary">%2$s</button></div>',
			$str,
			esc_html__( 'Copy to clipboard', 'newspack-network' )
		);
	}

	/**
	 * Get the value for each column
	 *
	 * @param Abstract_Event_Log_Item $item The line item.
	 * @param string                  $column_name The column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return $item->get_id();
			case 'date':
				return gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->get_timestamp() );
			case 'summary':
				return $item->get_summary();
			case 'node':
				return $item->get_node_url() ? $item->get_node_url() : '-';
			case 'action_name':
				return $item->get_action_name();
			case 'data':
				return $this->get_data_column( $item );
			default:
				return '';
		}
	}
}
