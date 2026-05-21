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
	const SOURCE_REST    = 'rest-api';

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
		$detected = $this->detect_source();
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'           => (int) get_current_user_id(),
				'source'            => $detected['source'],
				'app_password_name' => $detected['app_password_name'],
				'method'            => strtoupper( $request->get_method() ),
				'route'             => substr( $route, 0, 500 ),
				'object_type'       => $object_type,
				'object_id'         => $object_id,
				'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : null,
				'ip'                => $this->get_client_ip(),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Source detection
	// -------------------------------------------------------------------------

	/**
	 * Detect whether the current request originated from a tracked source.
	 *
	 * @return array{source: string, app_password_name: string|null}|null
	 *   Null if the request is not from a tracked source.
	 */
	private function detect_source() {
		// Must be a REST API request.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return null;
		}

		// Must be authenticated.
		if ( ! get_current_user_id() ) {
			return null;
		}

		// ---- WP.com MCP via User-Agent ----
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

		// ---- WP.com MCP via Jetpack connection headers ----
		if ( isset( $_SERVER['HTTP_X_JETPACK_SIGNATURE'] ) || isset( $_SERVER['HTTP_X_JP_SIGNATURE'] ) ) {
			return array(
				'source'            => self::SOURCE_WP_MCP,
				'app_password_name' => null,
			);
		}

		// ---- WP.com MCP via Automattic IP ranges ----
		if ( $this->is_automattic_ip( $this->get_client_ip() ) ) {
			return array(
				'source'            => self::SOURCE_WP_MCP,
				'app_password_name' => null,
			);
		}

		// ---- Agent Access or MCP via Application Password ----
		$app_password_uuid = rest_get_authenticated_app_password();

		if ( ! empty( $app_password_uuid ) ) {
			$user_id   = get_current_user_id();
			$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );

			foreach ( $passwords as $item ) {
				if ( $item['uuid'] !== $app_password_uuid ) {
					continue;
				}

				$name = $item['name'];

				// Agent Access managed credential.
				if ( $name === AGENT_ACCESS_APP_PASSWORD_NAME ) {
					return array(
						'source'            => self::SOURCE_AA,
						'app_password_name' => $name,
					);
				}

				/**
				 * Filters the app-password name substrings used to identify WP.com MCP.
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

				// Unrecognised app password — log it anyway, labelled as generic REST.
				return array(
					'source'            => self::SOURCE_REST,
					'app_password_name' => $name,
				);
			}
		}

		// Authenticated via session cookie (logged-in admin/editor making REST call).
		// Log these too so nothing slips through.
		return array(
			'source'            => self::SOURCE_REST,
			'app_password_name' => null,
		);
	}

	// -------------------------------------------------------------------------
	// Query helpers
	// -------------------------------------------------------------------------

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
		$table = esc_sql( $wpdb->prefix . self::TABLE_NAME );

		$defaults = array(
			'limit'   => 50,
			'offset'  => 0,
			'source'          => '',
			'user_id'         => 0,
			'method'          => '',
			'exclude_methods' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

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
		} elseif ( ! empty( $args['exclude_methods'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['exclude_methods'] ), '%s' ) );
			$where[]      = "method NOT IN ({$placeholders})";
			foreach ( $args['exclude_methods'] as $excl ) {
				$values[] = strtoupper( $excl );
			}
		}
		if ( ! empty( $args['exclude_route_prefix'] ) ) {
			$where[]  = 'route NOT LIKE %s';
			$values[] = $wpdb->esc_like( $args['exclude_route_prefix'] ) . '%';
		}

		$values[] = (int) $args['limit'];
		$values[] = (int) $args['offset'];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table name is a known constant prefixed by $wpdb->prefix; WHERE array uses %s/%d placeholders with values passed via spread.
		$sql = "SELECT l.*, u.user_login, u.display_name
		        FROM {$table} l
		        LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
		        WHERE " . implode( ' AND ', $where ) . "
		        ORDER BY l.created_at DESC
		        LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count total log entries (optionally filtered).
	 *
	 * @param array $args Same filter keys as get_entries() minus limit/offset.
	 * @return int
	 */
	public static function count_entries( $args = array() ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . self::TABLE_NAME );

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
		} elseif ( ! empty( $args['exclude_methods'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['exclude_methods'] ), '%s' ) );
			$where[]      = "method NOT IN ({$placeholders})";
			foreach ( $args['exclude_methods'] as $excl ) {
				$values[] = strtoupper( $excl );
			}
		}
		if ( ! empty( $args['exclude_route_prefix'] ) ) {
			$where[]  = 'route NOT LIKE %s';
			$values[] = $wpdb->esc_like( $args['exclude_route_prefix'] ) . '%';
		}

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$table_name = esc_sql( $wpdb->prefix . self::TABLE_NAME );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Check if an IP address falls within a known Automattic/WP.com proxy range.
	 *
	 * @param string $ip IPv4 address.
	 * @return bool
	 */
	private function is_automattic_ip( $ip ) {
		if ( empty( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		/**
		 * Filters the CIDR blocks used to identify Automattic/WP.com proxy IPs.
		 *
		 * @param string[] $ranges CIDR notation blocks.
		 */
		$ranges = apply_filters( 'agent_access_automattic_ip_ranges', array(
			'192.0.64.0/18',   // WP.com primary
			'192.0.99.0/24',   // WP.com API proxy (observed)
			'198.181.116.0/22', // Automattic
		) );

		$ip_long = ip2long( $ip );
		foreach ( $ranges as $cidr ) {
			list( $base, $bits ) = explode( '/', $cidr );
			$mask = -1 << ( 32 - (int) $bits );
			if ( ( $ip_long & $mask ) === ( ip2long( $base ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the client IP, respecting common proxy headers.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For can be a comma-separated list; take the first.
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				return substr( $ip, 0, 45 );
			}
		}
		return '';
	}
}
