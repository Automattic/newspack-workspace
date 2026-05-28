<?php
/**
 * Data Backfiller for order_changed events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Woocommerce\Events as Woo_Listeners;

/**
 * Backfiller class.
 */
class Order_Changed extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Order #%d with status %s.', $event->get_id(), $event->get_status_after() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		$params = [
			'limit' => -1,
			'type'  => 'shop_order',
		];

		if ( $this->start || $this->end ) {
			if ( ! $this->end ) {
				$params['date_created'] = '>=' . $this->start;
			} elseif ( ! $this->start ) {
				$params['date_created'] = '<=' . $this->end;
			} else {
				$params['date_created'] = $this->start . '...' . $this->end;
			}
		}

		$orders = wc_get_orders( $params );

		$this->maybe_initialize_progress_bar( 'Processing orders', count( $orders ) );

		$events = [];

		foreach ( $orders as $order ) {

			$order_data = Woo_Listeners::item_changed( $order->get_id(), '', $order->get_status(), $order );

			$timestamp = strtotime( $order->get_date_created() );

			$event = new \Newspack_Network\Incoming_Events\Order_Changed( get_bloginfo( 'url' ), $order_data, $timestamp );

			$events[] = $event;
		}

		return $events;
	}
}
