<?php
/**
 * Scope Enforcement — restrict agent credentials to specific REST routes.
 *
 * Scope is stored as a template key in user meta, keyed by app-password UUID.
 * Enforcement hooks rest_pre_dispatch at priority 5 (before the activity log
 * at priority 10, so blocked requests are never logged as successful writes).
 *
 * Predefined templates:
 *   posts       — POST/PUT/PATCH/DELETE on /wp/v2/posts + /wp/v2/categories + /wp/v2/tags
 *   posts_media — above + /wp/v2/media  (recommended default)
 *   full        — no route restriction (WP role is the only limit)
 *
 * @package BotCreds Agent Access
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Scope {

	const META_PREFIX  = '_agent_access_scope_';
	const DEFAULT_SCOPE = 'posts_media';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 5, 3 );
	}

	// ──────────────────────────────────────────────
	// Enforcement
	// ──────────────────────────────────────────────

	/**
	 * Block write requests that fall outside the agent's permitted scope.
	 *
	 * Priority 5 — runs before the activity log (10) so blocked requests
	 * are never recorded as successful writes.
	 *
	 * @param mixed            $result  null (pass-through) or existing response.
	 * @param WP_REST_Server   $server  REST server.
	 * @param WP_REST_Request  $request Current request.
	 * @return mixed Original $result, or WP_Error on scope violation.
	 */
	public static function enforce( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result; // Short-circuited upstream — don't interfere.
		}

		$method   = strtoupper( $request->get_method() );
		$is_write = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );

		// Only enforce on authenticated agent requests.
		$template_key = self::get_scope_for_current_request();
		if ( null === $template_key ) {
			return $result; // Not an agent request, or no scope set.
		}

		// For read_only scope, block all writes regardless of route.
		if ( 'read_only' === $template_key && $is_write ) {
			return new WP_Error(
				'agent_scope_violation',
				__( 'This agent credential is read-only. Write operations are not permitted.', 'botcreds-agent-access' ),
				array( 'status' => 403 )
			);
		}

		// For non-read_only scopes, pass GET/HEAD/OPTIONS through.
		if ( ! $is_write ) {
			return $result;
		}

		// 'full' scope — no route restriction.
		if ( 'full' === $template_key ) {
			return $result;
		}

		$template = self::get_template( $template_key );
		if ( ! $template ) {
			return $result; // Unknown template — fail open.
		}

		$route = $request->get_route();

		if ( ! self::route_is_allowed( $route, $method, $template ) ) {
			return new WP_Error(
				'agent_scope_violation',
				sprintf(
					/* translators: 1: HTTP method, 2: REST route */
					__( 'This agent is not authorised to perform %1$s on %2$s. Check the agent\'s scope in Agent Access settings.', 'botcreds-agent-access' ),
					$method,
					$route
				),
				array( 'status' => 403 )
			);
		}

		return $result;
	}

	// ──────────────────────────────────────────────
	// Scope lookup
	// ──────────────────────────────────────────────

	/**
	 * Return the scope template key for the current REST request, or null
	 * if the request is not from an Agent Access credential.
	 *
	 * @return string|null Template key, or null.
	 */
	public static function get_scope_for_current_request() {
		// Pro Auth token path.
		if ( ! empty( $GLOBALS['_agent_access_pro_token_row'] ) ) {
			$scope = $GLOBALS['_agent_access_pro_token_row']->scope ?? null;
			return $scope ?: self::DEFAULT_SCOPE;
		}

		// App password path.
		$uuid = rest_get_authenticated_app_password();
		if ( ! $uuid ) {
			return null;
		}

		// Only govern Agent Access credentials.
		$source = Agent_Access_Activity_Log::detect_source();
		if ( null === $source || Agent_Access_Activity_Log::SOURCE_AA !== $source['source'] ) {
			return null;
		}

		$user_id      = get_current_user_id();
		$template_key = get_user_meta( $user_id, self::META_PREFIX . $uuid, true );

		// Credentials created before scope was introduced default to 'full'
		// so existing deployments don't suddenly break.
		return $template_key ?: 'full';
	}

	// ──────────────────────────────────────────────
	// Scope storage
	// ──────────────────────────────────────────────

	/**
	 * Save a scope template for an app-password credential.
	 *
	 * @param int    $user_id      WP user id.
	 * @param string $uuid         App password UUID.
	 * @param string $template_key One of 'posts', 'posts_media', 'full'.
	 */
	public static function save( $user_id, $uuid, $template_key ) {
		$allowed = array_keys( self::get_templates() );
		if ( ! in_array( $template_key, $allowed, true ) ) {
			$template_key = self::DEFAULT_SCOPE;
		}
		update_user_meta( (int) $user_id, self::META_PREFIX . $uuid, $template_key );
	}

	/**
	 * Get the scope template key saved for a user+UUID pair.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @return string Template key, or 'full' if none set.
	 */
	public static function get( $user_id, $uuid ) {
		$key = get_user_meta( (int) $user_id, self::META_PREFIX . $uuid, true );
		return $key ?: 'full';
	}

	/**
	 * Delete the scope meta when an app password is revoked.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 */
	public static function delete( $user_id, $uuid ) {
		delete_user_meta( (int) $user_id, self::META_PREFIX . $uuid );
	}

	// ──────────────────────────────────────────────
	// Route matching
	// ──────────────────────────────────────────────

	/**
	 * Check whether a route + method pair is permitted by a template.
	 *
	 * Route patterns in templates support a trailing /* wildcard:
	 *   /wp/v2/posts   — exact match only
	 *   /wp/v2/posts/* — matches /wp/v2/posts/123, /wp/v2/posts/123/revisions, …
	 *   *              — matches everything (used by 'full')
	 *
	 * @param string $route    Actual request route (e.g. /wp/v2/posts/42).
	 * @param string $method   HTTP method (upper-case).
	 * @param array  $template Scope template array.
	 * @return bool
	 */
	private static function route_is_allowed( $route, $method, $template ) {
		$allowed_methods = $template['methods'] ?? array( 'POST', 'PUT', 'PATCH', 'DELETE' );
		if ( ! in_array( $method, $allowed_methods, true ) ) {
			return false;
		}

		$allowed_routes = $template['routes'] ?? array();

		foreach ( $allowed_routes as $pattern ) {
			if ( '*' === $pattern ) {
				return true;
			}

			// Exact match.
			if ( $route === $pattern ) {
				return true;
			}

			// Wildcard prefix: /wp/v2/posts/* matches /wp/v2/posts/123.
			if ( substr( $pattern, -2 ) === '/*' ) {
				$prefix = substr( $pattern, 0, -2 );
				if ( strpos( $route, $prefix . '/' ) === 0 || $route === $prefix ) {
					return true;
				}
			}
		}

		return false;
	}

	// ──────────────────────────────────────────────
	// Templates
	// ──────────────────────────────────────────────

	/**
	 * Return all scope templates.
	 *
	 * @return array<string, array{label: string, description: string, routes: string[], methods: string[]}>
	 */
	public static function get_templates() {
		return array(
			'posts'       => array(
				'label'       => __( 'Posts only', 'botcreds-agent-access' ),
				'description' => __( 'Create and edit posts, categories, and tags.', 'botcreds-agent-access' ),
				'routes'      => array(
					'/wp/v2/posts',
					'/wp/v2/posts/*',
					'/wp/v2/categories',
					'/wp/v2/categories/*',
					'/wp/v2/tags',
					'/wp/v2/tags/*',
				),
				'methods'     => array( 'POST', 'PUT', 'PATCH', 'DELETE' ),
			),
			'posts_media' => array(
				'label'       => __( 'Posts + Media', 'botcreds-agent-access' ),
				'description' => __( 'Create and edit posts; upload and manage media. Recommended for most agents.', 'botcreds-agent-access' ),
				'routes'      => array(
					'/wp/v2/posts',
					'/wp/v2/posts/*',
					'/wp/v2/media',
					'/wp/v2/media/*',
					'/wp/v2/categories',
					'/wp/v2/categories/*',
					'/wp/v2/tags',
					'/wp/v2/tags/*',
				),
				'methods'     => array( 'POST', 'PUT', 'PATCH', 'DELETE' ),
			),
			'full'        => array(
				'label'       => __( 'Full write access', 'botcreds-agent-access' ),
				'description' => __( 'All write operations permitted by the user\'s WP role. Use with care.', 'botcreds-agent-access' ),
				'routes'      => array( '*' ),
				'methods'     => array( 'POST', 'PUT', 'PATCH', 'DELETE' ),
			),
			'read_only'   => array(
				'label'       => __( 'Read only', 'botcreds-agent-access' ),
				'description' => __( 'Read any content (GET requests only). All write operations are blocked.', 'botcreds-agent-access' ),
				'routes'      => array( '*' ),
				'methods'     => array(), // No write methods permitted.
			),
		);
	}

	/**
	 * Return a single template by key, or null.
	 *
	 * @param string $key
	 * @return array|null
	 */
	public static function get_template( $key ) {
		$templates = self::get_templates();
		return $templates[ $key ] ?? null;
	}

	/**
	 * Return the display label for a scope key.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function get_label( $key ) {
		$template = self::get_template( $key );
		return $template ? $template['label'] : ucfirst( $key );
	}
}
