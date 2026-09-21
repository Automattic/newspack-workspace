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
	 * Number of HTTP requests made during a test.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->sent_to       = [];
		$this->failing       = [];
		$this->request_count = 0;
		add_filter( 'pre_http_request', [ $this, 'filter_api_response' ], 10, 3 );
		Newspack_Newsletters_Active_Campaign::instance()->set_api_credentials(
			[
				'url' => 'https://example.api-us1.com',
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
	 * Mock the v1 campaign_send endpoint.
	 *
	 * @param array|false $response    Short-circuit response.
	 * @param array       $parsed_args HTTP request arguments.
	 * @param string      $url         The request URL.
	 *
	 * @return array
	 */
	public function filter_api_response( $response, $parsed_args, $url ) {
		++$this->request_count;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$email = $query['email'] ?? '';
		if ( 'campaign_send' === ( $query['api_action'] ?? '' ) ) {
			$this->sent_to[] = $email;
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
