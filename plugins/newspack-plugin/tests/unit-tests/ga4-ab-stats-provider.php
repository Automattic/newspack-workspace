<?php
/**
 * Tests for the GA4 A/B stats provider.
 *
 * @package Newspack\Tests
 */

use Newspack\GA4_AB_Stats_Provider;

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
	 * A form-submission test needs two conversion reports and still fits the budget.
	 */
	public function test_get_counts_stays_within_four_requests_for_form_submission() {
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
	 * Without a GA4 property in Site Kit's settings there is nothing to query.
	 */
	public function test_missing_property_is_an_error() {
		delete_option( 'googlesitekit_analytics-4_settings' );
		$provider = new GA4_AB_Stats_Provider( fn() => $this->response( [] ) );
		$this->assertWPError( $provider->get_counts( $this->test_entry(), '', self::START, self::END ) );
	}
}
