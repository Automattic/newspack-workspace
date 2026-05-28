<?php
/**
 * Newspack Distributor Sync post status
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

/**
 * Class to sync post status when a post is trashed or restored from trash
 */
class Sync_Post_Status {

	const POST_STATUS_META_NAME = 'newspack_network_post_status';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'dt_subscription_post_args', [ __CLASS__, 'filter_subscription_post_args' ], 10, 2 );
		add_action( 'dt_process_subscription_attributes', [ __CLASS__, 'process_attributes' ], 10, 2 );
		add_action( 'dt_process_distributor_attributes', [ __CLASS__, 'process_attributes' ], 10, 2 );
	}

	/**
	 * Process distributed post attributes after the distribution has completed.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function process_attributes( $post ) {
		$origin_post_status  = get_post_meta( $post->ID, self::POST_STATUS_META_NAME, true );
		$current_post_status = get_post_status( $post->ID );
		if ( $origin_post_status && $origin_post_status !== $current_post_status ) {
			wp_update_post(
				[
					'ID'          => $post->ID,
					'post_status' => $origin_post_status,
				]
			);
		}
	}

	/**
	 * Send primary category slug to the Node when updating a post.
	 *
	 * @param array   $post_body The post data to be sent to the Node.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_subscription_post_args( $post_body, $post ) {
		// Attaching the post status only on updates (so not in filter_push_post_args).
		// By default, only published posts are distributable, so there's no need to attach the post status on new posts.
		$distributable_statuses = [ 'publish', 'trash' ];
		if ( in_array( $post->post_status, $distributable_statuses, true ) ) {
			$post_body['post_data']['distributor_meta'][ self::POST_STATUS_META_NAME ] = $post->post_status;
		}
		return $post_body;
	}
}
