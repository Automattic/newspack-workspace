<?php
/**
 * Pro Auth — Experimental token-based authentication for Agent Access.
 *
 * Provisioning flow:
 *   1. Admin generates a one-time setup link (15-min TTL).
 *   2. Admin pastes the JSON payload to their agent.
 *   3. Agent POSTs { setup_code, public_key?, agent_id? } to /agent-access/v1/activate.
 *   4. Plugin validates + burns the code, issues a persistent agt_ token.
 *   5. Agent uses Bearer <agt_token> on all subsequent requests.
 *
 * Pro mode (optional): agent includes an Ed25519 public key at activation.
 * Each request must then include X-Agent-Timestamp + X-Agent-Signature headers.
 * The plugin verifies the signature via libsodium — private key never leaves the agent.
 *
 * @package BotCreds Agent Access
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Pro_Auth {

	const TOKEN_PREFIX   = 'agt_';
	const TABLE_SUFFIX   = 'agent_access_pro_tokens';
	const OPTION_ENABLED = 'agent_access_pro_auth_enabled';
	const SETUP_TTL      = 900; // 15 minutes.
	const SIG_WINDOW     = 300; // 5 minutes replay window.

	// ──────────────────────────────────────────────
	// Bootstrap
	// ──────────────────────────────────────────────

	/**
	 * Register hooks. Called from agent_access_init() when pro auth is enabled.
	 */
	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_token' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Whether Pro Auth is enabled in site options.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	// ──────────────────────────────────────────────
	// REST routes
	// ──────────────────────────────────────────────

	/**
	 * Register the /activate endpoint — no auth required (open to agents).
	 */
	public static function register_routes() {
		register_rest_route(
			'agent-access/v1',
			'/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_activate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'setup_code' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'public_key' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'agent_id'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Exchange a setup code for a persistent agent token.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_activate( WP_REST_Request $request ) {
		$setup_code = $request->get_param( 'setup_code' );
		$code_hash  = hash( 'sha256', $setup_code );
		$data       = get_transient( 'aa_setup_' . $code_hash );

		if ( ! $data ) {
			return new WP_Error(
				'invalid_code',
				'Invalid or expired setup code.',
				array( 'status' => 401 )
			);
		}

		// Burn immediately — one-time use.
		delete_transient( 'aa_setup_' . $code_hash );

		// Validate optional public key (must be base64-encoded Ed25519 = 32 bytes).
		$public_key = $request->get_param( 'public_key' );
		if ( $public_key ) {
			$decoded = base64_decode( $public_key, true );
			if ( false === $decoded || strlen( $decoded ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
				return new WP_Error(
					'invalid_public_key',
					sprintf(
						'public_key must be a base64-encoded Ed25519 public key (%d bytes).',
						SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
					),
					array( 'status' => 400 )
				);
			}
		}

		$agent_id = $request->get_param( 'agent_id' );

		// Generate persistent token.
		$token      = self::TOKEN_PREFIX . wp_generate_password( 32, false );
		$token_hash = hash( 'sha256', $token );

		global $wpdb;
		$table     = $wpdb->prefix . self::TABLE_SUFFIX;
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$inserted = $wpdb->insert(
			$table,
			array(
				'token_hash' => $token_hash,
				'user_id'    => (int) $data['user_id'],
				'label'      => sanitize_text_field( $data['label'] ?: ( $agent_id ?: 'Agent' ) ),
				'public_key' => $public_key ?: null,
				'origin_ip'  => $remote_ip,
				'status'     => 'active',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Failed to store token.', array( 'status' => 500 ) );
		}

		do_action(
			'agent_access_audit',
			'pro_token_created',
			array(
				'user_id'    => $data['user_id'],
				'label'      => $data['label'],
				'origin_ip'  => $remote_ip,
				'has_pubkey' => ! empty( $public_key ),
			)
		);

		return new WP_REST_Response(
			array(
				'token'    => $token,
				'site_url' => home_url( '/' ),
				'mode'     => $public_key ? 'pro' : 'standard',
				'message'  => 'Token issued. Store it securely — it cannot be retrieved again.',
			),
			201
		);
	}

	// ──────────────────────────────────────────────
	// Authentication — determine_current_user hook
	// ──────────────────────────────────────────────

	/**
	 * Authenticate agt_ Bearer tokens.
	 *
	 * Priority 20 — runs after WP's native Basic/cookie auth (priority 10/15).
	 * Returns false (not WP_Error) for missing/unknown tokens so WP can fall
	 * through to other auth methods. Returns WP_Error only for a recognised
	 * Pro-mode token with a bad signature.
	 *
	 * @param int|false $user_id Already-resolved user id.
	 * @return int|false|WP_Error
	 */
	public static function authenticate_token( $user_id ) {
		if ( $user_id ) {
			return $user_id; // Already authenticated — don't interfere.
		}

		$token = self::extract_bearer_token();
		if ( ! $token ) {
			return $user_id;
		}

		// Quick prefix check before hitting the DB.
		if ( strpos( $token, self::TOKEN_PREFIX ) !== 0 ) {
			return $user_id;
		}

		$token_hash = hash( 'sha256', $token );

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE token_hash = %s AND status = 'active' LIMIT 1",
				$token_hash
			)
		);

		if ( ! $row ) {
			return false; // Unknown token — fall through.
		}

		// Pro mode: verify Ed25519 signature before granting access.
		if ( $row->public_key ) {
			if ( ! self::verify_signature( $row->public_key ) ) {
				return new WP_Error(
					'signature_invalid',
					'Request signature is invalid, missing, or expired.',
					array( 'status' => 401 )
				);
			}
		}

		// Log origin drift (non-blocking — alert only, never reject).
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $row->origin_ip && $row->origin_ip !== $remote_ip ) {
			do_action( 'agent_access_origin_drift', (array) $row, $remote_ip );
		}

		// Stamp last_used.
		$wpdb->update(
			$table,
			array( 'last_used_at' => current_time( 'mysql' ) ),
			array( 'id' => $row->id ),
			array( '%s' ),
			array( '%d' )
		);

		// Flag for downstream logging (detect_source hook).
		$GLOBALS['_agent_access_pro_token_row'] = $row;

		return (int) $row->user_id;
	}

	// ──────────────────────────────────────────────
	// Signature verification
	// ──────────────────────────────────────────────

	/**
	 * Verify an Ed25519 request signature using libsodium.
	 *
	 * Required headers:
	 *   X-Agent-Timestamp: <unix timestamp>
	 *   X-Agent-Signature: <base64 of sodium_crypto_sign_detached(message, private_key)>
	 *
	 * Signed message: "<METHOD>\n<PATH>\n<TIMESTAMP>"
	 *
	 * @param string $public_key_b64 Base64-encoded Ed25519 public key (32 bytes).
	 * @return bool
	 */
	private static function verify_signature( $public_key_b64 ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			// libsodium unavailable (should not happen on PHP 7.2+).
			// Fail open with a logged warning rather than silently locking out agents.
			trigger_error( 'Agent Access Pro Auth: libsodium not available, skipping signature check.', E_USER_WARNING );
			return true;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$timestamp = isset( $_SERVER['HTTP_X_AGENT_TIMESTAMP'] ) ? (int) $_SERVER['HTTP_X_AGENT_TIMESTAMP'] : 0;
		$sig_b64   = isset( $_SERVER['HTTP_X_AGENT_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AGENT_SIGNATURE'] ) ) : '';
		// phpcs:enable

		if ( ! $timestamp || ! $sig_b64 ) {
			return false;
		}

		// Reject timestamps outside the replay window.
		if ( abs( time() - $timestamp ) > self::SIG_WINDOW ) {
			return false;
		}

		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'POST';
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path    = wp_parse_url( $uri, PHP_URL_PATH );
		$message = $method . "\n" . $path . "\n" . $timestamp;

		$public_key = base64_decode( $public_key_b64, true );
		$signature  = base64_decode( $sig_b64, true );

		if ( false === $public_key || false === $signature ) {
			return false;
		}

		if ( strlen( $public_key ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( Exception $e ) {
			return false;
		}
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Extract a Bearer token from the Authorization header.
	 *
	 * @return string|false
	 */
	private static function extract_bearer_token() {
		$auth = '';

		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			foreach ( $headers as $key => $value ) {
				if ( 'authorization' === strtolower( $key ) ) {
					$auth = sanitize_text_field( $value );
					break;
				}
			}
		}

		if ( preg_match( '/^Bearer\s+(agt_[A-Za-z0-9_\-]+)$/i', trim( $auth ), $m ) ) {
			return $m[1];
		}

		return false;
	}

	// ──────────────────────────────────────────────
	// Setup link generation
	// ──────────────────────────────────────────────

	/**
	 * Generate a one-time setup code for the given user.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $label   Human label for the resulting token.
	 * @return array{ url: string, code: string, expires_in: int }
	 */
	public static function generate_setup_link( $user_id, $label = '' ) {
		$code      = self::TOKEN_PREFIX . wp_generate_password( 32, false );
		$code_hash = hash( 'sha256', $code );

		set_transient(
			'aa_setup_' . $code_hash,
			array(
				'user_id' => (int) $user_id,
				'label'   => sanitize_text_field( $label ),
			),
			self::SETUP_TTL
		);

		return array(
			'url'        => rest_url( 'agent-access/v1/activate' ),
			'code'       => $code,
			'expires_in' => self::SETUP_TTL,
		);
	}

	// ──────────────────────────────────────────────
	// Token management
	// ──────────────────────────────────────────────

	/**
	 * Revoke a pro token by its DB row id.
	 *
	 * @param int $token_id
	 * @return bool
	 */
	public static function revoke_token( $token_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		return (bool) $wpdb->update(
			$table,
			array( 'status' => 'revoked' ),
			array( 'id' => (int) $token_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get active pro tokens across all users (for admin dashboard).
	 *
	 * @return array
	 */
	public static function get_tokens() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT t.id, t.user_id, t.label, t.public_key, t.origin_ip, t.status,
			        t.created_at, t.last_used_at,
			        u.display_name, u.user_login
			 FROM `{$table}` t
			 LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
			 WHERE t.status = 'active'
			 ORDER BY t.created_at DESC"
		);
	}

	// ──────────────────────────────────────────────
	// DB install / uninstall
	// ──────────────────────────────────────────────

	/**
	 * Create the pro tokens table. Safe to call multiple times (CREATE TABLE IF NOT EXISTS).
	 */
	public static function install_table() {
		global $wpdb;
		$table           = $wpdb->prefix . self::TABLE_SUFFIX;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(100) NOT NULL DEFAULT '',
			public_key text DEFAULT NULL,
			origin_ip varchar(45) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			last_used_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the pro tokens table on plugin uninstall.
	 */
	public static function uninstall_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}
