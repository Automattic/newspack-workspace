<?php
/**
 * Newspack Insights — Audience (Tab 1) fixture payload (NPPD-1649).
 *
 * Realistic mock data for UI smoke testing without a GA4 connection,
 * served by Audience_REST_Controller when NEWSPACK_INSIGHTS_FIXTURE_MODE
 * is truthy. Returns the same { current, previous } shape the live
 * controller assembles. Values are computed date-relative so the fixture
 * never goes stale.
 *
 * Fully populated — no overlay/error states — so fixture mode shows the whole
 * tab rendered cleanly. (The overlay/error render paths are covered by the
 * component unit tests, not the fixture.)
 *   - realistic, varied values across scorecards, tables, charts, pies
 *   - two-series timeseries: new_vs_returning_over_time
 *   - Supporter Type pie: "both products" shape
 *   - hidden_in_v1 (skip-renders): top_categories, returning_reader_rate_strict
 *   - comparison deltas in both directions (previous window differs)
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

$now   = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable();
$end   = $now;
$start = $now->modify( '-29 days' );

$scalar = function ( $value, $type ) {
	return [
		'value'      => $value,
		'computable' => true,
		'type'       => $type,
	];
};

$breakdown = function ( array $rows ) {
	return [
		'rows'       => $rows,
		'computable' => true,
		'type'       => 'breakdown',
	];
};

$table = function ( array $rows ) {
	return [
		'rows'       => $rows,
		'computable' => true,
		'type'       => 'table',
	];
};

// Daily time series of $count points ending today, varying around $base.
$series = function ( int $count, int $base, float $jitter ) use ( $end ) {
	$rows = [];
	for ( $i = $count - 1; $i >= 0; $i-- ) {
		$day = $end->modify( "-$i days" );
		// Deterministic pseudo-variation: weekday dip + slow wave, no RNG.
		$dow    = (int) $day->format( 'N' );
		$weekend = ( $dow >= 6 ) ? 0.7 : 1.0;
		$wave    = 1 + ( $jitter * sin( $i / 4.0 ) );
		$rows[]  = [
			'date'           => $day->format( 'Ymd' ),
			'active_readers' => (int) round( $base * $weekend * $wave ),
		];
	}
	return $rows;
};

$build = function ( float $f ) use ( $scalar, $breakdown, $table, $series, $start, $end ) {
	return [
		'window'                             => [
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $end->format( 'Y-m-d' ),
		],

		// Reach.
		'active_readers'                     => $scalar( (int) round( 128430 * $f ), 'count' ),
		'pageviews'                          => $scalar( (int) round( 612900 * $f ), 'count' ),
		'avg_sessions_per_reader'            => $scalar( round( 2.19 * $f, 2 ), 'decimal' ),
		'newsletter_signups'                 => $scalar( (int) round( 1840 * $f ), 'count' ),

		// Time trends.
		// Two parallel series (new vs returning) on a shared date axis, with
		// realistic divergence — returning readers run lower and steadier.
		'new_vs_returning_over_time'         => [
			'rows'       => array_map(
				function ( $new_point, $returning_point ) {
					return [
						'date'      => $new_point['date'],
						'new'       => $new_point['active_readers'],
						'returning' => $returning_point['active_readers'],
					];
				},
				$series( 30, (int) round( 2600 * $f ), 0.30 ),
				$series( 30, (int) round( 1700 * $f ), 0.10 )
			),
			'computable' => true,
			'type'       => 'timeseries',
		],
		'readership_by_day_of_week'          => $breakdown(
			[
				[
					'day_of_week'    => 'Monday',
					'active_readers' => (int) round( 21300 * $f ),
				],
				[
					'day_of_week'    => 'Tuesday',
					'active_readers' => (int) round( 22800 * $f ),
				],
				[
					'day_of_week'    => 'Wednesday',
					'active_readers' => (int) round( 23100 * $f ),
				],
				[
					'day_of_week'    => 'Thursday',
					'active_readers' => (int) round( 21950 * $f ),
				],
				[
					'day_of_week'    => 'Friday',
					'active_readers' => (int) round( 18700 * $f ),
				],
				[
					'day_of_week'    => 'Saturday',
					'active_readers' => (int) round( 11200 * $f ),
				],
				[
					'day_of_week'    => 'Sunday',
					'active_readers' => (int) round( 12500 * $f ),
				],
			]
		),
		'readership_by_hour_of_day'          => $breakdown(
			array_map(
				function ( $h ) use ( $f ) {
					$shape = 0.4 + 0.6 * ( sin( ( $h - 6 ) / 24 * M_PI ) ** 2 );
					return [
						'hour'           => str_pad( (string) $h, 2, '0', STR_PAD_LEFT ),
						'active_readers' => (int) round( 5200 * $shape * $f ),
					];
				},
				range( 0, 23 )
			)
		),

		// Traffic sources.
		'traffic_sources_breakdown'          => $breakdown(
			[
				[
					'channel' => 'Organic Search',
					'readers' => (int) round( 51200 * $f ),
				],
				[
					'channel' => 'Direct',
					'readers' => (int) round( 33800 * $f ),
				],
				[
					'channel' => 'Social',
					'readers' => (int) round( 21400 * $f ),
				],
				[
					'channel' => 'Email',
					'readers' => (int) round( 12900 * $f ),
				],
				[
					'channel' => 'Referral',
					'readers' => (int) round( 6300 * $f ),
				],
				[
					'channel' => 'Paid Search',
					'readers' => (int) round( 2830 * $f ),
				],
			]
		),
		'top_campaigns'                      => $table(
			[
				[
					'source'   => 'newsletter',
					'medium'   => 'email',
					'campaign' => 'weekly-digest',
					'readers'  => (int) round( 8200 * $f ),
					'sessions' => (int) round( 11400 * $f ),
				],
				[
					'source'   => 'facebook',
					'medium'   => 'social',
					'campaign' => 'election-coverage',
					'readers'  => (int) round( 6100 * $f ),
					'sessions' => (int) round( 7300 * $f ),
				],
				[
					'source'   => 'google',
					'medium'   => 'cpc',
					'campaign' => 'membership-drive',
					'readers'  => (int) round( 3400 * $f ),
					'sessions' => (int) round( 3900 * $f ),
				],
				[
					'source'   => 'twitter',
					'medium'   => 'social',
					'campaign' => 'breaking-news',
					'readers'  => (int) round( 2900 * $f ),
					'sessions' => (int) round( 3300 * $f ),
				],
				[
					'source'   => 'partner-site',
					'medium'   => 'referral',
					'campaign' => '(no campaign)',
					'readers'  => (int) round( 1500 * $f ),
					'sessions' => (int) round( 1700 * $f ),
				],
			]
		),

		// Composition.
		'device_breakdown'                   => $breakdown(
			[
				[
					'device'  => 'mobile',
					'readers' => (int) round( 89400 * $f ),
				],
				[
					'device'  => 'desktop',
					'readers' => (int) round( 32100 * $f ),
				],
				[
					'device'  => 'tablet',
					'readers' => (int) round( 6930 * $f ),
				],
			]
		),
		'newsletter_subscriber_composition'  => $breakdown(
			[
				[
					'label' => 'Newsletter subscriber',
					'value' => (int) round( 32100 * $f ),
				],
				[
					'label' => 'Not subscribed',
					'value' => (int) round( 96330 * $f ),
				],
			]
		),
		'logged_in_vs_anonymous_composition' => $breakdown(
			[
				[
					'label' => 'Logged in',
					'value' => (int) round( 38500 * $f ),
				],
				[
					'label' => 'Anonymous',
					'value' => (int) round( 89930 * $f ),
				],
			]
		),
		// Supporter Type — among logged-in readers; "both products" shape.
		'supporter_type'                     => $breakdown(
			[
				[
					'label' => 'Subscriber only',
					'value' => (int) round( 14200 * $f ),
				],
				[
					'label' => 'Donor only',
					'value' => (int) round( 9100 * $f ),
				],
				[
					'label' => 'Both',
					'value' => (int) round( 3400 * $f ),
				],
				[
					'label' => 'Logged-in only',
					'value' => (int) round( 11800 * $f ),
				],
			]
		),

		// Geographic.
		'top_regions'                        => $table(
			[
				[
					'country' => 'United States',
					'region'  => 'Illinois',
					'readers' => (int) round( 41200 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'California',
					'readers' => (int) round( 18700 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'New York',
					'readers' => (int) round( 12300 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Texas',
					'readers' => (int) round( 9800 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Indiana',
					'readers' => (int) round( 7600 * $f ),
				],
			]
		),
		'top_cities'                         => $table(
			[
				[
					'country' => 'United States',
					'region'  => 'Illinois',
					'city'    => 'Chicago',
					'readers' => (int) round( 33400 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Illinois',
					'city'    => 'Evanston',
					'readers' => (int) round( 4100 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'California',
					'city'    => 'Los Angeles',
					'readers' => (int) round( 6800 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'New York',
					'city'    => 'New York',
					'readers' => (int) round( 9200 * $f ),
				],
				[
					'country' => 'United States',
					'region'  => 'Indiana',
					'city'    => 'Indianapolis',
					'readers' => (int) round( 3300 * $f ),
				],
			]
		),
		// Content performance.
		'top_pages'                          => $table(
			[
				[
					'post_id'        => '40122',
					'page_path'      => '/2026/05/city-budget-vote',
					'page_title'     => 'City council passes contested budget',
					'unique_readers' => (int) round( 21900 * $f ),
					'pageviews'      => (int) round( 28400 * $f ),
				],
				[
					'post_id'        => '39880',
					'page_path'      => '/2026/05/school-closures',
					'page_title'     => 'District announces three school closures',
					'unique_readers' => (int) round( 16400 * $f ),
					'pageviews'      => (int) round( 19100 * $f ),
				],
				[
					'post_id'        => '40310',
					'page_path'      => '/2026/06/heat-wave-guide',
					'page_title'     => 'Your guide to surviving the heat wave',
					'unique_readers' => (int) round( 11200 * $f ),
					'pageviews'      => (int) round( 12700 * $f ),
				],
				[
					'post_id'        => '39501',
					'page_path'      => '/2026/05/transit-expansion',
					'page_title'     => 'Transit expansion clears final hurdle',
					'unique_readers' => (int) round( 8100 * $f ),
					'pageviews'      => (int) round( 9800 * $f ),
				],
				[
					'post_id'        => '39444',
					'page_path'      => '/2026/05/mayor-interview',
					'page_title'     => 'An hour with the mayor: the full interview',
					'unique_readers' => (int) round( 7400 * $f ),
					'pageviews'      => (int) round( 8600 * $f ),
				],
				[
					'post_id'        => '40455',
					'page_path'      => '/2026/06/farmers-market-guide',
					'page_title'     => 'The 2026 farmers market guide',
					'unique_readers' => (int) round( 6300 * $f ),
					'pageviews'      => (int) round( 7900 * $f ),
				],
				[
					'post_id'        => '39988',
					'page_path'      => '/2026/05/housing-explainer',
					'page_title'     => 'Why rents keep climbing: an explainer',
					'unique_readers' => (int) round( 5500 * $f ),
					'pageviews'      => (int) round( 6700 * $f ),
				],
				[
					'post_id'        => '40133',
					'page_path'      => '/2026/05/election-results',
					'page_title'     => 'Live results: the spring municipal election',
					'unique_readers' => (int) round( 4800 * $f ),
					'pageviews'      => (int) round( 6100 * $f ),
				],
				[
					'post_id'        => '40522',
					'page_path'      => '/2026/06/restaurant-openings',
					'page_title'     => 'Six restaurants opening this summer',
					'unique_readers' => (int) round( 4100 * $f ),
					'pageviews'      => (int) round( 5200 * $f ),
				],
				[
					'post_id'        => '39777',
					'page_path'      => '/2026/05/school-board-recap',
					'page_title'     => 'School board recap: what you missed',
					'unique_readers' => (int) round( 3600 * $f ),
					'pageviews'      => (int) round( 4400 * $f ),
				],
			]
		),
		'top_authors_by_reader_count'        => $table(
			[
				[
					'author'         => 'Maria Alvarez',
					'unique_readers' => (int) round( 34200 * $f ),
					'pageviews'      => (int) round( 51200 * $f ),
				],
				[
					'author'         => 'James Okafor',
					'unique_readers' => (int) round( 28700 * $f ),
					'pageviews'      => (int) round( 39800 * $f ),
				],
				[
					'author'         => 'Priya Nair',
					'unique_readers' => (int) round( 19300 * $f ),
					'pageviews'      => (int) round( 24100 * $f ),
				],
				[
					'author'         => 'Daniel Cho',
					'unique_readers' => (int) round( 15800 * $f ),
					'pageviews'      => (int) round( 20400 * $f ),
				],
				[
					'author'         => 'Aisha Bello',
					'unique_readers' => (int) round( 13100 * $f ),
					'pageviews'      => (int) round( 16700 * $f ),
				],
				[
					'author'         => 'Tom Whitfield',
					'unique_readers' => (int) round( 10900 * $f ),
					'pageviews'      => (int) round( 13500 * $f ),
				],
				[
					'author'         => 'Sofia Romano',
					'unique_readers' => (int) round( 9200 * $f ),
					'pageviews'      => (int) round( 11800 * $f ),
				],
				[
					'author'         => 'Marcus Lee',
					'unique_readers' => (int) round( 7600 * $f ),
					'pageviews'      => (int) round( 9400 * $f ),
				],
				[
					'author'         => 'Hannah Berg',
					'unique_readers' => (int) round( 6100 * $f ),
					'pageviews'      => (int) round( 7700 * $f ),
				],
				[
					'author'         => 'Omar Haddad',
					'unique_readers' => (int) round( 4800 * $f ),
					'pageviews'      => (int) round( 6200 * $f ),
				],
			]
		),

		// BQ-only — hidden in v1 (UI skips rendering). Deliberately left hidden so
		// fixture mode shows the skip behavior (categories needs BQ UNNEST).
		'top_categories'                     => [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
			'reason'       => 'available when BigQuery catalog ships',
		],
		'returning_reader_rate_strict'       => [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
		],
	];
};

return [
	'current'  => $build( 1.0 ),
	'previous' => $build( 0.88 ),
];
