<?php
/**
 * Agent Access Activity Log — tracks all REST API activity from Agent Access
 * app passwords and WordPress.com MCP connections.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Activity_Log {

	const TABLE_NAME     = 'agent_access_log';
	const SOURCE_AA      = 'agent-access';
	const SOURCE_WP_MCP  = 'wordpress-mcp';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'rest_pre_dispatch', array( $this, 'log_request' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Log a REST request if it originated from a tracked source.
	 *
	 * @param mixed           $result  The existing result (pass through unchanged).
	 * @param WP_REST_Server  $server  The REST server instance.
	 * @param WP_REST_Request $request The current request.
	 * @return mixed  Unchanged $result.
	 */
	public function log_request( $result, $server, $request ) {
		$detected = self::detect_source();
		if ( null === $detected ) {
			return $result;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$route = $request->get_route();

		// Try to extract object type and ID from common REST route patterns.
		$object_type = null;
		$object_id   = null;
		if ( preg_match( '#^/wp/v2/([^/]+)/(\d+)#', $route, $m ) ) {
			$object_type = rtrim( $m[1], 's' ); // posts → post, pages → page, etc.
			$object_id   = (int) $m[2];
		} elseif ( preg_match( '#^/wp/v2/([^/]+)$#', $route, $m ) ) {
			$object_type = rtrim( $m[1], 's' );
		}

		// Always record REMOTE_ADDR as the audit IP. Forwarded headers
		// (CF-Connecting-IP, X-Forwarded-For) are client-controlled and cannot
		// be trusted for audit-log integrity on a site not behind a known proxy.
		$audit_ip = isset( $_SERVER['REMOTE_ADDR'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 )
			: '';

		// Build the row; omit object_id when null so the column stores NULL
		// (its schema default) rather than 0, which %d would cast it to.
		$row_data    = array(
			'user_id'           => (int) get_current_user_id(),
			'source'            => $detected['source'],
			'app_password_name' => $detected['app_password_name'],
			'method'            => strtoupper( $request->get_method() ),
			'route'             => substr( $route, 0, 500 ),
			'object_type'       => $object_type,
			'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : null,
			'ip'                => $audit_ip,
			'created_at'        => current_time( 'mysql' ),
		);
		$row_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $object_id ) {
			// Splice object_id in after object_type (position 6).
			$row_data    = array_slice( $row_data, 0, 6, true )
				+ array( 'object_id' => (int) $object_id )
				+ array_slice( $row_data, 6, null, true );
			array_splice( $row_formats, 6, 0, '%d' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $row_data, $row_formats );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Source detection
	// -------------------------------------------------------------------------

	/**
	 * Detect whether the current request originated from a tracked source.
	 *
	 * Public static so other classes (e.g. Tracker) can reuse detection logic
	 * without duplicating the UA / app-password patterns.
	 *
	 * @return array{source: string, app_password_name: string|null}|null
	 *   Null if the request is not from a tracked source.
	 */
	public static function detect_source() {
		// Must be a REST API request.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return null;
		}

		// Must be authenticated.
		if ( ! get_current_user_id() ) {
			return null;
		}

		// ---- WP.com MCP via User-Agent ----
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		/**
		 * Filters the User-Agent substrings used to identify WP.com MCP requests.
		 *
		 * @param string[] $patterns Case-insensitive substring patterns.
		 */
		$mcp_ua_patterns = apply_filters( 'agent_access_mcp_ua_patterns', array(
			'wordpress.com-mcp',
			'wpcom-mcp',
			'automattic-mcp',
			'wp-mcp',
		) );

		foreach ( $mcp_ua_patterns as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $ua, $pattern ) ) {
				return array(
					'source'            => self::SOURCE_WP_MCP,
					'app_password_name' => null,
				);
			}
		}

		// NOTE: Jetpack signature headers (X-Jetpack-Signature / X-JP-Signature)
		// are NOT used as a detection signal here. The signature is never verified
		// before this point, so any authenticated client can set the header and
		// have their writes attributed to the wordpress-mcp source, defeating the
		// audit log's integrity. Use only the verified app-password name below.

		// ---- WP.com MCP via Jetpack token auth ----
		// Jetpack-proxied requests (including WP.com MCP) use token-based auth
		// instead of Application Passwords. They are identifiable by the combination
		// of the "Jetpack by WordPress.com" User-Agent and the `_for=jetpack` query
		// param that Jetpack appends to every proxied REST request.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if (
			false !== stripos( $ua, 'jetpack' ) &&
			false !== strpos( $request_uri, '_for=jetpack' )
		) {
			return array(
				'source'            => self::SOURCE_WP_MCP,
				'app_password_name' => null,
			);
		}

		// ---- Agent Access or MCP via Application Password ----
		$app_password_uuid = rest_get_authenticated_app_password();
		if ( empty( $app_password_uuid ) ) {
			return null;
		}

		$user_id   = get_current_user_id();
		$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );

		foreach ( $passwords as $item ) {
			if ( $item['uuid'] !== $app_password_uuid ) {
				continue;
			}

			$name = $item['name'];

			// Check for the Agent Access app password.
			if ( $name === AGENT_ACCESS_APP_PASSWORD_NAME ) {
				return array(
					'source'            => self::SOURCE_AA,
					'app_password_name' => $name,
				);
			}

			/**
			 * Filters the app-password name substrings used to identify WP.com MCP.
			 *
			 * Useful when the user creates a dedicated password named e.g. "WordPress.com MCP".
			 *
			 * @param string[] $patterns Case-insensitive substring patterns.
			 */
			$mcp_name_patterns = apply_filters( 'agent_access_mcp_name_patterns', array(
				'wordpress.com',
				'wpcom',
				'mcp',
			) );

			foreach ( $mcp_name_patterns as $pattern ) {
				if ( '' !== $pattern && false !== stripos( $name, $pattern ) ) {
					return array(
						'source'            => self::SOURCE_WP_MCP,
						'app_password_name' => $name,
					);
				}
			}

			// Different app password — not tracked.
			return null;
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Query helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a WHERE clause and its bound values from common filter args.
	 *
	 * Shared by get_entries() and count_entries() so the filter contract
	 * stays in one place and can't drift between count and fetch.
	 *
	 * @param array $args Filter arguments: source, user_id, method.
	 * @return array{ 0: string[], 1: array } [ $where_clauses, $values ]
	 */
	private static function build_where( $args ) {
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$values[] = $args['source'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['method'] ) ) {
			$where[]  = 'method = %s';
			$values[] = strtoupper( $args['method'] );
		}

		return array( $where, $values );
	}

	/**
	 * Get recent log entries.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *     @type int    $limit   Max rows to return. Default 50.
	 *     @type int    $offset  Row offset for pagination. Default 0.
	 *     @type string $source  Filter by source ('agent-access' or 'wordpress-mcp').
	 *     @type int    $user_id Filter by user ID.
	 *     @type string $method  Filter by HTTP method (POST, GET, etc.).
	 * }
	 * @return array
	 */
	public static function get_entries( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		$defaults = array(
			'limit'   => 50,
			'offset'  => 0,
			'source'  => '',
			'user_id' => 0,
			'method'  => '',
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where, $values ) = self::build_where( $args );

		$values[] = (int) $args['limit'];
		$values[] = (int) $args['offset'];

		$sql = "SELECT l.*, u.user_login, u.display_name
		        FROM {$table} l
		        LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
		        WHERE " . implode( ' AND ', $where ) . "
		        ORDER BY l.created_at DESC
		        LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Count total log entries (optionally filtered).
	 *
	 * @param array $args Same filter keys as get_entries() minus limit/offset.
	 * @return int
	 */
	public static function count_entries( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;

		list( $where, $values ) = self::build_where( $args );

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	// -------------------------------------------------------------------------
	// DB schema
	// -------------------------------------------------------------------------

	/**
	 * Create or upgrade the log table. Call on plugin activation.
	 */
	public static function install_table() {
		global $wpdb;
		$table         = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(64) NOT NULL DEFAULT '',
			app_password_name varchar(191) DEFAULT NULL,
			method varchar(10) NOT NULL DEFAULT '',
			route varchar(500) NOT NULL DEFAULT '',
			object_type varchar(64) DEFAULT NULL,
			object_id bigint(20) unsigned DEFAULT NULL,
			user_agent varchar(500) DEFAULT NULL,
			ip varchar(45) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY source (source),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'agent_access_log_db_version', '1.0' );
	}

	/**
	 * Drop the log table. Called on uninstall when the option is set.
	 */
	public static function uninstall_table() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE_NAME ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DROP TABLE; table name is a hardcoded constant.
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

}
