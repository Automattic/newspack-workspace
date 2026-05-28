<?php
/**
 * Constant Contact ESP Service Controller.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * API Controller for Newspack Constant Contact ESP service.
 */
class Newspack_Newsletters_Constant_Contact_Controller extends Newspack_Newsletters_Service_Provider_Controller {
	/**
	 * Newspack_Newsletters_Constant_Contact_Controller constructor.
	 *
	 * @param \Newspack_Newsletters_Constant_Contact $constant_contact The service provider class.
	 */
	public function __construct( $constant_contact ) {
		$this->service_provider = $constant_contact;
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		parent::__construct( $constant_contact );
	}

	/**
	 * Register custom fields.
	 */
	public static function register_meta() {
		\register_meta(
			'post',
			'cc_campaign_id',
			[
				'object_subtype' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'show_in_rest'   => false,
				'type'           => 'string',
				'single'         => true,
				'auth_callback'  => '__return_true',
			]
		);
	}

	/**
	 * Register API endpoints unique to Constant Contact.
	 */
	public function register_routes() {

		// Register common ESP routes from \Newspack_Newsletters_Service_Provider_Controller::register_routes.
		parent::register_routes();

		\register_rest_route(
			$this->service_provider::BASE_NAMESPACE . $this->service_provider->service,
			'verify_token',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'verify_token' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_authoring_permissions_check' ],
			]
		);
		\register_rest_route(
			$this->service_provider::BASE_NAMESPACE . $this->service_provider->service,
			'(?P<id>[\a-z]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_retrieve' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_authoring_permissions_check' ],
				'args'                => [
					'id' => [
						'sanitize_callback' => 'absint',
						'validate_callback' => [ 'Newspack_Newsletters', 'validate_newsletter_id' ],
					],
				],
			]
		);
		\register_rest_route(
			$this->service_provider::BASE_NAMESPACE . $this->service_provider->service,
			'(?P<id>[\a-z]+)/test',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_test' ],
				'permission_callback' => [ 'Newspack_Newsletters', 'api_authoring_permissions_check' ],
				'args'                => [
					'id'         => [
						'sanitize_callback' => 'absint',
						'validate_callback' => [ 'Newspack_Newsletters', 'validate_newsletter_id' ],
					],
					'test_email' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Verify connection
	 *
	 * @return WP_REST_Response|mixed API response or error.
	 */
	public function verify_token() {
		$response = $this->service_provider->verify_token();
		return self::get_api_response( $response );
	}

	/**
	 * Get campaign data.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return WP_REST_Response|mixed API response or error.
	 */
	public function api_retrieve( $request ) {
		$response = $this->service_provider->retrieve( $request['id'] );
		return self::get_api_response( $response );
	}

	/**
	 * Test campaign.
	 *
	 * @param WP_REST_Request $request API request object.
	 * @return WP_REST_Response|mixed API response or error.
	 */
	public function api_test( $request ) {
		$emails = explode( ',', $request['test_email'] );
		foreach ( $emails as &$email ) {
			$email = sanitize_email( trim( $email ) );
		}
		$this->update_user_test_emails( $emails );
		$response = $this->service_provider->test(
			$request['id'],
			$emails
		);
		return self::get_api_response( $response );
	}
}
