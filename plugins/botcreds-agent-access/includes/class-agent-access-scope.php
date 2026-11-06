<?php
/**
 * Scope Enforcement — restrict agent credentials to specific REST routes.
 *
 * Scope is stored as a JSON-encoded array of post type slugs in user meta,
 * keyed by app-password UUID.
 *
 * Storage format (v2):  JSON array of slugs, e.g. ["post","page","attachment"]
 * Special values:
 *   ["*"]              — full write access (all routes)
 *   ["__read_only__"]  — read only, block all writes with 403
 *
 * Legacy format (v1):  a plain string template key ("posts", "posts_media",
 *   "full", "read_only") — migrated on first read via migrate_legacy_scope().
 *
 * @package BotCreds Agent Access
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Scope {

	const META_PREFIX    = '_agent_access_scope_';
	const DEFAULT_SCOPE  = 'posts_media';   // kept for back-compat; maps to ['post','attachment']
	const STORAGE_VERSION = 2;
	const ALL_CUSTOM_ENDPOINTS = '__custom_endpoints__';

	/** Post types excluded from the checklist. */
	private static $excluded_types = array(
		'wp_block',
		'wp_navigation',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
	);

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
		$slugs = self::get_slugs_for_current_request();
		if ( null === $slugs ) {
			return $result; // Not an agent request, or no scope set.
		}

		// Read-only scope — block all writes.
		if ( in_array( '__read_only__', $slugs, true ) ) {
			if ( $is_write ) {
				return new WP_Error(
					'agent_scope_violation',
					__( 'This agent credential is read-only. Write operations are not permitted.', 'botcreds-agent-access' ),
					array( 'status' => 403 )
				);
			}
			return $result;
		}

		// Pass GETs regardless of scope (reads are always allowed).
		if ( ! $is_write ) {
			return $result;
		}

		// Restrictive mode wins over full, content and integration grants, including legacy mixtures.
		if ( Agent_Access_Yoast_SEO::has_scope( $slugs ) ) {
			$valid = Agent_Access_Yoast_SEO::validate_write( $request );
			return is_wp_error( $valid ) ? $valid : $result;
		}

		// Wildcard — full write access.
		if ( in_array( '*', $slugs, true ) ) {
			return $result;
		}

		// Custom integrations never override the core WordPress scope.
		if ( in_array( self::ALL_CUSTOM_ENDPOINTS, $slugs, true ) && self::is_custom_route( $request->get_route() ) ) {
			return $result;
		}

		// Build allowed route patterns from the slug list.
		$allowed_routes = self::build_allowed_routes( $slugs );

		$route = $request->get_route();

		if ( ! self::route_matches_patterns( $route, $allowed_routes ) ) {
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
	// Slug array helpers
	// ──────────────────────────────────────────────

	/**
	 * Return the slug array for the current REST request, or null if not an
	 * agent request.
	 *
	 * @return array|null
	 */
	public static function get_slugs_for_current_request() {
		// Pro Auth token path.
		if ( ! empty( $GLOBALS['_agent_access_pro_token_row'] ) ) {
			$scope = $GLOBALS['_agent_access_pro_token_row']->scope ?? null;
			if ( null === $scope || '' === $scope ) {
				// Default: posts + attachment (posts_media equivalent).
				return array( 'post', 'attachment' );
			}
			// If the pro token row stores a v2 JSON slug array, use it.
			$decoded = json_decode( $scope, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			// Otherwise treat as legacy key.
			return self::migrate_legacy_scope( $scope );
		}

		// Authorization depends on the verified credential, never audit attribution.
		$uuid = Agent_Access_Activity_Log::get_authenticated_botcreds_uuid();
		if ( ! $uuid ) {
			return null;
		}

		$user_id = get_current_user_id();

		return self::get( $user_id, $uuid );
	}

	/**
	 * @deprecated Use get_slugs_for_current_request() instead.
	 */
	public static function get_scope_for_current_request() {
		return self::get_slugs_for_current_request();
	}

	/**
	 * Build a flat list of allowed REST route patterns for a slug array.
	 *
	 * @param array $slugs
	 * @return array
	 */
	private static function build_allowed_routes( array $slugs ) {
		$routes = array();
		$has_content_scope = false;

		// Fetch all REST-enabled types so we can resolve rest_base.
		$all_types = get_post_types( array(), 'objects' );

		foreach ( $slugs as $slug ) {
			// Literal route pattern (starts with /) — pass through as-is.
			if ( '/' === $slug[0] ) {
				$routes[] = $slug;
				// Also allow sub-routes if the pattern doesn't already end in /*
				if ( substr( $slug, -2 ) !== '/*' ) {
					$routes[] = $slug . '/*';
				}
				continue;
			}

			if ( '*' === $slug || '__read_only__' === $slug || self::ALL_CUSTOM_ENDPOINTS === $slug ) {
				continue;
			}

			// Find the rest_base for this post type.
			$has_content_scope = true;
			$rest_base = $slug; // fallback
			if ( isset( $all_types[ $slug ] ) ) {
				$rest_base = $all_types[ $slug ]->rest_base ?: $slug;
			}

			$routes[] = '/wp/v2/' . $rest_base;
			$routes[] = '/wp/v2/' . $rest_base . '/*';

			// Always allow media routes when attachment is included.
			if ( 'attachment' === $slug ) {
				// /wp/v2/media is the standard rest_base for attachment.
				// It's already covered via the rest_base lookup above, but
				// make sure we always add it explicitly.
				if ( 'media' !== $rest_base ) {
					$routes[] = '/wp/v2/media';
					$routes[] = '/wp/v2/media/*';
				}
			}
		}

		// Always allow taxonomy routes (categories/tags) when posting.
		// These are commonly needed alongside post types.
		if ( $has_content_scope ) {
			$routes[] = '/wp/v2/categories';
			$routes[] = '/wp/v2/categories/*';
			$routes[] = '/wp/v2/tags';
			$routes[] = '/wp/v2/tags/*';
		}

		return array_unique( $routes );
	}

	/**
	 * Check whether a route matches any of the allowed patterns.
	 *
	 * @param string $route    Actual request route.
	 * @param array  $patterns Allowed patterns (may include /* wildcards).
	 * @return bool
	 */
	private static function route_matches_patterns( $route, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( '*' === $pattern ) {
				return true;
			}

			if ( $route === $pattern ) {
				return true;
			}

			if ( substr( $pattern, -2 ) === '/*' ) {
				$prefix = substr( $pattern, 0, -2 );
				if ( 0 === strpos( $route, $prefix . '/' ) || $route === $prefix ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Core namespaces are never part of the custom-endpoint blanket grant.
	 * Reserve WordPress namespace prefixes, including future core namespaces.
	 *
	 * @param string $namespace REST namespace.
	 * @return bool
	 */
	public static function is_core_namespace( $namespace ) {
		return 'wp' === $namespace || 0 === strpos( $namespace, 'wp/' )
			|| 0 === strpos( $namespace, 'wp-' ) || 'oembed/1.0' === $namespace;
	}

	/**
	 * Validate a custom route prefix without transforming malformed grants.
	 *
	 * @param mixed $pattern Route prefix, optionally ending in /*.
	 * @return bool
	 */
	public static function is_valid_custom_pattern( $pattern ) {
		if ( ! is_string( $pattern ) || ! preg_match( '#^/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)*(?:/\*)?$#D', $pattern ) ) {
			return false;
		}
		foreach ( explode( '/', $pattern ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		$prefix = trim( $pattern, '/' );
		return ! self::is_core_namespace( $prefix ) && 'oembed' !== explode( '/', $prefix )[0];
	}

	/**
	 * Discover namespaces from registered REST routes, not installed plugin names.
	 * Registration is initialized by rest_get_server() on admin pages too.
	 *
	 * @return array
	 */
	public static function get_custom_integrations() {
		$labels = array(
			'newspack-workflows' => __( 'Newspack Workflows', 'botcreds-agent-access' ),
			'wc'                => __( 'WooCommerce', 'botcreds-agent-access' ),
			'wc-admin'          => __( 'WooCommerce Admin', 'botcreds-agent-access' ),
			'wc-analytics'      => __( 'WooCommerce Analytics', 'botcreds-agent-access' ),
			'wc/store'          => __( 'WooCommerce Store', 'botcreds-agent-access' ),
			'botcreds-memory'   => __( 'Agent Memory', 'botcreds-agent-access' ),
		);
		$result = array();
		$namespaces = rest_get_server()->get_namespaces();
		foreach ( $namespaces as $namespace ) {
			if ( self::is_core_namespace( $namespace ) || ! self::is_valid_custom_pattern( '/' . $namespace . '/*' ) ) {
				continue;
			}
			// A parent namespace already grants all descendants; do not list them twice.
			foreach ( $namespaces as $parent ) {
				if ( $parent !== $namespace && ! self::is_core_namespace( $parent ) && 0 === strpos( $namespace, $parent . '/' ) ) {
					continue 2;
				}
			}
			$parts   = explode( '/', $namespace );
			$version = array_pop( $parts );
			$name    = implode( '/', $parts );
			$label   = $labels[ $name ] ?? ucwords( str_replace( array( '-', '_', '/' ), ' ', $name ?: $namespace ) );
			$result[] = array(
				'slug'  => '/' . $namespace . '/*',
				'label' => $label . ( $name ? ' (' . $version . ')' : '' ),
			);
		}
		usort( $result, static function ( $a, $b ) { return strcasecmp( $a['label'], $b['label'] ); } );
		return $result;
	}

	/**
	 * Only registered, non-core namespaces qualify for the blanket grant.
	 *
	 * @param string $route REST request route.
	 * @return bool
	 */
	private static function is_custom_route( $route ) {
		foreach ( self::get_custom_integrations() as $integration ) {
			if ( self::route_matches_patterns( $route, array( $integration['slug'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ──────────────────────────────────────────────
	// Scope storage (v2)
	// ──────────────────────────────────────────────

	/**
	 * Save a scope for an app-password credential.
	 *
	 * @param int    $user_id   WP user id.
	 * @param string $uuid      App password UUID.
	 * @param array  $post_types Array of post type slugs, e.g. ['post','page'].
	 *                           Pass ['*'] for full, ['__read_only__'] for read-only.
	 */
	public static function save( $user_id, $uuid, $post_types ) {
		if ( ! is_array( $post_types ) ) {
			// Accept legacy template key for back-compat with callers
			// that still pass a string.
			$post_types = self::migrate_legacy_scope( (string) $post_types );
		}

		// Sanitise each slug.
		$clean = array();
		foreach ( $post_types as $slug ) {
			// Literal route patterns start with / — preserve them as-is.
			if ( is_string( $slug ) && isset( $slug[0] ) && '/' === $slug[0] ) {
				$clean[] = $slug;
				continue;
			}
			$s = sanitize_key( $slug );
			if ( '' !== $s ) {
				$clean[] = $s;
			}
		}

		update_user_meta( (int) $user_id, self::META_PREFIX . $uuid, wp_json_encode( $clean ) );
	}

	/**
	 * Get the slug array saved for a user+UUID pair.
	 *
	 * Automatically migrates legacy template-key strings.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @return array Slug array.
	 */
	public static function get( $user_id, $uuid ) {
		$raw = get_user_meta( (int) $user_id, self::META_PREFIX . $uuid, true );

		if ( ! $raw || '' === $raw ) {
			// Credentials created before scope was introduced default to full
			// so existing deployments don't suddenly break.
			return array( '*' );
		}

		// Try v2 JSON decode.
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Legacy: treat as template key.
		$migrated = self::migrate_legacy_scope( $raw );
		// Persist the migrated value.
		self::save( $user_id, $uuid, $migrated );

		return $migrated;
	}

	/**
	 * Convert a legacy template key to a v2 slug array.
	 *
	 * @param string $template_key Legacy key.
	 * @return array
	 */
	public static function migrate_legacy_scope( $template_key ) {
		switch ( $template_key ) {
			case 'posts':
				return array( 'post' );
			case 'posts_media':
				return array( 'post', 'attachment' );
			case 'full':
				return array( '*' );
			case 'read_only':
				return array( '__read_only__' );
			default:
				return array( '*' ); // Fail open.
		}
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
	// Available post types for the checklist
	// ──────────────────────────────────────────────

	/**
	 * Return post types suitable for the scope checklist.
	 *
	 * Returns an array of assoc arrays:
	 *   ['slug' => string, 'label' => string, 'rest_base' => string]
	 *
	 * Built-in WP types (post, page, attachment) are listed first, then
	 * remaining types alphabetically.
	 *
	 * @return array
	 */
	public static function get_available_post_types() {
		$types = get_post_types(
			array(
				'show_in_rest' => true,
				'public'       => true,
			),
			'objects'
		);

		$result  = array();
		$builtin = array( 'post', 'page', 'attachment' );

		foreach ( $builtin as $slug ) {
			if ( isset( $types[ $slug ] ) ) {
				$obj      = $types[ $slug ];
				$result[] = array(
					'slug'      => $slug,
					'label'     => $obj->labels->singular_name ?? ucfirst( $slug ),
					'rest_base' => $obj->rest_base ?: $slug,
				);
			}
		}

		// Sort remaining alphabetically.
		$remaining = array();
		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, $builtin, true ) ) {
				continue;
			}
			if ( in_array( $slug, self::$excluded_types, true ) ) {
				continue;
			}
			$remaining[ $slug ] = $obj;
		}
		ksort( $remaining );

		foreach ( $remaining as $slug => $obj ) {
			$result[] = array(
				'slug'      => $slug,
				'label'     => $obj->labels->singular_name ?? ucfirst( $slug ),
				'rest_base' => $obj->rest_base ?: $slug,
			);
		}

		return $result;
	}

	// ──────────────────────────────────────────────
	// Display helpers
	// ──────────────────────────────────────────────

	/**
	 * Return a human-readable label for a stored slug array.
	 *
	 * @param array|string $scope Slug array (or legacy string for compat).
	 * @return string
	 */
	public static function get_label( $scope ) {
		if ( ! is_array( $scope ) ) {
			$scope = self::migrate_legacy_scope( (string) $scope );
		}

		if ( empty( $scope ) ) {
			return __( 'None', 'botcreds-agent-access' );
		}

		if ( in_array( '__read_only__', $scope, true ) ) {
			return __( 'Read only', 'botcreds-agent-access' );
		}

		if ( Agent_Access_Yoast_SEO::has_scope( $scope ) ) {
			return __( 'Yoast SEO only', 'botcreds-agent-access' );
		}

		if ( in_array( '*', $scope, true ) ) {
			return __( 'Full write access', 'botcreds-agent-access' );
		}

		// Resolve slug → label.
		$all_types = get_post_types( array(), 'objects' );
		$labels    = array();
		foreach ( $scope as $slug ) {
			if ( self::ALL_CUSTOM_ENDPOINTS === $slug ) {
				$labels[] = __( 'All custom endpoints', 'botcreds-agent-access' );
			} elseif ( isset( $all_types[ $slug ] ) ) {
				$labels[] = $all_types[ $slug ]->labels->singular_name ?? ucfirst( $slug );
			} else {
				$labels[] = ucfirst( $slug );
			}
		}

		return implode( ', ', $labels );
	}

	// ──────────────────────────────────────────────
	// Legacy: Templates (deprecated — kept for migration reference)
	// ──────────────────────────────────────────────

	/**
	 * @deprecated 2.3.0 Use get_available_post_types() + slug arrays.
	 *
	 * @return array
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
				'methods'     => array(),
			),
		);
	}

	/**
	 * @deprecated 2.3.0
	 */
	public static function get_template( $key ) {
		$templates = self::get_templates();
		return $templates[ $key ] ?? null;
	}
}
