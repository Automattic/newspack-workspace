<?php
/**
 * MailPoet ESP Service Controller.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * API Controller for the Newspack MailPoet ESP service.
 */
class Newspack_Newsletters_Mailpoet_Controller extends Newspack_Newsletters_Service_Provider_Controller {

	/**
	 * Newspack_Newsletters_Mailpoet_Controller constructor.
	 *
	 * @param \Newspack_Newsletters_Mailpoet $mailpoet The service provider class.
	 */
	public function __construct( $mailpoet ) {
		$this->service_provider = $mailpoet;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		parent::__construct( $mailpoet );
	}

	/**
	 * Register API endpoints unique to MailPoet.
	 *
	 * MailPoet needs no credential or campaign routes: it is a local plugin, so
	 * there is nothing to authorize, and composing is handled in its own UI.
	 * Only the shared ESP routes are registered.
	 */
	public function register_routes() {
		parent::register_routes();

		\register_rest_route(
			$this->service_provider::BASE_NAMESPACE . $this->service_provider->service,
			'connection',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_connection' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_authoring_permissions_check' ],
			]
		);
	}

	/**
	 * Report connection state.
	 *
	 * Also reports MailPoet's signup confirmation setting, which decides whether
	 * readers synced from Newspack land subscribed or awaiting confirmation.
	 *
	 * @return WP_REST_Response|mixed
	 */
	public function api_connection() {
		return self::get_api_response(
			[
				'connected'                   => $this->service_provider->has_api_credentials(),
				'signup_confirmation_enabled' => $this->service_provider->is_signup_confirmation_enabled(),
			]
		);
	}
}
