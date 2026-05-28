<?php
/**
 * Data Backfiller for membership_plan_updated events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Data_Backfill;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use Newspack_Network\Woocommerce_Memberships\Events as Memberships_Events;
use WP_Cli;
use WC_Memberships_Membership_Plan;

/**
 * Backfiller class.
 */
class Membership_Plan_Updated extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Membership Plan #%d', $event->get_id() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {

		if ( ! class_exists( 'WC_Memberships_Membership_Plan' ) ) {
			return [];
		}

		// Get all memberships created or updated between $start and $end.
		$membership_plans = get_posts(
			[
				'post_type'   => Memberships_Admin::MEMBERSHIP_PLANS_CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'date_query'  => [
					'column'    => 'post_modified_gmt',
					'after'     => $this->start,
					'before'    => $this->end,
					'inclusive' => true,
				],
			]
		);

		$this->maybe_initialize_progress_bar( 'Processing membership plans', count( $membership_plans ) );

		$events = [];
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Found %s membership plan(s) eligible for sync.', count( $membership_plans ) ) );
		WP_CLI::line( '' );

		foreach ( $membership_plans as $plan ) {
			$membership_data = Memberships_Events::membership_plan_updated( $plan->ID );

			$timestamp = strtotime( $plan->post_modified_gmt );

			$events[] = new \Newspack_Network\Incoming_Events\Membership_Plan_Updated( get_bloginfo( 'url' ), $membership_data, $timestamp );
		}

		return $events;
	}
}
