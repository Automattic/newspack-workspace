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
		} finally {
			// In finally, not after the try/catch: anything besides WPDieException thrown
			// by send_form_response() (e.g. a TypeError from a future signature change)
			// must still close this buffer, or it leaks into whatever the test runner
			// captures next and turns one failure into a run's worth of noise.
			$output = ob_get_clean();
		}
		return json_decode( $output, true );
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
		$this->assertArrayHasKey( 'newsletters_subscription_method', $response['metadata'] );
		$this->assertArrayNotHasKey( 'email_address', $response['metadata'], 'metadata must not carry provider fields.' );
		$this->assertArrayNotHasKey( 'merge_fields', $response['metadata'], 'metadata must not carry provider fields.' );
	}

	/**
	 * The error branch returns a reader-facing message and nothing else. The
	 * WP_Error's own data can carry the provider's raw response.
	 */
	public function test_error_branch_returns_message_only() {
		$error = new WP_Error(
			'newspack_newsletters_subscribe_error',
			'Sorry, an error has occurred.',
			[ 'raw_provider_response' => [ 'email_address' => 'reader@example.com' ] ]
		);

		$response = $this->capture_response( $error );

		$this->assertSame( 'Sorry, an error has occurred.', $response['message'] );
		$this->assertArrayNotHasKey( 'data', $response, 'The raw WP_Error must not be returned.' );
	}

	/**
	 * Reader Activation absent or disabled: the registration keys are simply
	 * not in the payload. newspack-newsletters releases independently of
	 * newspack-plugin, so this is an ordinary production configuration, and the
	 * response must stay well-formed without them.
	 */
	public function test_response_is_well_formed_without_registration_keys() {
		$response = $this->capture_response(
			[
				'newspack_newsletters_subscribe' => '1',
				'metadata'                       => [ 'current_page_url' => 'https://example.com/post' ],
			]
		);

		$this->assertSame( 1, $response['newspack_newsletters_subscribed'] );
		$this->assertArrayHasKey( 'newspack_newsletters_subscribe', $response, 'Resubmission depends on this key.' );
		foreach ( [ 'registered', 'verified', 'verification_nonce', 'email' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $response, 'Registration keys must be absent, not empty.' );
		}
	}

	/**
	 * A provider that returns a sparse record must not break the filter. Only
	 * Mailchimp returns a rich contact object; others return very little.
	 */
	public function test_sparse_provider_record_is_handled() {
		$response = $this->capture_response( [ 'id' => '42' ] );

		$this->assertSame( 1, $response['newspack_newsletters_subscribed'] );
		$this->assertArrayNotHasKey( 'id', $response );
	}

	/**
	 * Mailchimp's double opt-in path sets metadata.status; it must survive.
	 */
	public function test_double_optin_status_survives() {
		$response = $this->capture_response(
			[ 'metadata' => [ 'status' => 'pending' ] ]
		);

		$this->assertSame( 'pending', $response['metadata']['status'] );
	}

	/**
	 * A submission from a popup carries a distinct registration_method, which
	 * the front end reports as reader activity.
	 */
	public function test_popup_registration_method_survives() {
		$response = $this->capture_response(
			[
				'metadata' => [
					'registration_method' => 'newsletters-subscription-popup',
					'newspack_popup_id'   => 7,
				],
			]
		);

		$this->assertSame( 'newsletters-subscription-popup', $response['metadata']['registration_method'] );
		$this->assertSame( 7, $response['metadata']['newspack_popup_id'] );
	}

	/**
	 * `metadata.registered` is what `view.js` gates the `reader_registered`
	 * Reader Activation dispatch on. It is distinct from the top-level
	 * `registered` flag asserted in test_every_consumed_key_survives — the
	 * nested copy is what a newly-registered reader's subscription actually
	 * depends on for that activity to fire.
	 */
	public function test_metadata_registered_survives() {
		$response = $this->capture_response(
			[
				'metadata' => [
					'registered'          => '1',
					'registration_method' => 'newsletters-subscription',
				],
			]
		);

		$this->assertSame( '1', $response['metadata']['registered'] );
	}

	/**
	 * `gate_post_id` is never set by this block — it originates in
	 * newspack-plugin's content gate — but `view.js` reads it defensively when
	 * present, so a gated subscription must still carry it through.
	 */
	public function test_gate_post_id_survives() {
		$response = $this->capture_response(
			[ 'metadata' => [ 'gate_post_id' => 123 ] ]
		);

		$this->assertSame( 123, $response['metadata']['gate_post_id'] );
	}

	/**
	 * Every other test in this file pins RESPONSE_KEYS/METADATA_KEYS against
	 * shrinking — each asserts that specific, named keys are absent or present.
	 * None of them would notice a key being *added* to either allowlist: a
	 * fixture that only checks for named provider fields stays green even
	 * after a new field joins the allowlist it was written to guard against.
	 * This test instead asserts the response's full key set equals the
	 * allowlist, so widening RESPONSE_KEYS or METADATA_KEYS has to be a
	 * deliberate act that updates this test, not a drive-by addition that
	 * slips through unnoticed.
	 */
	public function test_response_key_set_is_closed() {
		$response = $this->capture_response(
			[
				'message'                        => 'x',
				'newspack_newsletters_subscribe' => '1',
				'metadata'                       => [ 'current_page_url' => 'https://example.com/' ],
				'registered'                     => 1,
				'verified'                       => false,
				'verification_nonce'             => 'abc',
				'email'                          => 'reader@example.com',
				'name'                           => 'Ada',
				'phone'                          => '555',
				// Named but currently unlisted, so this stands in for whatever key a
				// future addition would introduce; the response must not carry it.
				'existing_contact_data'          => [ 'merge_fields' => [] ],
			]
		);

		$expected = [
			'email',
			'message',
			'metadata',
			'newspack_newsletters_subscribe',
			'newspack_newsletters_subscribed',
			'registered',
			'verification_nonce',
			'verified',
		];
		$actual   = array_keys( $response );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'The response carries exactly the allowlisted keys.' );

		$metadata_expected = [
			'current_page_url',
			'gate_post_id',
			'newsletters_subscription_method',
			'newspack_popup_id',
			'registered',
			'registration_method',
			'status',
		];
		// 'email_address'/'ip_signup' are provider fields the shrink-direction tests
		// already cover; 'phone' stands in for a plausible future METADATA_KEYS
		// addition. array_intersect_key only ever removes keys, so the widening
		// mutation this test guards against is invisible unless the bait key is
		// actually present in the input for it to preserve.
		$response = $this->capture_response(
			[ 'metadata' => array_fill_keys( array_merge( $metadata_expected, [ 'email_address', 'ip_signup', 'phone' ] ), 'x' ) ]
		);
		$actual   = array_keys( $response['metadata'] );
		sort( $actual );
		$this->assertSame( $metadata_expected, $actual, 'metadata carries exactly the allowlisted keys.' );
	}

	/**
	 * The non-JSON path redirects instead of emitting JSON, and is unchanged by
	 * this work. Asserting it here means a future edit to the filter that
	 * accidentally reaches the redirect branch fails a test.
	 *
	 * Intercepts via the `wp_redirect` filter rather than catching whatever
	 * `wp_safe_redirect()` happens to throw: the filter fires before
	 * `wp_redirect()` calls `header()`, so this needs no PHP warning to occur
	 * and no dependency on how this harness converts one. A generic catch on
	 * the redirect branch would also swallow an unrelated warning introduced
	 * there later — e.g. an undefined-array-key read while building
	 * `$args_to_remove` — and this test is the only one that exercises that
	 * branch, so such a regression would otherwise sit behind a green run
	 * indefinitely. Catching only Subscribe_Block_Redirect_Interrupt closes
	 * that gap: nothing else thrown in the branch is this exception type, so
	 * it propagates and fails the test instead of being absorbed here.
	 */
	public function test_non_json_request_does_not_emit_json() {
		unset( $_SERVER['HTTP_ACCEPT'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$intercept = function ( $location ) {
			$interrupt           = new Subscribe_Block_Redirect_Interrupt( 'Intercepted for testing; not a real error.' );
			$interrupt->location = $location;
			throw $interrupt;
		};
		add_filter( 'wp_redirect', $intercept );

		ob_start();
		try {
			send_form_response( [ 'merge_fields' => [ 'FNAME' => 'Ada' ] ] );
			$this->fail( 'Expected the wp_redirect filter to interrupt send_form_response().' );
		} catch ( Subscribe_Block_Redirect_Interrupt $e ) {
			$location = $e->location;
		} finally {
			// In finally: $this->fail() above throws past the catch block, and without
			// this here that throw would skip ob_get_clean() and leak the buffer into
			// whatever the test runner captures next.
			remove_filter( 'wp_redirect', $intercept );
			$output = ob_get_clean();
		}

		$this->assertSame( '', trim( $output ), 'The redirect branch must not emit a response body.' );
		$this->assertStringContainsString( 'newspack_newsletters_subscribed=1', $location, 'The redirect must report a successful subscription.' );
	}
}
