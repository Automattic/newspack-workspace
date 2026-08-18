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
		delete_option( Auth::REFUSAL_OPTION );
		delete_transient( Auth::REFRESH_COOLOFF_TRANSIENT );
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
		delete_option( Auth::REFUSAL_OPTION );
		delete_transient( Auth::REFRESH_COOLOFF_TRANSIENT );

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
	 * Store a finished connection: a token nowhere near expiry and a claimed page.
	 */
	private function connect_with_a_claimed_page() {
		$this->connect_with_a_valid_token();
		Nextdoor::update_settings( array_merge( Nextdoor::get_settings(), [ 'page_id' => 'page-123' ] ) );
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
	 * A credential is stored byte for byte. `sanitize_text_field()` drops every `%`
	 * followed by two hex digits, which would store an opaque secret quietly short.
	 */
	public function test_a_credential_keeps_a_percent_sequence() {
		Optional_Modules::activate_optional_module( 'nextdoor' );

		$secret = 'sk_%2Flive%2Fabc%7C99';

		$this->update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => $secret,
			]
		);

		self::assertSame( $secret, get_option( Nextdoor::SETTINGS_SLUG )['client_secret'] );
	}

	/**
	 * Blank means no change, so a value that sanitises away cannot wipe a working
	 * credential the form deliberately never sends back.
	 */
	public function test_an_emptied_credential_keeps_the_stored_one() {
		Optional_Modules::activate_optional_module( 'nextdoor' );

		$this->update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
			]
		);

		$this->update_settings( [ 'client_secret' => '   ' ] );

		self::assertSame( 'site-secret', get_option( Nextdoor::SETTINGS_SLUG )['client_secret'] );
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
	 * Declining at Nextdoor returns an error and no code, which has to reach the card
	 * rather than leaving the publisher on a closed one with nothing said.
	 */
	public function test_a_denied_authorization_is_reported() {
		$redirect_to = null;
		$capture     = function ( $location ) use ( &$redirect_to ) {
			$redirect_to = $location;
			throw new Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $capture, 1 );

		$state = Auth::create_oauth_state();

		$_GET['nextdoor_oauth_callback'] = '1';
		$_GET['state']                   = $state;
		$_GET['error']                   = 'access_denied';
		$_GET['error_description']       = 'The publisher declined.';

		try {
			try {
				Auth::handle_oauth_callback();
				self::fail( 'Expected a redirect.' );
			} catch ( Exception $e ) {
				self::assertStringContainsString( 'redirect_intercepted', $e->getMessage() );
			}

			self::assertStringContainsString( 'nextdoor_oauth_error=', (string) $redirect_to );
			self::assertStringContainsString( rawurlencode( 'The publisher declined.' ), (string) $redirect_to );
			// Consumed on the way through, so the denied attempt cannot be replayed.
			self::assertFalse( Auth::verify_oauth_state( $state ) );
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
			unset( $_GET['nextdoor_oauth_callback'], $_GET['state'], $_GET['error'], $_GET['error_description'] );
		}
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
	 * A misconfigured app is refused too, and correcting it should be enough.
	 */
	public function test_a_configuration_error_is_not_recorded_as_a_refusal() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'wrong-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'invalid_client' ] ),
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$result = Auth::refresh_access_token( 'site-id', 'wrong-secret', 'stored-refresh' );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
		// Still renewable, so fixing the credentials is all it takes.
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * A refusal stops describing the connection once its credential is replaced.
	 */
	public function test_a_refusal_lapses_when_its_credential_is_replaced() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'refused-refresh',
				'token_expires_at' => time() - 10,
			]
		);
		update_option( Auth::REFUSAL_OPTION, [ 'refresh_token' => wp_hash( 'refused-refresh' ) ] );

		self::assertFalse( Auth::has_usable_token() );

		// A concurrent sign-in rotates the token the refusal was about.
		Nextdoor::update_settings( array_merge( Nextdoor::get_settings(), [ 'refresh_token' => 'rotated-refresh' ] ) );

		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * Losing a refresh race does not fail the request that lost it.
	 */
	public function test_losing_a_refresh_race_still_reports_a_valid_token() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'superseded-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		// The winner rotates the token and renews the grant while this request is in
		// flight; Nextdoor then refuses the token this one set out with.
		add_filter(
			'pre_http_request',
			function () {
				Nextdoor::update_settings(
					array_merge(
						Nextdoor::get_settings(),
						[
							'access_token'     => 'winner-access',
							'refresh_token'    => 'rotated-refresh',
							'token_expires_at' => time() + HOUR_IN_SECONDS,
						]
					)
				);

				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'invalid_grant' ] ),
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		self::assertTrue( Auth::validate_token() );
		self::assertSame( 'winner-access', Nextdoor::get_settings()['access_token'] );
		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
	}

	/**
	 * A grant that never said how long it lasts is due, not good forever.
	 */
	public function test_a_token_without_an_expiry_is_treated_as_due() {
		Nextdoor::update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
				'access_token'  => 'no-expiry-access',
			]
		);

		self::assertTrue( Auth::needs_token_refresh() );
		// Nothing to renew it with, so the connection reports itself as unusable.
		self::assertFalse( Auth::has_usable_token() );

		Nextdoor::update_settings( array_merge( Nextdoor::get_settings(), [ 'refresh_token' => 'stored-refresh' ] ) );

		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * A grant response with no expiry is stored as already due.
	 */
	public function test_a_grant_without_an_expiry_is_stored_as_due() {
		Nextdoor::update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
				'access_token'  => 'old-access',
				'refresh_token' => 'stored-refresh',
			]
		);

		$this->http_body = wp_json_encode( [ 'access_token' => 'new-access' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		Auth::refresh_access_token( 'site-id', 'site-secret', 'stored-refresh' );

		self::assertSame( 'new-access', Nextdoor::get_settings()['access_token'] );
		self::assertNotEmpty( Nextdoor::get_settings()['token_expires_at'] );
		self::assertTrue( Auth::needs_token_refresh() );
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

		foreach ( [ 'api_publish_post', 'api_update_post', 'api_delete_post', 'api_get_post_sharing_status' ] as $handler ) {
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

	/**
	 * A dead grant asks for a reconnection; an unreachable Nextdoor does not.
	 */
	public function test_the_status_route_tells_a_dead_grant_from_an_outage() {
		// Installed first so the "nothing went out" assertion below has something to see.
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		// Nothing left to renew with.
		Nextdoor::update_settings(
			[
				'access_token'     => 'expired-access',
				'token_expires_at' => time() - 10,
				'page_id'          => 'page-123',
			]
		);

		$dead = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertTrue( $dead['needs_reconnect'] );
		self::assertFalse( $dead['is_unreachable'] );
		self::assertSame( [], $this->http_requests );

		// Renewable, but Nextdoor does not answer.
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
				'page_id'          => 'page-123',
			]
		);
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$outage = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertFalse( $outage['needs_reconnect'] );
		self::assertTrue( $outage['is_unreachable'] );
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * A refusal discovered mid-request is a reconnection, not an outage.
	 */
	public function test_a_grant_refused_during_the_refresh_asks_for_a_reconnection() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
				'page_id'          => 'page-123',
			]
		);

		// The grant is still usable on the way in; the refresh is what discovers otherwise.
		self::assertTrue( Auth::has_usable_token() );

		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'invalid_grant' ] ),
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertTrue( $data['needs_reconnect'] );
		self::assertFalse( $data['is_unreachable'] );
	}

	/**
	 * An outage stops the write without telling the publisher to reconnect.
	 */
	public function test_an_unreachable_refresh_does_not_ask_for_a_reconnection() {
		$post_id = $this->share_a_post_with_an_expired_token();

		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$request = new WP_REST_Request( 'PUT', '/newspack/v1/nextdoor/update-post/' . $post_id );
		$request->set_param( 'id', $post_id );

		$result = Controller::api_update_post( $request );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertSame( 'nextdoor_unreachable', $result->get_error_code() );
		// The grant is intact, so reconnecting would cost the page claim for nothing.
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * A revoked token shows up only on the report, and is still a reconnection.
	 */
	public function test_a_refused_ingestion_report_asks_for_a_reconnection() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		$this->connect_with_a_claimed_page();

		// The report rejects the bearer and the refresh refuses the grant behind it, which
		// together are what a revoked connection looks like.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$refused = false !== strpos( $url, '/v2/token' ) ? 'invalid_grant' : 'unauthorized';

				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => $refused ] ),
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertTrue( $data['needs_reconnect'] );
		self::assertFalse( $data['is_unreachable'] );
		// Persisted, so the card the reconnect link leads to agrees with this answer.
		self::assertNotEmpty( get_option( Auth::REFUSAL_OPTION ) );
		self::assertFalse( Auth::has_usable_token() );
	}

	/**
	 * A rejected access token is renewed rather than recorded: the refresh token is a
	 * separate grant, and a recorded refusal stops any later refresh being attempted.
	 */
	public function test_a_rejected_report_is_renewed_before_it_is_recorded() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		$this->connect_with_a_claimed_page();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '/v2/token' ) ) {
					return [
						'headers'  => [],
						'body'     => wp_json_encode(
							[
								'access_token'  => 'renewed-access',
								'refresh_token' => 'renewed-refresh',
								'expires_in'    => 3600,
							]
						),
						'response' => [
							'code'    => 200,
							'message' => 'OK',
						],
						'cookies'  => [],
						'filename' => null,
					];
				}

				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'unauthorized' ] ),
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		// Nothing recorded, so the next load can use the renewed token rather than sending
		// the publisher through a reconnection that also costs them their claimed page.
		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
		self::assertSame( 'renewed-access', Nextdoor::get_settings()['access_token'] );
		self::assertTrue( Auth::has_usable_token() );
		self::assertFalse( $data['needs_reconnect'] );
		self::assertTrue( $data['is_unreachable'] );
	}

	/**
	 * With nothing to renew with, the rejection is all the evidence there will be.
	 */
	public function test_a_rejected_report_without_a_refresh_token_asks_for_a_reconnection() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'valid-access',
				'refresh_token'    => '',
				'token_expires_at' => time() + HOUR_IN_SECONDS,
				'page_id'          => 'page-123',
			]
		);

		$this->http_body = wp_json_encode( [ 'error' => 'unauthorized' ] );
		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'unauthorized' ] ),
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertTrue( $data['needs_reconnect'] );
		self::assertNotEmpty( get_option( Auth::REFUSAL_OPTION ) );
		self::assertFalse( Auth::has_usable_token() );
	}

	/**
	 * A 403 reads as an outage: an edge or a scope gap is not cured by reconnecting.
	 */
	public function test_a_forbidden_ingestion_report_reads_as_unreachable() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		$this->connect_with_a_claimed_page();

		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'insufficient_scope' ] ),
					'response' => [
						'code'    => 403,
						'message' => 'Forbidden',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertFalse( $data['needs_reconnect'] );
		self::assertTrue( $data['is_unreachable'] );
		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
		// Still renewable, so the next load can recover without a reconnection.
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * A refusal of a token another request has already replaced is not recorded.
	 */
	public function test_a_refusal_of_a_superseded_token_is_not_recorded() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		$this->connect_with_a_claimed_page();

		// A concurrent sign-in replaces the token while this report is in flight.
		add_filter(
			'pre_http_request',
			function () {
				Nextdoor::update_settings( array_merge( Nextdoor::get_settings(), [ 'access_token' => 'newer-access' ] ) );

				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'unauthorized' ] ),
					'response' => [
						'code'    => 401,
						'message' => 'Unauthorized',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		Controller::api_get_post_sharing_status( $request );

		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * The connection is site-wide, so an unshared post hears about it too.
	 */
	public function test_an_unshared_post_is_told_the_connection_is_dead() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		Nextdoor::update_settings(
			[
				'access_token'     => 'expired-access',
				'token_expires_at' => time() - 10,
				'page_id'          => 'page-123',
			]
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertFalse( $data['is_shared'] );
		self::assertTrue( $data['needs_reconnect'] );
		self::assertFalse( $data['needs_setup'] );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * A connection that was never made is setup, not a reconnection.
	 */
	public function test_a_site_that_never_connected_is_told_to_finish_setup() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertFalse( $data['needs_reconnect'] );
		self::assertTrue( $data['needs_setup'] );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * A fresh grant with no page yet is setup too, not a working connection.
	 */
	public function test_a_reconnection_awaiting_its_page_claim_reports_setup() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		// What the OAuth callback leaves behind: a good token and no claimed page.
		$this->connect_with_a_valid_token();

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertFalse( $data['needs_reconnect'] );
		self::assertTrue( $data['needs_setup'] );
		self::assertFalse( $data['is_unreachable'] );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * A fresh grant cannot be assumed to own the page the last one claimed.
	 */
	public function test_signing_in_again_drops_the_claimed_page() {
		Nextdoor::update_settings(
			[
				'client_id'       => 'site-id',
				'client_secret'   => 'site-secret',
				'page_id'         => 'page-123',
				'publication_url' => 'https://example.com',
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

		$state = Auth::create_oauth_state();

		$_GET['nextdoor_oauth_callback'] = '1';
		$_GET['code']                    = 'auth-code';
		$_GET['state']                   = $state;

		try {
			Auth::handle_oauth_callback();
		} catch ( Exception $e ) {
			// The handler ends in a redirect and never returns normally.
			unset( $e );
		}

		$settings = Nextdoor::get_settings();

		self::assertSame( 'new-access', $settings['access_token'] );
		self::assertSame( '', $settings['page_id'] );
		// The URL is still the publisher's own, so it stays to prefill the claim step.
		self::assertSame( 'https://example.com', $settings['publication_url'] );

		unset( $_GET['nextdoor_oauth_callback'], $_GET['code'], $_GET['state'] );
	}

	/**
	 * A fresh grant never inherits the refresh token of the one it replaces.
	 */
	public function test_signing_in_again_drops_the_previous_refresh_token() {
		Nextdoor::update_settings(
			[
				'client_id'     => 'site-id',
				'client_secret' => 'site-secret',
				'access_token'  => 'old-access',
				'refresh_token' => 'previous-refresh',
			]
		);

		// An authorization-code grant that carries no refresh token of its own.
		$this->http_body = wp_json_encode(
			[
				'access_token' => 'new-access',
				'expires_in'   => 3600,
			]
		);
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		$_GET['nextdoor_oauth_callback'] = '1';
		$_GET['code']                    = 'auth-code';
		$_GET['state']                   = Auth::create_oauth_state();

		try {
			Auth::handle_oauth_callback();
		} catch ( Exception $e ) {
			unset( $e );
		}

		$settings = Nextdoor::get_settings();

		self::assertSame( 'new-access', $settings['access_token'] );
		self::assertSame( '', $settings['refresh_token'] );

		unset( $_GET['nextdoor_oauth_callback'], $_GET['code'], $_GET['state'] );
	}

	/**
	 * A report that never comes back is an outage, not a silent blank.
	 */
	public function test_a_failed_ingestion_report_reads_as_unreachable() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_nextdoor_guid', 'guid-1' );

		$this->connect_with_a_claimed_page();

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		$data = Controller::api_get_post_sharing_status( $request )->get_data();

		self::assertFalse( $data['needs_reconnect'] );
		self::assertTrue( $data['is_unreachable'] );
		self::assertNull( $data['ingestion_status'] );
	}

	/**
	 * The remedy offered matches what the reader of it can reach.
	 */
	public function test_the_status_route_reports_who_can_reconnect() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$request = new WP_REST_Request( 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id );
		$request->set_param( 'id', $post_id );

		self::assertTrue( Controller::api_get_post_sharing_status( $request )->get_data()['can_reconnect'] );

		get_role( 'editor' )->add_cap( Nextdoor::CAPABILITY_SLUG );
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );

		self::assertFalse( Controller::api_get_post_sharing_status( $request )->get_data()['can_reconnect'] );
	}

	/**
	 * A reader role stored before it was withheld loses the capability, without a save.
	 */
	public function test_a_stored_reader_role_is_not_granted_the_capability() {
		Optional_Modules::activate_optional_module( 'nextdoor' );
		update_option(
			Nextdoor::SETTINGS_SLUG,
			[ 'allowed_roles' => [ 'administrator', 'editor', 'subscriber' ] ]
		);

		Nextdoor::add_nextdoor_capability();

		self::assertTrue( get_role( 'editor' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
		self::assertFalse( get_role( 'subscriber' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
		self::assertNotContains( 'subscriber', Nextdoor::get_nextdoor_capability_roles() );
	}

	/**
	 * A grant already handed to a reader role is taken back on the next reconciliation.
	 */
	public function test_a_stale_reader_grant_is_revoked() {
		Optional_Modules::activate_optional_module( 'nextdoor' );
		get_role( 'subscriber' )->add_cap( Nextdoor::CAPABILITY_SLUG );
		update_option( Nextdoor::SETTINGS_SLUG, [ 'allowed_roles' => [ 'administrator', 'subscriber' ] ] );

		Nextdoor::add_nextdoor_capability();

		self::assertFalse( get_role( 'subscriber' )->has_cap( Nextdoor::CAPABILITY_SLUG ) );
	}

	/**
	 * A connection already known to be unrenewable is not re-tried on every read.
	 */
	public function test_validate_token_does_not_retry_a_refused_connection() {
		add_filter( 'pre_http_request', [ $this, 'stub_nextdoor_response' ], 10, 3 );

		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		update_option( Auth::REFUSAL_OPTION, [ 'refresh_token' => wp_hash( 'stored-refresh' ) ] );

		self::assertFalse( Auth::validate_token() );
		self::assertSame( [], $this->http_requests );
	}

	/**
	 * A transient refusal is not mistaken for a dead grant.
	 */
	public function test_a_rate_limited_refresh_is_not_recorded_as_a_refusal() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'expired-access',
				'refresh_token'    => 'stored-refresh',
				'token_expires_at' => time() - 10,
			]
		);

		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'rate_limited' ] ),
					'response' => [
						'code'    => 429,
						'message' => 'Too Many Requests',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$result = Auth::refresh_access_token( 'site-id', 'site-secret', 'stored-refresh' );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * Losing a refresh race does not mark the token the winner just renewed.
	 */
	public function test_a_losing_concurrent_refresh_leaves_the_renewed_token_alone() {
		Nextdoor::update_settings(
			[
				'client_id'        => 'site-id',
				'client_secret'    => 'site-secret',
				'access_token'     => 'new-access',
				'refresh_token'    => 'rotated-refresh',
				'token_expires_at' => time() + HOUR_IN_SECONDS,
			]
		);

		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'invalid_grant' ] ),
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		// The refresh token this request set out with has since been rotated away.
		$result = Auth::refresh_access_token( 'site-id', 'site-secret', 'superseded-refresh' );

		self::assertInstanceOf( 'WP_Error', $result );
		self::assertEmpty( get_option( Auth::REFUSAL_OPTION ) );
		self::assertTrue( Auth::has_usable_token() );
	}

	/**
	 * A refresh Nextdoor honours still describes the grant it set out with, and putting
	 * that back over a newer one would undo the sign-in that replaced it.
	 */
	public function test_a_superseded_refresh_does_not_replace_a_newer_token() {
		Nextdoor::update_settings(
			array_merge(
				Nextdoor::get_settings(),
				[
					'client_id'        => 'site-id',
					'client_secret'    => 'site-secret',
					'access_token'     => 'winner-access',
					'refresh_token'    => 'rotated-refresh',
					'token_expires_at' => time() + HOUR_IN_SECONDS,
				]
			)
		);

		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'access_token'  => 'superseded-access',
							'refresh_token' => 'superseded-rotated',
							'expires_in'    => 3600,
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		$result = Auth::refresh_access_token( 'site-id', 'site-secret', 'superseded-refresh' );

		// The payload comes back, so the caller can see what Nextdoor said.
		self::assertSame( 'superseded-access', $result['access_token'] );
		// What is stored belongs to the grant that replaced it.
		self::assertSame( 'winner-access', Nextdoor::get_settings()['access_token'] );
		self::assertSame( 'rotated-refresh', Nextdoor::get_settings()['refresh_token'] );
	}

	/**
	 * A disconnect landing inside the refresh window leaves nothing to authorise with,
	 * and an honoured refresh that was never applied does not say otherwise.
	 */
	public function test_a_disconnect_during_a_refresh_reports_no_usable_token() {
		Nextdoor::update_settings(
			array_merge(
				Nextdoor::get_settings(),
				[
					'client_id'        => 'site-id',
					'client_secret'    => 'site-secret',
					'access_token'     => 'expired-access',
					'refresh_token'    => 'stored-refresh',
					'token_expires_at' => time() - 10,
				]
			)
		);

		self::assertTrue( Auth::has_usable_token() );

		add_filter(
			'pre_http_request',
			function () {
				// The publisher disconnects while this request is out.
				Nextdoor::update_settings(
					array_merge(
						Nextdoor::get_settings(),
						[
							'access_token'  => '',
							'refresh_token' => '',
						]
					)
				);

				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'access_token'  => 'renewed-access',
							'refresh_token' => 'renewed-refresh',
							'expires_in'    => 3600,
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);

		self::assertFalse( Auth::validate_token() );
		self::assertSame( '', Nextdoor::get_settings()['access_token'] );
	}

	/**
	 * Every route turns away a reader, from the registration rather than the handler.
	 *
	 * The handlers are called directly elsewhere, which is what makes the assertions
	 * about their own guards readable, but it also means nothing else here would notice
	 * a `permission_callback` going missing from a route.
	 */
	public function test_every_route_refuses_a_reader() {
		$post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );

		// Required arguments are validated before the permission callback runs, so without a
		// well-formed body a 400 would stand in for the refusal under test.
		$routes = [
			[
				'POST',
				'/newspack/v1/nextdoor/oauth/start',
				[
					'email'   => 'editor@example.com',
					'country' => 'US',
				],
			],
			[ 'PUT', '/newspack/v1/nextdoor/claim-page', [ 'publication_url' => 'https://example.com' ] ],
			[ 'DELETE', '/newspack/v1/nextdoor/disconnect', [] ],
			[ 'GET', '/newspack/v1/nextdoor/post-status/' . $post_id, [] ],
			[ 'POST', '/newspack/v1/nextdoor/publish-post/' . $post_id, [] ],
			[ 'PUT', '/newspack/v1/nextdoor/update-post/' . $post_id, [] ],
			[ 'DELETE', '/newspack/v1/nextdoor/delete-post/' . $post_id, [] ],
			[ 'POST', self::SETTINGS_ROUTE, [] ],
		];

		foreach ( $routes as list( $method, $route, $params ) ) {
			$request = new WP_REST_Request( $method, $route );
			foreach ( $params as $param => $value ) {
				$request->set_param( $param, $value );
			}

			$response = $this->server->dispatch( $request );

			self::assertSame( 403, $response->get_status(), $route );
		}

		self::assertSame( [], $this->http_requests );
	}
}
