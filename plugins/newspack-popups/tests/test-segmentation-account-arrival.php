<?php
/**
 * Tests for the inbound account-param handler,
 * Newspack_Popups_Segmentation::handle_account_param().
 *
 * The param is always redirected away before any output. That is what keeps the
 * landing page a shared cacheable URL and what makes ActiveCampaign's `%TAG%`
 * syntax safe to emit at all (NPPM-3032), so the always-redirect property is
 * load-bearing, not incidental.
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
	 * Set up: two active segments and an empty snapshot store.
	 */
	public function set_up() {
		parent::set_up();
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
		$this->segment_ids = [];
		foreach ( Newspack_Popups_Segmentation::get_segments( false ) as $segment ) {
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
	 * A snapshot can name a segment that has since been deleted or disabled.
	 * Only IDs the site still ships to the browser are worth carrying.
	 */
	public function test_drops_segment_ids_that_are_not_active() {
		\Newspack\Reader_Data::$matched_segments = [
			42 => [ $this->segment_ids['carried-one'], '999999' ],
		];
		$this->arrive( '/p/?np_account=42' );
		$this->assertSame( $this->segment_ids['carried-one'], $this->cookie() );
	}

	/**
	 * An unsubstituted merge tag still gets redirected away. This is the whole
	 * reason ActiveCampaign can be supported: a malformed percent-escape must
	 * never survive into a rendered page.
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
	 * An account with no snapshot carries nothing and behaves as today.
	 */
	public function test_unknown_account_carries_nothing() {
		$this->assertSame( '/p/?a=1', $this->arrive( '/p/?a=1&np_account=777' ) );
		$this->assertNull( $this->cookie() );
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
	 * No param, nothing to do — the overwhelmingly common request.
	 */
	public function test_ignores_request_without_the_param() {
		$this->assertNull( $this->arrive( '/p/?utm_medium=email' ) );
	}

	/**
	 * Redirecting a POST would discard its body.
	 */
	public function test_ignores_non_get_requests() {
		$this->assertNull( $this->arrive( '/p/?np_account=42', 'POST' ) );
	}

	/**
	 * The resolver is the seam the handler is built on; exercise it directly for
	 * the degenerate inputs.
	 */
	public function test_resolver_rejects_non_positive_ids() {
		$this->assertSame( [], Newspack_Popups_Segmentation::get_carried_segments_for_account( 0 ) );
		$this->assertSame( [], Newspack_Popups_Segmentation::get_carried_segments_for_account( -1 ) );
	}
}
