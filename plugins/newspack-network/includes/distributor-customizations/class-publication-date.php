<?php
/**
 * Newspack Distributor Publication Date tweak.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

/**
 * Class to keep the publication date on the distributed posts.
 */
class Publication_Date {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_filter( 'dt_push_post_args', [ __CLASS__, 'filter_push_post_args' ], 10, 2 );
		add_filter( 'dt_pull_post_args', [ __CLASS__, 'filter_pull_post_args' ], 10, 3 );
	}

	/**
	 * Filter the arguments sent to the remote server during a push
	 *
	 * @param array   $post_body The post data to be sent to the Node.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_push_post_args( $post_body, $post ) {
		// Pass the original published date to the new pushed post and set the same published date
		// instead of setting it to the current time.
		$post_body['date']     = $post->post_date;
		$post_body['date_gmt'] = $post->post_date_gmt;

		return $post_body;
	}

	/**
	 * Keep the publication date on the new pulled post
	 *
	 * @param array   $post_array The post data to be sent to the Node.
	 * @param int     $remote_id The remote post ID.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_pull_post_args( $post_array, $remote_id, $post ) {
		$post_array['post_date']     = $post->post_date;
		$post_array['post_date_gmt'] = $post->post_date_gmt;

		return $post_array;
	}
}
