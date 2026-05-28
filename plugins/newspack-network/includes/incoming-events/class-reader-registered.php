<?php
/**
 * Newspack Hub Reader Registered Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Stores\Event_Log;
use Newspack_Network\User_Update_Watcher;
use Newspack_Network\Utils\Users as User_Utils;

/**
 * Class to handle the Registered Incoming Event
 */
class Reader_Registered extends Abstract_Incoming_Event {

	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->maybe_create_user();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->maybe_create_user();
	}

	/**
	 * Maybe creates a new WP user based on this event
	 *
	 * @return void
	 */
	public function maybe_create_user() {
		$email = $this->get_email();
		Debugger::log( 'Processing reader_registered with email: ' . $email );
		if ( ! $email ) {
			return;
		}

		User_Update_Watcher::$enabled = false;

		// If a user exists, but has a non-synchronizable role, add a synchronizable role.
		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user ) {
			$synced_roles = \Newspack_Network\Utils\Users::get_synced_user_roles();
			if ( ! array_intersect( $existing_user->roles, $synced_roles ) ) {
				$existing_user->add_role( $synced_roles[0] );
			}
		} else {
			$user = User_Utils::get_or_create_user_by_email( $email, $this->get_site(), $this->data->user_id ?? '', (array) $this->data );
		}
	}
}
