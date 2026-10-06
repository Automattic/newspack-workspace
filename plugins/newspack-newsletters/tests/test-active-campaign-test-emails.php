<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Newsletters Test ActiveCampaign Test Emails
 *
 * @package Newspack_Newsletters
 */

/**
 * Test sending ActiveCampaign test emails to multiple recipients.
 */
class ActiveCampaignTestEmailsTest extends WP_UnitTestCase {

	/**
	 * Recipients of the campaign_send requests made during a test.
	 *
	 * @var string[]
	 */
	private $sent_to = [];

	/**
	 * Recipients whose campaign_send request should fail.
	 *
	 * @var string[]
	 */
	private $failing = [];

	/**
	 * Recipients whose campaign_send request should time out.
	 *
	 * @var string[]
	 */
	private $timing_out = [];

	/**
	 * Request timeout seen by the last mocked request.
	 *
	 * @var int
	 */
	private $seen_timeout = 0;

	/**
	 * Raw URL of the last mocked campaign_send request.
	 *
	 * @var string
	 */
	private $last_url = '';

	/**
	 * Number of ActiveCampaign API requests made during a test.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Mocked ActiveCampaign API URL.
	 *
	 * @var string
	 */
	const API_URL = 'https://example.api-us1.com';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->sent_to       = [];
		$this->failing       = [];
		$this->timing_out    = [];
		$this->seen_timeout  = 0;
		$this->request_count = 0;
		add_filter( 'pre_http_request', [ $this, 'filter_api_response' ], 10, 3 );
		Newspack_Newsletters_Active_Campaign::instance()->set_api_credentials(
			[
				'url' => self::API_URL,
				'key' => 'doesnt_matter',
			]
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'filter_api_response' ], 10 );
		parent::tear_down();
	}

	/**
	 * Mock the ActiveCampaign v1 campaign_send endpoint. Other requests pass
	 * through untouched.
	 *
	 * @param array|false $response    Short-circuit response.
	 * @param array       $parsed_args HTTP request arguments.
	 * @param string      $url         The request URL.
	 *
	 * @return array|false
	 */
	public function filter_api_response( $response, $parsed_args, $url ) {
		if ( 0 !== strpos( $url, self::API_URL . '/' ) ) {
			return $response;
		}
		++$this->request_count;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( '/admin/api.php' !== wp_parse_url( $url, PHP_URL_PATH ) || 'campaign_send' !== ( $query['api_action'] ?? '' ) ) {
			return $response;
		}
		$email              = $query['email'] ?? '';
		$this->sent_to[]    = $email;
		$this->seen_timeout = $parsed_args['timeout'] ?? 0;
		$this->last_url     = $url;
		if ( in_array( $email, $this->timing_out, true ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		$failed = in_array( $email, $this->failing, true );
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode(
				[
					'result_code'    => $failed ? 0 : 1,
					'result_message' => $failed ? 'Invalid email' : 'Test email sent',
				]
			),
		];
	}

	/**
	 * More recipients than the cap are rejected before any API request.
	 */
	public function test_rejects_more_than_max_recipients() {
		$emails = [];
		for ( $i = 0; $i <= Newspack_Newsletters_Active_Campaign::MAX_TEST_RECIPIENTS; $i++ ) {
			$emails[] = "reader$i@example.com";
		}
		$post_id = self::factory()->post->create( [ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );

		$result = Newspack_Newsletters_Active_Campaign::instance()->test( $post_id, $emails );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_active_campaign_too_many_test_recipients', $result->get_error_code() );
		$this->assertSame( 0, $this->request_count );
	}

	/**
	 * Each recipient gets its own campaign_send request.
	 */
	public function test_sends_one_request_per_recipient() {
		$emails = [ 'one@example.com', 'two@example.com', 'three@example.com' ];

		$result = Newspack_Newsletters_Active_Campaign::instance()->send_test_emails( 10, 20, $emails );

		$this->assertSame( $emails, $this->sent_to );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'one@example.com, two@example.com, three@example.com', $result['message'] );
	}

	/**
	 * A failed recipient doesn't stop the others, and the message names both.
	 */
	public function test_partial_failure_reports_sent_and_failed() {
		$this->failing = [ 'two@example.com' ];

		$result = Newspack_Newsletters_Active_Campaign::instance()->send_test_emails( 10, 20, [ 'one@example.com', 'two@example.com', 'three@example.com' ] );

		$this->assertSame( [ 'one@example.com', 'two@example.com', 'three@example.com' ], $this->sent_to );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'one@example.com, three@example.com', $result['message'] );
		$this->assertStringContainsString( 'two@example.com', $result['message'] );
		$this->assertStringContainsString( 'Invalid email', $result['message'] );
	}

	/**
	 * A plus-address reaches ActiveCampaign as written, rather than with its
	 * "+" read back as a space.
	 */
	public function test_plus_address_is_url_encoded() {
		Newspack_Newsletters_Active_Campaign::instance()->send_test_emails( 10, 20, [ 'reader+tag@example.test' ] );

		$this->assertStringContainsString( 'email=reader%2Btag%40example.test', $this->last_url );
		$this->assertSame( [ 'reader+tag@example.test' ], $this->sent_to );
	}

	/**
	 * A timeout stops the loop, and the remaining recipients are reported as
	 * not sent rather than attempted against an unresponsive ActiveCampaign.
	 */
	public function test_timeout_stops_the_loop() {
		$this->timing_out = [ 'two@example.com' ];

		$result = Newspack_Newsletters_Active_Campaign::instance()->send_test_emails( 10, 20, [ 'one@example.com', 'two@example.com', 'three@example.com' ] );

		$this->assertSame( [ 'one@example.com', 'two@example.com' ], $this->sent_to );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'one@example.com', $result['message'] );
		$this->assertStringContainsString( 'Not sent to: three@example.com', $result['message'] );
	}

	/**
	 * Each test send is bounded by the test-send timeout, not the longer
	 * default, so a slow ActiveCampaign can't hold the whole loop.
	 */
	public function test_sends_use_the_test_send_timeout() {
		Newspack_Newsletters_Active_Campaign::instance()->send_test_emails( 10, 20, [ 'one@example.com' ] );

		$this->assertSame( Newspack_Newsletters_Active_Campaign::TEST_SEND_REQUEST_TIMEOUT, $this->seen_timeout );
		$this->assertLessThan( Newspack_Newsletters_Active_Campaign::DEFAULT_REQUEST_TIMEOUT, $this->seen_timeout );
	}

	/**
	 * When every recipient fails, the result is an error.
	 */
	public function test_all_failed_returns_error() {
		$this->failing = [ 'one@example.com', 'two@example.com' ];

		$result = Newspack_Newsletters_Active_Campaign::instance()->send_test_emails( 10, 20, [ 'one@example.com', 'two@example.com' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_newsletters_active_campaign_test', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid email', $result->get_error_message() );
	}
}
