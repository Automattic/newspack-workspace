<?php
/**
 * Newspack Ads GAM Api
 *
 * @package Newspack
 */

namespace Newspack_Ads\Providers\GAM;

use Newspack_Ads\Providers\GAM\Api;
use Newspack_Ads\Providers\GAM_Model;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\OAuth2;
use Google\AdsApi\Common\Configuration;
use Google\AdsApi\AdManager\AdManagerSessionBuilder;
use Google\AdsApi\AdManager\AdManagerSession;
use Google\AdsApi\AdManager\v202511\ServiceFactory;
use Google\AdsApi\AdManager\v202511\Network;
use Google\AdsApi\AdManager\v202511\User;
use Google\AdsApi\AdManager\v202511\ApiException;

require_once NEWSPACK_ADS_COMPOSER_ABSPATH . 'autoload.php';
require_once 'class-api-object.php';
require_once 'class-advertisers.php';
require_once 'class-creatives.php';
require_once 'class-targeting-keys.php';
require_once 'class-ad-units.php';
require_once 'class-line-items.php';
require_once 'class-orders.php';

/**
 * Newspack Ads GAM Api
 */
class Api {
	// https://developers.google.com/ad-manager/api/soap_xml: An arbitrary string name identifying your application. This will be shown in Google's log files.
	const APP = 'Newspack';

	const API_VERSION = 'v202511';

	/**
	 * Codes of networks that the user has access to.
	 *
	 * @var Network[]
	 */
	private $networks = [];

	/**
	 * Reusable GAM session.
	 *
	 * @var AdManagerSession
	 */
	private $session = null;

	/**
	 * GAM Network Code in use.
	 *
	 * @var string
	 */
	private $network_code = null;

	/**
	 * Authentication method. Either 'oauth' or 'service_account'.
	 *
	 * @var string
	 */
	private $auth_method = null;

	/**
	 * Credentials
	 *
	 * @var ServiceAccountCredentials|OAuth2
	 */
	private $credentials = null;

	/**
	 * Advertisers
	 *
	 * @var Api\Advertisers
	 */
	public $advertisers = null;

	/**
	 * Creatives
	 *
	 * @var Api\Creatives
	 */
	public $creatives = null;

	/**
	 * Targeting Keys
	 *
	 * @var Api\Targeting_Keys
	 */
	public $targeting_keys = null;

	/**
	 * Ad Units
	 *
	 * @var Api\Ad_Units
	 */
	public $ad_units = null;

	/**
	 * Line Items
	 *
	 * @var Api\Line_Items
	 */
	public $line_items = null;

	/**
	 * Orders
	 *
	 * @var Api\Orders
	 */
	public $orders = null;

	/**
	 * Constructor.
	 *
	 * @param string|AdManagerSession $auth_method_or_session Authentication method or session.
	 *                                                        Auth menthod should be either 'oauth2' or 'service_account'.
	 * @param array                   $credentials            OAuth2 or Service Account Credentials configuration.
	 * @param string                  $network_code           Optional GAM Network Code to use.
	 *
	 * @throws \Exception If the credentials are invalid or the environment is incompatible.
	 */
	public function __construct( $auth_method_or_session, $credentials = null, $network_code = null ) {
		if ( 'string' === gettype( $auth_method_or_session ) ) {
			$auth_method = $auth_method_or_session;
			if ( ! in_array( $auth_method, [ 'oauth2', 'service_account' ], true ) ) {
				throw new \Exception( esc_html__( 'Invalid authentication method.', 'newspack-ads' ) );
			}
			$this->auth_method = $auth_method;
			$this->credentials = $credentials;
		} else {
			$this->session = $auth_method_or_session;
		}
		$this->network_code = $network_code;
	}

	/**
	 * Initialize the API instance with a session and its API objects.
	 *
	 * @return true|\WP_Error True if the API was initialized successfully, WP_Error otherwise.
	 */
	public function init() {
		try {
			$session = $this->get_session();
			if ( \is_wp_error( $session ) ) {
				return $session;
			}
			// Ensure there's a valid user.
			$this->get_current_user();
			$this->advertisers    = new Api\Advertisers( $session, $this );
			$this->creatives      = new Api\Creatives( $session, $this );
			$this->targeting_keys = new Api\Targeting_Keys( $session, $this );
			$this->ad_units       = new Api\Ad_Units( $session, $this );
			$this->line_items     = new Api\Line_Items( $session, $this );
			$this->orders         = new Api\Orders( $session, $this );
		} catch ( ApiException $e ) {
			return $this->get_error( $e );
		}
		return true;
	}

	/**
	 * Get a WP_Error object from an optional ApiException or message.
	 *
	 * @param \Exception|ApiException $exception       Optional PHP exception or Google API exception.
	 * @param string                  $default_message Optional default message to use.
	 *
	 * @return WP_Error
	 */
	public function get_error( $exception = null, $default_message = null ) {
		$error_message = $default_message;
		$errors        = [];
		if ( ! is_null( $exception ) && $exception instanceof ApiException ) {
			$error_message = $error_message ?? $exception->getMessage();
			foreach ( $exception->getErrors() as $error ) {
				$errors[] = $error->getErrorString();
			}
		}
		$network_code = $this->network_code;
		$message_map  = [
			'UniqueError.NOT_UNIQUE'                    => __( 'Name must be unique.', 'newspack-ads' ),
			'CommonError.CONCURRENT_MODIFICATION'       => __( 'Unexpected API error, please try again in 30 seconds.', 'newspack-ads' ),
			'PermissionError.PERMISSION_DENIED'         => __( 'You do not have permission to perform this action. Make sure to connect an account with administrative access.', 'newspack-ads' ),
			'AuthenticationError.NETWORK_NOT_FOUND'     => __( 'The network code is invalid.', 'newspack-ads' ),
			'AuthenticationError.NETWORK_API_ACCESS_DISABLED' => sprintf(
				'%s <a href="%s" target="_blank">%s</a>',
				__( 'API access for this GAM account is disabled.', 'newspack-ads' ),
				"https://admanager.google.com/{$network_code}#admin/settings/network",
				__( 'Enable API access in your GAM settings.', 'newspack-ads' )
			),
			'AuthenticationError.NO_NETWORKS_TO_ACCESS' => __( 'You do not have access to any GAM networks.', 'newspack-ads' ),
		];
		foreach ( $message_map as $error_type => $message ) {
			if ( in_array( $error_type, $errors, true ) ) {
				$error_message = $message;
				break;
			}
		}
		return new \WP_Error(
			'newspack_ads_gam_error',
			$error_message ?? __( 'An unexpected error occurred', 'newspack-ads' ),
			array(
				'status' => '400',
				'level'  => 'warning',
			)
		);
	}

	/**
	 * Get GAM session for making API requests.
	 *
	 * @return AdManagerSession|\WP_Error Session object or WP_Error if the session could not be created.
	 */
	public function get_session() {
		if ( $this->session ) {
			return $this->session;
		}
		if ( ! $this->credentials ) {
			return new \WP_Error( 'newspack_ads_gam_error', __( 'No credentials provided.', 'newspack-ads' ) );
		}
		$config = [
			'AD_MANAGER' => [
				'applicationName' => self::APP,
			],
		];

		// Get network code and add it to the session config.
		try {
			// We're silencing errors here because the SDK throws a warning when the request config doesn't include a network code.
			$config['AD_MANAGER']['networkCode'] = @$this->get_network_code( ( new AdManagerSessionBuilder() )->from( new Configuration( $config ) )->withOAuth2Credential( $this->credentials )->build() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Exception | \ApiException $e ) {
			return $this->get_error( $e, __( 'Unable to fetch network code from GAM.', 'newspack-ads' ) );
		}
		// Generate and return session.
		$this->session = ( new AdManagerSessionBuilder() )->from( new Configuration( $config ) )->withOAuth2Credential( $this->credentials )->build();
		return $this->session;
	}

	/**
	 * Get GAM Connection User.
	 *
	 * @return User
	 */
	public function get_current_user() {
		return ( new ServiceFactory() )->createUserService( $this->session )->getCurrentUser();
	}

	/**
	 * Get GAM networks the authenticated user has access to.
	 *
	 * @param AdManagerSession $session Optional session to use.
	 *
	 * @return Network[] Array of networks.
	 */
	private function get_networks( $session = null ) {
		if ( empty( $session ) ) {
			$session = $this->get_session();
		}
		if ( empty( $this->networks ) ) {
			$this->networks = ( new ServiceFactory() )->createNetworkService( $session )->getAllNetworks();
		}
		return $this->networks;
	}

	/**
	 * Get serialized GAM networks the authenticated user has access to.
	 *
	 * @return array[] Array of serialized networks.
	 * @throws \Exception If not able to fetch networks.
	 */
	public function get_serialized_networks() {
		$networks = $this->get_networks() ?? [];
		return array_map(
			function( $network ) {
				return [
					'id'   => $network->getId(),
					'name' => $network->getDisplayName(),
					'code' => $network->getNetworkCode(),
				];
			},
			$networks
		);
	}

	/**
	 * Get user's GAM network. Defaults to the first found network if not found or empty.
	 *
	 * @param AdManagerSession $session Optional session to use.
	 *
	 * @return Network GAM network.
	 *
	 * @throws \Exception If there is no GAM network to use.
	 */
	public function get_network( $session = null ) {
		$networks     = $this->get_networks( $session );
		$network_code = $this->network_code;
		if ( empty( $networks ) ) {
			throw new \Exception( esc_html__( 'Missing GAM Ad network.', 'newspack-ads' ) );
		}
		if ( $network_code ) {
			foreach ( $networks as $network ) {
				if ( $network_code === $network->getNetworkCode() ) {
					return $network;
				}
			}
		}
		return $networks[0];
	}

	/**
	 * Get user's GAM network code.
	 *
	 * @param AdManagerSession $session Optional session to use.
	 *
	 * @return int GAM network code.
	 */
	public function get_network_code( $session = null ) {
		return $this->get_network( $session )->getNetworkCode();
	}

	/**
	 * Get details of the authorized GAM user.
	 *
	 * @return array Details of the user.
	 */
	public function get_settings() {
		try {
			$service_factory = new ServiceFactory();
			return [
				'user_email'   => $service_factory->createUserService( $this->session )->getCurrentUser()->getEmail(),
				'network_code' => self::get_network_code(),
			];
		} catch ( \Exception $e ) {
			return [];
		}
	}

	/**
	 * Get the current auth method.
	 *
	 * @return string Either 'service_account' or 'oauth'.
	 */
	public function get_auth_method() {
		return $this->auth_method;
	}
}
