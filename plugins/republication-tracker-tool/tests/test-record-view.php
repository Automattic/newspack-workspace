<?php
/**
 * Tests view recording: raw counter, guarded shadow counter, GA4 event (NPPM-2603).
 *
 * @package Republication_Tracker_Tool
 */

// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables, WordPressVIPMinimum.Variables.ServerVariables -- Tests simulate pixel requests by manipulating $_COOKIE / $_SERVER directly.

/**
 * Test wprtt_record_view(): the raw counter is the publisher-visible number and
 * never changes behavior; the guarded count is a shadow written alongside it
 * when the counting guards are enabled; the GA4 event keeps its legacy shape.
 *
 * @group pixel_counting
 */
class RecordViewTest extends WP_UnitTestCase {
	const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
	const BOT_UA     = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	const REFERRER   = 'https://republisher.example.com/reprinted-story/';

	/**
	 * Saved superglobals, restored after each test.
	 *
	 * @var array
	 */
	private $saved_server;
	private $saved_cookie; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * HTTP requests captured by the pre_http_request spy.
	 *
	 * @var array
	 */
	private $http_requests = [];

	/**
	 * Save superglobals and short-circuit all outbound HTTP.
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_server = $_SERVER;
		$this->saved_cookie = $_COOKIE;

		unset( $_COOKIE['_ga'], $_COOKIE['newspack-cid'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

		$this->http_requests = [];
		add_filter( 'pre_http_request', [ $this, 'capture_http_request' ], 10, 3 );
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
	 * Record outbound requests and return a canned success response.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request args.
	 * @param string      $url  Request URL.
	 * @return array Canned response.
	 */
	public function capture_http_request( $pre, $args, $url ) {
		$this->http_requests[] = [
			'url'  => $url,
			'args' => $args,
		];
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
	 * Read a sharing-counter meta value.
	 *
	 * @param int    $post_id  The post.
	 * @param string $meta_key The counter meta key.
	 * @return array The counter array, or empty array when never written.
	 */
	private function counter( $post_id, $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_array( $value ) ? $value : [];
	}

	/**
	 * The raw counter increments on every view, keyed by referrer.
	 */
	public function test_raw_counter_increments_per_view() {
		$post = get_post( self::factory()->post->create() );
		wprtt_record_view( $post, self::REFERRER, '', false );
		wprtt_record_view( $post, self::REFERRER, '', false );
		self::assertSame(
			[ self::REFERRER => 2 ],
			$this->counter( $post->ID, 'republication_tracker_tool_sharing' )
		);
	}

	/**
	 * The raw counter is the control number: with the guards enabled it still
	 * counts every hit — bots and repeats included — exactly as it always has.
	 */
	public function test_raw_counter_is_untouched_by_guards() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BOT_UA;
		wprtt_record_view( $post, self::REFERRER, '', true );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		wprtt_record_view( $post, self::REFERRER, '', true );
		wprtt_record_view( $post, self::REFERRER, '', true );
		self::assertSame(
			[ self::REFERRER => 3 ],
			$this->counter( $post->ID, 'republication_tracker_tool_sharing' ),
			'Raw counter must count every hit regardless of the guards.'
		);
	}

	/**
	 * With the guards on, a clean view is also counted in the guarded shadow meta.
	 */
	public function test_guarded_counter_written_when_guards_on() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		wprtt_record_view( $post, self::REFERRER, '', true );
		self::assertSame(
			[ self::REFERRER => 1 ],
			$this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' )
		);
	}

	/**
	 * With the guards off, no guarded shadow meta is written at all.
	 */
	public function test_no_guarded_counter_when_guards_off() {
		$post = get_post( self::factory()->post->create() );
		wprtt_record_view( $post, self::REFERRER, '', false );
		self::assertSame( [], $this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' ) );
	}

	/**
	 * Bot hits reach the raw counter but never the guarded one.
	 */
	public function test_guarded_counter_filters_bots() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BOT_UA;
		wprtt_record_view( $post, self::REFERRER, '', true );
		self::assertSame( [ self::REFERRER => 1 ], $this->counter( $post->ID, 'republication_tracker_tool_sharing' ) );
		self::assertSame( [], $this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' ) );
	}

	/**
	 * Repeat views from the same client dedup in the guarded counter only.
	 */
	public function test_guarded_counter_dedups_repeat_views() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		wprtt_record_view( $post, self::REFERRER, '', true );
		wprtt_record_view( $post, self::REFERRER, '', true );
		wprtt_record_view( $post, self::REFERRER, '', true );
		self::assertSame(
			[ self::REFERRER => 3 ],
			$this->counter( $post->ID, 'republication_tracker_tool_sharing' )
		);
		self::assertSame(
			[ self::REFERRER => 1 ],
			$this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' ),
			'The same client repeating a view within the window must count once in the guarded meta.'
		);
	}

	/**
	 * Dedup is per republisher: the same reader opening the same story on two
	 * different republisher sites counts once on EACH site's guarded entry.
	 * The tool's value is per-republisher attribution, and Parse.ly (the
	 * number publishers compare against) attributes per URL — keying dedup on
	 * client + post alone would silently zero the second republisher.
	 */
	public function test_guarded_counter_dedups_per_referrer() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		$other_referrer             = 'https://other-republisher.example.org/same-story/';
		wprtt_record_view( $post, self::REFERRER, '', true );
		wprtt_record_view( $post, $other_referrer, '', true );
		self::assertSame(
			[
				self::REFERRER  => 1,
				$other_referrer => 1,
			],
			$this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' ),
			'The same reader on a different republisher must count on that republisher.'
		);
	}

	/**
	 * The first guarded view for a post snapshots the raw counter and a start
	 * time. The raw counter carries lifetime history while the guarded count
	 * starts at zero when the flag flips, so without a baseline the pilot
	 * compares different time windows on any story with existing views. The
	 * honest comparison is guarded vs (raw minus baseline).
	 */
	public function test_first_guarded_view_snapshots_raw_baseline() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		// Pre-existing history: 5 raw views before the guards ever ran.
		update_post_meta( $post->ID, 'republication_tracker_tool_sharing', [ self::REFERRER => 5 ] );

		wprtt_record_view( $post, self::REFERRER, '', true );

		$baseline = get_post_meta( $post->ID, 'republication_tracker_tool_sharing_guarded_baseline', true );
		self::assertIsArray( $baseline );
		self::assertSame( [ self::REFERRER => 5 ], $baseline['raw'], 'Baseline must hold the raw counter as it stood BEFORE the first guarded-era view.' );
		self::assertGreaterThan( 0, $baseline['started'] );
		self::assertSame( [ self::REFERRER => 6 ], $this->counter( $post->ID, 'republication_tracker_tool_sharing' ) );
		self::assertSame( [ self::REFERRER => 1 ], $this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' ) );
	}

	/**
	 * The baseline is written once and never moves afterward.
	 */
	public function test_baseline_written_only_once() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		wprtt_record_view( $post, self::REFERRER, '', true );
		$first = get_post_meta( $post->ID, 'republication_tracker_tool_sharing_guarded_baseline', true );
		wprtt_record_view( $post, self::REFERRER, '', true );
		wprtt_record_view( $post, 'https://other-republisher.example.org/x/', '', true );
		self::assertSame(
			$first,
			get_post_meta( $post->ID, 'republication_tracker_tool_sharing_guarded_baseline', true ),
			'Later views, including new referrers, must not move the baseline.'
		);
	}

	/**
	 * With the guards off, no baseline is written: flag-off behavior stays
	 * byte-identical to release.
	 */
	public function test_no_baseline_when_guards_off() {
		$post = get_post( self::factory()->post->create() );
		wprtt_record_view( $post, self::REFERRER, '', false );
		self::assertSame( '', get_post_meta( $post->ID, 'republication_tracker_tool_sharing_guarded_baseline', true ) );
	}

	/**
	 * Without full GA4 configuration nothing leaves the site: no Measurement
	 * Protocol event, no title fetch. The counter still works.
	 */
	public function test_no_outbound_requests_without_ga4_config() {
		$post = get_post( self::factory()->post->create() );
		wprtt_record_view( $post, self::REFERRER, 'G-TEST', false );
		self::assertSame( [], $this->http_requests );
		self::assertSame( [ self::REFERRER => 1 ], $this->counter( $post->ID, 'republication_tracker_tool_sharing' ) );
	}

	/**
	 * With GA4 fully configured and a matching ga4 param, one Measurement
	 * Protocol event is sent per view — dedup never gates it (legacy behavior).
	 */
	public function test_ga4_event_sent_per_view_when_configured() {
		$post = get_post( self::factory()->post->create() );
		update_option( 'republication_tracker_tool_analytics_ga4_id', 'G-TEST' );
		update_option( 'republication_tracker_tool_analytics_ga4_secret', 's3cret' );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;
		// A returning reader with a cookie: minting (the cookie-less legacy path)
		// can't run under PHPUnit, where headers are already sent.
		$_COOKIE['newspack-cid'] = '555555555';

		wprtt_record_view( $post, '', 'G-TEST', true );
		wprtt_record_view( $post, '', 'G-TEST', true );

		self::assertCount( 2, $this->http_requests, 'One MP event per view, repeats included.' );
		self::assertStringContainsString( 'google-analytics.com/mp/collect', $this->http_requests[0]['url'] );
	}

	/**
	 * The GA4 event's client ID comes from the reader's existing cookie when
	 * one is present.
	 */
	public function test_ga4_client_id_read_from_cookie() {
		$post = get_post( self::factory()->post->create() );
		update_option( 'republication_tracker_tool_analytics_ga4_id', 'G-TEST' );
		update_option( 'republication_tracker_tool_analytics_ga4_secret', 's3cret' );
		$_COOKIE['newspack-cid'] = '123456789';

		wprtt_record_view( $post, '', 'G-TEST', false );

		self::assertCount( 1, $this->http_requests );
		$payload = json_decode( $this->http_requests[0]['args']['body'], true );
		self::assertSame( '123456789', $payload['client_id'] );
	}

	/**
	 * The counting path must never mint the newspack-cid cookie: it is the
	 * shared Newspack reader-identity cookie, and only the fully-configured GA4
	 * branch may create it (legacy behavior). Under PHPUnit headers are already
	 * sent, so any setcookie() attempt surfaces as a test error — this test
	 * passing proves the counting/dedup path is cookie-read-only.
	 *
	 * The guarantee rides on convertWarningsToExceptions="true" in
	 * phpunit.xml.dist, an attribute PHPUnit 10 removed — on a toolchain
	 * upgrade, re-verify this test still fails when a setcookie() is added to
	 * the counting path.
	 */
	public function test_counting_path_never_mints_the_cid_cookie() {
		$post                       = get_post( self::factory()->post->create() );
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER_UA;

		// Guards on, no cookies, GA4 unconfigured: raw + guarded counting run,
		// the GA4 branch (the only legitimate cookie-minting site) does not.
		wprtt_record_view( $post, self::REFERRER, '', true );
		wprtt_record_view( $post, self::REFERRER, '', true );

		self::assertSame( [ self::REFERRER => 2 ], $this->counter( $post->ID, 'republication_tracker_tool_sharing' ) );
		self::assertSame( [ self::REFERRER => 1 ], $this->counter( $post->ID, 'republication_tracker_tool_sharing_guarded' ) );
	}

	/**
	 * A ga4 param that doesn't match the configured Measurement ID sends nothing.
	 */
	public function test_ga4_param_mismatch_sends_nothing() {
		$post = get_post( self::factory()->post->create() );
		update_option( 'republication_tracker_tool_analytics_ga4_id', 'G-TEST' );
		update_option( 'republication_tracker_tool_analytics_ga4_secret', 's3cret' );

		wprtt_record_view( $post, '', 'G-OTHER', false );

		self::assertSame( [], $this->http_requests );
	}
}
