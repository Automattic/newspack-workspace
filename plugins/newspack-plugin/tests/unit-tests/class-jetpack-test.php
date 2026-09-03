<?php
/**
 * Tests for Jetpack integration tweaks.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\Jetpack;

/**
 * Test class for the Jetpack share-link bot obfuscation tweaks.
 *
 * @group jetpack
 */
class Test_Jetpack extends \WP_UnitTestCase {

	/**
	 * Setup before class.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once NEWSPACK_ABSPATH . 'includes/plugins/class-jetpack.php';
		require_once NEWSPACK_ABSPATH . 'tests/mocks/jetpack-mock.php';
	}

	/**
	 * The request URI in place before a test, restored afterwards so unsetting it never
	 * leaks into WordPress internals (e.g. cron) that expect it to be present.
	 *
	 * @var string|null
	 */
	private $original_request_uri;

	/**
	 * The Jetpack stub's active modules before a test, restored afterwards.
	 *
	 * @var string[]
	 */
	private $original_active_modules;

	/**
	 * Snapshot request and Jetpack-stub state the tests mutate, to restore after each test. The
	 * gate keys off Jetpack's presence (the stub is loaded), not the sharedaddy module, so the
	 * module list is left at its default and only the tests that care set it.
	 */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Snapshotting the value verbatim to restore it after the test.
		$this->original_request_uri    = $_SERVER['REQUEST_URI'] ?? null;
		$this->original_active_modules = \Jetpack::$test_active_modules;
	}

	/**
	 * Reset request superglobals and stub state the share gate reads.
	 */
	public function tear_down(): void {
		unset( $_GET['share'], $_GET[ Jetpack::SHARE_TOKEN_QUERY_ARG ], $_GET['nb'] );
		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		\Jetpack::$test_active_modules = $this->original_active_modules;
		$this->reset_share_state();
		parent::tear_down();
	}

	/**
	 * Reset the class's per-request static state between tests: the map of blanked queries (keyed
	 * by spl_object_id(), which the runtime may recycle once a source object is freed, so a stale
	 * entry could otherwise be read by another test) and the "did we obfuscate this request" flag.
	 */
	private function reset_share_state(): void {
		foreach ( [
			'blanked_queries' => [],
			'did_obfuscate'   => false,
		] as $name => $value ) {
			$property = new \ReflectionProperty( Jetpack::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Representative args as Jetpack's Sharing_Source::get_link() collects them via
	 * func_get_args(): [ $url, $text, $accessible_name, $query, $id, $data_attributes ].
	 *
	 * @param string $query The share query string, e.g. 'share=twitter'.
	 * @return array
	 */
	private function get_link_args( $query = 'share=twitter' ) {
		return [
			'https://example.com/a-post/',
			'X',
			'Share on X',
			$query,
			'sharing-twitter-1',
			[],
		];
	}

	/**
	 * Run the two filters Jetpack fires per share button, in order: the display-query filter
	 * (which blanks the href and stashes the query against the source object) then the
	 * data-attributes filter (which reads the stash). The data-attributes filter is given the
	 * block-theme argument shape, whose args omit the query, so the data attributes must be
	 * rebuilt from the stash rather than from the args.
	 *
	 * @param string $query    The share query string, e.g. 'share=twitter'.
	 * @param array  $existing Pre-existing data attributes passed to the data-attributes filter.
	 * @return array The resulting data attributes.
	 */
	private function run_share_link_filters( $query = 'share=twitter', $existing = [] ) {
		$source = new \stdClass();
		Jetpack::obfuscate_share_query( $query, $source, 'sharing-twitter-1', $this->get_link_args( $query ) );
		return Jetpack::add_obfuscation_data_attribute( $existing, $source, 'sharing-twitter-1', [ 'sharing-twitter-1', [] ] );
	}

	/**
	 * Run the data-attributes filter for an email button and return the resulting attributes.
	 * The email button carries an on-site tracking URL rather than a blanked share query.
	 *
	 * @param string $track_url The email-share-track-url the button starts with.
	 * @return array The resulting data attributes.
	 */
	private function run_email_data_attributes( $track_url = 'https://example.com/a-post/?share=email' ) {
		return Jetpack::add_obfuscation_data_attribute(
			[ 'email-share-track-url' => $track_url ],
			new \stdClass(),
			false,
			[ false, [] ]
		);
	}

	/**
	 * Extract our share token from a URL's query string, or null if absent.
	 *
	 * @param string $url The URL to read.
	 * @return string|null
	 */
	private function token_from_url( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		return $params[ Jetpack::SHARE_TOKEN_QUERY_ARG ] ?? null;
	}

	/**
	 * The display-query filter should blank the query for round-trip share services,
	 * so the rendered href is the bare (cacheable) permalink.
	 */
	public function test_obfuscate_share_query_blanks_share_service() {
		$this->assertSame(
			'',
			Jetpack::obfuscate_share_query( 'share=twitter', null, 'sharing-twitter-1', $this->get_link_args() )
		);
	}

	/**
	 * The display-query filter should leave non-round-trip queries untouched.
	 */
	public function test_obfuscate_share_query_ignores_non_share_query() {
		$this->assertSame(
			'foo=bar',
			Jetpack::obfuscate_share_query( 'foo=bar', null, 'id', $this->get_link_args( 'foo=bar' ) )
		);
		$this->assertSame(
			'',
			Jetpack::obfuscate_share_query( '', null, 'id', $this->get_link_args( '' ) )
		);
	}

	/**
	 * The data-attributes filter should stash the original share query so the client
	 * script can rebuild the real URL on genuine user interaction.
	 */
	public function test_data_attribute_added_for_share_service() {
		$result = $this->run_share_link_filters( 'share=twitter' );
		$this->assertArrayHasKey( 'share-query', $result );
		$this->assertSame( 'share=twitter', $result['share-query'] );
	}

	/**
	 * The data-attributes filter should not touch non-round-trip services and should
	 * preserve any attributes already present.
	 */
	public function test_data_attribute_not_added_for_non_share_service() {
		$existing = [ 'foo' => 'bar' ];
		$result   = $this->run_share_link_filters( '', $existing );
		$this->assertArrayNotHasKey( 'share-query', $result );
		$this->assertSame( $existing, $result );
	}

	/**
	 * The data attributes are rebuilt from the stashed query even when the data-attributes filter
	 * receives no query in its args, as on Jetpack's block Sharing Buttons, whose data-attributes
	 * filter runs in a separate method that never sees the query.
	 */
	public function test_data_attribute_written_when_query_absent_from_args() {
		$source = new \stdClass();
		Jetpack::obfuscate_share_query( 'share=twitter', $source, false, $this->get_link_args() );
		// Block-theme data-attributes args: [ $id, $data_attributes ], no query at index 3.
		$result = Jetpack::add_obfuscation_data_attribute( [], $source, false, [ false, [] ] );
		$this->assertSame( 'share=twitter', $result['share-query'] );
		$this->assertTrue( Jetpack::is_valid_share_token( $result['share-token'] ) );
	}

	/**
	 * With no prior blank for the source (the display-query filter never ran, or it was a
	 * non-round-trip link), the data-attributes filter writes nothing.
	 */
	public function test_data_attribute_not_written_without_prior_blank() {
		$source = new \stdClass();
		$result = Jetpack::add_obfuscation_data_attribute( [], $source, false, [ false, [] ] );
		$this->assertArrayNotHasKey( 'share-query', $result );
	}

	/**
	 * A stashed query is consumed on read, so a second data-attributes pass for the same source
	 * cannot reuse a stale query.
	 */
	public function test_stashed_query_is_consumed_after_read() {
		$source = new \stdClass();
		Jetpack::obfuscate_share_query( 'share=twitter', $source, false, $this->get_link_args() );
		Jetpack::add_obfuscation_data_attribute( [], $source, false, [ false, [] ] );
		$second = Jetpack::add_obfuscation_data_attribute( [], $source, false, [ false, [] ] );
		$this->assertArrayNotHasKey( 'share-query', $second );
	}

	/**
	 * Each source object keeps its own query, so buttons for different services do not cross
	 * wires regardless of the order their data attributes are read.
	 */
	public function test_distinct_sources_keep_distinct_queries() {
		$twitter  = new \stdClass();
		$facebook = new \stdClass();
		Jetpack::obfuscate_share_query( 'share=twitter', $twitter, false, $this->get_link_args() );
		Jetpack::obfuscate_share_query( 'share=facebook', $facebook, false, $this->get_link_args( 'share=facebook' ) );
		$facebook_attrs = Jetpack::add_obfuscation_data_attribute( [], $facebook, false, [ false, [] ] );
		$twitter_attrs  = Jetpack::add_obfuscation_data_attribute( [], $twitter, false, [ false, [] ] );
		$this->assertSame( 'share=facebook', $facebook_attrs['share-query'] );
		$this->assertSame( 'share=twitter', $twitter_attrs['share-query'] );
	}

	/**
	 * The opt-out filter should disable query blanking entirely.
	 */
	public function test_opt_out_filter_disables_query_blanking() {
		add_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		try {
			$this->assertSame(
				'share=twitter',
				Jetpack::obfuscate_share_query( 'share=twitter', null, 'sharing-twitter-1', $this->get_link_args() )
			);
		} finally {
			remove_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		}
	}

	/**
	 * The opt-out filter should disable the data attribute entirely.
	 */
	public function test_opt_out_filter_disables_data_attribute() {
		add_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		try {
			$result = $this->run_share_link_filters( 'share=twitter' );
			$this->assertArrayNotHasKey( 'share-query', $result );
		} finally {
			remove_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		}
	}

	/**
	 * The data-attributes filter should stash a valid share token so the client script can
	 * append it to the restored share URL and pass the server-side gate.
	 */
	public function test_data_attribute_includes_valid_share_token() {
		$result = $this->run_share_link_filters( 'share=twitter' );
		$this->assertArrayHasKey( 'share-token', $result );
		$this->assertTrue( Jetpack::is_valid_share_token( $result['share-token'] ) );
	}

	/**
	 * No token should be stashed for non-round-trip services.
	 */
	public function test_no_share_token_for_non_share_service() {
		$result = $this->run_share_link_filters( '' );
		$this->assertArrayNotHasKey( 'share-token', $result );
	}

	/**
	 * The email button's mailto: href is left alone, but Jetpack pings an on-site tracking URL
	 * on click. That URL must be signed so the ping carries a valid token.
	 */
	public function test_email_track_url_is_signed_with_valid_token() {
		$result = $this->run_email_data_attributes();
		$token  = $this->token_from_url( $result['email-share-track-url'] );
		$this->assertNotNull( $token );
		$this->assertTrue( Jetpack::is_valid_share_token( $token ) );
	}

	/**
	 * The signed tracking URL must clear the gate, so the email share is tracked rather than
	 * bounced to the bare permalink.
	 */
	public function test_signed_email_track_url_passes_gate() {
		$result                                 = $this->run_email_data_attributes();
		$_GET['share']                          = 'email';
		$_GET[ Jetpack::SHARE_TOKEN_QUERY_ARG ] = $this->token_from_url( $result['email-share-track-url'] );
		$this->assertFalse( Jetpack::should_block_share_request() );
	}

	/**
	 * A fabricated, unsigned email share request is still blocked, so signing does not reopen
	 * the vector for crawlers.
	 */
	public function test_unsigned_email_share_request_is_blocked() {
		$_GET['share'] = 'email';
		$this->assertTrue( Jetpack::should_block_share_request() );
	}

	/**
	 * With obfuscation disabled the tracking URL is left untouched.
	 */
	public function test_email_track_url_not_signed_when_disabled() {
		add_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		try {
			$result = $this->run_email_data_attributes( 'https://example.com/a-post/?share=email' );
			$this->assertSame( 'https://example.com/a-post/?share=email', $result['email-share-track-url'] );
		} finally {
			remove_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		}
	}

	/**
	 * A token minted a day ago must still verify, so a share click on a page Batcache has held
	 * for up to its 24h maximum TTL is not turned away. This is the regression guard for the
	 * short-lived-nonce bug: a WordPress nonce would already have expired here.
	 */
	public function test_share_token_from_previous_day_is_accepted() {
		$this->assertTrue( Jetpack::is_valid_share_token( Jetpack::share_token( 1 ) ) );
	}

	/**
	 * Two buckets back is still inside the acceptance window (margin over the cache TTL).
	 */
	public function test_share_token_two_days_old_is_accepted() {
		$this->assertTrue( Jetpack::is_valid_share_token( Jetpack::share_token( 2 ) ) );
	}

	/**
	 * A token older than the acceptance window is rejected, so the window is bounded rather
	 * than accept-anything.
	 */
	public function test_share_token_beyond_window_is_rejected() {
		$this->assertFalse( Jetpack::is_valid_share_token( Jetpack::share_token( 3 ) ) );
	}

	/**
	 * A forged or empty token is rejected.
	 */
	public function test_forged_share_token_is_rejected() {
		$this->assertFalse( Jetpack::is_valid_share_token( 'not-a-real-token' ) );
		$this->assertFalse( Jetpack::is_valid_share_token( '' ) );
	}

	/**
	 * A `?share=` request without a token should be blocked.
	 */
	public function test_gate_blocks_share_request_without_token() {
		$_GET['share'] = 'twitter';
		$this->assertTrue( Jetpack::should_block_share_request() );
	}

	/**
	 * A `?share=` request carrying an invalid token should be blocked.
	 */
	public function test_gate_blocks_share_request_with_invalid_token() {
		$_GET['share']                    = 'twitter';
		$_GET[ Jetpack::SHARE_TOKEN_QUERY_ARG ] = 'not-a-real-token';
		$this->assertTrue( Jetpack::should_block_share_request() );
	}

	/**
	 * A `?share=` request carrying a current token should pass through.
	 */
	public function test_gate_allows_share_request_with_valid_token() {
		$_GET['share']                    = 'twitter';
		$_GET[ Jetpack::SHARE_TOKEN_QUERY_ARG ] = Jetpack::share_token();
		$this->assertFalse( Jetpack::should_block_share_request() );
	}

	/**
	 * A share click carrying a day-old token (as served from a cached page) must pass the gate.
	 */
	public function test_gate_allows_share_request_with_day_old_token() {
		$_GET['share']                    = 'twitter';
		$_GET[ Jetpack::SHARE_TOKEN_QUERY_ARG ] = Jetpack::share_token( 1 );
		$this->assertFalse( Jetpack::should_block_share_request() );
	}

	/**
	 * A request without a `share` param is not a share request and must never be gated.
	 */
	public function test_gate_ignores_non_share_request() {
		$this->assertFalse( Jetpack::should_block_share_request() );
	}

	/**
	 * With obfuscation disabled the gate must not block anything, even a token-less share request.
	 */
	public function test_gate_disabled_by_opt_out_filter() {
		$_GET['share'] = 'twitter';
		add_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		try {
			$this->assertFalse( Jetpack::should_block_share_request() );
		} finally {
			remove_filter( 'newspack_jetpack_obfuscate_share_links', '__return_false' );
		}
	}

	/**
	 * The gate must stay active without the classic sharedaddy module, since the block theme's
	 * Sharing Buttons produce and process `?share=` round-trips without it.
	 */
	public function test_gate_active_without_sharedaddy_module() {
		$_GET['share']                 = 'twitter';
		\Jetpack::$test_active_modules = [];
		$this->assertTrue( Jetpack::should_block_share_request() );
	}

	/**
	 * The restore script is printed once we have actually obfuscated a button this request, so it
	 * accompanies blanked links on the block theme regardless of the sharedaddy module.
	 */
	public function test_restore_script_printed_after_obfuscating() {
		$this->run_share_link_filters( 'share=twitter' );
		ob_start();
		Jetpack::print_share_obfuscation_script();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'data-share-query', $output );
	}

	/**
	 * With nothing obfuscated this request, the restore script is not printed.
	 */
	public function test_restore_script_not_printed_without_obfuscating() {
		ob_start();
		Jetpack::print_share_obfuscation_script();
		$output = ob_get_clean();
		$this->assertSame( '', $output );
	}

	/**
	 * The rejection redirect target should be the current URL stripped of the share args,
	 * so a blocked request lands on the bare, cacheable permalink.
	 */
	public function test_share_redirect_url_strips_share_args() {
		$_SERVER['REQUEST_URI'] = '/a-post/?share=twitter&' . Jetpack::SHARE_TOKEN_QUERY_ARG . '=abc123&nb=1';
		$this->assertSame( '/a-post/', Jetpack::get_share_redirect_url() );
	}
}
