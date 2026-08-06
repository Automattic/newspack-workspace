<?php
/**
 * Tests the Nextdoor integration's REST behaviour.
 *
 * @package Newspack\Tests
 */

use Newspack\Nextdoor;
use Newspack\Nextdoor\Auth;
use Newspack\Nextdoor\Controller;
use Newspack\Optional_Modules;
use Newspack\Wizards\Newspack\Nextdoor_Section;

/**
 * Tests the Nextdoor integration's REST behaviour.
 *
 * @group nextdoor
 */
class Newspack_Test_Nextdoor extends WP_UnitTestCase {

	/**
	 * Settings route registered by the wizard section.
	 *
	 * @var string
	 */
	const SETTINGS_ROUTE = '/newspack/v1/wizard/newspack-settings/social/nextdoor';

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Body the stubbed Nextdoor API responds with.
	 *
	 * @var string
	 */
	protected $http_body = '{}';

	/**
	 * Set up a REST server, an administrator and a clean set of options.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( Nextdoor::SETTINGS_SLUG );
		delete_option( Optional_Modules::OPTION_NAME );
	}

	/**
	 * Leave no module, settings, capability or credentials behind.
	 */
	public function tear_down() {
		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_ID' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( Nextdoor::CAPABILITY_SLUG ) ) {
				$role->remove_cap( Nextdoor::CAPABILITY_SLUG );
			}
		}

		delete_option( Nextdoor::SETTINGS_SLUG );
		delete_option( Optional_Modules::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Answer any request to Nextdoor with the body the test set up.
	 *
	 * @param mixed  $preempt Short-circuit return value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function stub_nextdoor_response( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'nextdoor.com' ) ) {
			return $preempt;
		}

		return [
			'headers'  => [],
			'body'     => $this->http_body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * Dispatch a settings update as JSON.
	 *
	 * @param array $params Request body.
	 * @return WP_REST_Response
	 */
	private function update_settings( $params ) {
		$request = new WP_REST_Request( 'POST', self::SETTINGS_ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Unknown roles are dropped, duplicates collapse and the keys are a list.
	 */
	public function test_sanitize_allowed_roles_keeps_only_known_roles() {
		$section = new Nextdoor_Section( [ 'wizard_slug' => 'newspack-settings' ] );

		self::assertSame(
			[ 'administrator', 'editor' ],
			$section->sanitize_allowed_roles( [ 'administrator', 'editor', 'administrator', 'not_a_role', 42 ] )
		);
	}

	/**
	 * A scalar is an error, not an empty list.
	 */
	public function test_sanitize_allowed_roles_rejects_a_scalar() {
		$section = new Nextdoor_Section( [ 'wizard_slug' => 'newspack-settings' ] );
		$result  = $section->sanitize_allowed_roles( 'editor' );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'newspack_nextdoor_invalid_allowed_roles', $result->get_error_code() );
	}

	/**
	 * A scalar over REST is refused and the stored roles survive it.
	 */
	public function test_scalar_allowed_roles_does_not_empty_the_stored_roles() {
		Optional_Modules::activate_optional_module( 'nextdoor' );
		Nextdoor::update_settings( [ 'allowed_roles' => [ 'administrator', 'editor' ] ] );

		$response = $this->update_settings( [ 'allowed_roles' => 'editor' ] );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		self::assertSame( [ 'administrator', 'editor' ], Nextdoor::get_settings()['allowed_roles'] );
	}

	/**
	 * The capability follows the saved roles within the same request.
	 */
	public function test_saving_roles_grants_and_revokes_the_capability() {
		Optional_Modules::activate_optional_module( 'nextdoor' );

		$response = $this->update_settings( [ 'allowed_roles' => [ 'administrator', 'editor' ] ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'administrator', 'editor' ], Nextdoor::get_settings()['allowed_roles'] );
		self::assertTrue( get_role( 'editor' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
		self::assertFalse( get_role( 'author' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );

		$this->update_settings( [ 'allowed_roles' => [ 'administrator' ] ] );

		self::assertFalse( get_role( 'editor' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
	}

	/**
	 * Saving on a centrally credentialed site never writes the shared secret to the option.
	 */
	public function test_centralized_credentials_are_not_persisted() {
		if ( defined( 'NEWSPACK_NEXTDOOR_CLIENT_ID' ) || defined( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' ) ) {
			self::markTestSkipped( 'Centralized credentials are defined as constants in this environment.' );
		}

		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_ID=platform-id' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET=platform-secret' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		Optional_Modules::activate_optional_module( 'nextdoor' );

		$response = $this->update_settings( [ 'allowed_roles' => [ 'administrator' ] ] );
		$stored   = get_option( Nextdoor::SETTINGS_SLUG );

		self::assertSame( 200, $response->get_status() );
		self::assertArrayNotHasKey( 'client_id', $stored );
		self::assertArrayNotHasKey( 'client_secret', $stored );
		self::assertSame( 'platform-secret', Nextdoor::get_settings()['client_secret'] );
	}

	/**
	 * Credentials the publisher entered are still stored.
	 */
	public function test_publisher_credentials_are_persisted() {
		Optional_Modules::activate_optional_module( 'nextdoor' );

		$this->update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
			]
		);

		$stored = get_option( Nextdoor::SETTINGS_SLUG );

		self::assertSame( 'site-id', $stored['client_id'] );
		self::assertSame( 'site-secret', $stored['client_secret'] );
	}

	/**
	 * A claim response with no page is an error, not a success.
	 */
	public function test_claim_page_reports_a_response_without_a_page() {
		$this->http_body = wp_json_encode( [ 'status' => 'PENDING' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/claim-page' );
		$request->set_param( 'publication_url', 'https://example.com' );

		$result = Controller::api_claim_page( $request );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_claim_page_failed', $result->get_error_code() );
		self::assertSame( '', Nextdoor::get_settings()['page_id'] );
	}

	/**
	 * A claimed page is stored and reported back.
	 */
	public function test_claim_page_stores_the_returned_page() {
		$this->http_body = wp_json_encode( [ 'page_id' => 'page-123' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/claim-page' );
		$request->set_param( 'publication_url', 'https://example.com' );

		$result   = Controller::api_claim_page( $request );
		$settings = Nextdoor::get_settings();

		self::assertSame(
			[
				'success' => true,
				'page_id' => 'page-123',
			],
			$result->get_data()
		);
		self::assertSame( 'page-123', $settings['page_id'] );
		self::assertSame( 'https://example.com', $settings['publication_url'] );
	}

	/**
	 * Disconnecting twice succeeds twice.
	 */
	public function test_disconnect_is_idempotent() {
		Nextdoor::update_settings( [ 'access_token' => 'token' ] );

		$first = Controller::api_disconnect();

		self::assertTrue( $first->get_data()['success'] );
		self::assertFalse( get_option( Nextdoor::SETTINGS_SLUG, false ) );

		$second = Controller::api_disconnect();

		self::assertTrue( $second->get_data()['success'] );
	}

	/**
	 * The OAuth state matches once and only once.
	 */
	public function test_oauth_state_is_single_use() {
		self::assertFalse( Auth::verify_oauth_state( '' ) );

		Auth::create_oauth_state();
		self::assertFalse( Auth::verify_oauth_state( 'not-the-state' ) );

		$state = Auth::create_oauth_state();
		self::assertTrue( Auth::verify_oauth_state( $state ) );
		self::assertFalse( Auth::verify_oauth_state( $state ) );
	}

	/**
	 * Starting the flow hands Nextdoor a state this site can recognise.
	 */
	public function test_oauth_start_binds_a_state_to_the_login_url() {
		Nextdoor::update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
			]
		);

		$this->http_body = wp_json_encode( [ 'login_url' => 'https://auth.nextdoor.com/login?foo=bar' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/oauth/start' );
		$request->set_param( 'email', 'publisher@example.com' );
		$request->set_param( 'country', 'US' );

		$login_url = Controller::api_start_oauth( $request )->get_data()['login_url'];
		parse_str( (string) wp_parse_url( $login_url, PHP_URL_QUERY ), $query );

		self::assertSame( 'bar', $query['foo'] );
		self::assertNotEmpty( $query['state'] );
		self::assertTrue( Auth::verify_oauth_state( $query['state'] ) );
	}
}
