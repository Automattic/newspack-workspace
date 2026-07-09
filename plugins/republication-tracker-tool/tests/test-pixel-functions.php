<?php
/**
 * Tests the tracking pixel counting guards (NPPM-2603).
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Test bot filtering and per-client view deduplication for the tracking pixel.
 *
 * @group pixel_counting
 */
class PixelFunctionsTest extends WP_UnitTestCase {
	/**
	 * Saved superglobals, restored after each test.
	 *
	 * @var array
	 */
	private $saved_server;
	private $saved_cookie; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * Save superglobals mutated by these tests.
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_server = $_SERVER;
		$this->saved_cookie = $_COOKIE;
	}

	/**
	 * Restore superglobals.
	 */
	public function tear_down() {
		$_SERVER = $this->saved_server;
		$_COOKIE = $this->saved_cookie;
		parent::tear_down();
	}

	/**
	 * Known crawler / preview-bot user agents must be filtered.
	 *
	 * @dataProvider bot_user_agents
	 * @param string $user_agent The user agent string.
	 */
	public function test_bot_user_agents_are_filtered( $user_agent ) {
		self::assertTrue( wprtt_is_bot_request( $user_agent ), "\"$user_agent\" should be treated as a bot" );
	}

	/**
	 * Bot user agents data provider.
	 */
	public function bot_user_agents() {
		return [
			[ 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ],
			[ 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)' ],
			[ 'Twitterbot/1.0' ],
			[ 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ],
			[ 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)' ],
			[ 'Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.0)' ],
			[ 'curl/8.4.0' ],
			[ 'python-requests/2.31.0' ],
			[ 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/119.0.0.0' ],
			[ '' ], // Image requests from real browsers always carry a user agent.
		];
	}

	/**
	 * Real browser user agents are not filtered.
	 *
	 * @dataProvider browser_user_agents
	 * @param string $user_agent The user agent string.
	 */
	public function test_browser_user_agents_are_not_filtered( $user_agent ) {
		self::assertFalse( wprtt_is_bot_request( $user_agent ), "\"$user_agent\" should NOT be treated as a bot" );
	}

	/**
	 * Browser user agents data provider.
	 */
	public function browser_user_agents() {
		return [
			[ 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' ],
			[ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' ],
			[ 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0' ],
			[ 'Mozilla/5.0 (Linux; Android 11; Cubot X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36' ],
		];
	}

	/**
	 * The same client viewing the same post twice within the window counts once.
	 */
	public function test_repeat_view_within_window_is_deduplicated() {
		$post_id = self::factory()->post->create();
		self::assertTrue( wprtt_should_count_view( $post_id, 'client-123' ), 'First view should count.' );
		self::assertFalse( wprtt_should_count_view( $post_id, 'client-123' ), 'Repeat view within the window should not count.' );
	}

	/**
	 * Different clients viewing the same post each count.
	 */
	public function test_different_clients_each_count() {
		$post_id = self::factory()->post->create();
		self::assertTrue( wprtt_should_count_view( $post_id, 'client-a' ) );
		self::assertTrue( wprtt_should_count_view( $post_id, 'client-b' ) );
	}

	/**
	 * The same client viewing different posts counts on each post.
	 */
	public function test_different_posts_each_count() {
		$post_a = self::factory()->post->create();
		$post_b = self::factory()->post->create();
		self::assertTrue( wprtt_should_count_view( $post_a, 'client-123' ) );
		self::assertTrue( wprtt_should_count_view( $post_b, 'client-123' ) );
	}

	/**
	 * Without a client ID, dedup can't apply — views count (bot filtering is the guard there).
	 */
	public function test_missing_client_id_counts() {
		$post_id = self::factory()->post->create();
		self::assertTrue( wprtt_should_count_view( $post_id, '' ) );
		self::assertTrue( wprtt_should_count_view( $post_id, '' ) );
	}

	/**
	 * The counting guards ship default-off for a gradual rollout.
	 */
	public function test_counting_guards_default_off() {
		self::assertFalse( wprtt_counting_guards_enabled() );
	}

	/**
	 * The filter enables the counting guards.
	 */
	public function test_counting_guards_filter_enables() {
		add_filter( 'wprtt_counting_guards_enabled', '__return_true' );
		self::assertTrue( wprtt_counting_guards_enabled() );
		remove_filter( 'wprtt_counting_guards_enabled', '__return_true' );
	}

	/**
	 * With a client cookie present, the dedup identity is the cookie-derived client ID.
	 */
	public function test_dedup_identity_prefers_cookie() {
		$_COOKIE['_ga'] = 'GA1.2.111111.222222';
		$identity       = wprtt_get_dedup_identity();
		unset( $_COOKIE['_ga'] );
		self::assertSame( '111111.222222', $identity );
	}

	/**
	 * A malformed _ga cookie with fewer than three segments still yields a usable
	 * client ID (never null, no notice).
	 */
	public function test_malformed_ga_cookie_still_yields_client_id() {
		$_COOKIE['_ga'] = '111111.222222';
		$identity       = wprtt_get_dedup_identity();
		self::assertNotSame( '', (string) $identity );
		self::assertNotNull( $identity );
	}

	/**
	 * Without cookies (the common cross-site pixel case, where browsers withhold
	 * them), the identity falls back to a stable IP + user agent hash.
	 */
	public function test_dedup_identity_falls_back_to_ip_ua_hash() {
		unset( $_COOKIE['_ga'], $_COOKIE['newspack-cid'] );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$first = wprtt_get_dedup_identity();
		$again = wprtt_get_dedup_identity();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$other = wprtt_get_dedup_identity();
		self::assertNotSame( '', $first );
		self::assertSame( $first, $again, 'Same IP + UA must produce a stable identity.' );
		self::assertNotSame( $first, $other, 'A different IP must produce a different identity.' );
	}

	/**
	 * With no cookies and no request data at all, there is no identity (view counts).
	 */
	public function test_dedup_identity_empty_without_request_data() {
		unset( $_COOKIE['_ga'], $_COOKIE['newspack-cid'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
		self::assertSame( '', wprtt_get_dedup_identity() );
	}
}
