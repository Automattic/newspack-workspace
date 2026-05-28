<?php
/**
 * Data Backfiller for hub_name_updated events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Site_Role;

/**
 * Backfiller class.
 */
class Hub_Name_Updated extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Hub name updated to %s.', $event->get_new_name() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		if ( ! Site_Role::is_hub() ) {
			return [];
		}

		$this->maybe_initialize_progress_bar( 'Processing hub name', 1 );

		return [ new \Newspack_Network\Incoming_Events\Hub_Name_Updated( get_bloginfo( 'url' ), [ 'name' => get_bloginfo( 'name' ) ], time() ) ];
	}
}
