<?php
/**
 * Class Test Layouts REST Test-Send
 *
 * @package Newspack_Newsletters
 */

/**
 * Covers the layout-specific test-send REST route. Hooks `pre_wp_mail`
 * to capture invocations and short-circuit the real send.
 */
class Layouts_REST_Test_Send_Test extends WP_UnitTestCase {
	/**
	 * Captured wp_mail() invocations.
	 *
	 * @var array<int, array>
	 */
	private $captured_mail = [];

	/**
	 * Recipients the `pre_wp_mail` filter should report as failed.
	 *
	 * @var array<int, string>
	 */
	private $fail_recipients = [];

	/**
	 * Pre-test snapshot of the current user id.
	 *
	 * @var int
	 */
	private $previous_user_id = 0;

	/**
	 * Whether the layouts CPT was registered before set_up ran.
	 *
	 * @var bool
	 */
	private $layouts_cpt_was_registered = false;

	/**
	 * Test set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->captured_mail              = [];
		$this->fail_recipients            = [];
		$this->previous_user_id           = get_current_user_id();
		$this->layouts_cpt_was_registered = post_type_exists( \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT );

		// CPT is gated on `edit_others_posts`; re-register under an admin.
		// REST routes register on `rest_api_init`, which `rest_do_request` fires.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		\Newspack_Newsletters_Layouts::register_layout_cpt();

		add_filter( 'pre_wp_mail', [ $this, 'capture_wp_mail' ], 10, 2 );
	}

	/**
	 * Test tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_wp_mail' ], 10 );
		wp_set_current_user( $this->previous_user_id );

		if ( ! $this->layouts_cpt_was_registered && post_type_exists( \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT ) ) {
			unregister_post_type( \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT );
		}

		parent::tear_down();
	}

	/**
	 * `pre_wp_mail` filter: record the call, then short-circuit wp_mail. Returns `false`
	 * for recipients listed in `$fail_recipients` (simulating delivery failure), `true` otherwise.
	 *
	 * @param null|bool $short_circuit Unused.
	 * @param array     $atts          wp_mail attributes.
	 * @return bool
	 */
	public function capture_wp_mail( $short_circuit, $atts ) {
		unset( $short_circuit );
		$this->captured_mail[] = $atts;
		$to = is_array( $atts['to'] ) ? ( $atts['to'][0] ?? '' ) : $atts['to'];
		return in_array( $to, $this->fail_recipients, true ) ? false : true;
	}

	/**
	 * Helper: build a layout post with an optional rendered HTML payload.
	 *
	 * @param string $html The HTML to write to EMAIL_HTML_META; empty skips.
	 * @return int Post ID.
	 */
	private function make_layout( $html = '<p>Hello</p>' ) {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Test layout',
			]
		);
		if ( '' !== $html ) {
			update_post_meta( $post_id, Newspack_Newsletters::EMAIL_HTML_META, $html );
		}
		return $post_id;
	}

	/**
	 * No valid emails in the payload → 400, no wp_mail call.
	 */
	public function test_returns_400_when_no_valid_emails() {
		$post_id = $this->make_layout();

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'not-an-email, also@not, 12345' );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->captured_mail );
	}

	/**
	 * Layout has no rendered HTML yet → 409, no wp_mail call.
	 */
	public function test_returns_409_when_layout_has_no_html() {
		$post_id = $this->make_layout( '' );

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'a@example.com' );

		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertCount( 0, $this->captured_mail );
	}

	/**
	 * Multiple recipients → one wp_mail call each, each with a single
	 * To: (no recipient sees another's address).
	 */
	public function test_sends_one_email_per_recipient_with_single_to() {
		$post_id = $this->make_layout( '<p>Preview</p>' );

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'a@example.com, b@example.com, c@example.com' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $this->captured_mail );
		foreach ( $this->captured_mail as $atts ) {
			// wp_mail accepts To: as a string or single-element array.
			$to = $atts['to'];
			if ( is_array( $to ) ) {
				$this->assertCount( 1, $to );
				$to = $to[0];
			}
			$this->assertIsString( $to );
			$this->assertStringContainsString( '@example.com', $to );
		}
		$recipients = array_map(
			static function ( $atts ) {
				return is_array( $atts['to'] ) ? $atts['to'][0] : $atts['to'];
			},
			$this->captured_mail
		);
		$this->assertSame( [ 'a@example.com', 'b@example.com', 'c@example.com' ], $recipients );
	}

	/**
	 * Auth-gated but the comma split is otherwise unbounded — cap at 10.
	 */
	public function test_caps_recipients_at_ten() {
		$post_id = $this->make_layout( '<p>Preview</p>' );

		$addresses = [];
		for ( $i = 1; $i <= 15; $i++ ) {
			$addresses[] = sprintf( 'user%02d@example.com', $i );
		}

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', implode( ',', $addresses ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 10, $this->captured_mail );

		$recipients = array_map(
			static function ( $atts ) {
				return is_array( $atts['to'] ) ? $atts['to'][0] : $atts['to'];
			},
			$this->captured_mail
		);
		$this->assertSame( array_slice( $addresses, 0, 10 ), $recipients );
	}

	/**
	 * Successful send persists the recipient list to the current user's
	 * `newspack_nl_test_emails` meta — mirrors the provider /test handlers.
	 */
	public function test_persists_recipients_to_user_meta_on_success() {
		$post_id = $this->make_layout( '<p>Preview</p>' );

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'team@example.com, lead@example.com' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$stored = get_user_meta( get_current_user_id(), 'newspack_nl_test_emails', true );
		$this->assertSame( [ 'team@example.com', 'lead@example.com' ], $stored );
	}

	/**
	 * Partial failure → 200 with a `failed_recipients` array the client
	 * can branch on (without parsing message text).
	 */
	public function test_partial_failure_returns_200_with_failed_recipients() {
		$post_id               = $this->make_layout( '<p>Preview</p>' );
		$this->fail_recipients = [ 'b@example.com' ];

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'a@example.com, b@example.com, c@example.com' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'failed_recipients', $data );
		$this->assertSame( [ 'b@example.com' ], $data['failed_recipients'] );
	}

	/**
	 * Full success still carries `failed_recipients` (empty array) so the
	 * client can probe the field unconditionally.
	 */
	public function test_full_success_includes_empty_failed_recipients() {
		$post_id = $this->make_layout( '<p>Preview</p>' );

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'a@example.com, b@example.com' );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'failed_recipients', $data );
		$this->assertSame( [], $data['failed_recipients'] );
	}

	/**
	 * All recipients fail → 500 with the mail_failed error code (no
	 * partial-success masking).
	 */
	public function test_full_failure_returns_500() {
		$post_id               = $this->make_layout( '<p>Preview</p>' );
		$this->fail_recipients = [ 'a@example.com', 'b@example.com' ];

		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/layouts/' . $post_id . '/test' );
		$request->set_param( 'test_email', 'a@example.com, b@example.com' );

		$response = rest_do_request( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'newspack_newsletters_mail_failed', $response->get_data()['code'] ?? null );
	}
}
