<?php
/**
 * Connection to Google services.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Storage\User_Options;
use Google\Site_Kit\Core\Authentication\Authentication;
use Google\Site_Kit\Modules\Analytics as SiteKitAnalytics;
use Google\Auth\Credentials\UserRefreshCredentials;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Google_Services_Connection {
	/**
	 * Get Site Kit's authentication.
	 *
	 * @codeCoverageIgnore
	 * @return Authentication Site Kit's authentication.
	 */
	public static function get_site_kit_authentication() {
		if ( defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			$context        = new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
			$authentication = new Authentication( $context, new Options( $context ), new User_Options( $context ) );
			return $authentication;
		} else {
			return new WP_Error( 'newspack_sitekit_disconnected', __( 'Connect Site Kit plugin to view Campaign Analytics.', 'newspack' ) );
		}
	}

	/**
	 * Get OAuth2 client.
	 * More at https://googleapis.github.io/google-auth-library-php/master/Google/Auth/OAuth2.html.
	 *
	 * @codeCoverageIgnore
	 * @return object Google OAuth2 client.
	 */
	public static function get_site_kit_oauth_client() {
		return self::get_site_kit_authentication()->get_oauth_client();
	}

	/**
	 * Get OAuth2 credentials.
	 *
	 * @return UserRefreshCredentials Credentials to make requests with.
	 */
	public static function get_oauth2_credentials() {
		return \Newspack\Google_OAuth::get_oauth2_credentials();
	}
}
