<?php
/**
 * Newspack Network Data Listeners.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack\Data_Events;

/**
 * Class to register additional listeners to the Newspack Data Events API
 */
class Data_Listeners {

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_listeners' ] );
	}

	/**
	 * Register the listeners to the Newspack Data Events API
	 *
	 * @return void
	 */
	public static function register_listeners() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}

		Data_Events::register_listener( 'newspack_network_user_updated', 'network_user_updated', [ __CLASS__, 'user_updated' ] );
		Data_Events::register_listener( 'delete_user', 'network_user_deleted', [ __CLASS__, 'user_deleted' ] );
		Data_Events::register_listener( 'newspack_network_nodes_synced', 'network_nodes_synced', [ __CLASS__, 'nodes_synced' ] );

		if ( Site_Role::is_hub() ) {
			Data_Events::register_listener( 'update_option_blogname', 'network_hub_name_updated', [ __CLASS__, 'hub_name_updated' ] );
		}
	}

	/**
	 * Filters the user data for the event being triggered
	 *
	 * @param array $user_data The user data.
	 * @return array
	 */
	public static function user_updated( $user_data ) {
		return $user_data;
	}

	/**
	 * Filters the user data for the event being triggered
	 *
	 * @param int      $id       ID of the user to delete.
	 * @param int|null $reassign ID of the user to reassign posts and links to.
	 *                           Default null, for no reassignment.
	 * @param WP_User  $user     WP_User object of the user to delete.
	 * @return array
	 */
	public static function user_deleted( $id, $reassign, $user ) {
		$should_delete = apply_filters( 'newspack_network_process_user_deleted', true, $user->user_email );
		if ( ! $should_delete ) {
			Debugger::log( 'User deletion with email: ' . $user->user_email . ' was skipped due to filter use.' );
			return;
		}
		// Prevent deletion-related changes triggering a 'network_user_updated' event.
		User_Update_Watcher::$enabled = false;
		return [
			'email' => $user->user_email,
		];
	}

	/**
	 * Filters the nodes data for the event being triggered
	 *
	 * @param array $nodes_data The nodes data.
	 * @return array
	 */
	public static function nodes_synced( $nodes_data ) {
		return [ 'nodes_data' => $nodes_data ];
	}

	/**
	 * Filters the blogname data for the event being triggered
	 *
	 * @param string $old_value The old blogname.
	 * @param string $value     The new blogname.
	 * @return array
	 */
	public static function hub_name_updated( $old_value, $value ) {
		return [ 'name' => $value ];
	}
}
