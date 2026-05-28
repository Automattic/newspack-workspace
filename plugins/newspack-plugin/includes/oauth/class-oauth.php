<?php
/**
 * Authentication.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class OAuth {
	const CSRF_TOKEN_TRANSIENT_SCOPE_PREFIX = 'csrf_';

	/**
	 * Get API key for proxies.
	 */
	public static function get_proxy_api_key() {
		/**
		 * Option name storing the Newspack Manager API key.
		 * Required for OAuth proxy functionality.
		 *
		 * @constant NEWSPACK_MANAGER_API_KEY_OPTION_NAME
		 * @type     string
		 * @default  OAuth proxy unavailable
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME', 'newspack_manager_api_key' );
		 */
		if ( ! defined( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME' ) ) {
			return false;
		}
		return get_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME );
	}

	/**
	 * Get unique ID for user or client.
	 */
	public static function get_unique_id() {
		$id = get_current_user_id();
		if ( ! $id ) {
			$id = Reader_Activation::get_client_id();
		}
		if ( ! $id ) {
			$id = session_id(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_id
		}
		if ( ! $id ) {
			if ( session_status() !== PHP_SESSION_ACTIVE ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_status
				session_start(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_start
			}
			$id = session_id(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.session_session_id
		}
		return $id;
	}

	/**
	 * Generate a CSRF token and save it as transient.
	 *
	 * @param string $namespace Namespace for the token.
	 * @return string CSRF token.
	 */
	public static function generate_csrf_token( $namespace ) {
		$csrf_token = wp_generate_password( 40, false );
		$transient_scope = self::CSRF_TOKEN_TRANSIENT_SCOPE_PREFIX . $namespace;
		$unique_id = self::get_unique_id();
		if ( ! $unique_id ) {
			Logger::log( sprintf( 'Unable to get unique ID for CSRF token with "%s" namespace.', $namespace ) );
			return false;
		}
		return OAuth_Transients::set( self::get_unique_id(), $transient_scope, $csrf_token );
	}

	/**
	 * Retrieve a saved CSRF token.
	 *
	 * @param string $namespace Namespace for the token.
	 * @return string CSRF token.
	 */
	public static function retrieve_csrf_token( $namespace ) {
		$transient_scope = self::CSRF_TOKEN_TRANSIENT_SCOPE_PREFIX . $namespace;
		$value = OAuth_Transients::get( self::get_unique_id(), $transient_scope );
		return $value;
	}

	/**
	 * Process OAuth proxy URL.
	 *
	 * @param string $type 'google' for now.
	 * @param string $path Path to append to base URL.
	 * @param array  $query_args Query params.
	 * @throws \Exception If trying to authenticate a non-existent proxy.
	 */
	public static function authenticate_proxy_url( string $type, string $path = '', array $query_args = [] ) {
		if ( ! self::is_proxy_configured( $type ) ) {
			Logger::error( "$type proxy type is not configured." );
			throw new \Exception( esc_html( "Unknown proxy type: $type" ) );
		}
		$proxy_url = self::get_proxy_url( $type );
		Logger::log( "Using $type proxy: $proxy_url" );
		return add_query_arg(
			array_merge(
				[
					'api_key' => urlencode( self::get_proxy_api_key() ),
				],
				$query_args
			),
			$proxy_url . $path
		);
	}

	/**
	 * Is OAuth2 configured for this instance?
	 *
	 * @param string $type 'google' for now.
	 */
	public static function is_proxy_configured( $type ) {
		return self::get_proxy_url( $type ) && self::get_proxy_api_key();
	}

	/**
	 * Get proxy URL by type.
	 *
	 * @param string $type 'google' for now.
	 */
	private static function get_proxy_url( $type ) {
		switch ( $type ) {
			case 'google':
				/**
				 * Override URL for Google OAuth proxy. Takes precedence over
				 * NEWSPACK_GOOGLE_OAUTH_PROXY. Use for development/testing.
				 *
				 * @constant NEWSPACK_GOOGLE_OAUTH_PROXY_OVERRIDE
				 * @type     string
				 * @default  Uses NEWSPACK_GOOGLE_OAUTH_PROXY or none
				 * @status   draft
				 *
				 * @example define( 'NEWSPACK_GOOGLE_OAUTH_PROXY_OVERRIDE', 'https://dev-proxy.example.com' );
				 */
				if ( defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY_OVERRIDE' ) ) {
					return NEWSPACK_GOOGLE_OAUTH_PROXY_OVERRIDE;
				}
				/**
				 * URL of the Google OAuth proxy service. Required for Google
				 * sign-in functionality.
				 *
				 * @constant NEWSPACK_GOOGLE_OAUTH_PROXY
				 * @type     string
				 * @default  Google OAuth unavailable
				 * @status   draft
				 *
				 * @example define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'https://oauth.newspack.com' );
				 */
				if ( defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
					return NEWSPACK_GOOGLE_OAUTH_PROXY;
				}
				break;
		}
		return false;
	}
}
