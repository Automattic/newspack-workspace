<?php
/**
 * Newspack Users Admin page
 *
 * @package Newspack
 */

namespace Newspack_Network;

use const Newspack_Network\constants\EVENT_LOG_PAGE_SLUG;

/**
 * Class to handle the Users admin page
 */
class Users {
	private const SYNC_BULK_ACTION = 'sync-across-network';
	private const SYNC_BULK_SENDBACK_PARAM = 'np_network_manual_sync_updated_user_count';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_filter( 'manage_users_columns', [ __CLASS__, 'manage_users_columns' ] );
		add_filter( 'manage_users_custom_column', [ __CLASS__, 'manage_users_custom_column' ], 99, 3 ); // priority must be higher than Jetpack's jetpack_show_connection_status (10).
		add_filter( 'users_list_table_query_args', [ __CLASS__, 'users_list_table_query_args' ] );
		add_filter( 'bulk_actions-users', [ __CLASS__, 'add_users_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-users', [ __CLASS__, 'users_bulk_actions_sendback' ], 10, 3 );
		add_action( 'admin_init', [ __CLASS__, 'handle_users_bulk_actions' ] );
	}

	/**
	 * Add a custom column to the Users table
	 *
	 * @param array $columns The current columns.
	 * @return array
	 */
	public static function manage_users_columns( $columns ) {
		$columns['newspack_network_activity'] = __( 'Newspack Network Activity', 'newspack-network' );
		if ( \Newspack_Network\Admin::use_experimental_auditing_features() ) {
			$columns['newspack_network_user'] = __( 'Network Original User', 'newspack-network' );
		}
		return $columns;
	}

	/**
	 * Add content to the custom column
	 *
	 * @param string $value The current column value.
	 * @param string $column_name The current column name.
	 * @param int    $user_id The current user ID.
	 * @return string
	 */
	public static function manage_users_custom_column( $value, $column_name, $user_id ) {
		if ( 'newspack_network_user' === $column_name ) {
			$remote_site = get_user_meta( $user_id, \Newspack_Network\Utils\Users::USER_META_REMOTE_SITE, true );
			$remote_id = (int) get_user_meta( $user_id, \Newspack_Network\Utils\Users::USER_META_REMOTE_ID, true );
			if ( $remote_site ) {
				return sprintf(
					'<a href="%swp-admin/user-edit.php?user_id=%d">%s</a>',
					trailingslashit( esc_url( $remote_site ) ),
					$remote_id,
					sprintf( '%s (#%d)', $remote_site, $remote_id )
				);
			}
		}
		if ( 'newspack_network_activity' === $column_name ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				return $value;
			}
			if ( Site_Role::is_hub() ) {
				$last_activity = \Newspack_Network\Hub\Stores\Event_Log::get( [ 'email' => $user->user_email ], 1 );
				if ( empty( $last_activity ) ) {
					return '-';
				}

				$event_log_url = add_query_arg(
					[
						'page'  => EVENT_LOG_PAGE_SLUG,
						'email' => urlencode( $user->user_email ),
					],
					admin_url( 'admin.php' )
				);
				return sprintf(
					'%s: <code>%s</code><br><a href="%s">%s</a>',
					__( 'Last Activity', 'newspack-network' ),
					$last_activity[0]->get_summary(),
					$event_log_url,
					__( 'View all', 'newspack-network' )
				);
			} elseif ( Site_Role::is_node() ) {
				$event_log_url = add_query_arg(
					[
						'page'  => EVENT_LOG_PAGE_SLUG,
						'email' => urlencode( $user->user_email ),
					],
					untrailingslashit( Node\Settings::get_hub_url() ) . '/wp-admin/admin.php'
				);
				return sprintf(
					'<a href="%s">%s</a>',
					$event_log_url,
					__( 'View activity', 'newspack-network' )
				);
			}
		}
		return $value;
	}

	/**
	 * Handle the filtering of users by multiple roles.
	 * Unfortunatelly, `get_views` and `get_views_links` are not filterable, so "All" will
	 * be displayed as the active filter.
	 *
	 * @param array $args The current query args.
	 */
	public static function users_list_table_query_args( $args ) {
		if ( isset( $_REQUEST['role__in'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['role__in'] = explode( ',', sanitize_text_field( $_REQUEST['role__in'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $args['role'] );
		}
		if ( isset( $_REQUEST['role__not_in'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['role__not_in'] = explode( ',', sanitize_text_field( $_REQUEST['role__not_in'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $args['role'] );
		}
		return $args;
	}

	/**
	 * Add custom bulk actions to the Users table.
	 *
	 * @param array $actions An array of the available bulk actions.
	 */
	public static function add_users_bulk_actions( $actions ) {
		if ( current_user_can( 'edit_users' ) ) {
			$actions[ self::SYNC_BULK_ACTION ] = __( 'Sync across network', 'newspack-network' );
		}

		return $actions;
	}

	/**
	 * Change sendback URL for the bulk action.
	 *
	 * @param string $sendback The redirect URL.
	 * @param string $action The action being taken.
	 * @param array  $user_ids    The items to take the action on.
	 */
	public static function users_bulk_actions_sendback( $sendback, $action, $user_ids ) {
		if ( $action === self::SYNC_BULK_ACTION && ! empty( $user_ids ) ) {
			$sendback = add_query_arg( self::SYNC_BULK_SENDBACK_PARAM, count( $user_ids ), $sendback );
		}
		return $sendback;
	}

	/**
	 * Handle custom bulk actions to the Users table.
	 */
	public static function handle_users_bulk_actions() {
		// Handle the bulk-manual-sync request.
		if ( isset( $_GET['action'], $_REQUEST['users'], $_REQUEST['_wpnonce'] ) && $_GET['action'] === self::SYNC_BULK_ACTION ) {
			if ( ! wp_verify_nonce( sanitize_text_field( $_REQUEST['_wpnonce'] ), 'bulk-users' ) ) {
				return;
			}
			$user_ids = array_map( 'intval', (array) $_REQUEST['users'] );
			foreach ( $user_ids as $user_id ) {
				$user = get_user_by( 'ID', $user_id );
				if ( $user ) {
					do_action( 'newspack_network_manual_sync_user', $user );
				}
			}
		}

		// Handle the admin notice.
		if ( isset( $_GET[ self::SYNC_BULK_SENDBACK_PARAM ] ) && $_GET[ self::SYNC_BULK_SENDBACK_PARAM ] > 0 ) {
			$count = intval( $_GET[ self::SYNC_BULK_SENDBACK_PARAM ] );
			$message = sprintf(
				/* translators: %d is the users count. */
				_n(
					'Scheduled %d user to be synced across the network.',
					'Scheduled %d users to be synced across the network.',
					$count,
					'newspack-network'
				),
				$count
			);
			add_action(
				'admin_notices',
				function () use ( $message ) {
					printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
				}
			);
		}
	}
}
