<?php
/**
 * Data Backfiller for reader_registered events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use WP_CLI;

/**
 * Backfiller class.
 */
class Reader_Registered extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'User %s (#%d) registered on %s.', $event->get_email(), $event->get_data()->user_id, $event->get_formatted_date() );
	}

	/**
	 * Gets the events to be processed
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		$roles_to_sync = \Newspack_Network\Utils\Users::get_synced_user_roles();
		if ( empty( $roles_to_sync ) ) {
			WP_CLI::error( 'Incompatible Newspack plugin version or no roles to sync.' );
		}
		// Get all users registered between specified dates.
		$users = get_users(
			[
				'role__in'   => $roles_to_sync,
				'date_query' => [
					'after'     => $this->start,
					'before'    => $this->end,
					'inclusive' => true,
				],
				'orderby'    => 'user_registered',
				'fields'     => [ 'id', 'user_email', 'user_registered' ],
				'number'     => -1,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => \Newspack_Network\Utils\Users::USER_META_REMOTE_SITE,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Found %s user(s) eligible for sync.', count( $users ) ) );
		WP_CLI::line( '' );

		$this->maybe_initialize_progress_bar( 'Processing users', count( $users ) );

		$events = [];

		// Disregard any attached data when checking for duplicates of reader registrations.
		// Only the email address and the date are relevant in this case.
		add_filter(
			'newspack_network_event_log_get_args',
			function( $args ) {
				if ( $args['action_name'] === 'reader_registered' && isset( $args['data'] ) ) {
					unset( $args['data'] );
				}
				return $args;
			}
		);

		foreach ( $users as $user ) {
			$registration_method = get_user_meta( $user->ID, \Newspack\Reader_Activation::REGISTRATION_METHOD, true );
			$user_data = [
				'user_id'         => $user->ID,
				'email'           => $user->user_email,
				'user_registered' => $user->user_registered,
				'first_name'      => get_user_meta( $user->ID, 'first_name', true ),
				'last_name'       => get_user_meta( $user->ID, 'last_name', true ),
				'meta_input'      => [
					// 'current_page_url' is not saved, can't be backfilled.
					'registration_method' => empty( $registration_method ) ? 'backfill-script' : $registration_method,
				],
			];

			$incoming_event = new \Newspack_Network\Incoming_Events\Reader_Registered( get_bloginfo( 'url' ), $user_data, strtotime( $user->user_registered ) );
			$events[] = $incoming_event;
		}

		return $events;
	}
}
