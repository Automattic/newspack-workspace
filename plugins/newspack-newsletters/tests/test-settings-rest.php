<?php
/**
 * Class Test Settings REST
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Settings_REST;

/**
 * Tests the standalone-mode Settings REST surface.
 */
class Settings_REST_Test extends WP_UnitTestCase {
	/**
	 * Set the current user to a fresh administrator.
	 *
	 * @return int User ID.
	 */
	private function become_admin() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Build a request without dispatching through the REST server — the
	 * tests target the controller methods directly.
	 *
	 * @param string $method HTTP verb.
	 * @param array  $params Body params.
	 * @return WP_REST_Request
	 */
	private function rest_request( $method, $params = [] ) {
		$request = new WP_REST_Request( $method, '/newspack-newsletters/v1/admin-shell/settings' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Reset between tests so option mutations don't leak — including the
	 * per-provider credential keys, otherwise test order can flip the
	 * `credentials_set` flag map for later tests.
	 */
	public function tear_down() {
		$keys = [
			'newspack_newsletters_service_provider',
			'newspack_newsletters_public_posts_slug',
			'newspack_newsletters_support_comments',
			'newspack_newsletters_use_click_tracking',
			'newspack_newsletters_use_tracking_pixel',
			'newspack_mailchimp_api_key',
			'newspack_newsletters_constant_contact_api_key',
			'newspack_newsletters_constant_contact_api_secret',
			'newspack_newsletters_active_campaign_url',
			'newspack_newsletters_active_campaign_key',
		];
		foreach ( $keys as $key ) {
			delete_option( $key );
		}
		delete_transient( 'newspack_newsletters_oauth_valid_constant_contact' );
		delete_transient( 'newspack_newsletters_oauth_valid_mailchimp' );
		// Reset the memoized provider instance so later tests can't see
		// a stale `Newspack_Newsletters::$provider` after the option was
		// deleted out from under it.
		Newspack_Newsletters::memoize_service_provider();
		parent::tear_down();
	}

	/**
	 * Permission gate rejects users without `manage_options`.
	 */
	public function test_permission_check_rejects_subscribers() {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$result = Settings_REST::permission_check( $this->rest_request( 'GET' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Permission gate accepts administrators.
	 */
	public function test_permission_check_accepts_administrators() {
		$this->become_admin();

		$this->assertTrue( Settings_REST::permission_check( $this->rest_request( 'GET' ) ) );
	}

	/**
	 * GET payload exposes the top-level keys the React shell expects.
	 */
	public function test_get_payload_shape() {
		$this->become_admin();

		$response = Settings_REST::get_settings( $this->rest_request( 'GET' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'provider', $data );
		$this->assertArrayHasKey( 'providers', $data );
		$this->assertArrayHasKey( 'options', $data );
		$this->assertArrayHasKey( 'schema', $data );

		$this->assertArrayHasKey( 'selected', $data['provider'] );
		$this->assertArrayHasKey( 'credentials_set', $data['provider'] );
		$this->assertArrayHasKey( 'status', $data['provider'] );
		$this->assertArrayNotHasKey( 'credentials', $data['provider'], 'Raw credentials must not leak to the client.' );
	}

	/**
	 * Schema entries are JSON-safe — the raw `sanitize` callable kept
	 * server-side for write-time validation must not ship to the client.
	 */
	public function test_schema_strips_sanitize_callable() {
		$this->become_admin();

		$response = Settings_REST::get_settings( $this->rest_request( 'GET' ) );
		$schema   = $response->get_data()['schema'];

		$this->assertNotEmpty( $schema );
		foreach ( $schema as $entry ) {
			$this->assertArrayNotHasKey( 'sanitize', $entry );
		}
	}

	/**
	 * Empty provider slug rejected with a 400.
	 */
	public function test_update_rejects_empty_provider_slug() {
		$this->become_admin();
		update_option( 'newspack_newsletters_service_provider', 'manual' );

		$result = Settings_REST::update_settings(
			$this->rest_request( 'POST', [ 'provider' => [ 'slug' => '' ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data( 'newspack_newsletters_no_service_provider' )['status'] );
		$this->assertSame( 'manual', get_option( 'newspack_newsletters_service_provider' ) );
	}

	/**
	 * Unknown provider slug rejected with a 400 and the stored option is
	 * untouched (no mid-flight write to an invalid provider).
	 */
	public function test_update_rejects_unknown_provider_slug() {
		$this->become_admin();
		update_option( 'newspack_newsletters_service_provider', 'manual' );

		$result = Settings_REST::update_settings(
			$this->rest_request( 'POST', [ 'provider' => [ 'slug' => 'definitely-not-real' ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data( 'newspack_newsletters_invalid_provider' )['status'] );
		$this->assertSame( 'manual', get_option( 'newspack_newsletters_service_provider' ) );
	}

	/**
	 * Switching to `manual` skips the credential block and persists.
	 */
	public function test_update_switches_to_manual_provider() {
		$this->become_admin();

		$response = Settings_REST::update_settings(
			$this->rest_request( 'POST', [ 'provider' => [ 'slug' => 'manual' ] ] )
		);

		$this->assertNotInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'manual', get_option( 'newspack_newsletters_service_provider' ) );
	}

	/**
	 * Options writes are gated by the schema whitelist; unknown keys are
	 * silently dropped.
	 */
	public function test_update_options_drops_unknown_keys() {
		$this->become_admin();

		Settings_REST::update_settings(
			$this->rest_request(
				'POST',
				[
					'options' => [
						'newspack_newsletters_public_posts_slug' => 'My Custom Slug',
						'definitely_not_a_setting' => 'should be ignored',
					],
				]
			)
		);

		$this->assertSame( 'my-custom-slug', get_option( 'newspack_newsletters_public_posts_slug' ) );
		$this->assertFalse( get_option( 'definitely_not_a_setting' ), 'Unknown keys must not be written.' );
	}

	/**
	 * Boolean options coerced through `boolval` regardless of incoming
	 * representation (string, int, or bool).
	 */
	public function test_update_options_coerces_booleans() {
		$this->become_admin();

		Settings_REST::update_settings(
			$this->rest_request(
				'POST',
				[
					'options' => [
						'newspack_newsletters_support_comments'  => '1',
						'newspack_newsletters_use_click_tracking' => false,
					],
				]
			)
		);

		$this->assertSame( '1', (string) get_option( 'newspack_newsletters_support_comments' ) );
		// Default-true tracking option must persist `0` so the next
		// `get_option( …, default=true )` read returns the user's `false`
		// choice rather than falling back to the default.
		$this->assertSame( '0', (string) get_option( 'newspack_newsletters_use_click_tracking' ) );

		$response = Settings_REST::get_settings( $this->rest_request( 'GET' ) );
		$options  = $response->get_data()['options'];
		$this->assertFalse( $options['newspack_newsletters_use_click_tracking'] );
	}

	/**
	 * `credentials_set` is a per-allowlisted-field bool map for the active
	 * provider — never the raw values.
	 */
	public function test_credentials_set_returns_bool_flags() {
		$this->become_admin();
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'abc-us1' );

		$response = Settings_REST::get_settings( $this->rest_request( 'GET' ) );
		$flags    = $response->get_data()['provider']['credentials_set'];

		$this->assertIsArray( $flags );
		$this->assertArrayHasKey( 'api_key', $flags );
		$this->assertTrue( $flags['api_key'] );
	}

	/**
	 * Settings GET serves the cached OAuth snapshot rather than calling
	 * the provider's verify_token() on every page load.
	 */
	public function test_oauth_validity_served_from_transient() {
		$this->become_admin();
		Newspack_Newsletters::set_service_provider( 'constant_contact' );
		set_transient( 'newspack_newsletters_oauth_valid_constant_contact', [ 'valid' => true ], 60 );

		$oauth = Settings_REST::get_settings( $this->rest_request( 'GET' ) )->get_data()['provider']['oauth'];

		$this->assertTrue( $oauth['valid'] );
	}

	/**
	 * `auth_url` carries a session-token-scoped `wp_create_nonce` — it
	 * must not be persisted in the transient, otherwise a cached URL
	 * from a different session would fail the callback nonce check.
	 */
	public function test_oauth_auth_url_is_not_cached() {
		$this->become_admin();
		Newspack_Newsletters::set_service_provider( 'constant_contact' );
		set_transient( 'newspack_newsletters_oauth_valid_constant_contact', [ 'valid' => true ], 60 );

		Settings_REST::get_settings( $this->rest_request( 'GET' ) );

		$stored = get_transient( 'newspack_newsletters_oauth_valid_constant_contact' );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'auth_url', $stored );
	}

	/**
	 * Successful provider-switch busts the cached OAuth validity so the
	 * next GET reflects the new credentials.
	 */
	public function test_oauth_cache_busted_on_manual_switch() {
		$this->become_admin();
		Newspack_Newsletters::set_service_provider( 'constant_contact' );
		set_transient( 'newspack_newsletters_oauth_valid_constant_contact', [ 'valid' => true ], 60 );

		Settings_REST::update_settings( $this->rest_request( 'POST', [ 'provider' => [ 'slug' => 'manual' ] ] ) );

		$this->assertFalse( get_transient( 'newspack_newsletters_oauth_valid_constant_contact' ) );
	}

	/**
	 * Firing the `newspack_newsletters_provider_credentials_changed` action
	 * busts the cached OAuth validity — used by the OAuth callback path
	 * so the post-authorize Settings reload sees fresh state.
	 */
	public function test_oauth_cache_busted_on_credentials_changed_action() {
		$this->become_admin();
		set_transient( 'newspack_newsletters_oauth_valid_constant_contact', [ 'valid' => false ], 60 );

		do_action( 'newspack_newsletters_provider_credentials_changed', 'constant_contact' );

		$this->assertFalse( get_transient( 'newspack_newsletters_oauth_valid_constant_contact' ) );
	}
}
