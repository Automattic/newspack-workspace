<?php
/**
 * Manually sync individual users to network sites.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack\Data_Events;
use Newspack_Network\Debugger;

/**
 * Class to watch the user for updates and trigger events
 */
class User_Manual_Sync {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'edit_user_profile', [ __CLASS__, 'add_manual_sync_button' ] );
		\add_action( 'show_user_profile', [ __CLASS__, 'add_manual_sync_button' ] );
		\add_action( 'admin_init', [ __CLASS__, 'process_admin_action' ] );
		\add_action( 'init', [ __CLASS__, 'register_listener' ] );
	}

	/**
	 * Register the listeners to the Newspack Data Events API
	 *
	 * @return void
	 */
	public static function register_listener() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}

		Data_Events::register_listener( 'newspack_network_manual_sync_user', 'network_manual_sync_user', [ __CLASS__, 'manual_sync_user' ] );
	}

	/**
	 * Filters the user data for the event being triggered
	 *
	 * @param array $user_data The user data.
	 * @return array
	 */
	public static function manual_sync_user( $user_data ) {
		$synced_metadata = [];
		$synced_props    = [];

		// Create an array of all of the synced user meta values.
		foreach ( User_Update_Watcher::$watched_meta as $key ) {
			$synced_metadata[ $key ] = $user_data->$key;
		}

		foreach ( User_Update_Watcher::$user_props as $key ) {
			$synced_props[ $key ] = $user_data->$key;
		}

		return [
			'email'      => $user_data->user_email,
			'role'       => $user_data->roles,
			'user_id'    => $user_data->ID,
			'meta'       => $synced_metadata,
			'prop'       => $synced_props,
			'user_login' => $user_data->user_login,
		];
	}

	/**
	 * Create URL to manually sync user.
	 *
	 * @param WP_User $user The current WP_User object.
	 * @return string $url The wp-admin URL.
	 */
	public static function get_manual_sync_link( $user ) {
		$url = add_query_arg(
			array(
				'user_id'                       => \esc_attr( $user->ID ),
				'np_network_manual_sync_action' => 'np_network_manual_user_sync',
				'_wpnonce'                      => \wp_create_nonce( 'np_network_manual_user_sync' ),
			),
			'/wp-admin/user-edit.php'
		);

		return $url;
	}

	/**
	 * Add button to manually sync users to the Edit User Profile screen.
	 *
	 * @param WP_User $user The current WP_User object.
	 */
	public static function add_manual_sync_button( $user ) {
		if ( \current_user_can( 'edit_user', $user->ID ) ) :
			?>
		<div class="newspack-network-sync-user">
			<h2><?php \esc_html_e( 'Newspack Network Tools', 'newspack-network' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th>
						<label><?php esc_html_e( 'Manually sync user', 'newspack-network' ); ?></label>
					</th>
					<td>
						<a class="button" id="do-newspack-network-manual-sync" href="<?php echo esc_url( self::get_manual_sync_link( $user ) ); ?>">
							<?php esc_html_e( 'Sync user across network', 'newspack-network' ); ?>
						</a>
						<p class="description">
							<?php esc_html_e( 'Manually sync this user\'s information across all sites in your network, including their role. If this is a new user, clicking this button will also propagate the user account across your network.', 'newspack-network' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
			<?php
	endif;
	}

	/**
	 * Process the admin action request
	 */
	public static function process_admin_action() {
		/** Allowed actions */
		$sync_action = 'np_network_manual_user_sync';

		/** Add notice if admin action was successful. */
		if ( isset( $_GET['np_network_manual_sync_update'] ) && $_GET['np_network_manual_sync_update'] === $sync_action ) {
			$update  = \sanitize_text_field( \wp_unslash( $_GET['np_network_manual_sync_update'] ) );
			$message = __( 'This user is scheduled to be synced.', 'newspack-network' );
			if ( ! empty( $message ) ) {
				\add_action(
					'admin_notices',
					function() use ( $message ) {
						?>
						<div id="message" class="updated notice is-dismissible"><p><?php echo \esc_html( $message ); ?></p></div>
						<?php
					}
				);
			}
		}

		if ( ! isset( $_GET['np_network_manual_sync_action'] ) || $_GET['np_network_manual_sync_action'] !== $sync_action ) {
			return;
		}

		$action = \sanitize_text_field( \wp_unslash( $_GET['np_network_manual_sync_action'] ) );

		if ( ! isset( $_GET['user_id'] ) ) {
			\wp_die( \esc_html__( 'Invalid request.', 'newspack' ) );
		}

		if ( ! \check_admin_referer( $action ) ) {
			\wp_die( \esc_html__( 'Invalid request.', 'newspack' ) );
		}

		$user_id = \absint( \wp_unslash( $_GET['user_id'] ) );

		if ( ! \current_user_can( 'edit_user', $user_id ) ) {
			\wp_die( \esc_html__( 'You do not have permission to do that.', 'newspack' ) );
		}

		$user = \get_user_by( 'id', $user_id );

		if ( ! $user || \is_wp_error( $user ) ) {
			\wp_die( \esc_html__( 'User not found.', 'newspack' ) );
		}

		do_action( 'newspack_network_manual_sync_user', $user );

		$redirect = \add_query_arg( [ 'np_network_manual_sync_update' => $action ], \remove_query_arg( [ 'np_network_manual_sync_action', '_wpnonce' ] ) );
		\wp_safe_redirect( $redirect );
		exit;
	}
}
