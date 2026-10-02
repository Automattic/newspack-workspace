<?php
/**
 * Data Backfiller for newspack_node_one_time_purchase_changed events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Woocommerce\Events as Woo_Listeners;
use WP_CLI;

/**
 * Sends every paid order holding a one-time product with a Network ID, for
 * orders placed before purchases were reported or before the product was tagged.
 *
 * Orders are read as IDs and loaded one at a time; on a large site, pass --start
 * and --end to backfill in date ranges.
 */
class One_Time_Purchase_Changed extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\One_Time_Purchase_Changed $event The event.
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
		if ( ! function_exists( 'wc_get_orders' ) ) {
			WP_CLI::warning( 'WooCommerce is unavailable; nothing to send.' );
			return;
		}
		$params = [
			'limit'  => -1,
			'type'   => 'shop_order',
			'status' => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : [ 'processing', 'completed' ],
			'return' => 'ids',
		];
		if ( $this->start && $this->end ) {
			$params['date_created'] = $this->start . '...' . $this->end;
		} elseif ( $this->start ) {
			$params['date_created'] = '>=' . $this->start;
		} elseif ( $this->end ) {
			$params['date_created'] = '<=' . $this->end;
		}
		$order_ids = wc_get_orders( $params );

		$this->maybe_initialize_progress_bar( 'Processing orders', count( $order_ids ) );

		foreach ( $this->load_in_batches( $order_ids, 'wc_get_order' ) as $order ) {
			$data = Woo_Listeners::one_time_purchase_changed( $order->get_id(), '', $order->get_status(), $order );
			if ( empty( $data ) ) {
				continue;
			}
			// An order with no date reports 0, which the hub rejects as an event time, so the event itself is stamped now.
			$timestamp = $data['purchased_at'] ? (int) $data['purchased_at'] : time();
			yield new \Newspack_Network\Incoming_Events\One_Time_Purchase_Changed( get_bloginfo( 'url' ), $data, $timestamp );
		}
	}
}
