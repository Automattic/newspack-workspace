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
	 * @return \Generator<\Newspack_Network\Incoming_Events\Abstract_Incoming_Event> $events A generator of events.
	 */
	public function get_events() {
		$ids = $this->get_order_ids();

		$this->maybe_initialize_progress_bar( 'Processing orders', count( $ids ) );

		foreach ( $this->load_in_batches( $ids, 'wc_get_order' ) as $order ) {

			$order_data = Woo_Listeners::item_changed( $order->get_id(), '', $order->get_status(), $order );

			$timestamp = strtotime( $order->get_date_created() );

			yield new \Newspack_Network\Incoming_Events\Order_Changed( get_bloginfo( 'url' ), $order_data, $timestamp );
		}
	}

	/**
	 * Gets the IDs of the orders to backfill, created within the start and end dates.
	 *
	 * @return int[]
	 */
	protected function get_order_ids() {
		$params = [
			'limit'  => -1,
			'type'   => 'shop_order',
			'return' => 'ids',
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

		return wc_get_orders( $params );
	}
}
