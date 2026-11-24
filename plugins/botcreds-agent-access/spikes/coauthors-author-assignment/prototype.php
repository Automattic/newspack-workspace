<?php
/**
 * Disposable QA author-assignment spike. NOT loaded by the shipping plugin.
 * A fixture administrator provisions a separate, per-credential test grant.
 * Production requires real administrator controls and client author discovery.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AA_CAP_Author_Probe {
	const GRANT_PREFIX = '_aa_cap_author_probe_';

	public static function error( $code, $message, $status = 403 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function register() {
		register_rest_route( 'agent-access-probe/v1', '/authors/(?P<id>[1-9][0-9]*)', array(
			'methods' => 'GET,POST',
			'permission_callback' => array( __CLASS__, 'permissions' ),
			'callback' => array( __CLASS__, 'handle' ),
		) );
	}

	public static function permissions( $request ) {
		if ( ! class_exists( 'Agent_Access_Activity_Log' ) ) {
			return self::error( 'probe_unavailable', 'Agent Access is unavailable.', 503 );
		}
		$uuid = Agent_Access_Activity_Log::get_authenticated_botcreds_uuid();
		if ( ! $uuid || '1' !== get_user_meta( get_current_user_id(), self::GRANT_PREFIX . $uuid, true ) ) {
			return self::error( 'probe_grant_required', 'A separate administrator-issued author-assignment grant is required.' );
		}
		global $coauthors_plus;
		if ( ! is_object( $coauthors_plus ) || ! method_exists( $coauthors_plus, 'add_coauthors' ) ) {
			return self::error( 'probe_dependency_unavailable', 'Co-Authors Plus is unavailable.', 503 );
		}
		$id = (int) $request->get_url_params()['id'];
		$post = get_post( $id );
		$type = $post ? get_post_type_object( $post->post_type ) : null;
		if ( ! $type || ! $type->show_in_rest || ! $coauthors_plus->is_post_type_enabled( $post->post_type ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::error( 'probe_invalid_post', 'An existing supported content item is required.', 404 );
		}
		$scope = Agent_Access_Scope::get_slugs_for_current_request();
		if ( ! is_array( $scope ) || ( ! in_array( '*', $scope, true ) && ! in_array( $post->post_type, $scope, true ) ) ) {
			return self::error( 'probe_post_type_scope', 'This content type is outside the credential scope.' );
		}
		// Never grant a new editing foothold by allowing agents to add themselves
		// to unrelated content. CAP maps existing co-authorship to editing rights.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::error( 'probe_post_forbidden', 'You must already be allowed to edit this post.' );
		}
		return true;
	}

	public static function term_ids( $id ) {
		global $coauthors_plus;
		$ids = array();
		foreach ( get_coauthors( $id ) as $author ) {
			$term = $coauthors_plus->get_author_term( $author );
			if ( ! $term || is_wp_error( $term ) ) {
				return self::error( 'probe_read_failed', 'An author cannot be resolved.', 500 );
			}
			$ids[] = (int) $term->term_id;
		}
		return $ids;
	}

	/** Resolve through CAP, then require a canonical term round-trip (3.x/4.x). */
	public static function author_for_term( $term ) {
		global $coauthors_plus;
		if ( method_exists( $coauthors_plus, 'get_coauthor_by_term' ) ) {
			$author = $coauthors_plus->get_coauthor_by_term( $term );
		} else {
			// CAP 3.x uses this same resolver in its get_coauthors() template tag.
			$author = $coauthors_plus->get_coauthor_by( 'user_nicename', preg_replace( '#^cap-#', '', $term->slug ) );
		}
		$canonical = is_object( $author ) ? $coauthors_plus->get_author_term( $author ) : null;
		return $canonical && ! is_wp_error( $canonical ) && (int) $canonical->term_id === (int) $term->term_id ? $author : null;
	}

	public static function handle( $request ) {
		global $coauthors_plus;
		$id = (int) $request->get_url_params()['id'];
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			$authors = self::term_ids( $id );
			return is_wp_error( $authors ) ? $authors : rest_ensure_response( array( 'id' => $id, 'authors' => $authors ) );
		}
		$body = json_decode( $request->get_body() );
		if ( ! $request->is_json_content_type() || ! is_object( $body ) || array_keys( (array) $body ) !== array( 'authors' ) || ! is_array( $body->authors ) || ! count( $body->authors ) || count( $body->authors ) > 20 || $request->get_query_params() || $request->get_body_params() || $request->get_file_params() ) {
			return self::error( 'probe_invalid_payload', 'Send only a non-empty authors array of existing CAP author-term IDs as JSON.', 400 );
		}
		$ids = array();
		$logins = array();
		foreach ( $body->authors as $term_id ) {
			if ( ! is_int( $term_id ) || $term_id < 1 || in_array( $term_id, $ids, true ) ) {
				return self::error( 'probe_invalid_author', 'Author IDs must be distinct positive integers.', 400 );
			}
			$term = get_term( $term_id, $coauthors_plus->coauthor_taxonomy );
			$author = $term && ! is_wp_error( $term ) ? self::author_for_term( $term ) : null;
			if ( ! is_object( $author ) || empty( $author->user_login ) ) {
				return self::error( 'probe_invalid_author', 'Every author must resolve to an existing CAP author.', 400 );
			}
			$ids[] = $term_id;
			$logins[] = $author->user_login;
		}
		$before = get_post( $id, ARRAY_A );
		// The supported CAP setter preserves ordered author terms and understands
		// guests. Its boolean is insufficient: guest-only assignments can return
		// false after successfully updating terms, so verify durable state instead.
		$coauthors_plus->add_coauthors( $id, $logins, false, 'user_login' );
		clean_post_cache( $id );
		$after = get_post( $id, ARRAY_A );
		unset( $before['post_author'], $after['post_author'] );
		$actual = self::term_ids( $id );
		if ( $before !== $after || is_wp_error( $actual ) || $actual !== $ids ) {
			return self::error( 'probe_write_unverified', 'Assignment did not verify completely; inspect current state before retrying. Writes may have partially applied.', 500 );
		}
		return rest_ensure_response( array( 'id' => $id, 'authors' => $actual ) );
	}
}
add_action( 'rest_api_init', array( 'AA_CAP_Author_Probe', 'register' ) );
