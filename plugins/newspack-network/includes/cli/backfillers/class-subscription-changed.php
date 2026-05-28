<?php
/**
 * Data Backfiller for subscription_changed events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Woocommerce\Events as Woo_Listeners;

/**
 * Backfiller class.
 */
class Subscription_Changed extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Subscription #%d with status %s.', $event->get_id(), $event->get_status_after() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		$params = [
			'subscription_status'    => 'any',
			'subscriptions_per_page' => -1,
		];

		if ( $this->start || $this->end ) {
			$params['meta_query'] = [ 'relation' => 'AND' ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

			if ( $this->start ) {
				$params['meta_query'][] = [
					'key'     => wcs_get_date_meta_key( 'start' ),
					'compare' => '>=',
					'value'   => $this->start,
				];
			}

			if ( $this->end ) {
				$params['meta_query'][] = [
					'key'     => wcs_get_date_meta_key( 'start' ),
					'compare' => '<=',
					'value'   => $this->end,
				];
			}
		}

		$subscriptions = wcs_get_subscriptions( $params );

		$this->maybe_initialize_progress_bar( 'Processing subscriptions', count( $subscriptions ) );

		$events = [];

		foreach ( $subscriptions as $subscription ) {

			$subscription_data = Woo_Listeners::subscription_changed( $subscription->get_id(), '', $subscription->get_status(), $subscription );

			$timestamp = strtotime( $subscription->get_date_created() );

			$event = new \Newspack_Network\Incoming_Events\Subscription_Changed( get_bloginfo( 'url' ), $subscription_data, $timestamp );

			$events[] = $event;
		}

		return $events;
	}
}
