<?php
/**
 * Data Backfiller for newspack_node_group_seat_changed events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Data_Backfill;
use Newspack_Network\Woocommerce_Subscriptions\Group_Seats;
use WP_CLI;

/**
 * Sends every seat recorded on this site's readers, for seats taken before seat
 * changes were reported. A seat on a group that has since been turned off or
 * deleted is sent as cancelled, so a revocation whose event never arrived is
 * repaired too; a member removed from a group leaves no seat to send.
 *
 * Each seat's event is stamped with when the member joined, else with the
 * subscription's creation date, else (the subscription gone too) with the
 * member's registration date, so the stamp is stable across runs. A seat whose
 * payload matches one a backfill already sent, stamp included, is a duplicate
 * and isn't sent again, so a status that returns to an earlier one is carried
 * by the live event, not by a re-run. The start and end dates select seats by
 * that stamp. A date with no time given as the end counts through the end of
 * that day.
 */
class Group_Seat_Changed extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Group_Seat_Changed $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Seat for %s on subscription #%d: %s.', $event->get_email(), $event->get_id(), $event->get_status_after() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * Seats are listed first (a member ID, a subscription ID and a time each), then
	 * each seat's event is built one at a time, so a large group's members and
	 * their subscription don't all stay in memory for the whole run.
	 *
	 * @return \Generator<\Newspack_Network\Incoming_Events\Abstract_Incoming_Event> $events A generator of events.
	 */
	public function get_events() {
		if ( ! function_exists( 'wcs_get_subscription' ) || ! class_exists( 'Newspack\Group_Subscription' ) || ! class_exists( 'Newspack\Group_Subscription_Settings' ) ) {
			WP_CLI::warning( 'Group subscriptions are unavailable (WooCommerce Subscriptions and newspack-plugin are both needed); nothing to send.' );
			return;
		}
		$start = $this->start ? strtotime( $this->start ) : false;
		$end   = $this->end ? strtotime( $this->end ) : false;
		if ( $end && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $this->end ) ) {
			// A date alone means the end of that day, so chunked runs don't skip a boundary day.
			$end += DAY_IN_SECONDS - 1;
		}

		$seats = [];
		foreach ( $this->get_subscription_ids() as $subscription_id ) {
			$member_ids = Group_Seats::get_member_ids( $subscription_id );
			// One query for every member's meta, rather than one per member when the join time is read.
			update_meta_cache( 'user', $member_ids );
			foreach ( $member_ids as $member_id ) {
				$timestamp = $this->get_seat_timestamp( (int) $member_id, (int) $subscription_id );
				if ( null === $timestamp ) {
					Data_Backfill::increment_results_counter( Group_Seats::ACTION, 'skipped' );
					if ( $this->verbose ) {
						WP_CLI::line( sprintf( 'Skipping a seat on subscription #%d: no usable date.', $subscription_id ) );
					}
					continue;
				}
				if ( ( $start && $timestamp < $start ) || ( $end && $timestamp > $end ) ) {
					continue;
				}
				$seats[] = [ (int) $member_id, (int) $subscription_id, $timestamp ];
			}
		}

		$this->maybe_initialize_progress_bar( 'Processing group seats', count( $seats ) );

		$build = function ( $index ) use ( $seats ) {
			list( $member_id, $subscription_id, $timestamp ) = $seats[ $index ];
			$data = Group_Seats::get_event_data( $member_id, $subscription_id );
			if ( empty( $data ) ) {
				return false;
			}
			return new \Newspack_Network\Incoming_Events\Group_Seat_Changed( get_bloginfo( 'url' ), $data, $timestamp );
		};
		foreach ( $this->load_in_batches( array_keys( $seats ), $build ) as $event ) {
			yield $event;
		}
	}

	/**
	 * Every subscription a reader still holds a seat on, from the member meta itself
	 * rather than newspack-plugin's list of groups: a group turned off or deleted
	 * drops out of that list while its members' seats remain, and those are the
	 * seats a missed revocation left behind. A group with no members has no seats
	 * to send, so the meta is the whole set.
	 *
	 * @return int[]
	 */
	private function get_subscription_ids() {
		global $wpdb;
		$held = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", Group_Seats::MEMBER_META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_values( array_unique( array_map( 'intval', $held ) ) );
	}

	/**
	 * When a seat began: the member's join time, else the subscription's creation
	 * date, else (the subscription gone) the member's registration date.
	 *
	 * @param int $member_id       Member user ID.
	 * @param int $subscription_id Subscription ID.
	 * @return int|null Unix timestamp, or null when none is recorded.
	 */
	private function get_seat_timestamp( $member_id, $subscription_id ) {
		// Read the join time by its key rather than through get_member_joined_at(), which
		// loads the subscription first and so knows nothing once the subscription is gone.
		$joined_at = (int) get_user_meta( $member_id, \Newspack\Group_Subscription::get_member_joined_meta_key( $subscription_id ), true );
		if ( $joined_at ) {
			return $joined_at;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		if ( $subscription ) {
			$created = $subscription->get_date_created();
			return $created ? $created->getTimestamp() : null;
		}
		// A zero registration date (an imported user table) parses to a time before 1901; the event log's
		// int column would silently clamp it, so the seat would be mis-dated on every node. Skip it instead.
		$member     = get_userdata( $member_id );
		$registered = $member ? (int) strtotime( (string) $member->user_registered ) : 0;
		return $registered > 0 ? $registered : null;
	}
}
