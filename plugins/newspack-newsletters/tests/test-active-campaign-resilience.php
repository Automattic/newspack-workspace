<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for ActiveCampaign send-path resilience to a stuck/unresponsive AC API.
 *
 * Background: ActiveCampaign occasionally stops responding to calls that
 * reference a particular campaign. Those requests hang until the cURL timeout
 * (error 28, "0 bytes received"). This spec pins the two defenses against that:
 *
 *   1. Cleanup of a previously-created campaign must use a short, bounded
 *      timeout so a stuck campaign cannot block a fresh send for the full
 *      default timeout.
 *   2. A transport timeout must surface to the publisher as actionable guidance
 *      rather than a raw cURL string, while other transport errors pass through
 *      untouched.
 *
 * @package Newspack_Newsletters
 */

/**
 * Test ActiveCampaign resilience.
 */
class ActiveCampaignResilienceTest extends WP_UnitTestCase {

	/**
	 * Timeouts captured from every intercepted HTTP request, in order.
	 *
	 * @var int[]
	 */
	private $captured_timeouts = [];

	/**
	 * Transport error to return from the mocked HTTP layer, or null for success.
	 *
	 * @var WP_Error|null
	 */
	private $transport_error = null;

	/**
	 * Decoded body the mocked HTTP layer returns on success.
	 *
	 * @var array
	 */
	private $response_body = [ 'result_code' => 1 ];

	/**
	 * Set up: configure credentials and intercept all outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
		$this->captured_timeouts = [];
		$this->transport_error   = null;
		$this->response_body     = [ 'result_code' => 1 ];
		Newspack_Newsletters_Active_Campaign::instance()->set_api_credentials(
			[
				'url' => 'https://example.api-us1.com',
				'key' => 'test-key',
			]
		);
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * Intercept outbound requests: record the timeout and return a canned result.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    HTTP request arguments.
	 * @param string $url     Request URL.
	 *
	 * @return array|WP_Error
	 */
	public function mock_http( $preempt, $args, $url ) {
		$this->captured_timeouts[] = $args['timeout'];
		if ( $this->transport_error ) {
			return $this->transport_error;
		}
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( $this->response_body ),
		];
	}

	/**
	 * A caller-supplied timeout must reach the HTTP layer. The request args merge
	 * (`$args + $options`) silently drops it unless the request method honors it
	 * explicitly, so this guards the plumbing the cleanup fix depends on.
	 */
	public function test_api_v1_request_honors_caller_supplied_timeout() {
		Newspack_Newsletters_Active_Campaign::instance()->api_v1_request( 'campaign_delete', 'GET', [ 'timeout' => 12 ] );
		$this->assertSame( 12, end( $this->captured_timeouts ) );
	}

	/**
	 * Absent an explicit timeout, requests use the default.
	 */
	public function test_api_v1_request_defaults_to_default_timeout() {
		Newspack_Newsletters_Active_Campaign::instance()->api_v1_request( 'campaign_list', 'GET' );
		$this->assertSame(
			Newspack_Newsletters_Active_Campaign::DEFAULT_REQUEST_TIMEOUT,
			end( $this->captured_timeouts )
		);
	}

	/**
	 * Campaign cleanup (campaign_list + campaign_delete) must use the bounded
	 * cleanup timeout, not the full default, so a stuck prior campaign fails fast
	 * instead of stranding the send that triggered the cleanup.
	 */
	public function test_delete_campaign_uses_bounded_cleanup_timeout() {
		$active_campaign = Newspack_Newsletters_Active_Campaign::instance();
		$delete_campaign = new ReflectionMethod( $active_campaign, 'delete_campaign' );
		$delete_campaign->setAccessible( true );
		$delete_campaign->invoke( $active_campaign, '12345', true );

		$this->assertNotEmpty( $this->captured_timeouts, 'delete_campaign should make at least one request.' );
		foreach ( $this->captured_timeouts as $timeout ) {
			$this->assertSame(
				Newspack_Newsletters_Active_Campaign::CLEANUP_REQUEST_TIMEOUT,
				$timeout,
				'Every cleanup request must use the bounded cleanup timeout.'
			);
		}
	}

	/**
	 * The bounded cleanup timeout must be shorter than the default, otherwise it
	 * provides no protection against a hung cleanup call.
	 */
	public function test_cleanup_timeout_is_shorter_than_default() {
		$this->assertLessThan(
			Newspack_Newsletters_Active_Campaign::DEFAULT_REQUEST_TIMEOUT,
			Newspack_Newsletters_Active_Campaign::CLEANUP_REQUEST_TIMEOUT
		);
	}

	/**
	 * A cURL timeout (error 28) from the v1 API must be rephrased into a
	 * publisher-friendly, ActiveCampaign-attributed message.
	 */
	public function test_v1_timeout_is_humanized() {
		$this->transport_error = new WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 45002 milliseconds with 0 bytes received'
		);
		$result = Newspack_Newsletters_Active_Campaign::instance()->api_v1_request( 'campaign_list', 'GET' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'newspack_newsletters_active_campaign_timeout', $result->get_error_code() );
		$this->assertStringContainsString( 'ActiveCampaign', $result->get_error_message() );
	}

	/**
	 * The humanized timeout must keep the original transport failure as error
	 * data so logging and support tooling retain the underlying cURL detail.
	 */
	public function test_humanized_timeout_preserves_original_error() {
		$this->transport_error = new WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 45002 milliseconds with 0 bytes received'
		);
		$result = Newspack_Newsletters_Active_Campaign::instance()->api_v1_request( 'campaign_list', 'GET' );

		$data = $result->get_error_data();
		$this->assertSame( 'http_request_failed', $data['original_error_code'] );
		$this->assertStringContainsString( 'cURL error 28', $data['original_error_message'] );
		$this->assertArrayHasKey( 'original_error_data', $data );
	}

	/**
	 * The same translation applies to the v3 API.
	 */
	public function test_v3_timeout_is_humanized() {
		$this->transport_error = new WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 45002 milliseconds with 0 bytes received'
		);
		$result = Newspack_Newsletters_Active_Campaign::instance()->api_v3_request( 'audiences', 'GET' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'newspack_newsletters_active_campaign_timeout', $result->get_error_code() );
	}

	/**
	 * Non-timeout transport errors must pass through unchanged so genuine
	 * failures keep their original, more specific message.
	 */
	public function test_non_timeout_transport_error_passes_through() {
		$this->transport_error = new WP_Error(
			'http_request_failed',
			'cURL error 6: Could not resolve host: example.api-us1.com'
		);
		$result = Newspack_Newsletters_Active_Campaign::instance()->api_v1_request( 'campaign_list', 'GET' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Could not resolve host', $result->get_error_message() );
	}
}
