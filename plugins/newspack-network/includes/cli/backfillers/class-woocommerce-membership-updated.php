<?php
/**
 * Data Backfiller for newspack_network_woo_membership_updated events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Data_Backfill;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use WP_Cli;

/**
 * Backfiller class.
 */
class Woocommerce_Membership_Updated extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Membership #%d with status %s on %s, linked with network ID "%s".', $event->get_membership_id(), $event->get_new_status(), $event->get_formatted_date(), $event->get_plan_network_id() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		// Get all memberships created or updated between $start and $end.
		$membership_posts_ids = get_posts(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'date_query'  => [
					'column'    => 'post_modified_gmt',
					'after'     => $this->start,
					'before'    => $this->end,
					'inclusive' => true,
				],
				'meta_query'  => [ // phpcs:ignore
					[
						'key'     => Memberships_Admin::NETWORK_MANAGED_META_KEY,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$this->maybe_initialize_progress_bar( 'Processing memberships', count( $membership_posts_ids ) );

		$events = [];
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Found %s membership(s) eligible for sync.', count( $membership_posts_ids ) ) );
		WP_CLI::line( '' );

		foreach ( $membership_posts_ids as $post_id ) {
			$membership = new \WC_Memberships_User_Membership( $post_id );
			$status = $membership->get_status();
			$plan_network_id = get_post_meta( $membership->get_plan()->get_id(), \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY, true );
			if ( ! $plan_network_id ) {
				if ( $this->verbose ) {
					WP_CLI::line( sprintf( 'Skipping membership #%d with status %s, the plan has no network ID.', $membership->get_id(), $status ) );
				}
				Data_Backfill::increment_results_counter( 'newspack_network_woo_membership_updated', 'skipped' );
				continue;
			}
			$membership_data = [
				'email'           => $membership->get_user()->user_email,
				'user_id'         => $membership->get_user()->ID,
				'plan_network_id' => $plan_network_id,
				'membership_id'   => $membership->get_id(),
				'new_status'      => $status,
			];
			$timestamp = null;
			switch ( $status ) {
				case 'paused':
					$timestamp = strtotime( $membership->get_paused_date() );
					break;
				case 'cancelled':
					$timestamp = strtotime( $membership->get_cancelled_date() );
					break;
				case 'expired':
					$timestamp = strtotime( $membership->get_end_date() );
					break;
			}

			if ( ! $timestamp ) {
				$timestamp = strtotime( $membership->get_start_date() );
			}

			$events[] = new \Newspack_Network\Incoming_Events\Woocommerce_Membership_Updated( get_bloginfo( 'url' ), $membership_data, $timestamp );
		}

		return $events;
	}
}
