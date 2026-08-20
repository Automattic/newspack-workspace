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
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/nextdoor/oauth/start',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'api_start_oauth' ],
				'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				// Declaring a `sanitize_callback` stops WordPress installing its default
				// validator, so every arg below names `rest_validate_request_arg`. Without it
				// the types are documentation, not rules.
				'args'                => [
					'email'   => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						// sanitize_email only strips, so without this a malformed address reaches
						// Nextdoor. Validation sees the raw value, so it trims as the sanitizer would.
						'validate_callback' => function ( $value, $request, $param ) {
							$valid = rest_validate_request_arg( $value, $request, $param );
							if ( is_wp_error( $valid ) ) {
								return $valid;
							}
							return (bool) is_email( trim( (string) $value ) );
						},
					],
					'country' => [
						'required'          => true,
						'type'              => 'string',
						// Named here rather than coming back as an opaque error from Nextdoor.
						'enum'              => wp_list_pluck( Nextdoor::get_available_countries(), 'value' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

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
						'format'            => 'uri',
						// esc_url_raw() turns trailing whitespace into %20, so normalize before
						// it runs and validate the same value below.
						'sanitize_callback' => function ( $value ) {
							return esc_url_raw( self::normalize_publication_url( $value ) );
						},
						// Core does not validate the `uri` format, and esc_url_raw() answers garbage
						// with a mangled URL rather than an error, so require one that survives it.
						'validate_callback' => function ( $value, $request, $param ) {
							$valid = rest_validate_request_arg( $value, $request, $param );
							if ( is_wp_error( $valid ) ) {
								return $valid;
							}
							$url = self::normalize_publication_url( $value );
							return $url === esc_url_raw( $url )
								&& in_array( (string) wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true )
								&& ! empty( wp_parse_url( $url, PHP_URL_HOST ) );
						},
					],
					'test'            => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

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
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

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
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

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
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

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
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		// Deliberately API-only: the admin UI no longer calls this.
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
	 * Require a token Nextdoor will accept, and say why when there is not one.
	 *
	 * A refresh that could not reach Nextdoor leaves the grant intact, so the remedy is to
	 * wait rather than to reconnect, which would cost the publisher their page claim.
	 *
	 * @return WP_Error|null Error when the request should not go out.
	 */
	private static function require_usable_token() {
		if ( Auth::validate_token() ) {
			return null;
		}

		if ( Auth::has_usable_token() ) {
			return new \WP_Error(
				'nextdoor_unreachable',
				__( 'Nextdoor could not be reached. Please try again shortly.', 'newspack-plugin' ),
				[ 'status' => 503 ]
			);
		}

		return new \WP_Error(
			'nextdoor_token_invalid',
			__( 'Nextdoor access token is invalid or expired. Please reconnect your account.', 'newspack-plugin' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Check the current user against the post a request names.
	 *
	 * The capability says whether a user may share to Nextdoor at all, not which posts they
	 * may act on, and the post comes from the route.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $capability Capability to check against the post.
	 * @return WP_Error|null Error when the user may not act on the post.
	 */
	private static function check_post_capability( $post_id, $capability ) {
		if ( current_user_can( $capability, $post_id ) ) {
			return null;
		}

		return new \WP_Error(
			'nextdoor_post_forbidden',
			__( 'Sorry, you are not allowed to manage Nextdoor sharing for this post.', 'newspack-plugin' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Start OAuth flow via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_start_oauth( $request ) {
		$email   = $request->get_param( 'email' );
		$country = $request->get_param( 'country' );

		// Ties the request to this user so the callback can tell the publisher's own return
		// from someone else's code. It rides on the redirect URI because that is the query
		// string Nextdoor is known to preserve.
		$redirect_uri = Nextdoor::get_redirect_uri( Auth::create_oauth_state() );

		$api              = API::instance();
		$account_response = $api->create_account( $email, $country, $redirect_uri );

		if ( is_wp_error( $account_response ) ) {
			return $account_response;
		}

		// The client navigates to this, so refuse anything that is not one to navigate to.
		// `esc_url_raw()` only strips disallowed protocols: it passes a scheme-relative URL
		// through untouched and allows `mailto:` and the rest of `wp_allowed_protocols()`.
		$login_url = is_array( $account_response ) && ! empty( $account_response['login_url'] ) && is_string( $account_response['login_url'] )
			? esc_url_raw( $account_response['login_url'] )
			: '';

		if ( '' !== $login_url && (
			! in_array( (string) wp_parse_url( $login_url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ||
			empty( wp_parse_url( $login_url, PHP_URL_HOST ) )
		) ) {
			$login_url = '';
		}

		if ( empty( $login_url ) ) {
			return new \WP_Error(
				'nextdoor_oauth_start_failed',
				__( 'Nextdoor did not return a sign-in link. Please try again.', 'newspack-plugin' ),
				[ 'status' => 502 ]
			);
		}

		return rest_ensure_response( [ 'login_url' => $login_url ] );
	}

	/**
	 * Callback for claiming a page via API.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_claim_page( $request ) {
		$publication_url = $request->get_param( 'publication_url' );
		$test            = $request->get_param( 'test' );

		$unusable = self::require_usable_token();
		if ( $unusable ) {
			return $unusable;
		}

		$api    = API::instance();
		$result = $api->claim_page( $publication_url, $test );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Stored and echoed back, so an unexpected shape would be persisted into the settings
		// option and rendered, the same reason the ingestion report is pinned.
		if ( ! is_array( $result ) || empty( $result['page_id'] ) || ! is_scalar( $result['page_id'] ) ) {
			return new \WP_Error(
				'nextdoor_claim_page_failed',
				__( 'Nextdoor did not return a page for this publication URL. Check the URL and try again.', 'newspack-plugin' ),
				[ 'status' => 502 ]
			);
		}

		$page_id = (string) $result['page_id'];

		$settings                    = Nextdoor::get_settings();
		$settings['page_id']         = $page_id;
		$settings['publication_url'] = $publication_url;

		Nextdoor::update_settings( $settings );

		return rest_ensure_response(
			[
				'success' => true,
				'page_id' => $page_id,
			]
		);
	}

	/**
	 * Disconnect Nextdoor account via API.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function api_disconnect() {
		Nextdoor::delete_settings();

		// delete_option() also reports failure when the option is already absent,
		// so check the settings are gone rather than trusting its return value.
		if ( false !== get_option( Nextdoor::SETTINGS_SLUG, false ) ) {
			return new \WP_Error(
				'disconnect_failed',
				__( 'Failed to disconnect Nextdoor account.', 'newspack-plugin' ),
				[ 'status' => 500 ]
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
		$post_id   = $request->get_param( 'id' );
		$forbidden = self::check_post_capability( $post_id, 'edit_post' );
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( ! Nextdoor::is_connected() ) {
			return new \WP_Error(
				'nextdoor_not_connected',
				__( 'Nextdoor is not connected.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found or not published.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$nextdoor_guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( $nextdoor_guid ) {
			return new \WP_Error(
				'already_shared',
				__( 'Post has already been shared to Nextdoor.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		$api = API::instance();

		$unusable = self::require_usable_token();
		if ( $unusable ) {
			return $unusable;
		}

		// Read after the token check, which can renew the grant and rewrite the option.
		$article_data = self::prepare_article_data( $post_id, Nextdoor::get_settings() );

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
		$post_id   = $request->get_param( 'id' );
		$forbidden = self::check_post_capability( $post_id, 'edit_post' );
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( ! Nextdoor::is_connected() ) {
			return new \WP_Error(
				'nextdoor_not_connected',
				__( 'Nextdoor is not connected.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found or not published.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( ! $guid ) {
			return new \WP_Error(
				'post_not_shared',
				__( 'Post has not been shared to Nextdoor yet.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		$api = API::instance();

		$unusable = self::require_usable_token();
		if ( $unusable ) {
			return $unusable;
		}

		// Read after the token check, which can renew the grant and rewrite the option.
		$article_data = self::prepare_article_data( $post_id, Nextdoor::get_settings() );

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
		$post_id   = $request->get_param( 'id' );
		$forbidden = self::check_post_capability( $post_id, 'edit_post' );
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( ! Nextdoor::is_connected() ) {
			return new \WP_Error(
				'nextdoor_not_connected',
				__( 'Nextdoor is not connected.', 'newspack-plugin' ),
				[ 'status' => 400 ]
			);
		}

		$guid = get_post_meta( $post_id, '_nextdoor_guid', true );
		if ( ! $guid ) {
			return new \WP_Error(
				'post_not_shared',
				__( 'Post has not been shared to Nextdoor.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		$unusable = self::require_usable_token();
		if ( $unusable ) {
			return $unusable;
		}

		$api      = API::instance();
		$response = $api->delete_article( $guid );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
		$post_id = $request->get_param( 'id' );
		// `read_post` reduces to plain `read` for a published post, so it gates nothing. The
		// only caller is the editor sidebar, which mounts on a post the user is editing.
		$forbidden = self::check_post_capability( $post_id, 'edit_post' );
		if ( $forbidden ) {
			return $forbidden;
		}

		$guid                 = get_post_meta( $post_id, '_nextdoor_guid', true );
		$ingestion_status     = null;
		$ingestion_response   = [];
		$ingestion_error_msgs = [];
		// Three states stop sharing and read differently: a grant that stopped working is a
		// reconnection, one never finished is setup, and no response at all is an outage,
		// which passes on its own. All are site-wide, so all are reported for an unshared post.
		$has_tokens      = ! empty( Nextdoor::get_settings()['access_token'] );
		$needs_reconnect = $has_tokens && ! Auth::has_usable_token();
		$needs_setup     = ! $needs_reconnect && ! Nextdoor::is_connected();
		$is_unreachable  = false;

		if ( ! empty( $guid ) && ! $needs_reconnect && ! $needs_setup && ! Auth::validate_token() ) {
			// The refresh can itself discover the grant is dead and record it, so ask again
			// rather than reporting a refusal as an outage.
			$needs_reconnect = ! Auth::has_usable_token();
			$is_unreachable  = ! $needs_reconnect;
		}

		if ( ! empty( $guid ) && ! $needs_reconnect && ! $needs_setup && ! $is_unreachable ) {
			$api                = API::instance();
			$token_used         = Nextdoor::get_settings()['access_token'];
			$ingestion_response = $api->get_ingestion_report( [ $guid ] );

			if ( is_wp_error( $ingestion_response ) ) {
				// A token inside its window can still be revoked at the other end, where only
				// the report says so.
				$error_data      = $ingestion_response->get_error_data();
				$error_status    = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
				// Only an outright rejection of the bearer is a reconnection. A 403 can come
				// from an edge in front of the content API, or a scope the grant never had,
				// and reconnecting cures neither.
				$needs_reconnect = 401 === $error_status;
				$is_unreachable  = ! $needs_reconnect;

				// Recorded, not just reported: the reconnect offered leads to the settings
				// card, which would otherwise still show the connection as working. A refusal
				// is consulted ahead of any refresh, so recording one before trying the refresh
				// token would foreclose the only recovery short of re-authorising.
				if ( $needs_reconnect ) {
					if ( ! Auth::renew_rejected_token( $token_used ) ) {
						Auth::record_token_refusal( [ 'access_token' => $token_used ] );
					}
					// The recorder declines if another request rotated the token meanwhile.
					$needs_reconnect = ! Auth::has_usable_token();
					$is_unreachable  = ! $needs_reconnect;
				}
			}

			if ( ! is_wp_error( $ingestion_response ) &&
				isset( $ingestion_response['results'] ) &&
				is_array( $ingestion_response['results'] )
			) {
				foreach ( $ingestion_response['results'] as $result ) {
					if ( isset( $result['guid'] ) && $result['guid'] === $guid ) {
						// The editor renders both directly, so an unexpected shape takes the sidebar
						// down rather than degrading. What Nextdoor sends is not ours to guarantee.
						$ingestion_status     = isset( $result['status'] ) && is_scalar( $result['status'] ) ? (string) $result['status'] : null;
						$ingestion_error_msgs = isset( $result['error_msgs'] ) && is_array( $result['error_msgs'] )
							? array_values( array_filter( $result['error_msgs'], 'is_string' ) )
							: [];
						break;
					}
				}
			}

			if ( 'deleted' === $ingestion_status ) {
				update_post_meta( $post_id, '_nextdoor_deleted_at', current_time( 'mysql' ) );
				delete_post_meta( $post_id, '_nextdoor_shared_at' );
				delete_post_meta( $post_id, '_nextdoor_updated_at' );
			}
		}

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
			'needs_reconnect'  => $needs_reconnect,
			'needs_setup'      => $needs_setup,
			'is_unreachable'   => $is_unreachable,
			// Reconnecting lives in the settings wizard, which is `manage_options`, so the
			// remedy offered has to match what this user can reach.
			'can_reconnect'    => current_user_can( 'manage_options' ),
		];

		return rest_ensure_response( $response );
	}

	/**
	 * Normalize a publication URL before it is validated or stored.
	 *
	 * Schemes are case-insensitive per RFC 3986, so a mixed-case one is legal and must not
	 * be turned away by the validator's canonical-form check.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return string
	 */
	private static function normalize_publication_url( $value ) {
		$url    = trim( (string) $value );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( is_string( $scheme ) && '' !== $scheme ) {
			$url = strtolower( $scheme ) . substr( $url, strlen( $scheme ) );
		}

		return $url;
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
