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
	 * Remember request state the share gate mutates, and report the sharedaddy module active
	 * so the gate runs (it bails when Jetpack sharing is off).
	 */
	public function set_up(): void {
		parent::set_up();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Snapshotting the value verbatim to restore it after the test.
		$this->original_request_uri    = $_SERVER['REQUEST_URI'] ?? null;
		$this->original_active_modules = \Jetpack::$test_active_modules;
		\Jetpack::$test_active_modules = [ 'sharedaddy' ];
	}

	/**
	 * Reset request superglobals and stub state the share gate reads.
	 */
	public function tear_down(): void {
		unset( $_GET['share'], $_GET[ Jetpack::TOKEN_QUERY_ARG ], $_GET['nb'] );
		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		\Jetpack::$test_active_modules = $this->original_active_modules;
		parent::tear_down();
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
		$result = Jetpack::add_obfuscation_data_attribute( [], null, 'sharing-twitter-1', $this->get_link_args() );
		$this->assertArrayHasKey( 'share-query', $result );
		$this->assertSame( 'share=twitter', $result['share-query'] );
	}

	/**
	 * The data-attributes filter should not touch non-round-trip services and should
	 * preserve any attributes already present.
	 */
	public function test_data_attribute_not_added_for_non_share_service() {
		$existing = [ 'foo' => 'bar' ];
		$result   = Jetpack::add_obfuscation_data_attribute( $existing, null, 'id', $this->get_link_args( '' ) );
		$this->assertArrayNotHasKey( 'share-query', $result );
		$this->assertSame( $existing, $result );
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
			$result = Jetpack::add_obfuscation_data_attribute( [], null, 'sharing-twitter-1', $this->get_link_args() );
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
		$result = Jetpack::add_obfuscation_data_attribute( [], null, 'sharing-twitter-1', $this->get_link_args() );
		$this->assertArrayHasKey( 'share-token', $result );
		$this->assertTrue( Jetpack::is_valid_share_token( $result['share-token'] ) );
	}

	/**
	 * No token should be stashed for non-round-trip services.
	 */
	public function test_no_share_token_for_non_share_service() {
		$result = Jetpack::add_obfuscation_data_attribute( [], null, 'id', $this->get_link_args( '' ) );
		$this->assertArrayNotHasKey( 'share-token', $result );
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
		$_GET[ Jetpack::TOKEN_QUERY_ARG ] = 'not-a-real-token';
		$this->assertTrue( Jetpack::should_block_share_request() );
	}

	/**
	 * A `?share=` request carrying a current token should pass through.
	 */
	public function test_gate_allows_share_request_with_valid_token() {
		$_GET['share']                    = 'twitter';
		$_GET[ Jetpack::TOKEN_QUERY_ARG ] = Jetpack::share_token();
		$this->assertFalse( Jetpack::should_block_share_request() );
	}

	/**
	 * A share click carrying a day-old token (as served from a cached page) must pass the gate.
	 */
	public function test_gate_allows_share_request_with_day_old_token() {
		$_GET['share']                    = 'twitter';
		$_GET[ Jetpack::TOKEN_QUERY_ARG ] = Jetpack::share_token( 1 );
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
	 * When Jetpack's sharedaddy module is inactive the gate must not intercept `?share=`
	 * requests, which then belong to some other handler.
	 */
	public function test_gate_skipped_when_sharedaddy_inactive() {
		$_GET['share']                 = 'twitter';
		\Jetpack::$test_active_modules = [];
		$this->assertFalse( Jetpack::should_block_share_request() );
	}

	/**
	 * The rejection redirect target should be the current URL stripped of the share args,
	 * so a blocked request lands on the bare, cacheable permalink.
	 */
	public function test_share_redirect_url_strips_share_args() {
		$_SERVER['REQUEST_URI'] = '/a-post/?share=twitter&' . Jetpack::TOKEN_QUERY_ARG . '=abc123&nb=1';
		$this->assertSame( '/a-post/', Jetpack::get_share_redirect_url() );
	}
}
