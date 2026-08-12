<?php
/**
 * Tests for the subscribe block's JSON response shape.
 *
 * @package Newspack_Newsletters
 */

use function Newspack_Newsletters\Blocks\Subscribe\send_form_response;

/**
 * Tests that the subscribe block's JSON response carries only the keys the
 * front end consumes, and never the contact record returned by the ESP.
 *
 * @group subscribe-block
 */
class Subscribe_Block_Response_Test extends WP_UnitTestCase {

	/**
	 * Saved $_SERVER['HTTP_ACCEPT'], restored in tear_down.
	 *
	 * @var string|null
	 */
	private $original_accept;

	/**
	 * Make wp_is_json_request() true for the duration of each test.
	 *
	 * Also forces wp_doing_ajax() to true and routes wp_die()'s Ajax handler
	 * through the test case's own handler. wp_send_json() only ever reaches
	 * wp_die() when wp_doing_ajax() is true (otherwise it calls a bare die());
	 * and once there, wp_die() dispatches on wp_doing_ajax() before anything
	 * else, so the generic 'wp_die_handler' filter that WP_UnitTestCase wires
	 * up to throw WPDieException is never consulted. Wiring the same handler
	 * onto 'wp_die_ajax_handler' is what makes that exception catchable here.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_accept  = $_SERVER['HTTP_ACCEPT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saved verbatim, restored verbatim in tear_down, never used as output or in a query.
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
	}

	/**
	 * Restore the request headers and the wp_doing_ajax/wp_die_ajax_handler filters.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		if ( null === $this->original_accept ) {
			unset( $_SERVER['HTTP_ACCEPT'] );
		} else {
			$_SERVER['HTTP_ACCEPT'] = $this->original_accept;
		}
		parent::tear_down();
	}

	/**
	 * Call send_form_response() and return the decoded JSON it emitted.
	 *
	 * The wp_send_json() call echoes the payload and then calls wp_die(), which the
	 * WordPress test library turns into a WPDieException. Capturing the buffer
	 * and swallowing that exception is the only way to observe the response.
	 *
	 * @param mixed $data Payload handed to send_form_response().
	 * @return array Decoded response body.
	 */
	private function capture_response( $data ) {
		ob_start();
		try {
			send_form_response( $data );
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Expected: wp_send_json() always terminates.
		}
		return json_decode( ob_get_clean(), true );
	}

	/**
	 * A payload shaped like an ESP contact record keeps only the keys the
	 * front end reads. The provider's fields must not reach the caller.
	 */
	public function test_provider_record_fields_are_not_returned() {
		$response = $this->capture_response(
			[
				'id'            => 'abc123',
				'email_address' => 'reader@example.com',
				'merge_fields'  => [
					'FNAME' => 'Ada',
					'ADDR'  => '1 Example St',
				],
				'ip_signup'     => '198.51.100.7',
				'location'      => [
					'latitude'  => 51.5,
					'longitude' => -0.1,
				],
				'tags'          => [ [ 'name' => 'donor' ] ],
				'member_rating' => 4,
				'metadata'      => [ 'current_page_url' => 'https://example.com/post' ],
			]
		);

		foreach ( [ 'id', 'email_address', 'merge_fields', 'ip_signup', 'location', 'tags', 'member_rating' ] as $key ) {
			$this->assertArrayNotHasKey(
				$key,
				$response,
				sprintf( 'Provider field "%s" must not be returned to the caller.', $key )
			);
		}
	}

	/**
	 * Every key the front end consumes survives the filter. Losing any of these
	 * breaks a working flow rather than closing a hole.
	 */
	public function test_every_consumed_key_survives() {
		$response = $this->capture_response(
			[
				'newspack_newsletters_subscribe' => '1',
				'metadata'                       => [ 'current_page_url' => 'https://example.com/post' ],
				'registered'                     => 1,
				'verified'                       => false,
				'verification_nonce'             => 'abc123',
				'email'                          => 'reader@example.com',
				'merge_fields'                   => [ 'FNAME' => 'Ada' ],
			]
		);

		foreach ( [ 'newspack_newsletters_subscribe', 'metadata', 'registered', 'verified', 'verification_nonce', 'email' ] as $key ) {
			$this->assertArrayHasKey( $key, $response, sprintf( 'Front end consumes "%s"; it must survive.', $key ) );
		}
		$this->assertSame( 1, $response['newspack_newsletters_subscribed'], 'The success flag is added by send_form_response().' );
	}

	/**
	 * Metadata is bounded one level down, so a provider field merged into it
	 * later cannot ride out to the caller.
	 */
	public function test_metadata_contents_are_bounded() {
		$response = $this->capture_response(
			[
				'metadata' => [
					'current_page_url'                => 'https://example.com/post',
					'newspack_popup_id'               => 42,
					'newsletters_subscription_method' => 'newsletters-subscription-block',
					'email_address'                   => 'reader@example.com',
					'merge_fields'                    => [ 'FNAME' => 'Ada' ],
				],
			]
		);

		$this->assertArrayHasKey( 'current_page_url', $response['metadata'] );
		$this->assertArrayHasKey( 'newspack_popup_id', $response['metadata'] );
		$this->assertArrayNotHasKey( 'email_address', $response['metadata'], 'metadata must not carry provider fields.' );
		$this->assertArrayNotHasKey( 'merge_fields', $response['metadata'], 'metadata must not carry provider fields.' );
	}
}
