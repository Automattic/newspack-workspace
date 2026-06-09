<?php
/**
 * Newspack Insights — Advertising (Tab 8) fixture.
 *
 * Returns a callable that produces a realistic, date-relative Advertising
 * payload for UI smoke testing without a GAM connection. The shape matches the
 * live REST response — `{ current, previous }`, where each is the
 * {@see \Newspack\Insights\Advertising_Metric::get_all()} envelope (and
 * `previous` is null without comparison). Served by the REST controller when
 * NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
 *
 * Variants (via the `_fixture_state` query param — see dev-notes.md):
 *   populated       — full scorecards + tables + direct/programmatic split.
 *   not_ready       — is_report_ready false; both readiness issues present.
 *   zero            — zero-impression window: scorecards 0, tables empty.
 *   no_viewability  — viewability scorecard as a data_unavailable overlay.
 *
 * All dates are computed at runtime so the fixture never goes stale.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

return function ( string $start_date, string $end_date, bool $compare = false, string $variant = 'populated' ): array {
	$tz         = wp_timezone();
	$today      = new DateTimeImmutable( 'today', $tz );
	$data_as_of = $today->modify( '-7 days' )->format( 'Y-m-d' );
	$est_start  = $today->modify( '-7 days' )->format( 'Y-m-d' );

	/**
	 * Build a single window's metric set, scaled so comparison windows can
	 * differ and produce both positive and negative deltas.
	 *
	 * @param float $scale Multiplier applied to the baseline numbers.
	 * @param string $window_variant Variant for this window.
	 * @return array
	 */
	$metrics = function ( float $scale, string $window_variant ): array {
		if ( 'zero' === $window_variant ) {
			return [
				'total_impressions'      => [
					'value'      => 0,
					'computable' => true,
					'type'       => 'count',
				],
				'total_revenue'          => [
					'value'      => 0.0,
					'computable' => true,
					'type'       => 'currency',
				],
				'avg_ecpm'               => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'currency',
					'numerator'   => 0.0,
					'denominator' => 0,
				],
				'fill_rate'              => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'rate',
					'numerator'   => 0,
					'denominator' => 0,
				],
				'viewability_rate'       => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'rate',
					'numerator'   => 0,
					'denominator' => 0,
				],
				'direct_vs_programmatic' => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'breakdown',
				],
				'top_ad_units'           => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'top_advertisers'        => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'performance_by_device'  => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'top_countries'          => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
			];
		}

		$impressions = (int) round( 2400000 * $scale );
		$coded       = (int) round( $impressions * 0.87 );
		$revenue     = round( 4200.0 * $scale, 2 );
		$ecpm        = $coded > 0 ? round( ( $revenue / $coded ) * 1000, 2 ) : 0.0;

		$viewability = 'no_viewability' === $window_variant
			? [
				'value'      => null,
				'computable' => false,
				'overlay'    => [ 'type' => 'data_unavailable' ],
			]
			: [
				'value'       => 0.64,
				'computable'  => true,
				'type'        => 'rate',
				'numerator'   => (int) round( $coded * 0.64 ),
				'denominator' => $coded,
			];

		$ad_units = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$unit_rev = round( ( $revenue / 14 ) * ( 11 - $i ), 2 );
			$unit_imp = (int) round( ( $impressions / 14 ) * ( 11 - $i ) );
			$ad_units[] = [
				'ad_unit'     => sprintf( 'Ad Unit %02d', $i ),
				'impressions' => $unit_imp,
				'revenue'     => $unit_rev,
				'ecpm'        => $unit_imp > 0 ? round( ( $unit_rev / $unit_imp ) * 1000, 2 ) : 0.0,
				'ctr'         => round( 0.004 - ( $i * 0.0002 ), 4 ),
			];
		}

		$advertisers = [];
		for ( $i = 1; $i <= 8; $i++ ) {
			$adv_rev = round( ( $revenue * 0.6 / 9 ) * ( 9 - $i ), 2 );
			$advertisers[] = [
				'advertiser'  => sprintf( 'Advertiser %d', $i ),
				'impressions' => (int) round( ( $impressions * 0.6 / 9 ) * ( 9 - $i ) ),
				'revenue'     => $adv_rev,
			];
		}

		$devices = [
			[
				'device' => 'Smartphone',
				'share'  => 0.58,
			],
			[
				'device' => 'Desktop',
				'share'  => 0.34,
			],
			[
				'device' => 'Tablet',
				'share'  => 0.08,
			],
		];
		$device_rows = array_map(
			function ( $d ) use ( $impressions, $revenue, $coded ) {
				$imp = (int) round( $impressions * $d['share'] );
				$rev = round( $revenue * $d['share'], 2 );
				$cod = (int) round( $coded * $d['share'] );
				return [
					'device'      => $d['device'],
					'impressions' => $imp,
					'revenue'     => $rev,
					'ecpm'        => $cod > 0 ? round( ( $rev / $cod ) * 1000, 2 ) : 0.0,
					'ctr'         => 0.003,
				];
			},
			$devices
		);

		$countries = [
			[
				'country' => 'United States',
				'share'   => 0.82,
			],
			[
				'country' => 'Canada',
				'share'   => 0.07,
			],
			[
				'country' => 'United Kingdom',
				'share'   => 0.05,
			],
			[
				'country' => 'Mexico',
				'share'   => 0.04,
			],
			[
				'country' => 'Germany',
				'share'   => 0.02,
			],
		];
		$country_rows = array_map(
			function ( $c ) use ( $impressions, $revenue, $coded ) {
				$imp = (int) round( $impressions * $c['share'] );
				$rev = round( $revenue * $c['share'], 2 );
				$cod = (int) round( $coded * $c['share'] );
				return [
					'country'     => $c['country'],
					'impressions' => $imp,
					'revenue'     => $rev,
					'ecpm'        => $cod > 0 ? round( ( $rev / $cod ) * 1000, 2 ) : 0.0,
				];
			},
			$countries
		);

		return [
			'total_impressions'      => [
				'value'      => $impressions,
				'computable' => true,
				'type'       => 'count',
			],
			'total_revenue'          => [
				'value'      => $revenue,
				'computable' => true,
				'type'       => 'currency',
			],
			'avg_ecpm'               => [
				'value'       => $ecpm,
				'computable'  => true,
				'type'        => 'currency',
				'numerator'   => $revenue,
				'denominator' => $coded,
			],
			'fill_rate'              => [
				'value'       => 0.87,
				'computable'  => true,
				'type'        => 'rate',
				'numerator'   => $coded,
				'denominator' => $impressions,
			],
			'viewability_rate'       => $viewability,
			'direct_vs_programmatic' => [
				'rows'       => [
					[
						'label'       => 'direct',
						'revenue'     => round( $revenue * 0.6, 2 ),
						'impressions' => (int) round( $impressions * 0.55 ),
					],
					[
						'label'       => 'programmatic',
						'revenue'     => round( $revenue * 0.4, 2 ),
						'impressions' => (int) round( $impressions * 0.45 ),
					],
					[
						'label'       => 'house',
						'revenue'     => 0.0,
						'impressions' => (int) round( $impressions * 0.02 ),
					],
					[
						'label'       => 'other',
						'revenue'     => 0.0,
						'impressions' => 0,
					],
				],
				'computable' => true,
				'type'       => 'breakdown',
			],
			'top_ad_units'           => [
				'rows'       => $ad_units,
				'computable' => true,
				'type'       => 'table',
			],
			'top_advertisers'        => [
				'rows'       => $advertisers,
				'computable' => true,
				'type'       => 'table',
			],
			'performance_by_device'  => [
				'rows'       => $device_rows,
				'computable' => true,
				'type'       => 'table',
			],
			'top_countries'          => [
				'rows'       => $country_rows,
				'computable' => true,
				'type'       => 'table',
			],
		];
	};

	// Not-ready render path: tab visible, reporting not ready, both issues.
	if ( 'not_ready' === $variant ) {
		$not_ready = [
			'window'                      => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			'is_tab_visible'              => true,
			'is_report_ready'             => false,
			'readiness_issues'            => [
				[
					'code'            => 'oauth_scope_missing',
					'message'         => __( 'Your Google connection is missing the Ad Manager scope. Reconnect Google to grant it.', 'newspack-plugin' ),
					'remediation_url' => admin_url( 'admin.php?page=newspack-settings' ),
				],
				[
					'code'            => 'network_code_missing',
					'message'         => __( 'No Google Ad Manager network is configured.', 'newspack-plugin' ),
					'remediation_url' => admin_url( 'admin.php?page=newspack-advertising' ),
				],
			],
			'metrics'                     => [],
			'data_as_of'                  => $data_as_of,
			'has_estimated_data'          => false,
			'estimated_window_start_date' => null,
		];

		return [
			'current'  => $not_ready,
			'previous' => null,
		];
	}

	$envelope = [
		'window'                      => [
			'start' => $start_date,
			'end'   => $end_date,
		],
		'is_tab_visible'              => true,
		'is_report_ready'             => true,
		'readiness_issues'            => [],
		'metrics'                     => $metrics( 1.0, $variant ),
		'data_as_of'                  => $data_as_of,
		'has_estimated_data'          => true,
		'estimated_window_start_date' => $est_start,
	];

	$previous = null;
	if ( $compare ) {
		// Comparison window = the immediately-preceding window of equal length.
		try {
			$start       = new DateTimeImmutable( $start_date, $tz );
			$end         = new DateTimeImmutable( $end_date, $tz );
			$length_days = (int) $start->diff( $end )->format( '%a' ) + 1;
			$prior_end   = $start->modify( '-1 day' );
			$prior_start = $prior_end->modify( '-' . ( $length_days - 1 ) . ' days' );
		} catch ( Exception $e ) {
			$prior_start = $start_date;
			$prior_end   = $end_date;
		}

		// 0.85 scale → current is higher on volume (positive deltas) while a few
		// per-row figures land lower, exercising both delta directions.
		$previous = [
			'window'                      => [
				'start' => $prior_start instanceof DateTimeImmutable ? $prior_start->format( 'Y-m-d' ) : $prior_start,
				'end'   => $prior_end instanceof DateTimeImmutable ? $prior_end->format( 'Y-m-d' ) : $prior_end,
			],
			'is_tab_visible'              => true,
			'is_report_ready'             => true,
			'readiness_issues'            => [],
			'metrics'                     => $metrics( 0.85, 'zero' === $variant ? 'populated' : $variant ),
			'data_as_of'                  => $data_as_of,
			'has_estimated_data'          => true,
			'estimated_window_start_date' => $est_start,
		];
	}

	return [
		'current'  => $envelope,
		'previous' => $previous,
	];
};
