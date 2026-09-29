<?php
/**
 * Tests how the loopback dispatch transports its nonce.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events;

/**
 * The nonce is the only thing authenticating wp_ajax_nopriv_newspack_data_event, whose
 * handlers write reader data for a caller-supplied user_id. Sending it as a query
 * parameter puts it verbatim into the web server access log on every dispatch -- and
 * this loopback is the default path (Action Scheduler dispatch is opt-in). Keep it in
 * the request body, which is not logged.
 *
 * @group data-events
 * @group dispatch-nonce
 */
class Test_Dispatch_Nonce_Transport extends WP_UnitTestCase {

	/**
	 * The URL the dispatch was sent to.
	 *
	 * @var string
	 */
	private $captured_url;

	/**
	 * The wp_remote_post args of the dispatch.
	 *
	 * @var array
	 */
	private $captured_args;

	/**
	 * Set up. Short-circuit the loopback HTTP request and capture what it would send.
	 */
	public function set_up() {
		parent::set_up();
		$this->captured_url  = null;
		$this->captured_args = null;

		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		parent::tear_down();
	}

	/**
	 * Intercept the dispatch request instead of letting it hit the network.
	 *
	 * @param false|array|WP_Error $response The preempted response.
	 * @param array                $args     The request args.
	 * @param string               $url      The request URL.
	 *
	 * @return array A canned response.
	 */
	public function capture_request( $response, $args, $url ) {
		$this->captured_url  = $url;
		$this->captured_args = $args;
		return [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
	}

	/**
	 * Queue a dispatch and flush it through the loopback path.
	 */
	private function dispatch_one_event() {
		Data_Events::register_action( 'test_nonce_transport_action' );
		Data_Events::dispatch( 'test_nonce_transport_action', [ 'user_id' => 1 ] );
		Data_Events::execute_queued_dispatches();
	}

	/**
	 * The nonce must not travel in the URL, where the access log records it.
	 */
	public function test_nonce_is_not_sent_in_the_query_string() {
		$this->dispatch_one_event();

		$this->assertNotNull( $this->captured_url, 'The dispatch did not use the loopback path.' );

		$query = wp_parse_url( $this->captured_url, PHP_URL_QUERY );
		parse_str( (string) $query, $query_args );

		$this->assertArrayNotHasKey(
			'nonce',
			$query_args,
			'The dispatch nonce is in the request URL, so it lands in the access log.'
		);
	}

	/**
	 * The receiving handler reads $_REQUEST['nonce'], so the body must still carry a
	 * valid nonce or every dispatch would be rejected.
	 */
	public function test_nonce_is_sent_in_the_body_and_is_valid() {
		$this->dispatch_one_event();

		$this->assertArrayHasKey(
			'nonce',
			$this->captured_args['body'],
			'The dispatch body carries no nonce; the handler would reject it.'
		);
		$this->assertTrue(
			Data_Events::verify_nonce( $this->captured_args['body']['nonce'] ),
			'The nonce sent in the body does not verify.'
		);
	}

	/**
	 * The dispatch payload itself must survive the move.
	 */
	public function test_dispatches_are_still_sent() {
		$this->dispatch_one_event();

		$this->assertArrayHasKey( 'dispatches', $this->captured_args['body'] );
		$this->assertSame(
			'test_nonce_transport_action',
			$this->captured_args['body']['dispatches'][0]['action_name'],
			'The queued dispatch was not sent.'
		);
	}
}
