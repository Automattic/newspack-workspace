<?php
/**
 * Newspack Hub Group Members Changed Incoming Event class
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Hub\Stores\Subscriptions;

/**
 * A group subscription's members changed on the site that owns it.
 *
 * Carries the subscription as its status events do, so the hub can keep its copy
 * even if this arrives first, plus the members' emails.
 */
class Group_Members_Changed extends Subscription_Changed {

	/**
	 * Keep the hub's current members on its copy of the subscription.
	 *
	 * The status this event carries is as of when it was sent, and webhook retries
	 * can deliver it after a later status change, so it only writes a copy that is
	 * missing and never an existing copy's status; status events keep that current.
	 * The seats of a copy from before copies were kept per site aren't answered until
	 * a status event or a rebuild rewrites it (see Hub\Reader_Access_Endpoint).
	 *
	 * @return void
	 */
	public function always_process_in_hub() {
		if ( ! $this->get_email() || ! $this->get_id() ) {
			return;
		}
		$local_id = Subscriptions::find_local_id( $this );
		if ( ! $local_id ) {
			$local_id = Subscriptions::persist( $this );
		}
		if ( $local_id ) {
			Subscriptions::update_group_members( $local_id, $this->is_group_enabled() ? $this->get_group_members() : [] );
		}
	}

	/**
	 * The owner's own record is kept by their subscription's status events.
	 *
	 * @return void
	 */
	public function post_process_in_hub() {}

	/**
	 * Nodes don't pull this event.
	 *
	 * @return void
	 */
	public function process_in_node() {}

	/**
	 * Whether the subscription's group is turned on.
	 *
	 * @return bool
	 */
	public function is_group_enabled() {
		return ! empty( $this->data->group_enabled );
	}

	/**
	 * The members' emails.
	 *
	 * @return string[]
	 */
	public function get_group_members() {
		return array_values( array_filter( (array) ( $this->data->group_members ?? [] ), 'is_string' ) );
	}
}
