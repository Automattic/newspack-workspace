<?php
/**
 * Tests for GA4 segment reach: Data API clients and the reach cache.
 *
 * Mocks the GA4 Data API at the HTTP layer (via `pre_http_request`), the same
 * harness pattern as the custom-dimensions tests.
 *
 * @package Newspack\Tests
 */

use Newspack\GA4_Segment_Reach;
use Newspack\Google_OAuth_GA4_Client;

/**
 * GA4 segment reach.
 *
 * @group ga4-segment-reach
 */
class Newspack_Test_GA4_Segment_Reach extends WP_UnitTestCase {

	const SK_SETTINGS_OPTION = 'googlesitekit_analytics-4_settings';

	/**
	 * Recorded HTTP requests, each `[ 'url' => string, 'method' => string, 'body' => string|null ]`.
	 *
	 * @var array
	 */
	private $http_log = [];

	/**
	 * URL-substring => response array or `callable( $url, $args )`.
	 *
	 * @var array
	 */
	private $http_routes = [];

	/**
	 * Administrator user id used as the Site Kit module owner.
	 *
	 * @var int
	 */
	private $owner_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->owner_id    = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->http_log    = [];
		$this->http_routes = [];
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );

		delete_option( 'newspack_ga4_segment_reach' );
		delete_option( 'newspack_ga4_segment_reach_failures' );
		delete_transient( 'newspack_ga4_segment_reach_attempt' );
		delete_option( self::SK_SETTINGS_OPTION );

		if ( ! defined( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME' ) ) {
			define( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME', 'newspack_manager_api_key' );
		}
		if ( ! defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
			define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'https://oauth.example.test' );
		}
		delete_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME );
		delete_option( '_newspack_google_oauth' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * `pre_http_request` handler: records the request and returns the first
	 * registered route whose substring matches the URL, or a 404.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$method           = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$this->http_log[] = [
			'url'    => $url,
			'method' => $method,
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
		];
		foreach ( $this->http_routes as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return is_callable( $response ) ? call_user_func( $response, $url, $args ) : $response;
			}
		}
		return $this->json_response( 404, [ 'error' => [ 'message' => "Unmocked request to $url" ] ] );
	}

	/**
	 * Build an HTTP response array for `pre_http_request`.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body Response body, JSON-encoded.
	 * @return array
	 */
	private function json_response( $code, array $body ) {
		return [
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'body'     => wp_json_encode( $body ),
			'headers'  => [],
			'cookies'  => [],
		];
	}

	/**
	 * Make Newspack's Google OAuth count as configured. The reach path must
	 * work without the analytics.edit scope, so the stored token deliberately
	 * carries only the base analytics scope.
	 */
	private function configure_newspack_oauth() {
		update_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME, 'test-key' );
		update_option(
			'_newspack_google_oauth',
			[
				'access_token'  => 'fake-access-token',
				'expires_at'    => time() + HOUR_IN_SECONDS,
				'refresh_token' => 'fake-refresh-token',
			]
		);
		$this->http_routes['oauth2/v1/tokeninfo'] = $this->json_response(
			200,
			[
				'scope' => 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/analytics',
				'email' => 'owner@example.test',
			]
		);
	}

	/**
	 * Record a connected GA4 property (and module owner) in Site Kit's settings.
	 *
	 * @param string $property_id GA4 property ID.
	 */
	private function connect_property( $property_id ) {
		update_option(
			self::SK_SETTINGS_OPTION,
			[
				'propertyID' => $property_id,
				'ownerID'    => $this->owner_id,
			]
		);
	}

	/**
	 * A canned Data API response: segment 12 matched in 1240 sessions and won
	 * 320; segment 45 matched in 90 with no won rows; `none` matched in 500.
	 *
	 * @param string $timezone Property reporting timezone reported in metadata.
	 * @return array
	 */
	private function report_response( $timezone = 'UTC' ) {
		$row = function ( $segment_id, $event_name, $count ) {
			return [
				'dimensionValues' => [ [ 'value' => $segment_id ], [ 'value' => $event_name ] ],
				'metricValues'    => [ [ 'value' => (string) $count ] ],
			];
		};
		return [
			'rows'     => [
				$row( '12', 'np_segment_matched', 1240 ),
				$row( '12', 'np_segment_won', 320 ),
				$row( '45', 'np_segment_matched', 90 ),
				$row( 'none', 'np_segment_matched', 500 ),
			],
			'metadata' => [ 'timeZone' => $timezone ],
		];
	}

	/**
	 * The OAuth client posts the request body to the Data API runReport
	 * endpoint and returns the decoded response.
	 */
	public function test_oauth_client_runs_report() {
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response() );

		wp_set_current_user( $this->owner_id );
		$client   = Google_OAuth_GA4_Client::build();
		$request  = [
			'dateRanges' => [
				[
					'startDate' => '7daysAgo',
					'endDate'   => 'yesterday',
				],
			],
		];
		$response = $client->run_report( 'PROP-1', $request );

		$this->assertCount( 4, $response['rows'] );
		$last = end( $this->http_log );
		$this->assertStringContainsString( 'analyticsdata.googleapis.com/v1beta/properties/PROP-1:runReport', $last['url'] );
		$this->assertSame( 'POST', $last['method'] );
		$this->assertSame( $request, json_decode( $last['body'], true ) );
	}

	/**
	 * A Data API error surfaces as the exception the auth router expects.
	 */
	public function test_oauth_client_throws_on_report_error() {
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 403, [ 'error' => [ 'message' => 'insufficient scopes' ] ] );

		wp_set_current_user( $this->owner_id );
		$client = Google_OAuth_GA4_Client::build();
		$this->expectException( \RuntimeException::class );
		$client->run_report( 'PROP-1', [] );
	}

	/**
	 * Refresh runs the reach report through the read path — Newspack OAuth
	 * WITHOUT the analytics.edit scope — and stores parsed per-segment counts.
	 */
	public function test_refresh_stores_parsed_reach() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response() );

		GA4_Segment_Reach::refresh();

		$cache = get_option( 'newspack_ga4_segment_reach' );
		$this->assertSame( 'PROP-REACH', $cache['property_id'] );
		$this->assertSame( 7, $cache['range_days'] );
		$this->assertSame(
			[
				'matched' => 1240,
				'won'     => 320,
			],
			$cache['rows']['12']
		);
		// A segment with no won rows records zero, not null: the report ran.
		$this->assertSame(
			[
				'matched' => 90,
				'won'     => 0,
			],
			$cache['rows']['45']
		);
		$this->assertSame( 500, $cache['rows']['none']['matched'] );

		// The request shape is contract with a remote API that nothing local
		// exercises, so assert it exactly: a wrong dimension name is a
		// permanent 400 rather than an empty report, and a wrong metric or
		// filter nesting fails just as silently.
		$last = end( $this->http_log );
		$this->assertStringContainsString( 'analyticsdata.googleapis.com', $last['url'] );
		$body = json_decode( $last['body'], true );
		$this->assertSame(
			[
				[
					'startDate' => '7daysAgo',
					'endDate'   => 'yesterday',
				],
			],
			$body['dateRanges']
		);
		$this->assertSame(
			[
				[ 'name' => 'customEvent:segment_id' ],
				[ 'name' => 'eventName' ],
			],
			$body['dimensions']
		);
		$this->assertSame( [ [ 'name' => 'eventCount' ] ], $body['metrics'] );
		$this->assertSame(
			[
				'filter' => [
					'fieldName'    => 'eventName',
					'inListFilter' => [ 'values' => [ 'np_segment_matched', 'np_segment_won' ] ],
				],
			],
			$body['dimensionFilter']
		);
	}

	/**
	 * The stored `as_of` is yesterday in the *property's* reporting timezone,
	 * which is what GA4 resolves the `yesterday` keyword against. Deriving it
	 * from our own clock disagrees whenever the two are on different days.
	 */
	public function test_refresh_dates_the_report_in_the_property_timezone() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response( 'Pacific/Kiritimati' ) );

		GA4_Segment_Reach::refresh();

		$expected = ( new \DateTimeImmutable( 'yesterday', new \DateTimeZone( 'Pacific/Kiritimati' ) ) )->format( 'Y-m-d' );
		$cache    = get_option( 'newspack_ga4_segment_reach' );
		$this->assertSame( $expected, $cache['as_of'] );
	}

	/**
	 * A 2xx that is not a report — a proxy interstitial decoding to `[]`, or a
	 * failed re-encode yielding null — must not replace good numbers with an
	 * empty set. Parsing it would blank every segment for a day, and the empty
	 * payload would still pass the cache-validity check that gates the
	 * out-of-band re-fetch, so nothing would repair it.
	 */
	public function test_refresh_ignores_a_response_that_is_not_a_report() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$previous = [
			'property_id' => 'PROP-REACH',
			'fetched_at'  => time() - DAY_IN_SECONDS,
			'range_days'  => 7,
			'rows'        => [
				'12' => [
					'matched' => 5,
					'won'     => 1,
				],
			],
		];
		update_option( 'newspack_ga4_segment_reach', $previous, false );
		// 2xx, valid JSON, but nothing that looks like a report.
		$this->http_routes[':runReport'] = $this->json_response( 200, [ 'message' => 'service temporarily unavailable' ] );

		GA4_Segment_Reach::refresh();

		$this->assertSame( $previous, get_option( 'newspack_ga4_segment_reach' ) );
	}

	/**
	 * A report that genuinely found nothing still overwrites: `rowCount` marks
	 * it as a report, unlike the case above.
	 */
	public function test_refresh_stores_an_empty_report() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response(
			200,
			[
				'rowCount' => 0,
				'metadata' => [ 'timeZone' => 'UTC' ],
			]
		);

		GA4_Segment_Reach::refresh();

		$cache = get_option( 'newspack_ga4_segment_reach' );
		$this->assertSame( 'PROP-REACH', $cache['property_id'] );
		$this->assertSame( [], $cache['rows'] );
	}

	/**
	 * A report that cannot succeed — most often because the `segment_id`
	 * dimension is not on the property yet — stops being retried. Without a
	 * ceiling this calls Google on the daily schedule plus once an hour of
	 * admin traffic indefinitely, and the only record is a log line that
	 * compiles out unless the site defines NEWSPACK_LOG_LEVEL.
	 */
	public function test_refresh_gives_up_after_repeated_failures() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response(
			400,
			[ 'error' => [ 'message' => 'Field customEvent:segment_id is not a valid dimension.' ] ]
		);

		for ( $i = 0; $i < GA4_Segment_Reach::MAX_FAILURES; $i++ ) {
			delete_transient( 'newspack_ga4_segment_reach_attempt' );
			GA4_Segment_Reach::refresh();
		}

		$failures = get_option( 'newspack_ga4_segment_reach_failures' );
		$this->assertSame( GA4_Segment_Reach::MAX_FAILURES, $failures['count'] );
		$this->assertStringContainsString( 'not a valid dimension', $failures['last_error'] );

		// Past the ceiling, refresh stops calling Google entirely.
		delete_transient( 'newspack_ga4_segment_reach_attempt' );
		$this->http_log = [];
		GA4_Segment_Reach::refresh();
		$this->assertSame( [], $this->http_log );

		// And the admin path stops enqueuing the immediate fetch.
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			GA4_Segment_Reach::maybe_schedule();
			$this->assertFalse( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::ASYNC_GROUP ) );
		}
	}

	/**
	 * Provisioning the missing dimension is what makes the report possible, so
	 * it clears the give-up state rather than leaving the site waiting for a
	 * property switch.
	 */
	public function test_reset_failures_resumes_refreshing() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		update_option(
			'newspack_ga4_segment_reach_failures',
			[
				'property_id' => 'PROP-REACH',
				'count'       => GA4_Segment_Reach::MAX_FAILURES,
				'last_error'  => 'Field customEvent:segment_id is not a valid dimension.',
			],
			false
		);

		GA4_Segment_Reach::reset_failures();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response() );
		GA4_Segment_Reach::refresh();

		$cache = get_option( 'newspack_ga4_segment_reach' );
		$this->assertSame( 1240, $cache['rows']['12']['matched'] );
		// A success clears the record, so a later hiccup starts from zero.
		$this->assertFalse( get_option( 'newspack_ga4_segment_reach_failures' ) );
	}

	/**
	 * The failure record is scoped to a property: connecting a different one
	 * starts from zero rather than inheriting a give-up from a property that
	 * is no longer connected here.
	 */
	public function test_failures_do_not_carry_across_a_property_switch() {
		$this->connect_property( 'PROP-NEW' );
		update_option(
			'newspack_ga4_segment_reach_failures',
			[
				'property_id' => 'PROP-OLD',
				'count'       => GA4_Segment_Reach::MAX_FAILURES,
				'last_error'  => 'boom',
			],
			false
		);
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response() );

		GA4_Segment_Reach::refresh();

		$cache = get_option( 'newspack_ga4_segment_reach' );
		$this->assertSame( 'PROP-NEW', $cache['property_id'] );
	}

	/**
	 * A successful refresh clears the hourly backoff, so a property switch
	 * minutes later gets its immediate fetch instead of waiting out the hour
	 * the previous run armed.
	 */
	public function test_successful_refresh_clears_the_backoff() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport'] = $this->json_response( 200, $this->report_response() );

		GA4_Segment_Reach::refresh();

		$this->assertFalse( get_transient( 'newspack_ga4_segment_reach_attempt' ) );
	}

	/**
	 * Provisioning writes dimensions, so it keeps requiring the analytics.edit
	 * scope even though the shared router now takes a flag and the reach read
	 * passes false. A stored token carrying only the base analytics scope is
	 * skipped for writes and used for reads.
	 */
	public function test_provisioning_still_requires_the_edit_scope() {
		$this->connect_property( 'PROP-REACH' );
		// configure_newspack_oauth() deliberately stores a token WITHOUT
		// analytics.edit, and Site Kit is not loaded in the test suite, so
		// every write route is unavailable.
		$this->configure_newspack_oauth();
		$this->http_routes[':runReport']    = $this->json_response( 200, $this->report_response() );
		$this->http_routes['customDimensions'] = $this->json_response( 200, [ 'customDimensions' => [] ] );

		$provisioned = \Newspack\GA4_Custom_Dimensions::provision();
		$this->assertTrue( is_wp_error( $provisioned ) );
		$this->assertStringContainsString( 'analytics.edit', $provisioned->get_error_message() );

		// The same token reads fine.
		GA4_Segment_Reach::refresh();
		$cache = get_option( 'newspack_ga4_segment_reach' );
		$this->assertSame( 1240, $cache['rows']['12']['matched'] );
	}

	/**
	 * A failed fetch preserves the previous cache — the UI shows staler
	 * numbers, never none.
	 */
	public function test_refresh_failure_keeps_previous_cache() {
		$this->connect_property( 'PROP-REACH' );
		$this->configure_newspack_oauth();
		$previous = [
			'property_id' => 'PROP-REACH',
			'fetched_at'  => time() - DAY_IN_SECONDS,
			'range_days'  => 7,
			'rows'        => [
				'12' => [
					'matched' => 5,
					'won'     => 1,
				],
			],
		];
		update_option( 'newspack_ga4_segment_reach', $previous, false );
		$this->http_routes[':runReport'] = $this->json_response( 500, [ 'error' => [ 'message' => 'boom' ] ] );

		GA4_Segment_Reach::refresh();

		$this->assertSame( $previous, get_option( 'newspack_ga4_segment_reach' ) );
	}

	/**
	 * Decoration: numbers when a row exists, explicit nulls when the cache
	 * exists without the segment, untouched payload when there is no cache or
	 * the cache belongs to a different property.
	 */
	public function test_decorate_segments_states() {
		$segments = [
			[
				'id'   => '12',
				'name' => 'Donors',
			],
			[
				'id'   => '99',
				'name' => 'Young segment',
			],
		];

		// No cache: payload untouched.
		$this->assertSame( $segments, GA4_Segment_Reach::decorate_segments( $segments ) );

		$this->connect_property( 'PROP-REACH' );
		update_option(
			'newspack_ga4_segment_reach',
			[
				'property_id' => 'PROP-REACH',
				'fetched_at'  => strtotime( '2026-08-07 06:00:00' ),
				'range_days'  => 7,
				'rows'        => [
					'12' => [
						'matched' => 1240,
						'won'     => 320,
					],
				],
			],
			false
		);

		$decorated = GA4_Segment_Reach::decorate_segments( $segments );
		$this->assertSame( 1240, $decorated[0]['reach']['matched'] );
		$this->assertSame( 320, $decorated[0]['reach']['won'] );
		// No `as_of` recorded: fall back to the day before the fetch.
		$this->assertSame( '2026-08-06', $decorated[0]['reach']['as_of'] );
		// The window travels with the numbers so the label can't drift from
		// the report it describes.
		$this->assertSame( 7, $decorated[0]['reach']['range_days'] );
		// Cache present, no row: explicit nulls — "no data yet", not zero.
		$this->assertNull( $decorated[1]['reach']['matched'] );
		$this->assertNull( $decorated[1]['reach']['won'] );

		// A recorded `as_of` wins over the derivation.
		$cache          = get_option( 'newspack_ga4_segment_reach' );
		$cache['as_of'] = '2026-08-04';
		update_option( 'newspack_ga4_segment_reach', $cache, false );
		$decorated = GA4_Segment_Reach::decorate_segments( $segments );
		$this->assertSame( '2026-08-04', $decorated[0]['reach']['as_of'] );

		// Cache for a different property is treated as absent.
		$this->connect_property( 'PROP-OTHER' );
		$this->assertSame( $segments, GA4_Segment_Reach::decorate_segments( $segments ) );
	}

	/**
	 * Decoration must not leave the returned array's last element aliased to
	 * the by-reference loop variable: a caller that copies the result and
	 * writes to that element would otherwise mutate this array too.
	 */
	public function test_decorate_segments_does_not_leak_a_reference() {
		$this->connect_property( 'PROP-REACH' );
		update_option(
			'newspack_ga4_segment_reach',
			[
				'property_id' => 'PROP-REACH',
				'fetched_at'  => time(),
				'range_days'  => 7,
				'rows'        => [],
			],
			false
		);

		$decorated = GA4_Segment_Reach::decorate_segments(
			[
				[ 'id' => '12' ],
				[ 'id' => '99' ],
			]
		);
		$copy               = $decorated;
		$copy[1]['id']      = 'mutated';

		$this->assertSame( '99', $decorated[1]['id'] );
	}

	/**
	 * `admin_init` also fires on admin-ajax.php — authenticated or not — so
	 * every heartbeat tick, autosave and nopriv AJAX hit would otherwise pay
	 * for an actionscheduler_actions query, and an unauthenticated request
	 * could be what enqueues the first fetch.
	 */
	public function test_schedule_skips_ajax_requests() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not available.' );
		}
		$this->connect_property( 'PROP-REACH' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		GA4_Segment_Reach::maybe_schedule();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::GROUP ) );
		$this->assertFalse( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::ASYNC_GROUP ) );
	}

	/**
	 * The daily refresh follows the property: scheduled while connected,
	 * dropped when disconnected. Skipped when Action Scheduler is absent.
	 */
	public function test_schedule_follows_property() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not available.' );
		}
		$this->connect_property( 'PROP-REACH' );
		GA4_Segment_Reach::maybe_schedule();
		$this->assertTrue( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::GROUP ) );
		// With no cache yet, the first fetch is enqueued immediately — and the
		// has-scheduled guard keeps a second pass from enqueuing another.
		$this->assertTrue( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::ASYNC_GROUP ) );
		GA4_Segment_Reach::maybe_schedule();
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				[
					'hook'   => GA4_Segment_Reach::REFRESH_ACTION,
					'group'  => GA4_Segment_Reach::ASYNC_GROUP,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				],
				'ids'
			)
		);

		delete_option( self::SK_SETTINGS_OPTION );
		GA4_Segment_Reach::maybe_schedule();
		$this->assertFalse( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::GROUP ) );
	}

	/**
	 * Connecting a different GA4 property fetches immediately rather than
	 * waiting out the daily refresh. The old property's cache is not usable —
	 * decoration already hides it — so leaving it to the recurring action
	 * would blank the segments list for up to a day.
	 */
	public function test_property_switch_fetches_without_waiting_for_the_daily_refresh() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler not available.' );
		}
		$this->connect_property( 'PROP-OLD' );
		update_option(
			'newspack_ga4_segment_reach',
			[
				'property_id' => 'PROP-OLD',
				'fetched_at'  => time(),
				'range_days'  => 7,
				'rows'        => [
					'12' => [
						'matched' => 1240,
						'won'     => 320,
					],
				],
			],
			false
		);
		// A current, matching cache is no reason to fetch.
		GA4_Segment_Reach::maybe_schedule();
		$this->assertFalse( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::ASYNC_GROUP ) );

		$this->connect_property( 'PROP-NEW' );
		GA4_Segment_Reach::maybe_schedule();
		$this->assertTrue( as_has_scheduled_action( GA4_Segment_Reach::REFRESH_ACTION, [], GA4_Segment_Reach::ASYNC_GROUP ) );
	}
}
