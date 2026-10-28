<?php
/**
 * The Events Calendar integration class.
 *
 * @package Newspack
 */

namespace Newspack_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class The_Events_Calendar {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'tribe_events_register_event_type_args', [ __CLASS__, 'register_event_type_args' ] );
		add_filter( 'newspack_blocks_displayed_post_date', [ __CLASS__, 'get_displayed_post_date' ], 10, 2 );
		add_filter( 'newspack_blocks_formatted_displayed_post_date', [ __CLASS__, 'get_formatted_displayed_post_date' ], 10, 2 );
		add_filter( 'newspack_blocks_article_meta_footer', [ __CLASS__, 'get_article_meta_footer' ], 10, 2 );
	}

	/**
	 * Enable Newspack Blocks support for The Events Calendar.
	 *
	 * @param array $args The post type args.
	 */
	public static function register_event_type_args( $args ) {
		$args['supports'][] = 'newspack_blocks';
		return $args;
	}

	/**
	 * Get the displayed post date.
	 *
	 * @param string  $date The date.
	 * @param WP_Post $post The post object.
	 */
	public static function get_displayed_post_date( $date, $post ) {
		if ( $post->post_type === 'tribe_events' ) {
			return mysql_to_rfc3339( get_post_meta( $post->ID, '_EventStartDate', true ) );
		}
		return $date;
	}

	/**
	 * Get the formatted displayed post date.
	 *
	 * @param string  $date The date.
	 * @param WP_Post $post The post object.
	 */
	public static function get_formatted_displayed_post_date( $date, $post ) {
		if ( $post->post_type === 'tribe_events' && function_exists( 'tribe_events_event_schedule_details' ) ) {
			try {
				$html = tribe_events_event_schedule_details( $post );
				return wp_strip_all_tags( $html );
			} catch ( \Throwable $th ) {
				return $date;
			}
		}
		return $date;
	}

	/**
	 * Get the article meta footer.
	 *
	 * @param string  $footer The footer.
	 * @param WP_Post $post   The post object.
	 */
	public static function get_article_meta_footer( $footer, $post ) {
		if ( $post->post_type === 'tribe_events' && function_exists( 'tribe_get_venue' ) ) {
			$venue = tribe_get_venue( $post );
			if ( ! $venue ) {
				return $footer;
			}
			return $footer . '<span class="newspack-blocks__tec-venue">' . $venue . '</span>';
		}
		return $footer;
	}
}
The_Events_Calendar::init();
