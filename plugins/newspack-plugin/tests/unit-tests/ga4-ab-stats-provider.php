<?php
/**
 * Tests for the GA4 A/B stats provider.
 *
 * @package Newspack\Tests
 */

use Newspack\GA4_AB_Stats_Provider;
use Newspack\GoogleSiteKit;

require_once __DIR__ . '/../mocks/newspack-popups-ab-stats-provider-mock.php';
require_once dirname( __DIR__, 2 ) . '/includes/plugins/google-site-kit/class-ga4-ab-stats-provider.php';

/**
 * GA4 A/B stats provider: request builders, response parsing, and the
 * per-variant totals, with the report runner injected.
 *
 * @group ga4-ab-stats
 */
class Newspack_Test_GA4_AB_Stats_Provider extends WP_UnitTestCase {

	const START = '2026-10-01';
	const END   = '2026-10-28';

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'googlesitekit_analytics-4_settings', [ 'propertyID' => '123456' ] );
	}

	/**
	 * A Data API response, as the runner hands it back.
	 *
	 * @param array $rows        `[ [ dimension values..., metric value ], ... ]`.
	 * @param bool  $thresholded Whether GA4 withheld rows.
	 * @return array
	 */
	private function response( array $rows, $thresholded = false ) {
		return [
			'rows'     => array_map(
				function ( $row ) {
					$metric = array_pop( $row );
					return [
						'dimensionValues' => array_map( fn( $v ) => [ 'value' => (string) $v ], $row ),
						'metricValues'    => [ [ 'value' => (string) $metric ] ],
					];
				},
				$rows
			),
			'metadata' => [ 'subjectToThresholding' => $thresholded ],
		];
	}

	/**
	 * A two-arm test as the dashboard passes it.
	 */
	private function test_entry() {
		return [
			'test_id'  => 't',
			'goal'     => 'link_click',
			'variants' => [
				'a' => [
					'id'     => 12,
					'title'  => 'A',
					'status' => 'publish',
				],
				'b' => [
					'id'     => 13,
					'title'  => 'B',
					'status' => 'publish',
				],
			],
		];
	}

	/**
	 * Every filter clause in a request, flattened to `[ field, matchType|in, value(s) ]`.
	 *
	 * @param array $request Request.
	 * @return array
	 */
	private function clauses( array $request ) {
		$out = [];
		foreach ( $request['dimensionFilter']['andGroup']['expressions'] as $expression ) {
			$filter = $expression['filter'];
			if ( isset( $filter['stringFilter'] ) ) {
				$out[] = [ $filter['fieldName'], $filter['stringFilter']['matchType'], $filter['stringFilter']['value'] ];
			} else {
				$out[] = [ $filter['fieldName'], 'in', $filter['inListFilter']['values'] ];
			}
		}
		return $out;
	}

	/**
	 * The readers request counts distinct users who saw one of the prompts.
	 */
	public function test_readers_request_filters_seen_events_for_the_prompts() {
		$request = GA4_AB_Stats_Provider::build_readers_request( [ 12, 13 ], self::START, self::END, 'totalUsers', false );
		$this->assertSame( [ [ 'name' => 'customEvent:newspack_popup_id' ] ], $request['dimensions'] );
		$this->assertSame( [ [ 'name' => 'totalUsers' ] ], $request['metrics'] );
		$this->assertSame(
			[
				[
					'startDate' => self::START,
					'endDate'   => self::END,
				],
			],
			$request['dateRanges'] 
		);
		$this->assertSame( 100, $request['limit'] );
		$this->assertSame(
			[
				[ 'eventName', 'EXACT', 'np_prompt_interaction' ],
				[ 'customEvent:action', 'EXACT', 'seen' ],
				[ 'customEvent:newspack_popup_id', 'in', [ '12', '13' ] ],
			],
			$this->clauses( $request )
		);

		$split = GA4_AB_Stats_Provider::build_readers_request( [ 12 ], self::START, self::END, 'eventCount', true );
		$this->assertSame( [ [ 'name' => 'customEvent:newspack_popup_id' ], [ 'name' => 'customEvent:logged_in' ] ], $split['dimensions'] );
		$this->assertSame( [ [ 'name' => 'eventCount' ] ], $split['metrics'] );
	}

	/**
	 * One conversion request per goal, two for form submissions, none without a goal.
	 */
	public function test_conversion_requests_per_goal() {
		$build = fn( $goal ) => GA4_AB_Stats_Provider::build_conversion_requests( $goal, [ 12 ], self::START, self::END, 'totalUsers' );

		$this->assertCount( 0, $build( '' ) );
		$this->assertCount( 1, $build( 'link_click' ) );
		$this->assertCount( 2, $build( 'form_submission' ) );
		$this->assertCount( 1, $build( 'donation' ) );
		$this->assertCount( 1, $build( 'subscription' ) );

		$this->assertSame(
			[
				[ 'eventName', 'EXACT', 'np_prompt_interaction' ],
				[ 'customEvent:action', 'EXACT', 'clicked' ],
				[ 'customEvent:newspack_popup_id', 'in', [ '12' ] ],
			],
			$this->clauses( $build( 'link_click' )[0] )
		);
		$forms = $build( 'form_submission' );
		$this->assertSame( [ 'eventName', 'in', [ 'np_reader_registered', 'np_newsletter_subscribed' ] ], $this->clauses( $forms[0] )[0] );
		$this->assertSame(
			[
				[ 'eventName', 'EXACT', 'np_modal_checkout_interaction' ],
				[ 'customEvent:action', 'EXACT', 'form_submission_success' ],
				[ 'customEvent:newspack_popup_id', 'in', [ '12' ] ],
			],
			$this->clauses( $forms[1] )
		);
		$this->assertContains( [ 'customEvent:action_type', 'EXACT', 'donation' ], $this->clauses( $build( 'donation' )[0] ) );
		$subscription = $this->clauses( $build( 'subscription' )[0] );
		$this->assertContains( [ 'customEvent:action_type', 'EXACT', 'checkout_button' ], $subscription );
		$this->assertContains( [ 'customEvent:product_type', 'EXACT', 'subscription' ], $subscription );
		foreach ( $build( 'form_submission' ) as $request ) {
			$this->assertSame( [ [ 'name' => 'customEvent:newspack_popup_id' ] ], $request['dimensions'] );
		}
	}

	/**
	 * With the logged-in split, every row counts and the `yes` rows also count as logged in.
	 */
	public function test_parse_rows_sums_the_logged_in_split() {
		$parsed = GA4_AB_Stats_Provider::parse_rows( $this->response( [ [ 12, 'yes', 40 ], [ 12, 'no', 60 ], [ 13, 'no', 7 ] ] ), true );
		$this->assertSame(
			[
				'12' => [
					'count'     => 100,
					'logged_in' => 40,
				],
				'13' => [
					'count'     => 7,
					'logged_in' => 0,
				],
			],
			$parsed
		);
		$this->assertSame(
			[
				'12' => [
					'count'     => 100,
					'logged_in' => 0,
				],
			],
			GA4_AB_Stats_Provider::parse_rows( $this->response( [ [ 12, 100 ] ] ) ) 
		);
		$this->assertSame( [], GA4_AB_Stats_Provider::parse_rows( [] ) );
		$this->assertFalse( GA4_AB_Stats_Provider::is_thresholded( $this->response( [] ) ) );
		$this->assertTrue( GA4_AB_Stats_Provider::is_thresholded( $this->response( [], true ) ) );
	}

	/**
	 * Counts land on the variant whose prompt produced them, and a variant with no rows reads zero.
	 */
	public function test_get_counts_maps_prompts_to_variants_and_zero_fills() {
		$responses = [
			$this->response( [ [ 12, 100 ] ] ),
			$this->response( [ [ 12, 'yes', 40 ], [ 12, 'no', 60 ] ] ),
			$this->response( [ [ 12, 5 ] ] ),
		];
		$seen      = [];
		$provider  = new GA4_AB_Stats_Provider(
			function ( $property_id, $request ) use ( &$responses, &$seen ) {
				$seen[] = [ $property_id, $request['metrics'][0]['name'] ];
				return array_shift( $responses );
			}
		);
		$this->assertSame( 'ga4-site-kit', $provider->get_slug() );
		$this->assertTrue( $provider->is_available() );

		$counts = $provider->get_counts( $this->test_entry(), 'link_click', self::START, self::END );
		$this->assertSame( 'readers', $counts['unit'] );
		$this->assertSame(
			[
				'a' => [
					'readers'           => 100,
					'conversions'       => 5,
					'logged_in_readers' => 40,
				],
				'b' => [
					'readers'           => 0,
					'conversions'       => 0,
					'logged_in_readers' => 0,
				],
			],
			$counts['variants']
		);
		$this->assertIsString( $counts['note'] );
		$this->assertSame( [ '123456', 'totalUsers' ], $seen[0] );
	}

	/**
	 * A form-submission test needs two conversion reports, so four reports in all when nothing is withheld.
	 */
	public function test_form_submission_runs_four_reports_when_nothing_is_withheld() {
		$calls    = 0;
		$provider = new GA4_AB_Stats_Provider(
			function () use ( &$calls ) {
				++$calls;
				return $this->response( [] );
			}
		);
		$counts   = $provider->get_counts( $this->test_entry(), 'form_submission', self::START, self::END );
		$this->assertSame( 4, $calls );
		$this->assertSame( 0, $counts['variants']['a']['readers'] );
	}

	/**
	 * A runner error comes back as the WP_Error the dashboard shows.
	 */
	public function test_runner_error_is_returned_as_wp_error() {
		$provider = new GA4_AB_Stats_Provider( fn() => new WP_Error( 'ga4', 'Quota exceeded.' ) );
		$result   = $provider->get_counts( $this->test_entry(), 'link_click', self::START, self::END );
		$this->assertWPError( $result );
		$this->assertSame( 'Quota exceeded.', $result->get_error_message() );
	}

	/**
	 * Without Site Kit there is no runner, and the provider says so without calling out.
	 */
	public function test_unavailable_without_site_kit() {
		if ( defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			$this->markTestSkipped( 'Only meaningful with Site Kit absent.' );
		}
		$provider  = new GA4_AB_Stats_Provider();
		$available = $provider->is_available();
		$this->assertWPError( $available );
		$this->assertSame( 'Site Kit is not installed.', $available->get_error_message() );
		$this->assertWPError( $provider->get_counts( $this->test_entry(), 'link_click', self::START, self::END ) );
	}

	/**
	 * When GA4 withholds reader rows, every report is rerun as event counts, the split included.
	 */
	public function test_thresholded_readers_fall_back_to_event_counts() {
		$responses = [
			$this->response( [ [ 12, 3 ] ], true ),
			$this->response( [ [ 12, 250 ], [ 13, 240 ] ] ),
			$this->response( [ [ 12, 'yes', 100 ], [ 12, 'no', 150 ], [ 13, 'no', 240 ] ] ),
			$this->response( [ [ 12, 10 ], [ 13, 12 ] ] ),
		];
		$metrics   = [];
		$provider  = new GA4_AB_Stats_Provider(
			function ( $property_id, $request ) use ( &$responses, &$metrics ) {
				$metrics[] = $request['metrics'][0]['name'];
				return array_shift( $responses );
			}
		);
		$counts    = $provider->get_counts( $this->test_entry(), 'link_click', self::START, self::END );
		$this->assertSame( [ 'totalUsers', 'eventCount', 'eventCount', 'eventCount' ], $metrics );
		$this->assertSame( 'events', $counts['unit'] );
		$this->assertSame( 250, $counts['variants']['a']['readers'] );
		$this->assertSame( 240, $counts['variants']['b']['readers'] );
		$this->assertSame( 12, $counts['variants']['b']['conversions'] );
		$this->assertSame( 100, $counts['variants']['a']['logged_in_readers'] );
		$this->assertSame( 0, $counts['variants']['b']['logged_in_readers'] );
	}

	/**
	 * A withheld conversions report also switches the whole test to event counts, so a
	 * small arm never reads as zero conversions against a counted one.
	 */
	public function test_thresholded_conversions_rerun_everything_as_events() {
		$responses = [
			$this->response( [ [ 12, 100 ], [ 13, 90 ] ] ),
			$this->response( [ [ 12, 'yes', 40 ], [ 13, 'yes', 30 ] ] ),
			$this->response( [ [ 12, 5 ] ], true ),
			$this->response( [ [ 12, 300 ], [ 13, 280 ] ] ),
			$this->response( [ [ 12, 'yes', 120 ] ] ),
			$this->response( [ [ 12, 9 ], [ 13, 7 ] ] ),
		];
		$metrics   = [];
		$provider  = new GA4_AB_Stats_Provider(
			function ( $property_id, $request ) use ( &$responses, &$metrics ) {
				$metrics[] = $request['metrics'][0]['name'];
				return array_shift( $responses );
			}
		);
		$counts    = $provider->get_counts( $this->test_entry(), 'link_click', self::START, self::END );
		$this->assertSame( [ 'totalUsers', 'totalUsers', 'totalUsers', 'eventCount', 'eventCount', 'eventCount' ], $metrics );
		$this->assertSame( 'events', $counts['unit'] );
		$this->assertSame( 280, $counts['variants']['b']['readers'] );
		$this->assertSame( 7, $counts['variants']['b']['conversions'] );
		$this->assertSame( 120, $counts['variants']['a']['logged_in_readers'] );
	}

	/**
	 * The widest case: a form-submission test whose last conversion report is withheld runs
	 * its four reports, then all four again as event counts.
	 */
	public function test_form_submission_with_a_late_withheld_report_is_eight_reports() {
		$calls    = 0;
		$provider = new GA4_AB_Stats_Provider(
			function () use ( &$calls ) {
				++$calls;
				return $this->response( [], 4 === $calls );
			}
		);
		$counts   = $provider->get_counts( $this->test_entry(), 'form_submission', self::START, self::END );
		$this->assertSame( 8, $calls );
		$this->assertSame( 'events', $counts['unit'] );
	}

	/**
	 * When GA4 withholds the event counts as well, the test reads as unavailable rather than as zeros.
	 */
	public function test_withheld_event_counts_are_an_error_not_zeros() {
		$provider = new GA4_AB_Stats_Provider( fn() => $this->response( [ [ 12, 3 ] ], true ) );
		$result   = $provider->get_counts( $this->test_entry(), 'link_click', self::START, self::END );
		$this->assertWPError( $result );
		$this->assertSame( 'Google Analytics withheld the counts for this test.', $result->get_error_message() );
	}

	/**
	 * A Data API error body is reduced to its message; a plain exception keeps its first line.
	 */
	public function test_api_error_body_is_reduced_to_its_message() {
		$body = "{\n  \"error\": {\n    \"code\": 400,\n    \"message\": \"Field customEvent:product_type is not a valid dimension.\",\n    \"status\": \"INVALID_ARGUMENT\"\n  }\n}";
		$this->assertSame( 'Field customEvent:product_type is not a valid dimension.', GA4_AB_Stats_Provider::reason_from( new \Exception( $body ) ) );
		$this->assertSame( 'Token has been expired or revoked.', GA4_AB_Stats_Provider::reason_from( new \RuntimeException( "Token has been expired or revoked.\nTrace: here" ) ) );
		$this->assertSame( '', GA4_AB_Stats_Provider::reason_from( new \Exception( '{"error":{"code":500}}' ) ) );
		$this->assertSame( '', GA4_AB_Stats_Provider::reason_from( new \Exception( '' ) ) );
	}

	/**
	 * The Site Kit integration registers the provider on the Campaigns filter and defers to one already there.
	 */
	public function test_filter_supplies_the_provider() {
		$this->assertNotFalse( has_filter( 'newspack_popups_ab_stats_provider', [ GoogleSiteKit::class, 'supply_ab_stats_provider' ] ) );
		$this->assertInstanceOf( GA4_AB_Stats_Provider::class, GoogleSiteKit::supply_ab_stats_provider( null ) );
		$existing = new GA4_AB_Stats_Provider( fn() => [] );
		$this->assertSame( $existing, GoogleSiteKit::supply_ab_stats_provider( $existing ) );
	}

	/**
	 * Without a GA4 property in Site Kit's settings there is nothing to query.
	 */
	public function test_missing_property_is_an_error() {
		delete_option( 'googlesitekit_analytics-4_settings' );
		$provider = new GA4_AB_Stats_Provider( fn() => $this->response( [] ) );
		$this->assertWPError( $provider->get_counts( $this->test_entry(), '', self::START, self::END ) );
	}
}
