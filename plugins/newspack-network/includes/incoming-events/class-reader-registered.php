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

		// If a user exists with no role of its own, add a synchronizable role.
		// An account that already holds any role is left exactly as it is: this
		// event is the only signal that the two emails match, and that alone
		// isn't enough to change what the account can already do here. This is
		// narrower than Users::is_syncable_account() on purpose — a roleless
		// account is the only case that needs a role *added*; one that already
		// holds a synced role needs no action, so reusing that check here would
		// just re-run add_role() on every such account on every event.
		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user ) {
			$synced_roles = \Newspack_Network\Utils\Users::get_synced_user_roles();
			if ( empty( $existing_user->roles ) && ! empty( $synced_roles ) ) {
				$existing_user->add_role( $synced_roles[0] );
			}
		} else {
			$user = User_Utils::get_or_create_user_by_email( $email, $this->get_site(), $this->data->user_id ?? '', (array) $this->data );
		}
	}
}
