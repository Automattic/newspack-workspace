<?php
/**
 * Tests for the test_connection() methods.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test the test_connection() method on the base class, Mailchimp, ActiveCampaign,
 * and the static Newspack_Newsletters::test_connection() entry point.
 */
class Test_Test_Connection extends WP_UnitTestCase {

	/**
	 * Stored pre_http_request callback for removal in tear_down.
	 *
	 * @var callable|null
	 */
	private $http_filter = null;

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'newspack_mailchimp_api_key' );
		delete_option( 'newspack_newsletters_mailchimp_api_key' );
		delete_option( 'newspack_newsletters_active_campaign_url' );
		delete_option( 'newspack_newsletters_active_campaign_key' );
		Newspack_Newsletters_Mailchimp_Api::set_mock_success( true, '' );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() {
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter );
			$this->http_filter = null;
		}
		parent::tear_down();
	}

	/**
	 * Helper to mock HTTP responses for ActiveCampaign.
	 *
	 * @param array|WP_Error $response The mocked response.
	 */
	private function mock_http_response( $response ) {
		$this->http_filter = function () use ( $response ) {
			return $response;
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	// --- Base class tests ---

	/**
	 * Test that the base class test_connection() returns true by default.
	 */
	public function test_base_class_returns_true() {
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		// With no API key, has_api_credentials() is false, but the base class method returns true.
		// We test the base class default by using a provider that doesn't override test_connection().
		// Since all current providers override it, we test via the static entry point with no provider.
		$provider = \Newspack_Newsletters::get_service_provider();
		// The base class method itself returns true, tested indirectly through Constant Contact
		// which does not override test_connection().
		$cc = Newspack_Newsletters_Constant_Contact::instance();
		$result = $cc->test_connection();
		$this->assertTrue( $result );
	}

	// --- Mailchimp tests ---

	/**
	 * Test Mailchimp test_connection() returns WP_Error when credentials are missing.
	 */
	public function test_mailchimp_missing_credentials() {
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$mc = Newspack_Newsletters_Mailchimp::instance();

		$result = $mc->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_newsletters_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test Mailchimp test_connection() returns true on successful ping.
	 */
	public function test_mailchimp_successful_connection() {
		update_option( 'newspack_mailchimp_api_key', 'test-key-us1' );
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		Newspack_Newsletters_Mailchimp_Api::set_mock_success( true );

		$mc     = Newspack_Newsletters_Mailchimp::instance();
		$result = $mc->test_connection();

		$this->assertTrue( $result );
	}

	/**
	 * Test Mailchimp test_connection() returns WP_Error when API reports failure.
	 */
	public function test_mailchimp_api_failure() {
		update_option( 'newspack_mailchimp_api_key', 'test-key-us1' );
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		Newspack_Newsletters_Mailchimp_Api::set_mock_success( false, 'API Key Invalid' );

		$mc     = Newspack_Newsletters_Mailchimp::instance();
		$result = $mc->test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_newsletters_connection_error', $result->get_error_code() );
		$this->assertEquals( 'API Key Invalid', $result->get_error_message() );
	}

	// --- ActiveCampaign tests ---

	/**
	 * Test ActiveCampaign test_connection() returns WP_Error when credentials are missing.
	 */
	public function test_active_campaign_missing_credentials() {
		\Newspack_Newsletters::set_service_provider( 'active_campaign' );
		$ac = Newspack_Newsletters_Active_Campaign::instance();

		$result = $ac->test_connection();

		$this->assertWPError( $result );
	}

	/**
	 * Test ActiveCampaign test_connection() returns true on success.
	 */
	public function test_active_campaign_successful_connection() {
		update_option( 'newspack_newsletters_active_campaign_url', 'https://test.api-us1.com' );
		update_option( 'newspack_newsletters_active_campaign_key', 'test-key' );
		\Newspack_Newsletters::set_service_provider( 'active_campaign' );

		$this->mock_http_response(
			[
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => wp_json_encode( [ 'user' => [ 'id' => 1 ] ] ),
			]
		);

		$ac     = Newspack_Newsletters_Active_Campaign::instance();
		$result = $ac->test_connection();

		$this->assertTrue( $result );
	}

	/**
	 * Test ActiveCampaign test_connection() returns WP_Error on API failure.
	 */
	public function test_active_campaign_api_failure() {
		update_option( 'newspack_newsletters_active_campaign_url', 'https://test.api-us1.com' );
		update_option( 'newspack_newsletters_active_campaign_key', 'bad-key' );
		\Newspack_Newsletters::set_service_provider( 'active_campaign' );

		$this->mock_http_response(
			[
				'response' => [
					'code'    => 403,
					'message' => 'Forbidden',
				],
				'body'     => null,
			]
		);

		$ac     = Newspack_Newsletters_Active_Campaign::instance();
		$result = $ac->test_connection();

		$this->assertWPError( $result );
	}

	// --- Static entry point tests ---

	/**
	 * Test Newspack_Newsletters::test_connection() returns WP_Error when no provider is set.
	 */
	public function test_static_no_provider() {
		\Newspack_Newsletters::set_service_provider( '' );

		$result = \Newspack_Newsletters::test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_newsletters_no_provider', $result->get_error_code() );
	}

	/**
	 * Test Newspack_Newsletters::test_connection() delegates to the active provider.
	 */
	public function test_static_delegates_to_provider() {
		update_option( 'newspack_mailchimp_api_key', 'test-key-us1' );
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		Newspack_Newsletters_Mailchimp_Api::set_mock_success( true );

		$result = \Newspack_Newsletters::test_connection();

		$this->assertTrue( $result );
	}

	/**
	 * Test Newspack_Newsletters::test_connection() propagates provider errors.
	 */
	public function test_static_propagates_error() {
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		// No API key set — Mailchimp's test_connection will return missing credentials error.

		$result = \Newspack_Newsletters::test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'newspack_newsletters_missing_credentials', $result->get_error_code() );
	}
}
