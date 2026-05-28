<?php
/**
 * Nextdoor management.
 *
 * @package Newspack
 */

namespace Newspack\Nextdoor;

use Newspack\Nextdoor;

defined( 'ABSPATH' ) || exit;

/**
 * Nextdoor management class.
 */
class Controller {

	/**
	 * Initialise.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_api_endpoints' ] );
	}

	/**
	 * Register REST API endpoints.
	 */
	public static function register_api_endpoints() {
		// OAuth endpoints.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/oauth/start',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_start_oauth' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'email'   => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
					'country' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Page claim endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/claim-page',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_claim_page' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				'args'                => [
					'publication_url' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					],
					'test'            => [
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		// Post sharing status endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/post-status/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'api_get_post_sharing_status' ],
				'permission_callback' => [ __CLASS__, 'api_post_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Publish post endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/publish-post/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_publish_post' ],
				'permission_callback' => [ __CLASS__, 'api_post_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Update post endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/update-post/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'api_update_post' ],
				'permission_callback' => [ __CLASS__, 'api_post_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Delete post endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/delete-post/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'api_delete_post' ],
				'permission_callback' => [ __CLASS__, 'api_post_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Disconnect endpoint.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/disconnect',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'api_disconnect' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
			]
		);
	}

	/**
	 * Check if user has permission to manage Nextdoor settings.
	 *
	 * @return bool
	 */
	public static function api_permissions_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Check if user has permission to publish posts to Nextdoor.
	 *
	 * @return bool
	 */
	public static function api_post_permissions_check() {
		return Nextdoor::can_user_publish();
	}

	/**
	 * Start OAuth flow via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_start_oauth( $request ) {
		$email   = $request->get_param( 'email' );
		$country = $request->get_param( 'country' );

		$redirect_uri     = Nextdoor::get_redirect_uri();
		$api              = API::instance();
		$account_response = $api->create_account( $email, $country, $redirect_uri );

		if ( is_wp_error( $account_response ) ) {
			return $account_response;
		}

		return rest_ensure_response(
			[
				'login_url' => isset( $account_response['login_url'] ) ? $account_response['login_url'] : '',
			]
		);
	}

	/**
	 * Callback for claiming a page via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_claim_page( $request ) {
		$publication_url = $request->get_param( 'publication_url' );
		$test            = $request->get_param( 'test' );

		$api    = API::instance();
		$result = $api->claim_page( $publication_url, $test );

		if ( is_array( $result ) && isset( $result['page_id'] ) ) {
			$settings                    = Nextdoor::get_settings();
			$settings['page_id']         = $result['page_id'];
			$settings['publication_url'] = $publication_url;

			Nextdoor::update_settings( $settings );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Disconnect Nextdoor account via API.
	 *
	 * @return WP_REST_Response
	 */
	public static function api_disconnect() {
		$result = Nextdoor::delete_settings();

		if ( ! $result ) {
			return new \WP_Error(
				'disconnect_failed',
				__( 'Failed to disconnect Nextdoor account.', 'newspack-plugin' )
			);
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Nextdoor account disconnected successfully.', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Publish post to Nextdoor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_publish_post( $request ) {
		$post_id = $request->get_param( 'id' );
		if ( ! Nextdoor::is_connected() ) {
			return new \WP_Error(
				'nextdoor_not_connected',
				__( 'Nextdoor is not connected.', 'newspack-plugin' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found or not published.', 'newspack-plugin' )
			);
		}

		// Check if post is already shared.
		$nextdoor_guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( $nextdoor_guid ) {
			return new \WP_Error(
				'already_shared',
				__( 'Post has already been shared to Nextdoor.', 'newspack-plugin' )
			);
		}

		$settings = Nextdoor::get_settings();
		$api      = API::instance();

		// Check if the access token is valid.
		$token_valid = Auth::validate_token();
		if ( ! $token_valid ) {
			return new \WP_Error(
				'nextdoor_token_invalid',
				__( 'Nextdoor access token is invalid or expired. Please reconnect your account.', 'newspack-plugin' )
			);
		}

		$article_data = self::prepare_article_data( $post_id, $settings );

		$response = $api->create_article( $article_data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		update_post_meta( $post_id, '_nextdoor_guid', $article_data['guid'] );
		update_post_meta( $post_id, '_nextdoor_shared_at', current_time( 'mysql' ) );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Post successfully published to Nextdoor.', 'newspack-plugin' ),
				'article' => $response,
			]
		);
	}

	/**
	 * Update post on Nextdoor via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_update_post( $request ) {
		$post_id = $request->get_param( 'id' );
		if ( ! Nextdoor::is_connected() ) {
			return new \WP_Error(
				'nextdoor_not_connected',
				__( 'Nextdoor is not connected.', 'newspack-plugin' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found or not published.', 'newspack-plugin' )
			);
		}

		// Check if post has been shared to Nextdoor.
		$guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( ! $guid ) {
			return new \WP_Error(
				'post_not_shared',
				__( 'Post has not been shared to Nextdoor yet.', 'newspack-plugin' )
			);
		}

		$settings = Nextdoor::get_settings();
		$api      = API::instance();

		$article_data = self::prepare_article_data( $post_id, $settings );

		$article_data['modified_at'] = get_the_modified_date( 'c', $post_id );

		$response = $api->update_article( $article_data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		update_post_meta( $post_id, '_nextdoor_updated_at', current_time( 'mysql' ) );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Post successfully updated on Nextdoor.', 'newspack-plugin' ),
				'article' => $response,
			]
		);
	}

	/**
	 * Delete post from Nextdoor via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_delete_post( $request ) {
		$post_id = $request->get_param( 'id' );
		if ( ! Nextdoor::is_connected() ) {
			return new \WP_Error(
				'nextdoor_not_connected',
				__( 'Nextdoor is not connected.', 'newspack-plugin' )
			);
		}

		// Check if post has been shared to Nextdoor.
		$guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( ! $guid ) {
			return new \WP_Error(
				'post_not_shared',
				__( 'Post has not been shared to Nextdoor.', 'newspack-plugin' )
			);
		}

		$api      = API::instance();
		$response = $api->delete_article( $guid );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Mark the post as deleted in post meta.
		update_post_meta( $post_id, '_nextdoor_deleted_at', current_time( 'mysql' ) );
		delete_post_meta( $post_id, '_nextdoor_shared_at' );
		delete_post_meta( $post_id, '_nextdoor_updated_at' );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Post successfully removed from Nextdoor.', 'newspack-plugin' ),
			]
		);
	}

	/**
	 * Get post Nextdoor sharing status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function api_get_post_sharing_status( $request ) {
		$post_id              = $request->get_param( 'id' );
		$guid                 = get_post_meta( $post_id, '_nextdoor_guid', true );
		$ingestion_status     = null;
		$ingestion_response   = [];
		$ingestion_error_msgs = [];

		if ( ! empty( $guid ) ) {
			$api                = API::instance();
			$ingestion_response = $api->get_ingestion_report( [ $guid ] );

			if ( ! is_wp_error( $ingestion_response ) &&
				isset( $ingestion_response['results'] ) &&
				is_array( $ingestion_response['results'] )
			) {
				foreach ( $ingestion_response['results'] as $result ) {
					if ( isset( $result['guid'] ) && $result['guid'] === $guid ) {
						$ingestion_status     = isset( $result['status'] ) ? $result['status'] : null;
						$ingestion_error_msgs = isset( $result['error_msgs'] ) ? $result['error_msgs'] : [];
						break;
					}
				}
			}

			// If the post was deleted on Nextdoor, update local meta & response accordingly.
			if ( 'deleted' === $ingestion_status ) {
				update_post_meta( $post_id, '_nextdoor_deleted_at', current_time( 'mysql' ) );
				delete_post_meta( $post_id, '_nextdoor_shared_at' );
				delete_post_meta( $post_id, '_nextdoor_updated_at' );
			}
		}

		// Prepare response.
		$shared_at    = get_post_meta( $post_id, '_nextdoor_shared_at', true );
		$updated_at   = get_post_meta( $post_id, '_nextdoor_updated_at', true );
		$deleted_at   = get_post_meta( $post_id, '_nextdoor_deleted_at', true );
		$can_publish  = Nextdoor::can_user_publish();
		$post         = get_post( $post_id );
		$is_published = $post && $post->post_status === 'publish';

		$response = [
			'is_shared'        => ! empty( $guid ),
			'is_deleted'       => ! empty( $deleted_at ),
			'guid'             => $guid,
			'can_publish'      => $can_publish,
			'shared_at'        => $shared_at,
			'updated_at'       => $updated_at,
			'is_published'     => $is_published,
			'last_modified'    => $post ? get_the_modified_date( 'c', $post_id ) : null,
			'ingestion_status' => $ingestion_status,
			'ingestion_errors' => $ingestion_error_msgs,
		];

		return rest_ensure_response( $response );
	}

	/**
	 * Prepare article data for Nextdoor API.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $settings Nextdoor settings.
	 * @return array
	 */
	private static function prepare_article_data( $post_id, $settings ) {
		$post = get_post( $post_id );

		// Generate GUID for the article.
		$guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( ! $guid ) {
			$site_name_slug = sanitize_title( get_bloginfo( 'name' ) );
			$guid           = $site_name_slug . '_' . $post_id . '_' . time();
		}

		$article_data = [
			'publication_url' => $settings['publication_url'],
			'guid'            => $guid,
			'content_url'     => get_permalink( $post_id ),
			'title'           => get_the_title( $post_id ),
			'description'     => get_the_excerpt( $post_id ),
			'authors'         => [ get_the_author_meta( 'display_name', $post->post_author ) ],
			'published_at'    => get_the_date( 'c', $post_id ),
			'modified_at'     => get_the_modified_date( 'c', $post_id ),
			'content'         => wp_strip_all_tags( get_the_content( null, false, $post_id ), true ),
		];

		// Add featured image if available.
		$featured_image_id = get_post_thumbnail_id( $post_id );
		if ( $featured_image_id ) {
			$image_url = wp_get_attachment_image_url( $featured_image_id, 'large' );
			if ( $image_url ) {
				$article_data['media'] = [
					'type' => 'image',
					'url'  => $image_url,
				];
			}
		}

		// Add categories as tags.
		$categories = get_the_category( $post_id );
		if ( $categories ) {
			$article_data['tags'] = array_map(
				function( $cat ) {
					return $cat->name;
				},
				$categories
			);
		}

		/**
		 * Filter article data before sending to Nextdoor.
		 *
		 * @param array $article_data Article data.
		 * @param int   $post_id      Post ID.
		 */
		return apply_filters( 'newspack_nextdoor_article_data', $article_data, $post_id );
	}
}

Controller::init();
