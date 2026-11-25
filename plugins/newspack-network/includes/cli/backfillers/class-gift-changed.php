<?php
/**
 * Data Backfiller for newspack_node_gift_changed events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Data_Backfill;
use Newspack_Network\Woocommerce_Subscriptions\Gift_Recipients;
use WP_CLI;

/**
 * Sends every gift subscription on this site to its recipient, for gifts given
 * before gift recipients were reported. A reader who has since lost a gift isn't
 * recorded anywhere here, so the backfill can't revoke a gift on their behalf;
 * that is left to the live event sent when they lost it.
 *
 * Each gift's event is stamped with the subscription's creation date, so the stamp
 * is stable across runs and a gift whose payload a backfill already sent isn't sent
 * again. The start and end dates select gifts by their start date, as the
 * subscription backfill does.
 */
class Gift_Changed extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Gift_Changed $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Gift subscription #%d for %s: %s.', $event->get_id(), $event->get_email(), $event->get_status_after() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * Gift IDs are listed first, then each gift is loaded and its event built one at
	 * a time, so every subscription doesn't stay in memory for the whole run.
	 *
	 * @return \Generator<\Newspack_Network\Incoming_Events\Abstract_Incoming_Event> $events A generator of events.
	 */
	public function get_events() {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! class_exists( 'WCS_Gifting' ) ) {
			WP_CLI::warning( 'Gift subscriptions are unavailable (WooCommerce Subscriptions gifting is needed); nothing to send.' );
			return;
		}

		$ids = $this->get_subscription_ids();

		$this->maybe_initialize_progress_bar( 'Processing gift subscriptions', count( $ids ) );

		$build = function ( $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			$recipient_id = Gift_Recipients::get_recipient_id( $subscription );
			$data         = $recipient_id ? Gift_Recipients::get_event_data( $recipient_id, $subscription_id ) : null;
			$created      = $data ? $subscription->get_date_created() : null;
			if ( ! $created ) {
				// Not a gift after all, or its recipient has no account left to name.
				Data_Backfill::increment_results_counter( Gift_Recipients::ACTION, 'skipped' );
				if ( $this->verbose ) {
					WP_CLI::line( sprintf( 'Skipping subscription #%d: no gift to send.', $subscription_id ) );
				}
				return false;
			}
			return new \Newspack_Network\Incoming_Events\Gift_Changed( get_bloginfo( 'url' ), $data, $created->getTimestamp() );
		};
		foreach ( $this->load_in_batches( $ids, $build ) as $event ) {
			yield $event;
		}
	}

	/**
	 * IDs of the subscriptions carrying a gift recipient, of any status, started within
	 * the start and end dates.
	 *
	 * Asks only that the recipient is recorded: a subscription that was never a gift can
	 * carry it empty, and is skipped once loaded.
	 *
	 * @return int[]
	 */
	protected function get_subscription_ids() {
		$params = [
			'type'       => 'shop_subscription',
			'status'     => [ 'any' ],
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				[
					'key'     => '_recipient_user',
					'compare' => 'EXISTS',
				],
			],
		];

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

		// Not wcs_get_subscriptions(), which loads every subscription before returning, and not
		// wc_get_orders(), which ignores meta_query without HPOS.
		return array_map( 'intval', wcs_get_orders_with_meta_query( $params ) );
	}
}
