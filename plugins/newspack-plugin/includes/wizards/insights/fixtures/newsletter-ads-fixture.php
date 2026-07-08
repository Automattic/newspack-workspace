<?php
/**
 * Newspack Insights — Newsletter Ads fixture (NPPD-1861).
 *
 * Returns a callable that produces a realistic, date-relative Newsletter Ads
 * payload for UI smoke testing without the newsletters plugin. The shape
 * matches the live REST response — `{ current, previous }`, where each is the
 * {@see \Newspack\Insights\Newsletter_Ads_Metric::get_all()} envelope (and
 * `previous` is null without comparison). Served by the REST controller when
 * NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
 *
 * Variants (via the `_fixture_state` query param):
 *   populated      — full scorecards + tables + daily series.
 *   zero           — zero-activity window: scorecards 0, tables empty
 *                    (has_window_activity false).
 *   not_ready      — is_report_ready false + the stats-missing issue;
 *                    lifetime metrics still populated, timeframe metrics
 *                    non-computable.
 *   no_impressions — clicks > 0 but impressions 0 (pixel tracking disabled):
 *                    ctr / ecpm / lifetime_ctr non-computable, tables ranked
 *                    by clicks, tracking-disabled readiness issue present.
 *
 * No 'loading' variant — this tab computes synchronously (is_loading is
 * always false).
 *
 * All dates are computed at runtime so the fixture never goes stale. All
 * advertiser / ad / newsletter names are deliberately generic and fictional.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

return function ( string $start_date, string $end_date, bool $compare = false, string $variant = 'populated' ): array {
	$tz         = wp_timezone();
	$today      = new DateTimeImmutable( 'today', $tz );
	$data_as_of = $today->format( 'Y-m-d' );

	// Obviously-fake advertisers and ads (never real business or publisher names).
	$catalog = [
		[
			'advertiser' => 'Hometown Hardware',
			'title'      => 'Hometown Hardware — Spring Sale',
			'weight'     => 10,
		],
		[
			'advertiser' => 'Riverside Cafe',
			'title'      => 'Riverside Cafe — Weekend Brunch',
			'weight'     => 8,
		],
		[
			'advertiser' => 'Maple Street Books',
			'title'      => 'Maple Street Books — Author Night',
			'weight'     => 7,
		],
		[
			'advertiser' => 'Sunrise Bakery',
			'title'      => 'Sunrise Bakery — Fresh Daily',
			'weight'     => 5,
		],
		[
			'advertiser' => 'Valley Bike Shop',
			'title'      => 'Valley Bike Shop — Tune-Up Special',
			'weight'     => 4,
		],
		[
			'advertiser' => 'Bluebird Florist',
			'title'      => 'Bluebird Florist — Mothers Day',
			'weight'     => 3,
		],
		[
			'advertiser' => '',
			'title'      => 'House Promo — Membership Drive',
			'weight'     => 2,
		],
	];

	/**
	 * Build a single window's metric set, scaled so comparison windows can
	 * differ and produce both positive and negative deltas.
	 *
	 * @param float  $scale          Multiplier applied to the baseline numbers.
	 * @param string $window_variant Variant for this window.
	 * @return array
	 */
	$metrics = function ( float $scale, string $window_variant ) use ( $catalog, $start_date, $end_date, $tz ): array {
		$no_impressions = 'no_impressions' === $window_variant;
		$zero           = 'zero' === $window_variant;

		// Lifetime counters (meta) — always present, even on a zero window.
		$lifetime_impressions = $no_impressions ? 0 : 1850000;
		$lifetime_clicks      = 24800;
		$lifetime             = [
			'lifetime_impressions' => [
				'value'      => $lifetime_impressions,
				'computable' => true,
				'type'       => 'count',
			],
			'lifetime_clicks'      => [
				'value'      => $lifetime_clicks,
				'computable' => true,
				'type'       => 'count',
			],
			'lifetime_ctr'         => [
				'value'       => $lifetime_impressions > 0 ? round( $lifetime_clicks / $lifetime_impressions, 4 ) : 0.0,
				'computable'  => $lifetime_impressions > 0,
				'type'        => 'rate',
				'numerator'   => $lifetime_clicks,
				'denominator' => $lifetime_impressions,
			],
		];

		if ( $zero ) {
			return array_merge(
				$lifetime,
				[
					'total_impressions'    => [
						'value'      => 0,
						'computable' => true,
						'type'       => 'count',
					],
					'total_clicks'         => [
						'value'      => 0,
						'computable' => true,
						'type'       => 'count',
					],
					'ctr'                  => [
						'value'       => 0.0,
						'computable'  => false,
						'type'        => 'rate',
						'numerator'   => 0,
						'denominator' => 0,
					],
					'total_revenue'        => [
						'value'      => 0.0,
						'computable' => true,
						'type'       => 'currency',
					],
					'revenue_excluded_ads' => [
						'value'      => 0,
						'computable' => true,
						'type'       => 'count',
					],
					'ecpm'                 => [
						'value'       => 0.0,
						'computable'  => false,
						'type'        => 'currency',
						'numerator'   => 0.0,
						'denominator' => 0,
					],
					'active_ads'           => [
						'value'      => 0,
						'computable' => true,
						'type'       => 'count',
					],
					'performance_by_day'   => [
						'rows'       => [],
						'computable' => false,
						'type'       => 'timeseries',
					],
					'top_ads'              => [
						'rows'       => [],
						'computable' => false,
						'type'       => 'table',
					],
					'top_advertisers'      => [
						'rows'       => [],
						'computable' => false,
						'type'       => 'table',
					],
					'by_newsletter'        => [
						'rows'       => [],
						'computable' => false,
						'type'       => 'table',
					],
				]
			);
		}

		$impressions = $no_impressions ? 0 : (int) round( 96000 * $scale );
		$clicks      = (int) round( 1440 * $scale );
		$revenue     = round( 1260.0 * $scale, 2 );
		$total_w     = (float) array_sum( array_column( $catalog, 'weight' ) );

		// Per-ad rows spread across the catalog by weight.
		$ad_rows = [];
		foreach ( $catalog as $i => $entry ) {
			$share    = $entry['weight'] / $total_w;
			$ad_imp   = (int) round( $impressions * $share );
			$ad_click = (int) round( $clicks * $share );
			// The house promo carries no price → excluded from revenue.
			$ad_rev    = '' === $entry['advertiser'] ? null : round( $revenue * $share, 2 );
			$ad_rows[] = [
				'ad_id'       => 9000 + $i,
				'title'       => $entry['title'],
				'advertiser'  => $entry['advertiser'],
				'impressions' => $ad_imp,
				'clicks'      => $ad_click,
				'ctr'         => $ad_imp > 0 ? round( $ad_click / $ad_imp, 4 ) : null,
				'revenue'     => $ad_rev,
			];
		}

		$advertiser_rows = [];
		foreach ( $ad_rows as $row ) {
			$name = '' !== $row['advertiser'] ? $row['advertiser'] : __( '(no advertiser)', 'newspack-plugin' );
			if ( ! isset( $advertiser_rows[ $name ] ) ) {
				$advertiser_rows[ $name ] = [
					'advertiser'  => $name,
					'ads'         => 0,
					'impressions' => 0,
					'clicks'      => 0,
					'revenue'     => null,
				];
			}
			++$advertiser_rows[ $name ]['ads'];
			$advertiser_rows[ $name ]['impressions'] += $row['impressions'];
			$advertiser_rows[ $name ]['clicks']      += $row['clicks'];
			if ( null !== $row['revenue'] ) {
				$advertiser_rows[ $name ]['revenue'] = round( ( $advertiser_rows[ $name ]['revenue'] ?? 0.0 ) + $row['revenue'], 2 );
			}
		}
		$advertiser_rows = array_values( $advertiser_rows );
		foreach ( $advertiser_rows as $i => $row ) {
			$advertiser_rows[ $i ]['ctr'] = $row['impressions'] > 0 ? round( $row['clicks'] / $row['impressions'], 4 ) : null;
		}

		// By-newsletter rows: weekly sends across the window, date-relative.
		$newsletter_rows = [];
		try {
			$day = new DateTimeImmutable( $end_date, $tz );
		} catch ( Exception $e ) {
			$day = new DateTimeImmutable( 'today', $tz );
		}
		for ( $i = 0; $i < 6; $i++ ) {
			$sent   = $day->modify( '-' . ( $i * 7 ) . ' days' );
			$factor = ( 6 - $i ) / 6;
			$nl_imp = (int) round( ( $impressions / 8 ) * $factor );
			$nl_clk = (int) round( ( $clicks / 8 ) * $factor );
			$newsletter_rows[] = [
				'newsletter_id' => 7000 + $i,
				'title'         => sprintf( 'Weekly Roundup — %s', $sent->format( 'M j' ) ),
				'sent_date'     => $sent->format( 'Y-m-d' ),
				'ads'           => 3,
				'impressions'   => $nl_imp,
				'clicks'        => $nl_clk,
				'ctr'           => $nl_imp > 0 ? round( $nl_clk / $nl_imp, 4 ) : null,
			];
		}

		// Rank like the live orchestrator: by impressions, falling back to
		// clicks when nothing recorded an impression (pixel disabled).
		$rank = function ( array $rows ) use ( $no_impressions ): array {
			$by = $no_impressions ? 'clicks' : 'impressions';
			usort(
				$rows,
				function ( $a, $b ) use ( $by ) {
					return ( $b[ $by ] ?? 0 ) <=> ( $a[ $by ] ?? 0 );
				}
			);
			return array_slice( $rows, 0, 10 );
		};

		return array_merge(
			$lifetime,
			[
				'total_impressions'    => [
					'value'      => $impressions,
					'computable' => true,
					'type'       => 'count',
				],
				'total_clicks'         => [
					'value'      => $clicks,
					'computable' => true,
					'type'       => 'count',
				],
				'ctr'                  => [
					'value'       => $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0.0,
					'computable'  => $impressions > 0,
					'type'        => 'rate',
					'numerator'   => $clicks,
					'denominator' => $impressions,
				],
				'total_revenue'        => [
					'value'      => $revenue,
					'computable' => true,
					'type'       => 'currency',
				],
				'revenue_excluded_ads' => [
					'value'      => 1,
					'computable' => true,
					'type'       => 'count',
				],
				'ecpm'                 => [
					'value'       => $impressions > 0 ? round( ( $revenue / $impressions ) * 1000, 2 ) : 0.0,
					'computable'  => $revenue > 0 && $impressions > 0,
					'type'        => 'currency',
					'numerator'   => $revenue,
					'denominator' => $impressions,
				],
				'active_ads'           => [
					'value'      => count( $catalog ),
					'computable' => true,
					'type'       => 'count',
				],
				'performance_by_day'   => [
					'rows'       => [],
					'computable' => true,
					'type'       => 'timeseries',
				],
				'top_ads'              => [
					'rows'       => $rank( $ad_rows ),
					'computable' => true,
					'type'       => 'table',
				],
				'top_advertisers'      => [
					'rows'       => $rank( $advertiser_rows ),
					'computable' => true,
					'type'       => 'table',
				],
				'by_newsletter'        => [
					'rows'       => $rank( $newsletter_rows ),
					'computable' => true,
					'type'       => 'table',
				],
			]
		);
	};

	// Daily impressions/clicks series across the requested window, computed
	// date-relative so the fixture never goes stale. Slight midweek lift
	// (newsletters send on weekdays) and an upward drift; capped at 92 days.
	$daily_series = function ( string $from, string $to, float $scale, bool $no_impressions ) use ( $tz ): array {
		try {
			$day = new DateTimeImmutable( $from, $tz );
			$end = new DateTimeImmutable( $to, $tz );
		} catch ( Exception $e ) {
			return [];
		}
		$rows = [];
		$i    = 0;
		while ( $day <= $end && $i < 92 ) {
			$is_send_day = in_array( (int) $day->format( 'N' ), [ 2, 4 ], true ) ? 2.4 : 0.7;
			$trend       = 1 + ( $i * 0.01 );
			$wobble      = 1 + ( ( $i % 5 ) - 2 ) * 0.05;
			$factor      = $scale * $trend * $is_send_day * $wobble;
			$rows[]      = [
				'date'        => $day->format( 'Y-m-d' ),
				'impressions' => $no_impressions ? 0 : (int) round( 3200 * $factor ),
				'clicks'      => (int) round( 48 * $factor ),
			];
			$day = $day->modify( '+1 day' );
			++$i;
		}
		return $rows;
	};

	/**
	 * Assemble a window envelope for a variant.
	 *
	 * @param string $from           Window start.
	 * @param string $to             Window end.
	 * @param float  $scale          Baseline multiplier.
	 * @param string $window_variant Variant for this window.
	 * @return array
	 */
	$envelope = function ( string $from, string $to, float $scale, string $window_variant ) use ( $metrics, $daily_series, $data_as_of ): array {
		$window_metrics = $metrics( $scale, $window_variant );
		if ( 'zero' !== $window_variant ) {
			$window_metrics['performance_by_day'] = [
				'rows'       => $daily_series( $from, $to, $scale, 'no_impressions' === $window_variant ),
				'computable' => true,
				'type'       => 'timeseries',
			];
		}

		$issues = [];
		if ( 'no_impressions' === $window_variant ) {
			// The real shape of "click tracking on, pixel off": the informational
			// tracking-disabled issue accompanies the zero-impression figures.
			$issues[] = [
				'code'            => 'newsletter_ads_tracking_disabled',
				'message'         => __( 'Newsletter open tracking (the tracking pixel) is disabled, so ad impressions are not recorded. Enable it in Newsletters settings to measure impressions.', 'newspack-plugin' ),
				'remediation_url' => '',
			];
		}

		$out = [
			'window'           => [
				'start' => $from,
				'end'   => $to,
			],
			'is_tab_visible'   => true,
			'is_report_ready'  => true,
			'readiness_issues' => $issues,
			'data_as_of'       => $data_as_of,
			'is_loading'       => false,
			'metrics'          => $window_metrics,
		];

		// Mirror the live derivation: set only when the window volume metrics
		// are computable.
		$imp = $window_metrics['total_impressions'] ?? [];
		$clk = $window_metrics['total_clicks'] ?? [];
		if ( ! empty( $imp['computable'] ) && ! empty( $clk['computable'] ) ) {
			$out['has_window_activity'] = ( (int) ( $imp['value'] ?? 0 ) ) > 0 || ( (int) ( $clk['value'] ?? 0 ) ) > 0;
		}

		return $out;
	};

	// Not-ready render path: tab visible (ads exist), stats table missing —
	// lifetime metrics real, timeframe metrics non-computable.
	if ( 'not_ready' === $variant ) {
		$lifetime_impressions = 1850000;
		$lifetime_clicks      = 24800;
		$count_na             = [
			'value'      => 0,
			'computable' => false,
			'type'       => 'count',
		];
		$not_ready            = [
			'window'           => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			'is_tab_visible'   => true,
			'is_report_ready'  => false,
			'readiness_issues' => [
				[
					'code'            => 'newsletter_ads_stats_missing',
					'message'         => __( 'Newsletter ad statistics require the latest Newspack Newsletters plugin.', 'newspack-plugin' ),
					'remediation_url' => '',
				],
			],
			'data_as_of'       => $data_as_of,
			'is_loading'       => false,
			'metrics'          => [
				'lifetime_impressions' => [
					'value'      => $lifetime_impressions,
					'computable' => true,
					'type'       => 'count',
				],
				'lifetime_clicks'      => [
					'value'      => $lifetime_clicks,
					'computable' => true,
					'type'       => 'count',
				],
				'lifetime_ctr'         => [
					'value'       => round( $lifetime_clicks / $lifetime_impressions, 4 ),
					'computable'  => true,
					'type'        => 'rate',
					'numerator'   => $lifetime_clicks,
					'denominator' => $lifetime_impressions,
				],
				'total_impressions'    => $count_na,
				'total_clicks'         => $count_na,
				'ctr'                  => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'rate',
					'numerator'   => 0,
					'denominator' => 0,
				],
				'total_revenue'        => [
					'value'      => 0.0,
					'computable' => false,
					'type'       => 'currency',
				],
				'revenue_excluded_ads' => $count_na,
				'ecpm'                 => [
					'value'       => 0.0,
					'computable'  => false,
					'type'        => 'currency',
					'numerator'   => 0.0,
					'denominator' => 0,
				],
				'active_ads'           => $count_na,
				'performance_by_day'   => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'timeseries',
				],
				'top_ads'              => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'top_advertisers'      => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
				'by_newsletter'        => [
					'rows'       => [],
					'computable' => false,
					'type'       => 'table',
				],
			],
		];

		return [
			'current'  => $not_ready,
			'previous' => null,
		];
	}

	$current = $envelope( $start_date, $end_date, 1.0, $variant );

	$previous = null;
	if ( $compare ) {
		// Comparison window = the immediately-preceding window of equal length.
		try {
			$start       = new DateTimeImmutable( $start_date, $tz );
			$end         = new DateTimeImmutable( $end_date, $tz );
			$length_days = (int) $start->diff( $end )->format( '%a' ) + 1;
			$prior_end   = $start->modify( '-1 day' )->format( 'Y-m-d' );
			$prior_start = $start->modify( '-' . $length_days . ' days' )->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			$prior_start = $start_date;
			$prior_end   = $end_date;
		}

		// 0.85 scale → current is higher on volume (positive deltas). The prior
		// window always uses a non-empty variant so comparison deltas render.
		$previous = $envelope( $prior_start, $prior_end, 0.85, 'zero' === $variant ? 'populated' : $variant );
	}

	return [
		'current'  => $current,
		'previous' => $previous,
	];
};
