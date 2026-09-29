<?php
/**
 * Tests the tracking pixel counting guards (NPPM-2603).
 *
 * @package Republication_Tracker_Tool
 */

// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables, WordPressVIPMinimum.Variables.ServerVariables -- Tests simulate pixel requests by manipulating $_COOKIE / $_SERVER directly.

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
			[ 'Pinterest/0.2 (+https://www.pinterest.com/bot.html)' ],
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
			// In-app browsers are real readers, not link-preview crawlers.
			[ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [Pinterest/iOS]' ],
			[ 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.0.0 Mobile Safari/537.36 [Pinterest/Android]' ],
			[ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/467.0.0.28.109;FBBV/606529085]' ],
			[ 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.0.0 Mobile Safari/537.36 Instagram 334.0.0.42.95' ],
		];
	}

	/**
	 * The bot pattern is filterable, so a false positive in the field is a
	 * support-desk fix instead of a plugin release.
	 */
	public function test_bot_pattern_is_filterable() {
		$override = function () {
			return '/testonlytoken/i';
		};
		add_filter( 'wprtt_bot_request_pattern', $override );
		$filtered_in  = wprtt_is_bot_request( 'Mozilla/5.0 TestOnlyToken/1.0' );
		$filtered_out = wprtt_is_bot_request( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
		remove_filter( 'wprtt_bot_request_pattern', $override );
		self::assertTrue( $filtered_in, 'The filtered pattern must apply.' );
		self::assertFalse( $filtered_out, 'The filtered pattern must fully replace the default.' );
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
	 * An empty _ga cookie value falls through to the newspack-cid cookie instead
	 * of producing an empty client ID (which would disable dedup).
	 */
	public function test_empty_ga_cookie_falls_through_to_newspack_cid() {
		$_COOKIE['_ga']          = '';
		$_COOKIE['newspack-cid'] = '424242424';
		self::assertSame( '424242424', wprtt_get_dedup_identity() );
	}

	/**
	 * A present-but-empty newspack-cid cookie is treated as missing: the dedup
	 * identity falls back to IP + user agent instead of an empty string.
	 */
	public function test_empty_newspack_cid_cookie_falls_back_to_ip_ua() {
		unset( $_COOKIE['_ga'] );
		$_COOKIE['newspack-cid']    = '   ';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$identity                   = wprtt_get_dedup_identity();
		self::assertStringStartsWith( 'ipua_', $identity );
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
	 * Accept-Language is mixed into the fallback identity: UA reduction makes
	 * IP + UA coarse behind shared egress IPs, and the header still carries
	 * entropy on image subresource requests.
	 */
	public function test_dedup_identity_mixes_in_accept_language() {
		unset( $_COOKIE['_ga'], $_COOKIE['newspack-cid'] );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT']      = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		$first                           = wprtt_get_dedup_identity();
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
		$other                           = wprtt_get_dedup_identity();
		self::assertNotSame( $first, $other, 'Different Accept-Language behind the same IP + UA must produce different identities.' );
	}

	/**
	 * With a persistent object cache, dedup uses wp_cache_add: atomic (two
	 * simultaneous pixel loads can't both count) and cheaper than transients.
	 */
	public function test_should_count_view_uses_atomic_cache_add_with_object_cache() {
		$post_id = self::factory()->post->create();
		$prior   = wp_using_ext_object_cache( true );
		$first   = wprtt_should_count_view( $post_id, 'client-cache' );
		$again   = wprtt_should_count_view( $post_id, 'client-cache' );
		$stored  = wp_cache_get( 'wprtt_view_' . $post_id . '_' . md5( 'client-cache|' ), 'wprtt_views' );
		wp_using_ext_object_cache( $prior );
		self::assertTrue( $first, 'First view must count.' );
		self::assertFalse( $again, 'Repeat view must not count.' );
		self::assertNotFalse( $stored, 'Dedup must go through wp_cache_add when a persistent object cache is present.' );
	}

	/**
	 * With no cookies and no request data at all, there is no identity (view counts).
	 */
	public function test_dedup_identity_empty_without_request_data() {
		unset( $_COOKIE['_ga'], $_COOKIE['newspack-cid'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
		self::assertSame( '', wprtt_get_dedup_identity() );
	}

	/**
	 * A valid post ID with a ga4 param resolves to that post.
	 */
	public function test_resolve_shared_post_returns_the_requested_post() {
		$post_id  = self::factory()->post->create();
		$resolved = wprtt_resolve_shared_post(
			[
				'post' => (string) $post_id,
				'ga4'  => 'G-TEST',
			]
		);
		self::assertInstanceOf( WP_Post::class, $resolved );
		self::assertSame( $post_id, $resolved->ID );
	}

	/**
	 * Requests without both pixel params resolve to nothing.
	 */
	public function test_resolve_shared_post_requires_both_params() {
		$post_id = self::factory()->post->create();
		self::assertNull( wprtt_resolve_shared_post( [] ) );
		self::assertNull( wprtt_resolve_shared_post( [ 'post' => (string) $post_id ] ) );
		self::assertNull( wprtt_resolve_shared_post( [ 'ga4' => 'G-TEST' ] ) );
	}

	/**
	 * Anything but a strictly positive integer post ID must never resolve to a
	 * post: get_post( 0 ) falls back to the global post, and PHP's coercing
	 * casts map '-1' / '1abc' / '1.9' toward real ID 1 — either way crediting
	 * views to a story that was never republished. The settings page's
	 * copy-paste snippet ships the literal YOUR-POST-ID placeholder, so this
	 * is a real request shape, not just a crawler edge case.
	 *
	 * @dataProvider unresolvable_post_ids
	 * @param string $post_param The raw post query param.
	 */
	public function test_resolve_shared_post_refuses_unresolvable_ids( $post_param ) {
		// Arm both traps: post ID 1 must exist (the coercion target), and the
		// global post is set (the get_post( 0 ) fallback target) — otherwise a
		// refusal can pass for the wrong reason against a coercing validator.
		$armed_id = self::factory()->post->create( [ 'import_id' => 1 ] );
		self::assertSame( 1, $armed_id, 'Precondition: post ID 1 must exist to arm the coercion trap.' );
		$decoy_id        = self::factory()->post->create();
		$GLOBALS['post'] = get_post( $decoy_id );
		$resolved        = wprtt_resolve_shared_post(
			[
				'post' => $post_param,
				'ga4'  => 'G-TEST',
			]
		);
		unset( $GLOBALS['post'] );
		self::assertNull( $resolved, "post={$post_param} must not resolve to any post" );
	}

	/**
	 * Unresolvable post ID data provider.
	 */
	public function unresolvable_post_ids() {
		return [
			'empty'                => [ '' ],
			'non-numeric'          => [ 'abc' ],
			'settings placeholder' => [ 'YOUR-POST-ID' ],
			'explicit zero'        => [ '0' ],
			'negative'             => [ '-1' ],
			'trailing garbage'     => [ '1abc' ],
			'float'                => [ '1.9' ],
		];
	}

	/**
	 * An array-valued post param must never resolve: PHP casts a non-empty
	 * array to int 1, so absint( array( 'x' ) ) is 1 — a request like
	 * ?post[]=x would otherwise credit views to whatever post ID 1 is.
	 */
	public function test_resolve_shared_post_refuses_array_params() {
		// Arm the trap: a post must exist at ID 1, the value PHP's array→int
		// cast produces, or get_post( 1 ) returns null and hides the defect.
		$post_id = self::factory()->post->create( [ 'import_id' => 1 ] );
		self::assertSame( 1, $post_id, 'Precondition: post ID 1 must exist to arm the trap.' );
		self::assertNull(
			wprtt_resolve_shared_post(
				[
					'post' => [ 'x' ],
					'ga4'  => 'G-TEST',
				]
			)
		);
	}

	/**
	 * A well-formed ID that matches no post resolves to nothing.
	 */
	public function test_resolve_shared_post_refuses_unknown_ids() {
		self::assertNull(
			wprtt_resolve_shared_post(
				[
					'post' => '999999999',
					'ga4'  => 'G-TEST',
				]
			)
		);
	}
}
