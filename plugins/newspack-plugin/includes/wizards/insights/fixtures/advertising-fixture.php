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
 *   zero            — zero-activity window: scorecards 0, tables empty
 *                     (has_window_activity false → ReachRevenue no_opportunity).
 *   no_revenue      — impressions running, zero revenue (ReachRevenue per-card
 *                     no-revenue treatment on Total Revenue).
 *   loading         — is_loading true, empty metrics (tab shows progressive
 *                     messages; the empty-state branches must NOT render).
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
				// Cross-system scorecards (NPPD-1675) can't be joined in a no-activity
				// window (no sessions to divide by); the section collapses to its empty
				// state anyway, but keep the metric keys present as data-unavailable.
				'rpm'                         => [
					'value'      => null,
					'computable' => false,
					'overlay'    => [ 'type' => 'data_unavailable' ],
				],
				'avg_impressions_per_session' => [
					'value'      => null,
					'computable' => false,
					'overlay'    => [ 'type' => 'data_unavailable' ],
				],
				'total_impressions'           => [
					'value'      => 0,
					'computable' => true,
					'type'       => 'count',
				],
				'total_revenue'               => [
					'value'      => 0.0,
					'computable' => true,
					'type'       => 'currency',
				],
				'avg_ecpm'                    => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'currency',
					'numerator'   => 0.0,
					'denominator' => 0,
				],
				'fill_rate'                   => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'rate',
					'numerator'   => 0,
					'denominator' => 0,
				],
				'viewability_rate'            => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'rate',
					'numerator'   => 0,
					'denominator' => 0,
				],
				'direct_vs_programmatic'      => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'breakdown',
				],
				'top_ad_units'                => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'top_advertisers'             => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
			];
		}

		if ( 'no_revenue' === $window_variant ) {
			// Impressions running, zero revenue — exercises the ReachRevenue per-card
			// no-revenue treatment on the Total Revenue card.
			$no_rev_impressions = (int) round( 2400000 * $scale );
			$no_rev_sessions    = 800000;
			return [
				// Derived scorecards still compute: RPM is a genuine $0.00 (no revenue),
				// while impressions-per-session stays meaningful.
				'rpm'                         => \Newspack\Insights\Derived\Cross_System_Metrics::rpm(
					[
						'value'      => 0.0,
						'computable' => true,
						'type'       => 'currency',
					],
					$no_rev_sessions
				),
				'avg_impressions_per_session' => \Newspack\Insights\Derived\Cross_System_Metrics::avg_impressions_per_session(
					[
						'value'      => $no_rev_impressions,
						'computable' => true,
						'type'       => 'count',
					],
					$no_rev_sessions
				),
				'total_impressions'           => [
					'value'      => $no_rev_impressions,
					'computable' => true,
					'type'       => 'count',
				],
				'total_revenue'               => [
					'value'      => 0.0,
					'computable' => true,
					'type'       => 'currency',
				],
				'avg_ecpm'                    => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'currency',
					'numerator'   => 0.0,
					'denominator' => 0,
				],
				'fill_rate'                   => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'rate',
					'numerator'   => 0,
					'denominator' => 0,
				],
				'viewability_rate'            => [
					'value'      => null,
					'computable' => false,
					'overlay'    => [ 'type' => 'data_unavailable' ],
				],
				'direct_vs_programmatic'      => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'breakdown',
				],
				'top_ad_units'                => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'top_advertisers'             => [
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
		// Mock sessions (NPPD-1675): 800k against 2.4M impressions / $4,200 revenue →
		// 3.0 ads per session and $5.25 RPM. Held FLAT across windows (not scaled)
		// so the comparison window — whose volume/revenue scale down — produces a
		// visible RPM / ads-per-session delta ("revenue grew on flat traffic").
		$sessions = 800000;

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
		// 10 advertisers (descending) so Top Advertisers shows 5 collapsed and
		// expands to 10 via "See more" (MetricTable defaultRowLimit 5, rowLimit 10).
		for ( $i = 1; $i <= 10; $i++ ) {
			$weight  = 11 - $i;
			$adv_rev = round( ( $revenue * 0.6 / 11 ) * $weight, 2 );
			$advertisers[] = [
				'advertiser'  => sprintf( 'Advertiser %d', $i ),
				'impressions' => (int) round( ( $impressions * 0.6 / 11 ) * $weight ),
				'revenue'     => $adv_rev,
			];
		}

		return [
			// Cross-system derived scorecards (NPPD-1675), computed through the real
			// join so the fixture and production render identically.
			'rpm'                         => \Newspack\Insights\Derived\Cross_System_Metrics::rpm(
				[
					'value'      => $revenue,
					'computable' => true,
					'type'       => 'currency',
				],
				$sessions
			),
			'avg_impressions_per_session' => \Newspack\Insights\Derived\Cross_System_Metrics::avg_impressions_per_session(
				[
					'value'      => $impressions,
					'computable' => true,
					'type'       => 'count',
				],
				$sessions
			),
			'total_impressions'           => [
				'value'      => $impressions,
				'computable' => true,
				'type'       => 'count',
			],
			'total_revenue'               => [
				'value'      => $revenue,
				'computable' => true,
				'type'       => 'currency',
			],
			'avg_ecpm'                    => [
				'value'       => $ecpm,
				'computable'  => true,
				'type'        => 'currency',
				'numerator'   => $revenue,
				'denominator' => $coded,
			],
			'fill_rate'                   => [
				'value'       => 0.87,
				'computable'  => true,
				'type'        => 'rate',
				'numerator'   => $coded,
				'denominator' => $impressions,
			],
			'viewability_rate'            => $viewability,
			'direct_vs_programmatic'      => [
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
			'top_ad_units'                => [
				'rows'       => $ad_units,
				'computable' => true,
				'type'       => 'table',
			],
			'top_advertisers'             => [
				'rows'       => $advertisers,
				'computable' => true,
				'type'       => 'table',
			],
		];
	};

	// Daily revenue series for the Revenue trend chart (NPPD-1674). One point per
	// day across the window, with a slight upward drift and a weekend lift so the
	// line has a readable shape. Date-relative (spans the requested window), capped
	// at 92 days so a huge range stays sane.
	$revenue_series = function ( string $from, string $to, float $scale ) use ( $tz ): array {
		try {
			$day = new DateTimeImmutable( $from, $tz );
			$end = new DateTimeImmutable( $to, $tz );
		} catch ( Exception $e ) {
			return [];
		}
		$rows = [];
		$i    = 0;
		while ( $day <= $end && $i < 92 ) {
			$is_weekend = (int) $day->format( 'N' ) >= 6 ? 1.35 : 1.0;
			$trend      = 1 + ( $i * 0.01 );
			$wobble     = 1 + ( ( $i % 5 ) - 2 ) * 0.06;
			$rows[]     = [
				'date'  => $day->format( 'Y-m-d' ),
				'value' => round( 120.0 * $scale * $trend * $is_weekend * $wobble, 2 ),
			];
			$day = $day->modify( '+1 day' );
			++$i;
		}
		return $rows;
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
			'is_network_member'           => false,
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

	// Loading render path: report not yet cached, background refresh scheduled.
	// The tab shows progressive GAM messages (NPPD-1684) and the empty-state
	// branches must NOT render — has_window_activity is deliberately absent.
	if ( 'loading' === $variant ) {
		return [
			'current'  => [
				'window'            => [
					'start' => $start_date,
					'end'   => $end_date,
				],
				'is_tab_visible'    => true,
				'is_report_ready'   => true,
				'is_network_member' => false,
				'readiness_issues'  => [],
				'metrics'           => [],
				'is_loading'        => true,
			],
			'previous' => null,
		];
	}

	// Per-site breakdown (NPPD-1671): the `network` fixture state flags the site as
	// a network member and adds a top_sites table; every other state is a
	// non-network publisher (is_network_member false, no top_sites).
	$is_network = 'network' === $variant;
	$site_rows  = function ( float $scale ): array {
		$sites = [
			[
				'site'        => 'almanacnews.com',
				'impressions' => 980000,
				'revenue'     => 1720.0,
			],
			[
				'site'        => 'paloaltoonline.com',
				'impressions' => 760000,
				'revenue'     => 1340.0,
			],
			[
				'site'        => 'mv-voice.com',
				'impressions' => 410000,
				'revenue'     => 705.0,
			],
			[
				'site'        => 'pleasantonweekly.com',
				'impressions' => 190000,
				'revenue'     => 300.0,
			],
			[
				'site'        => 'livermorevine.com',
				'impressions' => 95000,
				'revenue'     => 138.0,
			],
		];
		return array_map(
			function ( $row ) use ( $scale ) {
				$impressions = (int) round( $row['impressions'] * $scale );
				$revenue     = round( $row['revenue'] * $scale, 2 );
				return [
					'site'        => $row['site'],
					'impressions' => $impressions,
					'revenue'     => $revenue,
					'ecpm'        => $impressions > 0 ? round( ( $revenue / $impressions ) * 1000, 2 ) : 0.0,
				];
			},
			$sites
		);
	};

	$current_metrics = $metrics( 1.0, $variant );
	// Revenue trend (NPPD-1674): a daily series across the window. Empty on the
	// zero-activity variant so the chart renders its no-data state.
	$current_metrics['revenue_by_day'] = [
		'rows'       => 'zero' === $variant ? [] : $revenue_series( $start_date, $end_date, 1.0 ),
		'computable' => 'zero' !== $variant,
		'type'       => 'timeseries',
	];
	if ( $is_network ) {
		$current_metrics['top_sites'] = [
			'rows'       => $site_rows( 1.0 ),
			'computable' => true,
			'type'       => 'table',
		];
	}
	$envelope = [
		'window'                      => [
			'start' => $start_date,
			'end'   => $end_date,
		],
		'is_tab_visible'              => true,
		'is_report_ready'             => true,
		'is_network_member'           => $is_network,
		'readiness_issues'            => [],
		'metrics'                     => $current_metrics,
		'data_as_of'                  => $data_as_of,
		'has_estimated_data'          => true,
		'estimated_window_start_date' => $est_start,
	];
	// Derived empty-state signal (NPPD-1697): mirror the live read_window()
	// derivation EXACTLY — same signal function, and set ONLY when both volume
	// metrics are computable. Left absent otherwise, so an errored-volume-metric
	// variant would render per-card errors rather than collapse to no_opportunity.
	$imp = $current_metrics['total_impressions'] ?? [];
	$rev = $current_metrics['total_revenue'] ?? [];
	if ( ! empty( $imp['computable'] ) && ! empty( $rev['computable'] ) ) {
		$envelope['has_window_activity'] = \Newspack\Insights\Advertising_Metric::window_activity_signal( (int) ( $imp['value'] ?? 0 ), (float) ( $rev['value'] ?? 0 ) );
	}

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
		// per-row figures land lower, exercising both delta directions. The prior
		// window always uses a non-empty variant so comparison deltas render.
		$prev_metrics = $metrics( 0.85, 'zero' === $variant ? 'populated' : $variant );
		// Prior-period daily revenue (NPPD-1674): the dimmed overlay line under compare.
		$prev_from                      = $prior_start instanceof DateTimeImmutable ? $prior_start->format( 'Y-m-d' ) : $prior_start;
		$prev_to                        = $prior_end instanceof DateTimeImmutable ? $prior_end->format( 'Y-m-d' ) : $prior_end;
		$prev_metrics['revenue_by_day'] = [
			'rows'       => $revenue_series( $prev_from, $prev_to, 0.85 ),
			'computable' => true,
			'type'       => 'timeseries',
		];
		if ( $is_network ) {
			$prev_metrics['top_sites'] = [
				'rows'       => $site_rows( 0.85 ),
				'computable' => true,
				'type'       => 'table',
			];
		}
		$previous = [
			'window'                      => [
				'start' => $prior_start instanceof DateTimeImmutable ? $prior_start->format( 'Y-m-d' ) : $prior_start,
				'end'   => $prior_end instanceof DateTimeImmutable ? $prior_end->format( 'Y-m-d' ) : $prior_end,
			],
			'is_tab_visible'              => true,
			'is_report_ready'             => true,
			'is_network_member'           => $is_network,
			'readiness_issues'            => [],
			'metrics'                     => $prev_metrics,
			'data_as_of'                  => $data_as_of,
			'has_estimated_data'          => true,
			'estimated_window_start_date' => $est_start,
		];
		$prev_imp = $prev_metrics['total_impressions'] ?? [];
		$prev_rev = $prev_metrics['total_revenue'] ?? [];
		if ( ! empty( $prev_imp['computable'] ) && ! empty( $prev_rev['computable'] ) ) {
			$previous['has_window_activity'] = \Newspack\Insights\Advertising_Metric::window_activity_signal( (int) ( $prev_imp['value'] ?? 0 ), (float) ( $prev_rev['value'] ?? 0 ) );
		}
	}

	return [
		'current'  => $envelope,
		'previous' => $previous,
	];
};
