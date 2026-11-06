<?php
/**
 * Narrow Yoast metadata API. Never calls wp_update_post() or generic meta writes.
 *
 * @package BotCreds Agent Access
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Yoast_SEO {
	const SCOPE = '__yoast_seo_only__';
	const ROUTE = '/agent-access/v1/yoast-seo/';
	const FIELDS = array( 'title', 'metadesc', 'focuskw', 'opengraph-title', 'opengraph-description', 'twitter-title', 'twitter-description' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function available() {
		return defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Meta' );
	}

	public static function register_routes() {
		register_rest_route( 'agent-access/v1', '/yoast-seo/(?P<id>[1-9][0-9]*)', array(
			'methods' => 'GET,POST,PUT,PATCH',
			'permission_callback' => array( __CLASS__, 'permissions' ),
			'callback' => array( __CLASS__, 'handle' ),
		) );
	}

	public static function permissions( $request ) {
		if ( ! self::available() ) {
			return new WP_Error( 'agent_seo_unavailable', __( 'Yoast SEO must be active.', 'botcreds-agent-access' ), array( 'status' => 503 ) );
		}
		$id = (int) $request->get_url_params()['id'];
		$post = get_post( $id );
		$type = $post ? get_post_type_object( $post->post_type ) : null;
		if ( ! $post || ! $type || ! $type->show_in_rest || in_array( $post->post_type, array( 'attachment', 'revision' ), true ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new WP_Error( 'agent_seo_invalid_post', __( 'An existing editable content item is required.', 'botcreds-agent-access' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot edit SEO for this content item.', 'botcreds-agent-access' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/** Strict JSON allowlist, checked in full before the first durable write. */
	public static function validate_write( $request ) {
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || ! preg_match( '#^/agent-access/v1/yoast-seo/[1-9][0-9]*$#D', $request->get_route() ) ) {
			return new WP_Error( 'agent_scope_violation', __( 'SEO-only credentials may only update the dedicated Yoast SEO endpoint.', 'botcreds-agent-access' ), array( 'status' => 403 ) );
		}
		$json = json_decode( $request->get_body() );
		if ( ! $request->is_json_content_type() || ! is_object( $json ) || empty( (array) $json ) || $request->get_body_params() || $request->get_file_params() || $request->get_query_params() ) {
			return new WP_Error( 'agent_seo_invalid_payload', __( 'Send a non-empty JSON object containing only supported Yoast fields; query, form and file parameters are not allowed.', 'botcreds-agent-access' ), array( 'status' => 400 ) );
		}
		foreach ( (array) $json as $key => $value ) {
			if ( ! in_array( $key, self::FIELDS, true ) || ! is_string( $value ) || strlen( $value ) > 10000 ) {
				return new WP_Error( 'agent_seo_invalid_field', __( 'Only supported Yoast text fields are allowed. Content, status and other metadata cannot be changed.', 'botcreds-agent-access' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	public static function handle( $request ) {
		$id = (int) $request->get_url_params()['id'];
		if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			$valid = self::validate_write( $request );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			foreach ( $request->get_json_params() as $key => $value ) {
				$value = sanitize_text_field( $value );
				WPSEO_Meta::set_value( $key, $value, $id );
				if ( WPSEO_Meta::get_value( $key, $id ) !== $value ) {
					return new WP_Error( 'agent_seo_save_failed', __( 'A Yoast field could not be saved. Read back the current values before retrying.', 'botcreds-agent-access' ), array( 'status' => 500 ) );
				}
			}
		}
		$data = array();
		foreach ( self::FIELDS as $field ) {
			$data[ $field ] = WPSEO_Meta::get_value( $field, $id );
		}
		return rest_ensure_response( array( 'id' => $id, 'yoast' => $data ) );
	}
}
