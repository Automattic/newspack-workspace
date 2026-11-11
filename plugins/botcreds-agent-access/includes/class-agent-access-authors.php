<?php
/**
 * Administrator-authorized, byline-only Co-Authors Plus assignment.
 * No broad role or global Co-Authors Plus permission changes.
 * @package BotCreds Agent Access
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Authors {
	const SCOPE = '__assign_authors__';

	public static function error( $code, $message, $status = 403 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function can_assign( $user_id ) {
		return Agent_Access_Yoast_SEO::can_assign( $user_id );
	}

	public static function available() {
		global $coauthors_plus;
		return is_object( $coauthors_plus ) && method_exists( $coauthors_plus, 'add_coauthors' );
	}

	public static function register() {
		register_rest_route( 'agent-access/v1', '/authors/(?P<id>[1-9][0-9]*)', array(
			'methods' => 'GET,POST',
			'permission_callback' => array( __CLASS__, 'permissions' ),
			'callback' => array( __CLASS__, 'handle' ),
		) );
		register_rest_route( 'agent-access/v1', '/authors/(?P<id>[1-9][0-9]*)/available', array(
			'methods' => 'GET',
			'permission_callback' => array( __CLASS__, 'permissions' ),
			'callback' => array( __CLASS__, 'search' ),
		) );
	}

	public static function permissions( $request ) {
		if ( ! class_exists( 'Agent_Access_Activity_Log' ) ) {
			return self::error( 'agent_authors_unavailable', __( 'Agent Access is unavailable.', 'botcreds-agent-access' ), 503 );
		}
		$uuid = Agent_Access_Activity_Log::get_authenticated_botcreds_uuid();
		$scope = Agent_Access_Scope::get_slugs_for_current_request();
		if ( ! $uuid || ! is_array( $scope ) || ! in_array( self::SCOPE, $scope, true ) || in_array( '__read_only__', $scope, true ) || Agent_Access_Yoast_SEO::has_scope( $scope ) ) {
			return self::error( 'agent_authors_grant_required', __( 'An administrator must enable author assignment for this credential.', 'botcreds-agent-access' ) );
		}
		global $coauthors_plus;
		if ( ! self::available() ) {
			return self::error( 'agent_authors_dependency_unavailable', __( 'Co-Authors Plus is unavailable.', 'botcreds-agent-access' ), 503 );
		}
		$id = (int) $request->get_url_params()['id'];
		$post = get_post( $id );
		$type = $post ? get_post_type_object( $post->post_type ) : null;
		if ( ! $type || ! $type->show_in_rest || ! $coauthors_plus->is_post_type_enabled( $post->post_type ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return self::error( 'agent_authors_invalid_post', __( 'An existing supported content item is required.', 'botcreds-agent-access' ), 404 );
		}
		$scope = Agent_Access_Scope::get_slugs_for_current_request();
		if ( ! is_array( $scope ) || ( ! in_array( '*', $scope, true ) && ! in_array( $post->post_type, $scope, true ) ) ) {
			return self::error( 'agent_authors_post_type_scope', __( 'This content type is outside the credential scope.', 'botcreds-agent-access' ) );
		}
		// Never grant a new editing foothold by allowing agents to add themselves
		// to unrelated content. CAP maps existing co-authorship to editing rights.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::error( 'agent_authors_post_forbidden', __( 'You must already be allowed to edit this post.', 'botcreds-agent-access' ) );
		}
		return true;
	}

	public static function term_ids( $id ) {
		global $coauthors_plus;
		$ids = array();
		foreach ( get_coauthors( $id ) as $author ) {
			$term = $coauthors_plus->get_author_term( $author );
			if ( ! $term || is_wp_error( $term ) ) {
				return self::error( 'agent_authors_read_failed', __( 'An author cannot be resolved.', 'botcreds-agent-access' ), 500 );
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
			return self::error( 'agent_authors_invalid_payload', __( 'Send only a non-empty authors array of existing CAP author-term IDs as JSON.', 'botcreds-agent-access' ), 400 );
		}
		$ids = array();
		$logins = array();
		foreach ( $body->authors as $term_id ) {
			if ( ! is_int( $term_id ) || $term_id < 1 || in_array( $term_id, $ids, true ) ) {
				return self::error( 'agent_authors_invalid_author', __( 'Author IDs must be distinct positive integers.', 'botcreds-agent-access' ), 400 );
			}
			$term = get_term( $term_id, $coauthors_plus->coauthor_taxonomy );
			$author = $term && ! is_wp_error( $term ) ? self::author_for_term( $term ) : null;
			if ( ! is_object( $author ) || empty( $author->user_login ) ) {
				return self::error( 'agent_authors_invalid_author', __( 'Every author must resolve to an existing CAP author.', 'botcreds-agent-access' ), 400 );
			}
			$ids[] = $term_id;
			$logins[] = $author->user_login;
		}
		$old_authors = self::term_ids( $id );
		if ( is_wp_error( $old_authors ) ) { return $old_authors; }
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
			return self::error( 'agent_authors_write_unverified', __( 'Assignment did not verify completely; inspect current state before retrying. Writes may have partially applied.', 'botcreds-agent-access' ), 500 );
		}
		$history = get_post_meta( $id, '_agent_access_author_history', true );
		$history = is_array( $history ) ? $history : array();
		$history[] = array( 'user_id' => get_current_user_id(), 'uuid' => Agent_Access_Activity_Log::get_authenticated_botcreds_uuid(), 'at' => gmdate( 'c' ), 'old_authors' => $old_authors, 'new_authors' => $actual );
		update_post_meta( $id, '_agent_access_author_history', array_slice( $history, -20 ) );
		do_action( 'agent_access_audit', 'authors_assigned', array( 'post_id' => $id, 'old_authors' => $old_authors, 'new_authors' => $actual ) );
		return rest_ensure_response( array( 'id' => $id, 'authors' => $actual, 'can_edit_after' => current_user_can( 'edit_post', $id ) ) );
	}
	/** Minimal CAP discovery: current canonical term IDs and public display names only. */
	public static function search( $request ) {
		global $coauthors_plus;
		$query = $request->get_query_params();
		if ( array_diff( array_keys( $query ), array( 'search', 'page' ) ) || ( isset( $query['search'] ) && ( ! is_string( $query['search'] ) || strlen( $query['search'] ) > 100 ) ) || ( isset( $query['page'] ) && ( ! is_string( $query['page'] ) || ! preg_match( '/^[1-9][0-9]{0,3}$/D', $query['page'] ) ) ) ) {
			return self::error( 'agent_authors_invalid_search', __( 'Use search (up to 100 characters) and a positive page number.', 'botcreds-agent-access' ), 400 );
		}
		$page = isset( $query['page'] ) ? (int) $query['page'] : 1;
		$limit = static function ( $args ) use ( $page ) {
			$args['number'] = 21;
			$args['offset'] = ( $page - 1 ) * 20;
			$args['orderby'] = 'name';
			$args['order'] = 'ASC';
			return $args;
		};
		add_filter( 'coauthors_search_authors_get_terms_args', $limit );
		try {
			$found = array_values( $coauthors_plus->search_authors( isset( $query['search'] ) ? sanitize_text_field( $query['search'] ) : '' ) );
		} finally {
			remove_filter( 'coauthors_search_authors_get_terms_args', $limit );
		}
		$more = count( $found ) > 20;
		$authors = array();
		foreach ( array_slice( $found, 0, 20 ) as $author ) {
			$term = $coauthors_plus->get_author_term( $author );
			if ( $term && ! is_wp_error( $term ) && self::author_for_term( $term ) ) {
				$authors[] = array( 'id' => (int) $term->term_id, 'name' => $author->display_name );
			}
		}
		return rest_ensure_response( array( 'authors' => $authors, 'page' => $page, 'has_more' => $more ) );
	}

}
