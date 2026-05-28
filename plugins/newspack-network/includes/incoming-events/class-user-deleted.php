<?php
/**
 * Newspack Hub User Deleted Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Utils\Users as User_Utils;

/**
 * Class to handle the user deletion.
 */
class User_Deleted extends Abstract_Incoming_Event {
	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->process_user_deleted();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->process_user_deleted();
	}

	/**
	 * Process user deleted
	 *
	 * @return void
	 */
	public function process_user_deleted() {
		$email = $this->get_email();
		Debugger::log( sprintf( 'Processing user deletion for %s from %s.', $email, $this->get_site() ) );
		if ( ! $email ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			Debugger::log( sprintf( 'User to be deleted not found by email: %s, skipping.', $email ) );
			return;
		}

		// Ensure this is a reader.
		if ( ! \Newspack\Reader_Activation::is_user_reader( $user ) ) {
			Debugger::log( sprintf( 'User %s is not a reader, skipping deletion.', $email ) );
			return;
		}

		/** Make sure `wp_delete_user()` is available. */
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// Don't broadcast this deletion on the network.
		add_filter( 'newspack_network_process_user_deleted', '__return_false' );
		// Prevent deletion-related changes triggering a 'network_user_updated' event.
		\Newspack_Network\User_Update_Watcher::$enabled = false;
		// Delete the user.
		$result = \wp_delete_user( $user->ID );
		remove_filter( 'newspack_network_process_user_deleted', '__return_false' );

		if ( $result ) {
			Debugger::log( sprintf( 'User %s deleted.', $email ) );
		} else {
			Debugger::log( sprintf( 'User %s could not be deleted.', $email ) );
		}
	}
}
