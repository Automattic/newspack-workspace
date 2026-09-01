<?php
/**
 * Newspack Scheduled Post Checker
 * Checks to make sure posts haven't missed their schedule, and publishes them if needed.
 *
 * @package Newspack
 */

namespace Newspack\Scheduled_Post_Checker;

defined( 'ABSPATH' ) || exit;
define( 'NEWSPACK_SCHEDULED_POST_CHECKER_CRON_HOOK', 'newspack_scheduled_post_checker' );

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
 * (Campaign prompts, Sponsors) are therefore invisible to `'any'`, so a
 * scheduled one that misses its cron slot would otherwise sit in `future`
 * indefinitely. Start from the search-visible set and add those known editorial
 * types; the filter lets any plugin register its own schedulable type.
 *
 * @return string[] Post type slugs.
 */
function nspc_get_post_types() {
	$post_types = get_post_types( [ 'exclude_from_search' => false ] );

	foreach ( [ 'newspack_popups_cpt', 'newspack_spnsrs_cpt' ] as $editorial_cpt ) {
		if ( post_type_exists( $editorial_cpt ) ) {
			$post_types[ $editorial_cpt ] = $editorial_cpt;
		}
	}

	/**
	 * Filters the post types the scheduled-post checker rescues. Add a slug here
	 * to have a non-public, editor-scheduled CPT rescued when it misses its slot.
	 *
	 * @param string[] $post_types Post type slugs.
	 */
	return apply_filters( 'newspack_scheduled_post_checker_post_types', array_values( $post_types ) );
}

/**
 * Check to see if any posts have missed schedule, and try sending them live again if so.
 */
function nspc_run_check() {
	$time = wp_date( 'Y-m-d H:i:s' );

	$posts_with_missed_schedule = get_posts(
		[
			'post_status'    => 'future',
			'post_type'      => nspc_get_post_types(),
			'fields'         => 'ids',
			// Rescue a backlog in one run rather than the get_posts() default of 5.
			'posts_per_page' => 100,
			'date_query'     => [
				[
					'before'    => $time,
					'inclusive' => false,
				],
			],
		]
	);

	foreach ( $posts_with_missed_schedule as $post_id ) {
		check_and_publish_future_post( $post_id );
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
