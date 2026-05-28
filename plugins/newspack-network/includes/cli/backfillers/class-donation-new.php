<?php
/**
 * Data Backfiller for donation_new events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

/**
 * Backfiller class.
 */
class Donation_New extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Order #%d completed on %s.', $event->get_data()->platform_data['order_id'], $event->get_formatted_date() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		$orders = wc_get_orders(
			[
				'status'       => 'completed',
				'date_created' => $this->start . '...' . $this->end,
				'limit'        => -1,
			]
		);

		$this->maybe_initialize_progress_bar( 'Processing orders', count( $orders ) );

		$events = [];

		foreach ( $orders as $order ) {
			$order_id = $order->get_id();
			$order_data = \Newspack\Data_Events\Utils::get_order_data( $order_id, true );
			if ( ! $order_data ) {
				continue;
			}
			$timestamp = strtotime( $order->get_date_completed() );

			$event = new \Newspack_Network\Incoming_Events\Donation_New( get_bloginfo( 'url' ), $order_data, $timestamp );

			$events[] = $event;
		}

		return $events;
	}
}
