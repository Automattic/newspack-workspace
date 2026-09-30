<?php
/**
 * Data Backfiller for newspack_node_group_members_changed events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Woocommerce_Subscriptions\Group_Members;

/**
 * Sends every group subscription's current members to the hub, for groups whose
 * members were added before their changes were reported.
 *
 * The start and end dates don't apply: a member list is current state, not history.
 */
class Group_Members_Changed extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Group_Members_Changed $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Group subscription #%d with %d members.', $event->get_id(), count( $event->get_group_members() ) );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		if ( ! class_exists( 'Newspack\Group_Subscription_Settings' ) ) {
			return [];
		}

		$subscription_ids = \Newspack\Group_Subscription_Settings::get_group_subscription_ids();

		$this->maybe_initialize_progress_bar( 'Processing group subscriptions', count( $subscription_ids ) );

		$events = [];
		foreach ( $subscription_ids as $subscription_id ) {
			$data = Group_Members::get_event_data( $subscription_id );
			if ( empty( $data ) ) {
				continue;
			}
			$events[] = new \Newspack_Network\Incoming_Events\Group_Members_Changed( get_bloginfo( 'url' ), $data, time() );
		}

		return $events;
	}
}
