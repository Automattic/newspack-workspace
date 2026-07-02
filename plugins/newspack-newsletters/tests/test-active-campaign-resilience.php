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
	 * Optional per-action canned responses, keyed by API action (v1 `api_action`
	 * or `v3:<resource>`). A value may be a decoded body array (returned as a 200)
	 * or a WP_Error (returned as a transport failure). When set for the matched
	 * action it takes precedence over $transport_error / $response_body.
	 *
	 * @var array
	 */
	private $responses = [];

	/**
	 * Every ActiveCampaign action invoked through the mocked HTTP layer, in order.
	 * Lets tests assert that a step was (or was not) attempted.
	 *
	 * @var string[]
	 */
	private $called_actions = [];

	/**
	 * Set up: configure credentials and intercept all outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
		$this->captured_timeouts = [];
		$this->transport_error   = null;
		$this->response_body     = [ 'result_code' => 1 ];
		$this->responses         = [];
		$this->called_actions    = [];
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
		$action                    = $this->resolve_action( $args, $url );
		$this->called_actions[]    = $action;

		// Per-action overrides take precedence (used by the send() choreography test).
		if ( ! empty( $this->responses ) ) {
			if ( array_key_exists( $action, $this->responses ) ) {
				$canned = $this->responses[ $action ];
				if ( is_wp_error( $canned ) ) {
					return $canned;
				}
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'body'     => wp_json_encode( $canned ),
				];
			}
		}

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
	 * Resolve the ActiveCampaign action for a request. v1 GET calls carry
	 * `api_action` in the query string; v1 POST calls carry it in the body; v3
	 * calls are identified by the resource path.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  Request URL.
	 *
	 * @return string Action key, e.g. `campaign_create` or `v3:audiences`.
	 */
	private function resolve_action( $args, $url ) {
		if ( isset( $args['body']['api_action'] ) ) {
			return $args['body']['api_action'];
		}
		if ( preg_match( '/api_action=([a-z_]+)/', $url, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '#/api/3/([a-z_]+)#', $url, $matches ) ) {
			return 'v3:' . $matches[1];
		}
		return '';
	}

	/**
	 * A caller-supplied timeout must reach the HTTP layer. The request args merge
	 * (`$args + $options`) silently drops it unless the request method honors it
	 * explicitly, so this guards the plumbing the cleanup fix depends on.
	 */
	public function test_api_v1_request_honors_caller_supplied_timeout() {
		Newspack_Newsletters_Active_Campaign::instance()->api_v1_request( 'campaign_delete', 'GET', [ 'timeout' => 12 ] ); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		$this->assertSame( 12, end( $this->captured_timeouts ) );
	}

	/**
	 * The v3 request method honors a caller-supplied timeout too, so the plumbing
	 * is symmetric with v1.
	 */
	public function test_api_v3_request_honors_caller_supplied_timeout() {
		Newspack_Newsletters_Active_Campaign::instance()->api_v3_request( 'audiences', 'GET', [ 'timeout' => 9 ] ); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		$this->assertSame( 9, end( $this->captured_timeouts ) );
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

	/**
	 * Build a newsletter post wired up to send, carrying a stored campaign id from
	 * a previous attempt.
	 *
	 * @param string $stored_campaign_id The ac_campaign_id left by a prior attempt.
	 *
	 * @return int Post ID.
	 */
	private function create_sendable_post( $stored_campaign_id ) {
		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Resilience Newsletter',
				'post_status' => 'draft',
			]
		);
		update_post_meta( $post_id, 'ac_campaign_id', (string) $stored_campaign_id );
		update_post_meta( $post_id, 'senderName', 'Sender' );
		update_post_meta( $post_id, 'senderEmail', 'sender@example.com' );
		update_post_meta( $post_id, 'send_list_id', '5' );
		update_post_meta( $post_id, Newspack_Newsletters::EMAIL_HTML_META, '<p>Hello</p>' );
		return $post_id;
	}

	/**
	 * Canned responses for a successful fresh send chain (everything except the
	 * `campaign_list` status check, which each test sets to drive the gate).
	 *
	 * @return array
	 */
	private function fresh_send_chain_responses() {
		return [
			'list_list'       => [
				'result_code' => 1,
				[
					'id'               => 5,
					'name'             => 'Main',
					'subscriber_count' => 10,
				],
			],
			'v3:audiences'    => [
				'data' => [],
				'meta' => [ 'page' => [ 'total' => 0 ] ],
			],
			'message_add'     => [
				'result_code' => 1,
				'id'          => 555,
			],
			'message_view'    => [
				'result_code' => 1,
				'id'          => 555,
				'html'        => '<p>Hello</p>',
			],
			'campaign_create' => [
				'result_code' => 1,
				'id'          => 777,
			],
			'campaign_delete' => [ 'result_code' => 1 ],
			'campaign_status' => [ 'result_code' => 1 ],
		];
	}

	/**
	 * The headline safety guarantee. When a prior attempt's campaign can't be
	 * reached (its status check times out), send() must NOT resend — it can't tell
	 * whether that campaign already started dispatching, so it fails safe with a
	 * publisher-facing notice instead of risking a duplicate send.
	 */
	public function test_send_fails_safe_when_prior_campaign_status_unverifiable() {
		$active_campaign = Newspack_Newsletters_Active_Campaign::instance();
		$post_id         = $this->create_sendable_post( '999999' );

		$this->responses = array_merge(
			$this->fresh_send_chain_responses(),
			[
				'campaign_list' => new WP_Error(
					'http_request_failed',
					'cURL error 28: Operation timed out after 45002 milliseconds with 0 bytes received'
				),
			]
		);

		$result = $active_campaign->send( get_post( $post_id ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'newspack_newsletters_active_campaign_unverified_campaign', $result->get_error_code() );
		$this->assertNotContains( 'campaign_create', $this->called_actions, 'A new campaign must not be created when the prior one is unverifiable.' );
		$this->assertNotContains( 'campaign_status', $this->called_actions, 'No send must be triggered when the prior campaign is unverifiable.' );
	}

	/**
	 * The state check failing with a NON-timeout error (e.g. an HTTP 5xx during an
	 * AC incident, surfaced as a generic api_error) must also fail safe. This is
	 * the branch most likely to fire during the degradation the gate exists for,
	 * and the one that would double-send if it defaulted to "recreate".
	 */
	public function test_send_fails_safe_when_status_check_returns_non_timeout_error() {
		$active_campaign = Newspack_Newsletters_Active_Campaign::instance();
		$post_id         = $this->create_sendable_post( '12345' );

		$this->responses = array_merge(
			$this->fresh_send_chain_responses(),
			// result_code !== 1 makes api_v1_request return a generic api_error
			// (the shape AC returns for a 5xx), NOT the timeout code.
			[
				'campaign_list' => [
					'result_code'    => 0,
					'result_message' => 'Internal error',
				],
			]
		);

		$result = $active_campaign->send( get_post( $post_id ) );

		$this->assertTrue( is_wp_error( $result ), 'A non-timeout state-check error must not proceed to a resend.' );
		$this->assertSame( 'newspack_newsletters_active_campaign_unverified_campaign', $result->get_error_code() );
		$this->assertNotContains( 'campaign_create', $this->called_actions, 'No campaign may be created when the prior state is unverifiable for any reason.' );
		$this->assertNotContains( 'campaign_status', $this->called_actions, 'No send may be triggered when the prior state is unverifiable for any reason.' );
	}

	/**
	 * Statuses that mean the campaign was already dispatched on a prior attempt.
	 *
	 * @return array
	 */
	public function already_dispatched_statuses() {
		return [
			'scheduled' => [ '1' ],
			'sending'   => [ '2' ],
			'completed' => [ '5' ],
		];
	}

	/**
	 * When the stored campaign is already scheduled/sending/sent, a prior attempt
	 * already dispatched it (its response was just lost). send() must treat that as
	 * success and NOT create or trigger another campaign — no double send.
	 *
	 * @dataProvider already_dispatched_statuses
	 *
	 * @param string $status The ActiveCampaign campaign status code.
	 */
	public function test_send_does_not_resend_already_dispatched_campaign( $status ) {
		$active_campaign = Newspack_Newsletters_Active_Campaign::instance();
		$post_id         = $this->create_sendable_post( '12345' );

		$this->responses = array_merge(
			$this->fresh_send_chain_responses(),
			[
				'campaign_list' => [
					'result_code' => 1,
					[
						'id'     => 12345,
						'status' => $status,
					],
				],
			]
		);

		$result = $active_campaign->send( get_post( $post_id ) );

		$this->assertTrue( $result, 'An already-dispatched campaign must report success, not error.' );
		$this->assertNotContains( 'campaign_create', $this->called_actions, 'A second campaign must not be created.' );
		$this->assertNotContains( 'campaign_status', $this->called_actions, 'The send must not be triggered again.' );
		$this->assertNotContains( 'campaign_delete', $this->called_actions, 'A dispatched campaign must not be deleted.' );
	}

	/**
	 * Indeterminate statuses (paused, stopped, or anything unrecognised) — the
	 * campaign may have partially sent, so it must not be auto-resent; send()
	 * surfaces a needs-review notice for a human.
	 *
	 * @return array
	 */
	public function needs_review_statuses() {
		return [
			'paused'  => [ '3' ],
			'stopped' => [ '4' ],
			'unknown' => [ '99' ],
		];
	}

	/**
	 * An indeterminate prior-campaign state must surface a needs-review notice
	 * rather than auto-resend.
	 *
	 * @dataProvider needs_review_statuses
	 *
	 * @param string $status The ActiveCampaign campaign status code.
	 */
	public function test_send_flags_for_review_when_campaign_state_is_indeterminate( $status ) {
		$active_campaign = Newspack_Newsletters_Active_Campaign::instance();
		$post_id         = $this->create_sendable_post( '12345' );

		$this->responses = array_merge(
			$this->fresh_send_chain_responses(),
			[
				'campaign_list' => [
					'result_code' => 1,
					[
						'id'     => 12345,
						'status' => $status,
					],
				],
			]
		);

		$result = $active_campaign->send( get_post( $post_id ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'newspack_newsletters_active_campaign_send_needs_review', $result->get_error_code() );
		$this->assertNotContains( 'campaign_create', $this->called_actions );
		$this->assertNotContains( 'campaign_status', $this->called_actions );
	}

	/**
	 * When the stored campaign is confirmed a draft (never dispatched), send()
	 * proceeds normally: it recreates and dispatches a fresh campaign and the new
	 * id replaces the stored one.
	 */
	public function test_send_recreates_and_sends_when_prior_campaign_is_draft() {
		$active_campaign = Newspack_Newsletters_Active_Campaign::instance();
		$post_id         = $this->create_sendable_post( '12345' );

		$this->responses = array_merge(
			$this->fresh_send_chain_responses(),
			[
				'campaign_list' => [
					'result_code' => 1,
					[
						'id'     => 12345,
						'status' => '0',
					],
				],
			] // 0 = draft.
		);

		$result = $active_campaign->send( get_post( $post_id ) );

		$this->assertTrue( $result, 'A draft prior campaign is safe to recreate and send.' );
		$this->assertSame( '777', (string) get_post_meta( $post_id, 'ac_campaign_id', true ), 'The fresh campaign id replaces the stored draft.' );
		$this->assertContains( 'campaign_create', $this->called_actions, 'A fresh campaign must be created.' );
		$this->assertContains( 'campaign_status', $this->called_actions, 'The send must be triggered.' );
		$this->assertSame(
			1,
			count( array_keys( $this->called_actions, 'campaign_delete', true ) ),
			'The confirmed draft must be deleted exactly once before recreating.'
		);
		$this->assertSame(
			1,
			count( array_keys( $this->called_actions, 'campaign_list', true ) ),
			'The force-delete must skip the status re-check, so campaign_list runs only once (the dispatch-state gate).'
		);
	}
}
