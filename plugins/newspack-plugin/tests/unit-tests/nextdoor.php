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
	 * Requests the stub intercepted, in order.
	 *
	 * @var array[]
	 */
	protected $http_requests = [];

	/**
	 * Set up a REST server, an administrator and a clean set of options.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// The module's classes are only loaded while it is active, so the routes under
		// test are not guaranteed to have been registered by the action above.
		if ( ! isset( $this->server->get_routes()['/newspack/v1/nextdoor/oauth/start'] ) ) {
			Controller::register_api_endpoints();
		}

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->http_requests = [];

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

		$this->http_requests[] = [
			'url'  => $url,
			'args' => $args,
		];

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
	 * Dispatch a POST as JSON.
	 *
	 * @param string $route  Route to dispatch to.
	 * @param array  $params Request body.
	 * @return WP_REST_Response
	 */
	private function post( $route, $params ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a settings update as JSON.
	 *
	 * @param array $params Request body.
	 * @return WP_REST_Response
	 */
	private function update_settings( $params ) {
		return $this->post( self::SETTINGS_ROUTE, $params );
	}

	/**
	 * Store a token that is nowhere near expiry, so nothing has to be renewed.
	 */
	private function connect_with_a_valid_token() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'valid-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() + HOUR_IN_SECONDS,
			]
		);
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
		$this->connect_with_a_valid_token();
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
		$this->connect_with_a_valid_token();
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
	 * Set credentials, stub Nextdoor and start the flow.
	 *
	 * @param string $email Optional. Email to connect with.
	 * @return WP_REST_Response|WP_Error
	 */
	private function start_oauth( $email = 'publisher@example.com' ) {
		Nextdoor::update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
			]
		);

		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/oauth/start' );
		$request->set_param( 'email', $email );
		$request->set_param( 'country', 'US' );

		return Controller::api_start_oauth( $request );
	}

	/**
	 * Starting the flow hands Nextdoor a redirect URI this site can recognise on the way back.
	 */
	public function test_oauth_start_binds_a_state_to_the_redirect_uri() {
		$this->http_body = wp_json_encode( [ 'login_url' => 'https://auth.nextdoor.com/login?foo=bar' ] );

		$login_url = $this->start_oauth()->get_data()['login_url'];

		// The login URL is Nextdoor's to build: the state rides on the redirect URI.
		self::assertSame( 'https://auth.nextdoor.com/login?foo=bar', $login_url );

		$sent = json_decode( $this->http_requests[0]['args']['body'], true );
		parse_str( (string) wp_parse_url( $sent['redirect_uri'], PHP_URL_QUERY ), $query );

		self::assertSame( '1', $query['nextdoor_oauth_callback'] );
		self::assertNotEmpty( $query['state'] );
		self::assertTrue( Auth::verify_oauth_state( $query['state'] ) );
	}

	/**
	 * The token exchange can rebuild the redirect URI the authorization was requested with,
	 * and the URI the publisher registers with Nextdoor is not the one carrying the state.
	 */
	public function test_the_redirect_uri_is_stable() {
		$state = Auth::create_oauth_state();

		self::assertSame( admin_url( 'admin.php?page=newspack-settings&nextdoor_oauth_callback=1' ), Nextdoor::get_redirect_uri() );
		self::assertSame( Nextdoor::get_redirect_uri( $state ), Nextdoor::get_redirect_uri( $state ) );
		self::assertNotSame( Nextdoor::get_redirect_uri(), Nextdoor::get_redirect_uri( $state ) );
	}

	/**
	 * A start response with no login URL is an error, not a redirect to nowhere.
	 */
	public function test_oauth_start_reports_a_response_without_a_login_url() {
		$this->http_body = wp_json_encode( [ 'status' => 'PENDING' ] );

		$result = $this->start_oauth();

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_oauth_start_failed', $result->get_error_code() );
	}

	/**
	 * A login URL the browser must not navigate to is refused.
	 */
	public function test_oauth_start_refuses_a_login_url_that_is_not_a_url() {
		$this->http_body = wp_json_encode( [ 'login_url' => 'javascript:alert(1)' ] );

		$result = $this->start_oauth();

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_oauth_start_failed', $result->get_error_code() );
	}

	/**
	 * An address pasted with whitespace is accepted, and reaches Nextdoor trimmed.
	 */
	public function test_oauth_start_accepts_an_email_with_surrounding_whitespace() {
		Nextdoor::update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
			]
		);

		$this->http_body = wp_json_encode( [ 'login_url' => 'https://auth.nextdoor.com/login' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$response = $this->post(
			'/newspack/v1/nextdoor/oauth/start',
			[
				'email'   => '  publisher@example.com  ',
				'country' => 'US',
			]
		);
		$sent     = json_decode( $this->http_requests[0]['args']['body'], true );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'publisher@example.com', $sent['email_address'] );
	}

	/**
	 * A publication URL that is not a URL never reaches the endpoint.
	 */
	public function test_claim_page_rejects_a_publication_url_that_is_not_a_url() {
		$response = $this->post( '/newspack/v1/nextdoor/claim-page', [ 'publication_url' => 'not a url' ] );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		self::assertSame( '', Nextdoor::get_settings()['publication_url'] );
	}

	/**
	 * A publication URL pasted with whitespace is stored as a URL.
	 */
	public function test_claim_page_trims_the_publication_url() {
		$this->connect_with_a_valid_token();
		$this->http_body = wp_json_encode( [ 'page_id' => 'page-123' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$response = $this->post( '/newspack/v1/nextdoor/claim-page', [ 'publication_url' => '  https://example.com  ' ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'https://example.com', Nextdoor::get_settings()['publication_url'] );
	}

	/**
	 * A legal mixed-case scheme is accepted, and stored in canonical form.
	 */
	public function test_claim_page_accepts_a_mixed_case_scheme() {
		$this->connect_with_a_valid_token();
		$this->http_body = wp_json_encode( [ 'page_id' => 'page-123' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$response = $this->post( '/newspack/v1/nextdoor/claim-page', [ 'publication_url' => 'HTTPS://example.com' ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'https://example.com', Nextdoor::get_settings()['publication_url'] );
	}

	/**
	 * The callback guards accept the administrator who started the flow, once.
	 */
	public function test_oauth_callback_authorization_is_single_use() {
		$state = Auth::create_oauth_state();

		self::assertTrue( Auth::authorize_oauth_callback( $state ) );

		$replayed = Auth::authorize_oauth_callback( $state );

		self::assertInstanceOf( 'WP_Error', $replayed );
		self::assertSame( 'nextdoor_oauth_invalid_state', $replayed->get_error_code() );
	}

	/**
	 * Someone without the capability is turned away before the state is consumed.
	 */
	public function test_a_non_administrator_cannot_consume_an_oauth_state() {
		$administrator = get_current_user_id();
		$state         = Auth::create_oauth_state();

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );

		$result = Auth::authorize_oauth_callback( $state );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_oauth_forbidden', $result->get_error_code() );

		wp_set_current_user( $administrator );

		self::assertTrue( Auth::authorize_oauth_callback( $state ) );
	}

	/**
	 * A grant that carries no token is an error, and nothing is stored.
	 */
	public function test_a_grant_without_a_token_is_an_error() {
		$this->http_body = wp_json_encode( [ 'error' => 'invalid_grant' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$result = Auth::get_access_token( 'site-id', 'site-secret', 'auth-code', Nextdoor::get_redirect_uri( 'state' ) );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_oauth_invalid_response', $result->get_error_code() );
		self::assertSame( '', Nextdoor::get_settings()['access_token'] );
	}

	/**
	 * Refreshing sends the stored refresh token and stores what comes back.
	 */
	public function test_refresh_sends_the_stored_refresh_token() {
		Nextdoor::update_settings(
			[
				'access_token'  => 'old-access',
				'refresh_token' => 'stored-refresh',
			]
		);

		$this->http_body = wp_json_encode(
			[
				'access_token'  => 'new-access',
				'refresh_token' => 'new-refresh',
				'expires_in'    => 3600,
			]
		);
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$settings = Nextdoor::get_settings();
		$result   = Auth::refresh_access_token( 'site-id', 'site-secret', $settings['refresh_token'] );
		$stored   = Nextdoor::get_settings();

		self::assertIsArray( $result );
		self::assertSame( 'stored-refresh', $this->http_requests[0]['args']['body']['refresh_token'] );
		self::assertSame( 'new-access', $stored['access_token'] );
		self::assertSame( 'new-refresh', $stored['refresh_token'] );
		self::assertGreaterThan( time(), $stored['token_expires_at'] );
	}

	/**
	 * A malformed refresh response leaves the working token where it is.
	 */
	public function test_a_malformed_refresh_response_leaves_the_stored_token() {
		Nextdoor::update_settings(
			[
				'access_token'     => 'working-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => 1,
			]
		);

		$this->http_body = wp_json_encode( [ 'error' => 'invalid_grant' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$result = Auth::refresh_access_token( 'site-id', 'site-secret', 'stored-refresh' );
		$stored = Nextdoor::get_settings();

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_oauth_invalid_response', $result->get_error_code() );
		self::assertSame( 'working-access', $stored['access_token'] );
		self::assertSame( 'stored-refresh', $stored['refresh_token'] );
		self::assertSame( 1, $stored['token_expires_at'] );
	}

	/**
	 * Reporting the connection status never calls Nextdoor.
	 */
	public function test_token_validity_is_reported_without_calling_nextdoor() {
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		Nextdoor::update_settings(
			[
				'access_token'     => 'expired-access',
				'token_expires_at' => time() - 10,
			]
		);

		self::assertFalse( Auth::has_usable_token() );

		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		self::assertTrue( Auth::has_usable_token() );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * A refresh token with nothing to exchange it with cannot renew anything.
	 */
	public function test_an_expired_token_without_credentials_is_not_usable() {
		Nextdoor::update_settings(
			[
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		self::assertFalse( Auth::has_usable_token() );
	}

	/**
	 * A refusal from Nextdoor is recorded, so the card stops reporting a dead connection.
	 */
	public function test_a_refused_refresh_marks_the_connection_as_unusable() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		self::assertTrue( Auth::has_usable_token() );

		$this->http_body = wp_json_encode( [ 'error' => 'invalid_grant' ] );
		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => $this->http_body,
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$result = Auth::refresh_access_token( 'site-id', 'site-secret', 'stored-refresh' );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertFalse( Auth::has_usable_token() );
	}

	/**
	 * Connect a page whose access token has expired, and stub a refresh that renews it.
	 *
	 * @return int ID of a published post already shared to Nextdoor.
	 */
	private function share_a_post_with_an_expired_token() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
				'page_id'          => 'page-123',
				'publication_url'  => 'https://example.com',
			]
		);

		$this->http_body = wp_json_encode(
			[
				'access_token' => 'new-access',
				'expires_in'   => 3600,
			]
		);
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		return $post_id;
	}

	/**
	 * Updating a shared post renews an expired token, and sends the renewed one.
	 */
	public function test_update_post_refreshes_an_expired_token() {
		$post_id = $this->share_a_post_with_an_expired_token();

		$request = new WP_REST_Request( 'PUT', '/newspack/v1/nextdoor/update-post/' . $post_id );
		$request->set_param( 'id', $post_id );

		$response = Controller::api_update_post( $request );

		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( 'new-access', Nextdoor::get_settings()['access_token'] );
		self::assertStringContainsString( 'auth.nextdoor.com', $this->http_requests[0]['url'] );
		self::assertSame( 'Bearer new-access', $this->http_requests[1]['args']['headers']['Authorization'] );
	}

	/**
	 * Removing a shared post renews an expired token, and sends the renewed one.
	 */
	public function test_delete_post_refreshes_an_expired_token() {
		$post_id = $this->share_a_post_with_an_expired_token();

		$request = new WP_REST_Request( 'DELETE', '/newspack/v1/nextdoor/delete-post/' . $post_id );
		$request->set_param( 'id', $post_id );

		$response = Controller::api_delete_post( $request );

		self::assertTrue( $response->get_data()['success'] );
		self::assertSame( 'new-access', Nextdoor::get_settings()['access_token'] );
		self::assertStringContainsString( 'auth.nextdoor.com', $this->http_requests[0]['url'] );
		self::assertSame( 'Bearer new-access', $this->http_requests[1]['args']['headers']['Authorization'] );
	}

	/**
	 * A token that cannot be renewed stops the update before it reaches Nextdoor.
	 */
	public function test_update_post_refuses_an_unrenewable_token() {
		$post_id = $this->share_a_post_with_an_expired_token();

		Nextdoor::update_settings( array_merge( Nextdoor::get_settings(), [ 'refresh_token' => '' ] ) );

		$request = new WP_REST_Request( 'PUT', '/newspack/v1/nextdoor/update-post/' . $post_id );
		$request->set_param( 'id', $post_id );

		$result = Controller::api_update_post( $request );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_token_invalid', $result->get_error_code() );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * Claiming a page renews an expired token, and sends the renewed one.
	 */
	public function test_claim_page_refreshes_an_expired_token() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		$this->http_body = wp_json_encode(
			[
				'access_token' => 'new-access',
				'expires_in'   => 3600,
				'page_id'      => 'page-123',
			]
		);
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/claim-page' );
		$request->set_param( 'publication_url', 'https://example.com' );

		$result = Controller::api_claim_page( $request );

		self::assertTrue( $result->get_data()['success'] );
		self::assertSame( 'new-access', Nextdoor::get_settings()['access_token'] );
		self::assertStringContainsString( 'auth.nextdoor.com', $this->http_requests[0]['url'] );
		self::assertSame( 'Bearer new-access', $this->http_requests[1]['args']['headers']['Authorization'] );
	}

	/**
	 * A token that cannot be renewed stops the claim before it reaches Nextdoor.
	 */
	public function test_claim_page_refuses_an_unrenewable_token() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => '',
				'token_expires_at' => time() - 10,
			]
		);
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/claim-page' );
		$request->set_param( 'publication_url', 'https://example.com' );

		$result = Controller::api_claim_page( $request );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_token_invalid', $result->get_error_code() );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * The settings response carries what the form needs and nothing secret.
	 */
	public function test_settings_response_never_carries_the_client_secret() {
		Optional_Modules::activate_optional_module( 'nextdoor' );
		$this->connect_with_a_valid_token();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::SETTINGS_ROUTE ) );
		$settings = $response->get_data()['settings'];

		self::assertSame( 200, $response->get_status() );
		self::assertArrayNotHasKey( 'client_secret', $settings );
		self::assertArrayNotHasKey( 'access_token', $settings );
		self::assertArrayNotHasKey( 'refresh_token', $settings );
		self::assertSame( [ 'client_id', 'publication_url', 'allowed_roles' ], array_keys( $settings ) );
	}

	/**
	 * Turning the module off takes the publishing capability with it.
	 */
	public function test_disabling_the_module_revokes_the_publishing_capability() {
		Optional_Modules::activate_optional_module( 'nextdoor' );
		$this->update_settings( [ 'allowed_roles' => [ 'administrator', 'editor' ] ] );

		self::assertTrue( get_role( 'editor' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );

		$response = $this->update_settings( [ 'module_enabled_nextdoor' => false ] );

		self::assertSame( 200, $response->get_status() );
		self::assertFalse( get_role( 'editor' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
		self::assertFalse( get_role( 'administrator' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );

		$this->update_settings( [ 'module_enabled_nextdoor' => true ] );

		self::assertTrue( get_role( 'editor' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
	}

	/**
	 * Reader roles are neither offered nor grantable, so one tick cannot reach the audience.
	 */
	public function test_reader_roles_are_not_offered_or_granted() {
		Optional_Modules::activate_optional_module( 'nextdoor' );

		$offered = wp_list_pluck( Nextdoor::get_available_roles(), 'value' );

		self::assertNotContains( 'subscriber', $offered );
		self::assertContains( 'editor', $offered );

		$response = $this->update_settings( [ 'allowed_roles' => [ 'administrator', 'subscriber' ] ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'administrator' ], Nextdoor::get_settings()['allowed_roles'] );
		self::assertFalse( get_role( 'subscriber' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
	}

	/**
	 * The capability says whether a user may share at all; the post says which ones.
	 */
	public function test_post_routes_refuse_a_post_the_user_cannot_edit() {
		$post_id = $this->share_a_post_with_an_expired_token();
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_author' => $this->factory->user->create( [ 'role' => 'author' ] ),
			]
		);

		get_role( 'author' )->add_cap( Nextdoor::CAPABILITY_SLUG );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		self::assertTrue( Nextdoor::can_user_publish() );

		foreach ( [ 'api_publish_post', 'api_update_post', 'api_delete_post' ] as $handler ) {
			$request = new WP_REST_Request( 'POST', '/newspack/v1/nextdoor/' . $handler );
			$request->set_param( 'id', $post_id );

			$result = Controller::$handler( $request );

			self::assertInstanceOf( 'WP_Error', $result, $handler );
			self::assertSame( 'nextdoor_post_forbidden', $result->get_error_code(), $handler );
		}

		self::assertSame( [], $this->http_requests );
	}

	/**
	 * Platform constants arriving later do not take the site's own credentials with them.
	 */
	public function test_a_site_keeps_its_own_credentials_when_platform_constants_appear() {
		if ( defined( 'NEWSPACK_NEXTDOOR_CLIENT_ID' ) || defined( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' ) ) {
			self::markTestSkipped( 'Centralized credentials are defined as constants in this environment.' );
		}

		Nextdoor::update_settings(
			[
				'client_id'     => 'own-id',
				'client_secret' => 'own-secret',
			]
		);

		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_ID=platform-id' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET=platform-secret' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		// Any write reads through get_settings(), which overlays the platform values.
		Nextdoor::update_settings( Nextdoor::get_settings() );

		self::assertSame( 'platform-secret', Nextdoor::get_settings()['client_secret'] );

		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_ID' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'NEWSPACK_NEXTDOOR_CLIENT_SECRET' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv

		self::assertSame( 'own-id', Nextdoor::get_settings()['client_id'] );
		self::assertSame( 'own-secret', Nextdoor::get_settings()['client_secret'] );
	}
}
