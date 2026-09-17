<?php
/**
 * Newspack Scheduled Post Checker
 * Checks to make sure posts haven't missed their schedule, and publishes them if needed.
 *
 * @package Newspack
 */

namespace Newspack\Scheduled_Post_Checker;

use Newspack\Logger;

defined( 'ABSPATH' ) || exit;
define( 'NEWSPACK_SCHEDULED_POST_CHECKER_CRON_HOOK', 'newspack_scheduled_post_checker' );

const LOGGER_HEADER = 'NEWSPACK-SCHEDULED-POST-CHECKER';

/**
 * Set up the checking.
 */
function nspc_init() {
	add_action( 'newspack_deactivation', '\Newspack\Scheduled_Post_Checker\nspc_deactivate' );
	if ( ! wp_next_scheduled( NEWSPACK_SCHEDULED_POST_CHECKER_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'fivemins', NEWSPACK_SCHEDULED_POST_CHECKER_CRON_HOOK );
	}
}
add_action( 'init', __NAMESPACE__ . '\nspc_init' );

/**
 * Clear the cron job when this plugin is deactivated.
 */
function nspc_deactivate() {
	wp_clear_scheduled_hook( NEWSPACK_SCHEDULED_POST_CHECKER_CRON_HOOK );
}

/**
 * The post types the checker rescues.
 *
 * WordPress's `post_type => 'any'` shorthand matches only types whose
 * `exclude_from_search` is false — which it derives from `public` when the
 * argument is omitted. Editor-authored types registered `public => false`
 * (Campaign prompts, Sponsor, Customizer changesets) are therefore invisible to
 * `'any'`, so a scheduled one that misses its cron slot would otherwise sit in
 * `future` indefinitely. Start from the search-visible set and add those known
 * editorial types; the filter lets any plugin register its own schedulable type.
 *
 * @return string[] Post type slugs.
 */
function nspc_get_post_types() {
	$post_types = get_post_types( [ 'exclude_from_search' => false ] );

	foreach ( [ 'newspack_popups_cpt', 'newspack_spnsrs_cpt', 'customize_changeset' ] as $hidden_cpt ) {
		if ( post_type_exists( $hidden_cpt ) ) {
			$post_types[ $hidden_cpt ] = $hidden_cpt;
		}
	}

	/**
	 * Filters the post types the scheduled-post checker rescues. Add a slug here
	 * to have a non-public, editor-scheduled CPT rescued when it misses its slot.
	 *
	 * Removing `customize_changeset` from the returned list is also the way to opt
	 * a site out of the Customizer-changeset rescue entirely.
	 *
	 * @param string[] $post_types Post type slugs.
	 */
	return apply_filters( 'newspack_scheduled_post_checker_post_types', array_values( $post_types ) );
}

/**
 * Check to see if any posts have missed schedule, and try sending them live again if so.
 *
 * Customizer changesets are age-limited and queried separately: replaying a long-stranded
 * one unattended feels wrong, so anything past the window is left for this job to ignore.
 * That only stops this job's own replay — WP core still treats an aged-out changeset as
 * active, so a human in the Customizer can build a new save on it, and this checker will
 * then rescue that save too. Filtering in the query (not after) keeps stale changesets
 * from filling the row limit and starving the unbounded post backlog.
 */
function nspc_run_check() {
	$post_types    = nspc_get_post_types();
	$content_types = array_values( array_diff( $post_types, [ 'customize_changeset' ] ) );

	if ( ! empty( $content_types ) ) {
		$posts_with_missed_schedule = get_posts(
			[
				'post_status'    => 'future',
				'post_type'      => $content_types,
				'fields'         => 'ids',
				// Rescue a backlog in one run rather than the get_posts() default of 5.
				'posts_per_page' => 100,
				'date_query'     => [
					[
						'before'    => wp_date( 'Y-m-d H:i:s' ),
						'inclusive' => false,
					],
				],
			]
		);

		foreach ( $posts_with_missed_schedule as $post_id ) {
			check_and_publish_future_post( $post_id );
		}
	}

	if ( in_array( 'customize_changeset', $post_types, true ) ) {
		nspc_rescue_changesets();
	}
}

/**
 * Rescue Customizer changesets that missed their slot within the rescue window, and log
 * (once each, via a post meta flag) any that missed it but have aged out of that window —
 * those are otherwise indistinguishable from a successful publish, which is exactly the
 * silence this checker exists to fix.
 */
function nspc_rescue_changesets() {
	// Both queries below bound on post_date_gmt (unlike the content-types query,
	// which bounds local post_date) so the 3-day window survives a site timezone
	// change rather than drifting with it.
	$rescuable = get_posts(
		[
			'post_status'    => 'future',
			'post_type'      => 'customize_changeset',
			'fields'         => 'ids',
			'posts_per_page' => 100,
			// Oldest first, so a later changeset overwrites an earlier one.
			'order'          => 'ASC',
			'date_query'     => [
				[
					'column'    => 'post_date_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ),
					'before'    => gmdate( 'Y-m-d H:i:s' ),
					'inclusive' => false,
				],
			],
		]
	);

	foreach ( $rescuable as $changeset_id ) {
		$missed_gmt = strtotime( get_post_field( 'post_date_gmt', $changeset_id ) . ' GMT' );
		Logger::log( sprintf( 'Rescuing changeset %d, %s overdue.', $changeset_id, human_time_diff( $missed_gmt ) ), LOGGER_HEADER );
		check_and_publish_future_post( $changeset_id );
	}

	// Bounded and self-limiting: once a stranded changeset is logged, the meta flag
	// drops it out of this query for good, so the row limit only ever covers newly
	// stranded changesets, not every one that's ever aged out.
	$newly_stranded = get_posts(
		[
			'post_status'    => 'future',
			'post_type'      => 'customize_changeset',
			'fields'         => 'ids',
			'posts_per_page' => 100,
			'date_query'     => [
				[
					'column'    => 'post_date_gmt',
					'before'    => gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ),
					'inclusive' => false,
				],
			],
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => '_nspc_logged_stranded',
					'compare' => 'NOT EXISTS',
				],
			],
		]
	);

	foreach ( $newly_stranded as $changeset_id ) {
		$missed_gmt = strtotime( get_post_field( 'post_date_gmt', $changeset_id ) . ' GMT' );
		Logger::log(
			sprintf(
				'Changeset %d missed its slot %s ago and is outside the rescue window; it will stay unpublished unless someone reopens the Customizer.',
				$changeset_id,
				human_time_diff( $missed_gmt )
			),
			LOGGER_HEADER,
			'warning'
		);
		update_post_meta( $changeset_id, '_nspc_logged_stranded', true );
	}
}
add_action( NEWSPACK_SCHEDULED_POST_CHECKER_CRON_HOOK, __NAMESPACE__ . '\nspc_run_check' );

/**
 * Add a cron interval for every five minutes.
 *
 * @param array $schedules Defined cron schedules.
 * @return array Modified $schedules.
 */
function nspc_add_cron_schedule( $schedules ) {
	$schedules['fivemins'] = [
		'interval' => MINUTE_IN_SECONDS * 5,
		'display'  => 'Every 5 minutes',
	];
	return $schedules;
}
add_filter( 'cron_schedules', __NAMESPACE__ . '\nspc_add_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- https://github.com/WordPress/WordPress-Coding-Standards/issues/1865
