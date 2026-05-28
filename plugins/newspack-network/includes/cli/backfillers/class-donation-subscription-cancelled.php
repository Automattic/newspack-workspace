<?php
/**
 * Data Backfiller for donation_subcription_cancelled events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

/**
 * Backfiller class.
 */
class Donation_Subscription_Cancelled extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Subscription #%d cancelled on %s.', $event->get_data()->subscription_id, $event->get_formatted_date() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		$subscriptions = wcs_get_subscriptions(
			[
				'subscription_status'    => 'cancelled',
				'subscriptions_per_page' => -1,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'     => wcs_get_date_meta_key( 'cancelled' ),
						'compare' => '>=',
						'value'   => $this->start,
					],
					[
						'key'     => wcs_get_date_meta_key( 'cancelled' ),
						'compare' => '<=',
						'value'   => $this->end,
					],
				],
			]
		);

		$this->maybe_initialize_progress_bar( 'Processing subscriptions', count( $subscriptions ) );

		$events = [];

		foreach ( $subscriptions as $subscription ) {
			$subscription_data = \Newspack\Data_Events\Utils::get_recurring_donation_data( $subscription );
			if ( ! $subscription_data ) {
				continue;
			}
			$timestamp = strtotime( $subscription->get_date( 'cancelled' ) );

			$event = new \Newspack_Network\Incoming_Events\Donation_Subscription_Cancelled( get_bloginfo( 'url' ), $subscription_data, $timestamp );

			$events[] = $event;
		}

		return $events;
	}
}
