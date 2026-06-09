<?php
/**
 * Class Test Legacy api_set_settings.
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests the legacy `Newspack_Newsletters::api_set_settings` endpoint.
 */
class Api_Set_Settings_Test extends WP_UnitTestCase {
	/**
	 * Reset between tests so option mutations don't leak between cases.
	 */
	public function tear_down() {
		$keys = [
			'newspack_newsletters_service_provider',
			'newspack_mailchimp_api_key',
			'newspack_newsletters_mailchimp_api_key',
			'newspack_newsletters_constant_contact_api_key',
			'newspack_newsletters_constant_contact_api_secret',
			'newspack_newsletters_active_campaign_url',
			'newspack_newsletters_active_campaign_key',
		];
		foreach ( $keys as $key ) {
			delete_option( $key );
		}
		Newspack_Newsletters::memoize_service_provider();
		parent::tear_down();
	}

	/**
	 * Build a request without dispatching through the REST server — the
	 * test targets the controller method directly.
	 *
	 * @param array $params Body params.
	 * @return WP_REST_Request
	 */
	private function rest_request( $params = [] ) {
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/settings' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * A rejected credentials POST must not leave the site pointing at the
	 * rejected provider — set_service_provider should only commit on
	 * credentials success.
	 */
	public function test_rejected_credentials_do_not_switch_provider() {
		update_option( 'newspack_newsletters_service_provider', 'manual' );
		Newspack_Newsletters::memoize_service_provider();

		$result = Newspack_Newsletters::api_set_settings(
			$this->rest_request(
				[
					'service_provider' => 'active_campaign',
					'credentials'      => [
						'url' => '',
						'key' => '',
					],
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( 'newspack_newsletters_invalid_keys' ) );
		$this->assertSame( 400, $result->get_error_data( 'newspack_newsletters_invalid_keys' )['status'] ?? null );
		$this->assertSame(
			'manual',
			get_option( 'newspack_newsletters_service_provider' ),
			'Provider option must roll back to the previous value on credential rejection.'
		);
	}

	/**
	 * Switching to `manual` skips the credential block and persists.
	 */
	public function test_switch_to_manual_persists() {
		update_option( 'newspack_newsletters_service_provider', 'active_campaign' );
		Newspack_Newsletters::memoize_service_provider();

		$result = Newspack_Newsletters::api_set_settings(
			$this->rest_request( [ 'service_provider' => 'manual' ] )
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'manual', get_option( 'newspack_newsletters_service_provider' ) );
	}

	/**
	 * A non-string `service_provider` (e.g. an array payload from
	 * `service_provider[]=mailchimp`) is rejected before it can trip a
	 * TypeError in `isset( $providers[ $slug ] )` on PHP 8.
	 */
	public function test_non_string_service_provider_rejected() {
		update_option( 'newspack_newsletters_service_provider', 'manual' );
		Newspack_Newsletters::memoize_service_provider();

		$result = Newspack_Newsletters::api_set_settings(
			$this->rest_request( [ 'service_provider' => [ 'mailchimp' ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( 'newspack_newsletters_no_service_provider' ) );
		$this->assertSame( 400, $result->get_error_data( 'newspack_newsletters_no_service_provider' )['status'] ?? null );
		$this->assertSame( 'manual', get_option( 'newspack_newsletters_service_provider' ) );
	}

	/**
	 * A non-array `credentials` payload (e.g. `credentials=foo`) is
	 * rejected before reaching the provider's set_api_credentials() —
	 * which would otherwise do string-offset access on `$credentials['…']`.
	 */
	public function test_non_array_credentials_rejected() {
		update_option( 'newspack_newsletters_service_provider', 'manual' );
		Newspack_Newsletters::memoize_service_provider();

		$result = Newspack_Newsletters::api_set_settings(
			$this->rest_request(
				[
					'service_provider' => 'active_campaign',
					'credentials'      => 'foo',
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( 'newspack_newsletters_invalid_keys' ) );
		$this->assertSame( 400, $result->get_error_data( 'newspack_newsletters_invalid_keys' )['status'] ?? null );
		$this->assertSame( 'manual', get_option( 'newspack_newsletters_service_provider' ) );
	}

	/**
	 * Accepted credentials commit both the provider option and the
	 * credential fields.
	 */
	public function test_accepted_credentials_commit_provider_switch() {
		update_option( 'newspack_newsletters_service_provider', 'manual' );
		Newspack_Newsletters::memoize_service_provider();

		$result = Newspack_Newsletters::api_set_settings(
			$this->rest_request(
				[
					'service_provider' => 'active_campaign',
					'credentials'      => [
						'url' => 'https://example.api-us1.com',
						'key' => 'test-key',
					],
				]
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'active_campaign', get_option( 'newspack_newsletters_service_provider' ) );
		$this->assertSame( 'https://example.api-us1.com', get_option( 'newspack_newsletters_active_campaign_url' ) );
		$this->assertSame( 'test-key', get_option( 'newspack_newsletters_active_campaign_key' ) );
	}
}
