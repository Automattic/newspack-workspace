<?php
/**
 * Tests for the inbound account-param handler,
 * Newspack_Popups_Segmentation::handle_account_param(). The param must always
 * be redirected away before output: it keeps the landing page cacheable and is
 * what makes ActiveCampaign's `%TAG%` syntax safe to emit (NPPM-3032).
 *
 * @package Newspack_Popups
 */

// Stand-ins for the exit()-halting exception and the cross-plugin reader-data
// store; the popups test suite loads only newspack-popups.
require_once __DIR__ . '/mocks/class-segmentation-redirect-exception.php';
require_once __DIR__ . '/mocks/class-reader-data.php';

/**
 * Test the inbound account-param handler.
 */
class SegmentationAccountArrivalTest extends WP_UnitTestCase {

	/**
	 * Segment IDs created for the test, in creation order.
	 *
	 * @var string[]
	 */
	private $segment_ids = [];

	/**
	 * Reflection onto the private get_carried_segments_cookie_options():
	 * setcookie() itself never runs under PHPUnit (headers already sent), so
	 * this is the only way to reach the option values.
	 *
	 * @var ReflectionMethod
	 */
	private static $cookie_options_method;

	/**
	 * Reflection onto the private get_carried_segments_cookie_value(); same
	 * rationale as $cookie_options_method.
	 *
	 * @var ReflectionMethod
	 */
	private static $cookie_value_method;

	/**
	 * Set up: two active segments, one disabled segment, and an empty snapshot
	 * store.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! self::$cookie_options_method ) {
			self::$cookie_options_method = new ReflectionMethod( 'Newspack_Popups_Segmentation', 'get_carried_segments_cookie_options' );
			self::$cookie_options_method->setAccessible( true );
		}
		if ( ! self::$cookie_value_method ) {
			self::$cookie_value_method = new ReflectionMethod( 'Newspack_Popups_Segmentation', 'get_carried_segments_cookie_value' );
			self::$cookie_value_method->setAccessible( true );
		}
		Newspack_Segments_Model::delete_all_segments();
		Newspack_Popups_Segmentation::create_segment(
			[
				'name'          => 'carried-one',
				'configuration' => [],
			]
		);
		Newspack_Popups_Segmentation::create_segment(
			[
				'name'          => 'carried-two',
				'configuration' => [],
			]
		);
		// Disabled, not deleted: exercises the `false` argument to get_segments()
		// in get_carried_segments_for_account(), which a nonexistent ID cannot.
		Newspack_Popups_Segmentation::create_segment(
			[
				'name'          => 'carried-disabled',
				'configuration' => [ 'is_disabled' => true ],
			]
		);
		// Include inactive here so $this->segment_ids also carries the disabled
		// segment's real ID; get_carried_segments_for_account() does its own
		// active-only filtering internally via get_segments( false ).
		$this->segment_ids = [];
		foreach ( Newspack_Popups_Segmentation::get_segments() as $segment ) {
			$this->segment_ids[ $segment['name'] ] = (string) $segment['id'];
		}
		\Newspack\Reader_Data::$matched_segments = [];
		unset( $_COOKIE[ Newspack_Popups_Segmentation::CARRIED_SEGMENTS_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_COOKIE[ Newspack_Popups_Segmentation::CARRIED_SEGMENTS_COOKIE ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		parent::tear_down();
	}

	/**
	 * Run the handler against a simulated request, capturing the redirect
	 * instead of letting the handler exit.
	 *
	 * @param string $request_uri Request URI, including query string.
	 * @param string $method      HTTP method.
	 *
	 * @return string|null Redirect target, or null when no redirect was issued.
	 */
	private function arrive( $request_uri, $method = 'GET' ) {
		$captured = null;
		$filter   = function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new Segmentation_Redirect_Exception();
		};

		// Simulating an inbound request, so populating the superglobals the
		// handler reads is the point of this helper.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$_SERVER['REQUEST_URI']    = $request_uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = [];
		$query                     = wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $_GET );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		add_filter( 'wp_redirect', $filter );
		try {
			Newspack_Popups_Segmentation::handle_account_param();
		} catch ( Segmentation_Redirect_Exception $e ) {
			unset( $e ); // Expected: stands in for the exit() after the redirect.
		} finally {
			remove_filter( 'wp_redirect', $filter );
			unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
			$_GET = [];
		}

		return $captured;
	}

	/**
	 * The cookie value the handler handed off, or null.
	 *
	 * @return string|null
	 */
	private function cookie() {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $_COOKIE[ Newspack_Popups_Segmentation::CARRIED_SEGMENTS_COOKIE ] ?? null;
	}

	/**
	 * The setcookie() options get_carried_segments_cookie_options() returns,
	 * via reflection — see $cookie_options_method.
	 *
	 * @return array
	 */
	private function cookie_options() {
		return self::$cookie_options_method->invoke( null );
	}

	/**
	 * The carried-segments cookie value get_carried_segments_cookie_value()
	 * computes for a given resolved set — via reflection, see
	 * $cookie_value_method.
	 *
	 * @param string[] $segment_ids Active segment IDs.
	 *
	 * @return string
	 */
	private function cookie_value( $segment_ids ) {
		return self::$cookie_value_method->invoke( null, $segment_ids );
	}

	/**
	 * The happy path: a known account's snapshot is handed off and the param is
	 * stripped, leaving every other param intact.
	 */
	public function test_resolves_snapshot_and_strips_the_param() {
		\Newspack\Reader_Data::$matched_segments = [ 42 => [ $this->segment_ids['carried-one'] ] ];

		$this->assertSame(
			'/2026/07/20/some-post/?utm_medium=email&npnl=ABC123',
			$this->arrive( '/2026/07/20/some-post/?utm_medium=email&np_account=42&npnl=ABC123' )
		);
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * Multiple segments are handed off as a comma-joined list.
	 */
	public function test_hands_off_multiple_segment_ids() {
		\Newspack\Reader_Data::$matched_segments = [
			42 => [ $this->segment_ids['carried-one'], $this->segment_ids['carried-two'] ],
		];
		$this->arrive( '/p/?np_account=42' );
		$this->assertSame(
			$this->segment_ids['carried-one'] . ',' . $this->segment_ids['carried-two'],
			$this->cookie()
		);
	}

	/**
	 * A snapshot can name a segment ID that no longer exists at all (e.g.
	 * deleted since the snapshot was taken). An ID with no matching term can
	 * never match anything, so it's dropped here rather than carried.
	 */
	public function test_drops_unknown_segment_ids() {
		\Newspack\Reader_Data::$matched_segments = [
			42 => [ $this->segment_ids['carried-one'], '999999' ],
		];
		$this->arrive( '/p/?np_account=42' );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * A disabled segment's ID is dropped. Exercises the active-only argument
	 * to get_segments(), which a nonexistent ID alone cannot distinguish.
	 */
	public function test_drops_disabled_segment_ids() {
		\Newspack\Reader_Data::$matched_segments = [
			42 => [ $this->segment_ids['carried-one'], $this->segment_ids['carried-disabled'] ],
		];
		$this->arrive( '/p/?np_account=42' );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * An unsubstituted merge tag still gets redirected away — a malformed
	 * percent-escape must never survive into a rendered page (NPPM-3032).
	 *
	 * @param string $value Raw param value as it arrives.
	 *
	 * @dataProvider unresolvable_value_provider
	 */
	public function test_redirects_away_unresolvable_values( $value ) {
		$this->assertSame( '/p/?a=1', $this->arrive( '/p/?a=1&np_account=' . $value ) );
		$this->assertNull( $this->cookie() );
	}

	/**
	 * Values that are not a plain positive integer: unsubstituted tags from each
	 * supported ESP, and junk.
	 *
	 * @return array[]
	 */
	public function unresolvable_value_provider() {
		return [
			'mailchimp tag'        => [ '*|NP_ACCOUNT|*' ],
			'activecampaign tag'   => [ '%NP_ACCOUNT%' ],
			'constant contact tag' => [ '[[NP_ACCOUNT]]' ],
			'campaign monitor tag' => [ '[NP_ACCOUNT]' ],
			'hex-shaped tag'       => [ '%CAFE%' ],
			'empty'                => [ '' ],
			'zero'                 => [ '0' ],
			'negative'             => [ '-5' ],
			'not a number'         => [ 'abc' ],
			'float'                => [ '4.2' ],
		];
	}

	/**
	 * `np_account[]=…` puts an array in $_GET rather than a string — the one
	 * value shape not covered by unresolvable_value_provider(), because it
	 * can't be interpolated into a URL as a scalar. Confirmed safe rather than
	 * assumed: core's sanitize_text_field() (via _sanitize_text_fields())
	 * returns '' for an array, so preg_match() never sees one, and the
	 * redirect still fires with nothing carried.
	 *
	 * Account 42 is seeded with a real snapshot so this also catches a
	 * regression where the array is naively reduced to its first element
	 * ('42') instead of being rejected outright — that would resolve and
	 * cookie a match, which must not happen here.
	 */
	public function test_ignores_array_shaped_param_value() {
		\Newspack\Reader_Data::$matched_segments = [ 42 => [ $this->segment_ids['carried-one'] ] ];
		$this->assertSame( '/p/?a=1', $this->arrive( '/p/?a=1&np_account[]=42' ) );
		$this->assertNull( $this->cookie() );
	}

	/**
	 * An account with no snapshot resolves to "matches nothing": the cookie
	 * carries the CARRIED_SEGMENTS_NONE sentinel — never an empty string,
	 * which setcookie() would send as a deletion.
	 */
	public function test_unknown_account_carries_nothing() {
		$this->assertSame( '/p/?a=1', $this->arrive( '/p/?a=1&np_account=777' ) );
		$this->assertSame( Newspack_Popups_Segmentation::CARRIED_SEGMENTS_NONE, $this->cookie() );
	}

	/**
	 * Each valid arrival is authoritative: one that resolves nothing must
	 * overwrite a previous arrival's cookie with the CARRIED_SEGMENTS_NONE
	 * sentinel, not leave the old value, empty the string, or unset it.
	 */
	public function test_overwrites_a_previous_cookie_when_nothing_resolves() {
		\Newspack\Reader_Data::$matched_segments = [ 42 => [ $this->segment_ids['carried-one'] ] ];
		$this->arrive( '/p/?np_account=42' );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );

		// A second, later arrival for an account with nothing to carry.
		$this->arrive( '/p/?np_account=777' );
		$this->assertSame( Newspack_Popups_Segmentation::CARRIED_SEGMENTS_NONE, $this->cookie() );
	}

	/**
	 * A value that never passes the positive-integer gate asserts nothing
	 * about the reader, so an earlier arrival's carried segments stay put —
	 * while the redirect still fires.
	 *
	 * @param string $value Raw param value as it arrives.
	 *
	 * @dataProvider unresolvable_value_provider
	 */
	public function test_unresolvable_value_leaves_existing_cookie_intact( $value ) {
		\Newspack\Reader_Data::$matched_segments = [ 42 => [ $this->segment_ids['carried-one'] ] ];
		$this->arrive( '/p/?np_account=42' );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );

		// A second, later arrival carrying a value that never resolves.
		$this->assertSame( '/p/?a=1', $this->arrive( '/p/?a=1&np_account=' . $value ) );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * The redirect target must not itself trigger another redirect.
	 */
	public function test_redirect_result_does_not_redirect_again() {
		\Newspack\Reader_Data::$matched_segments = [ 42 => [ $this->segment_ids['carried-one'] ] ];
		$once = $this->arrive( '/p/?a=1&np_account=42' );
		$this->assertSame( '/p/?a=1', $once );
		$this->assertNull( $this->arrive( $once ) );
	}

	/**
	 * No param: no redirect, and an existing cookie is left alone.
	 */
	public function test_ignores_request_without_the_param() {
		$_COOKIE[ Newspack_Popups_Segmentation::CARRIED_SEGMENTS_COOKIE ] = $this->segment_ids['carried-one']; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertNull( $this->arrive( '/p/?utm_medium=email' ) );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * A POST is ignored (a redirect would discard its body) and an existing
	 * cookie is left alone.
	 */
	public function test_ignores_non_get_requests() {
		$_COOKIE[ Newspack_Popups_Segmentation::CARRIED_SEGMENTS_COOKIE ] = $this->segment_ids['carried-one']; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertNull( $this->arrive( '/p/?np_account=42', 'POST' ) );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * Non-positive IDs are rejected by the resolver. The mock is seeded under
	 * keys `0` and `-1`, so an empty result can only come from the guard.
	 */
	public function test_resolver_rejects_non_positive_ids() {
		\Newspack\Reader_Data::$matched_segments = [
			0  => [ $this->segment_ids['carried-one'] ],
			-1 => [ $this->segment_ids['carried-one'] ],
		];
		$this->assertSame( [], Newspack_Popups_Segmentation::get_carried_segments_for_account( 0 ) );
		$this->assertSame( [], Newspack_Popups_Segmentation::get_carried_segments_for_account( -1 ) );
	}

	/**
	 * The cookie options must stay JS-readable, session-scoped, and site-wide.
	 * Exercised via reflection because the setcookie() call never runs under
	 * PHPUnit — an `httponly => true` typo would pass every other test.
	 */
	public function test_cookie_options_are_js_readable_session_scoped_and_site_wide() {
		$options = $this->cookie_options();
		$this->assertSame( 0, $options['expires'], 'Must always be a session cookie.' );
		$this->assertFalse( $options['httponly'], 'The view script reads this cookie via document.cookie; httponly would silently break the feature.' );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertSame( '/', $options['path'] );
	}

	/**
	 * The cookie value is never an empty string: setcookie() sends a deletion
	 * for an empty value regardless of `expires`, so the "matches nothing"
	 * assertion would never reach a browser.
	 */
	public function test_cookie_value_for_no_segments_is_the_sentinel_not_empty() {
		$value = $this->cookie_value( [] );
		$this->assertNotSame( '', $value, 'An empty string would be sent by setcookie() as a deletion, indistinguishable from no handoff at all.' );
		$this->assertSame( Newspack_Popups_Segmentation::CARRIED_SEGMENTS_NONE, $value );
	}

	/**
	 * A single resolved segment is carried as-is, with nothing to join.
	 */
	public function test_cookie_value_for_one_segment_is_just_that_id() {
		$this->assertSame( '5', $this->cookie_value( [ '5' ] ) );
	}

	/**
	 * Multiple resolved segments are carried as a comma-joined list — the
	 * form the view script's carried-segments.js splits back apart.
	 */
	public function test_cookie_value_for_multiple_segments_is_comma_joined() {
		$this->assertSame( '5,7', $this->cookie_value( [ '5', '7' ] ) );
	}
}
