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
	 * @param string[] $post_types Post type slugs.
	 */
	return apply_filters( 'newspack_scheduled_post_checker_post_types', array_values( $post_types ) );
}

/**
 * Check to see if any posts have missed schedule, and try sending them live again if so.
 *
 * Customizer changesets are queried separately because only they are age-limited:
 * publishing one rewrites site configuration and core deletes it in the same request,
 * so a long stranded changeset is left alone. Limiting in the query rather than skipping
 * rows afterwards keeps stale changesets from filling the row limit and starving the
 * post backlog, which stays deliberately unbounded.
 */
function nspc_run_check() {
	$post_types                 = nspc_get_post_types();
	$content_types              = array_values( array_diff( $post_types, [ 'customize_changeset' ] ) );
	$posts_with_missed_schedule = [];

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
	}

	if ( in_array( 'customize_changeset', $post_types, true ) ) {
		$posts_with_missed_schedule = array_merge(
			$posts_with_missed_schedule,
			get_posts(
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
			)
		);
	}

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
