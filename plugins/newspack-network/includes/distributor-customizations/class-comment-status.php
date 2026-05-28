<?php
/**
 * Newspack Distributor Comment Status Sync.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

/**
 * Class to keep the Comment and Ping statuses in sync.
 */
class Comment_Status {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Comment status is already correctly distributed on PULL, so we only need the filter on PUSH and Subscriptions.
		add_filter( 'dt_push_post_args', [ __CLASS__, 'filter_post_args' ], 10, 2 );

		add_filter( 'dt_subscription_post_args', [ __CLASS__, 'filter_post_args' ], 10, 2 );
		add_action( 'dt_process_subscription_attributes', [ __CLASS__, 'process_attributes' ], 10, 2 );
		add_action( 'dt_process_distributor_attributes', [ __CLASS__, 'process_attributes' ], 10, 2 );
	}

	/**
	 * Filter the arguments sent to the remote server during a push
	 *
	 * @param array   $post_body The post data to be sent to the Node.
	 * @param WP_Post $post The post object.
	 */
	public static function filter_post_args( $post_body, $post ) {
		$post_body['post_data']['comment_status'] = $post->comment_status;
		$post_body['post_data']['ping_status']    = $post->ping_status;

		return $post_body;
	}

	/**
	 * Process distributed post attributes after the distribution has completed.
	 *
	 * @param WP_Post         $post The post object.
	 * @param WP_REST_Request $request The request object.
	 */
	public static function process_attributes( $post, $request ) {
		wp_update_post(
			[
				'ID'             => $post->ID,
				'comment_status' => $request['post_data']['comment_status'],
				'ping_status'    => $request['post_data']['ping_status'],
			]
		);
	}
}
