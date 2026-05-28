<?php
/**
 * Newspack Network Content Distribution API.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack\Data_Events;
use InvalidArgumentException;
use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Utils;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * API Class.
 */
class API {
	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the REST API routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/distribute/(?P<post_id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'distribute' ],
				'args'                => [
					'urls'              => [
						'type'     => 'array',
						'required' => true,
						'items'    => [
							'type' => 'string',
						],
					],
					'status_on_publish' => [
						'type'    => 'string',
						'enum'    => [ 'draft', 'pending', 'publish' ],
						'default' => 'draft',
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/unlink/(?P<post_id>\d+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'toggle_unlink' ],
				'args'                => [
					'unlinked' => [
						'required' => true,
						'type'     => 'boolean',
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/pull/(?P<post_id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'pull_post' ],
				'args'                => [
					'url'               => [
						'type'     => 'string',
						'required' => false, // If not provided, it'll look for the X-Network-Site-URL header.
					],
					'status_on_publish' => [
						'type'    => 'string',
						'enum'    => [ 'draft', 'pending', 'publish' ],
						'default' => 'draft',
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);

		register_rest_route(
			'newspack-network/v1',
			'/content-distribution/insert',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'insert_post' ],
				'args'                => [
					'payload' => [
						'type'     => 'object',
						'required' => true,
					],
				],
				'permission_callback' => function () {
					return current_user_can( Admin::CAPABILITY );
				},
			]
		);
	}

	/**
	 * Toggle the unlinked status of an incoming post.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function toggle_unlink( $request ): WP_REST_Response|WP_Error {
		$post_id  = $request->get_param( 'post_id' );
		$unlinked = $request->get_param( 'unlinked' );

		try {
			$incoming_post = new Incoming_Post( $post_id );
			$incoming_post->set_unlinked( $unlinked );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $e->getMessage(), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'post_id'  => $post_id,
				'unlinked' => ! $incoming_post->is_linked(),
				'status'   => 'success',
			]
		);
	}

	/**
	 * Distribute a post to the network.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function distribute( $request ) {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return new WP_Error( 'newspack_network_content_distribution_error', __( 'Data Events class not found.', 'newspack-network' ), [ 'status' => 400 ] );
		}

		$post_id          = $request->get_param( 'post_id' );
		$urls             = $request->get_param( 'urls' );
		$status_on_publish = $request->get_param( 'status_on_publish' );

		// Prevent auto-drafts from being distributed.
		$post = get_post( $post_id );
		if ( 'auto-draft' === $post->post_status ) {
			return new WP_Error( 'newspack_network_content_distribution_error', __( 'Post is currently an auto-draft. Save before distributing it.', 'newspack-network' ), [ 'status' => 400 ] );
		}

		try {
			$outgoing_post = new Outgoing_Post( $post_id );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $e->getMessage(), [ 'status' => 400 ] );
		}

		$distribution = $outgoing_post->set_distribution( $urls );

		if ( is_wp_error( $distribution ) ) {
			return new WP_Error( 'newspack_network_content_distribution_error', $distribution->get_error_message(), [ 'status' => 400 ] );
		}

		$payload = $outgoing_post->get_payload( $status_on_publish );
		Data_Events::dispatch( 'network_post_updated', $payload );

		// Store payload hash to prevent unnecessary updates.
		update_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, $outgoing_post->get_payload_hash( $payload ) );

		return rest_ensure_response( $distribution );
	}

	/**
	 * Pull a post and set up distribution to the requester.
	 *
	 * This request will not dispatch a post update. It's up to the requester
	 * to create the post on their site with the provided payload.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function pull_post( $request ): WP_REST_Response|WP_Error {
		$post_id  = $request->get_param( 'post_id' );
		$url      = $request->get_param( 'url' );
		$status_on_publish = $request->get_param( 'status_on_publish' );

		if ( ! $url ) {
			$url = filter_input( INPUT_SERVER, 'HTTP_X_NETWORK_SITE_URL', FILTER_VALIDATE_URL );
			if ( ! $url ) {
				return new WP_Error( 'missing_url', 'The URL is required.', [ 'status' => 400 ] );
			}
		}

		if ( ! Utils\Network::is_networked_url( $url ) ) {
			return new WP_Error( 'site_not_networked', 'The destination site is not part of the network.', [ 'status' => 400 ] );
		}

		try {
			$outgoing_post = new Outgoing_Post( $post_id );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_post_id', $e->getMessage(), [ 'status' => 400 ] );
		}

		$distribution = $outgoing_post->set_distribution( [ $url ] );
		if ( is_wp_error( $distribution ) ) {
			return $distribution;
		}

		return rest_ensure_response(
			$outgoing_post->get_payload( $status_on_publish )
		);
	}

	/**
	 * Insert a post given an Outgoing_Post payload.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response|WP_Error The REST response or error.
	 */
	public static function insert_post( $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_param( 'payload' );

		try {
			$incoming_post = new Incoming_Post( $payload );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_payload', $e->getMessage(), [ 'status' => 400 ] );
		}

		$post_id = $incoming_post->insert();
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'insert_failed', $post_id->get_error_message(), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'post_id' => $post_id,
			]
		);
	}
}
