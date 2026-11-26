<?php
/**
 * Register the Video Playlist block's REST endpoint.
 *
 * @package Newspack_Blocks
 */

/**
 * REST endpoint for the Video Playlist block. The Content Loop endpoints and fields
 * in newspack-blocks' copy of this class aren't bundled.
 */
class Newspack_Blocks_API {
	/**
	 * Register the video-playlist endpoint.
	 */
	public static function register_video_playlist_endpoint() {
		register_rest_route(
			'newspack-blocks/v1',
			'/video-playlist',
			[
				'methods'             => 'GET',
				'callback'            => [ 'Newspack_Blocks_API', 'video_playlist_endpoint' ],
				'permission_callback' => function() {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	/**
	 * Process requests to the video-playlist endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response.
	 */
	public static function video_playlist_endpoint( $request ) {
		$args = $request->get_params();
		return new \WP_REST_Response( newspack_blocks_get_video_playlist( $args ), 200 );
	}
}

add_action( 'rest_api_init', array( 'Newspack_Blocks_API', 'register_video_playlist_endpoint' ) );
