<?php
/**
 * Newspack Insights — Conversion Journey (Tab 3) fixture payload (NPPD-1609).
 *
 * Realistic mock data for UI smoke testing without a BigQuery proxy connection,
 * served by Conversion_REST_Controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
 * The implemented Phase-B snapshot sections (4.2/4.3 distributions, 4.4 lag) are
 * 'populated' across every variant (they are all-history, window-independent); the
 * still-deferred 5.1/5.2 cohorts report 'coming_soon' with their preserved keys.
 * The optional `_fixture_state` request param selects a render path:
 *   - 'populated' (default) — every Phase-A metric has data.
 *   - 'empty'    — every windowed collection reports the empty state (succeeded,
 *     no rows); scalars report non-computable zero.
 *   - 'error'    — every BQ-backed metric reports the error state (tab banner
 *     would show if every metric were error; snapshot counts stay 'populated'
 *     as they are local-only, matching the real controller behaviour).
 *
 * Returns a closure so the single required file can build any variant. Never
 * enable fixture mode in production.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

return function ( string $variant = 'populated', bool $compare = false ): array {
	$now    = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable();
	$end    = $now;
	$start  = $now->modify( '-29 days' );
	$window = [
		'start' => $start->format( 'Y-m-d' ),
		'end'   => $end->format( 'Y-m-d' ),
	];

	$prev_end    = $start->modify( '-1 day' );
	$prev_start  = $prev_end->modify( '-29 days' );
	$prev_window = [
		'start' => $prev_start->format( 'Y-m-d' ),
		'end'   => $prev_end->format( 'Y-m-d' ),
	];

	// Phase-B sections — 4.2/4.3/4.4 are 'populated' (implemented; all-history
	// snapshots, so identical across variants); 5.1/5.2 are 'populated' snapshots
	// (mock curves). Each preserves the extra keys React reads unconditionally.
	$deferred = static function () {
		return [
			// 4.2 — time-to-subscribe (multi-series, per-source cumulative).
			'time_to_subscribe_distribution'       => [
				'state'  => 'populated',
				'groups' => [
					[
						'label'  => 'gate',
						'points' => [
							[
								'day'            => 0,
								'cumulative_pct' => 0.34,
							],
							[
								'day'            => 7,
								'cumulative_pct' => 0.62,
							],
							[
								'day'            => 30,
								'cumulative_pct' => 0.89,
							],
							[
								'day'            => 90,
								'cumulative_pct' => 1.0,
							],
						],
					],
					[
						'label'  => 'prompt',
						'points' => [
							[
								'day'            => 0,
								'cumulative_pct' => 0.19,
							],
							[
								'day'            => 14,
								'cumulative_pct' => 0.48,
							],
							[
								'day'            => 60,
								'cumulative_pct' => 0.83,
							],
							[
								'day'            => 150,
								'cumulative_pct' => 1.0,
							],
						],
					],
					[
						'label'  => 'direct',
						'points' => [
							[
								'day'            => 0,
								'cumulative_pct' => 0.22,
							],
							[
								'day'            => 21,
								'cumulative_pct' => 0.55,
							],
							[
								'day'            => 90,
								'cumulative_pct' => 0.9,
							],
							[
								'day'            => 200,
								'cumulative_pct' => 1.0,
							],
						],
					],
				],
			],
			// 4.3 — time-to-donate (multi-series, per-source cumulative).
			'time_to_donate_distribution'          => [
				'state'  => 'populated',
				'groups' => [
					[
						'label'  => 'gate',
						'points' => [
							[
								'day'            => 0,
								'cumulative_pct' => 0.41,
							],
							[
								'day'            => 7,
								'cumulative_pct' => 0.7,
							],
							[
								'day'            => 30,
								'cumulative_pct' => 0.92,
							],
							[
								'day'            => 75,
								'cumulative_pct' => 1.0,
							],
						],
					],
					[
						'label'  => 'prompt',
						'points' => [
							[
								'day'            => 0,
								'cumulative_pct' => 0.28,
							],
							[
								'day'            => 10,
								'cumulative_pct' => 0.57,
							],
							[
								'day'            => 45,
								'cumulative_pct' => 0.86,
							],
							[
								'day'            => 120,
								'cumulative_pct' => 1.0,
							],
						],
					],
					[
						'label'  => 'direct',
						'points' => [
							[
								'day'            => 0,
								'cumulative_pct' => 0.3,
							],
							[
								'day'            => 14,
								'cumulative_pct' => 0.6,
							],
							[
								'day'            => 60,
								'cumulative_pct' => 0.88,
							],
							[
								'day'            => 180,
								'cumulative_pct' => 1.0,
							],
						],
					],
				],
			],
			// 4.4 — subscriber-to-donor lag (visibility-gated single-series CDF).
			'subscriber_to_donor_lag_distribution' => [
				'state'             => 'populated',
				'points'            => [],
				'visibility'        => 'hidden',
				'visibility_reason' => 'insufficient_data',
			],
			// 5.1 — registration-to-conversion cohort (snapshot; autoscaled, no reference line).
			'registration_to_conversion_cohort'    => [
				'state'          => 'populated',
				'cohorts'        => [
					[
						'label'  => '2025-07',
						'points' => [
							[
								'period' => 0,
								'value'  => 0.0,
							],
							[
								'period' => 3,
								'value'  => 0.008,
							],
							[
								'period' => 6,
								'value'  => 0.015,
							],
							[
								'period' => 9,
								'value'  => 0.019,
							],
							[
								'period' => 12,
								'value'  => 0.022,
							],
						],
					],
					[
						'label'  => '2025-10',
						'points' => [
							[
								'period' => 0,
								'value'  => 0.0,
							],
							[
								'period' => 3,
								'value'  => 0.010,
							],
							[
								'period' => 6,
								'value'  => 0.017,
							],
							[
								'period' => 9,
								'value'  => 0.021,
							],
						],
					],
					[
						'label'  => '2026-01',
						'points' => [
							[
								'period' => 0,
								'value'  => 0.0,
							],
							[
								'period' => 3,
								'value'  => 0.012,
							],
							[
								'period' => 6,
								'value'  => 0.018,
							],
						],
					],
					[
						'label'  => '2026-04',
						'points' => [
							[
								'period' => 0,
								'value'  => 0.0,
							],
							[
								'period' => 2,
								'value'  => 0.009,
							],
						],
					],
				],
				'reference_line' => null,
			],
			// 5.2 — subscriber retention cohort (snapshot; 70% reference line).
			'subscriber_retention_cohort'          => [
				'state'          => 'populated',
				'cohorts'        => [
					[
						'label'  => '2025-07',
						'points' => [
							[
								'period' => 0,
								'value'  => 1.0,
							],
							[
								'period' => 3,
								'value'  => 0.88,
							],
							[
								'period' => 6,
								'value'  => 0.79,
							],
							[
								'period' => 9,
								'value'  => 0.73,
							],
							[
								'period' => 12,
								'value'  => 0.69,
							],
						],
					],
					[
						'label'  => '2025-10',
						'points' => [
							[
								'period' => 0,
								'value'  => 1.0,
							],
							[
								'period' => 3,
								'value'  => 0.90,
							],
							[
								'period' => 6,
								'value'  => 0.82,
							],
							[
								'period' => 9,
								'value'  => 0.76,
							],
						],
					],
					[
						'label'  => '2026-01',
						'points' => [
							[
								'period' => 0,
								'value'  => 1.0,
							],
							[
								'period' => 3,
								'value'  => 0.91,
							],
							[
								'period' => 6,
								'value'  => 0.84,
							],
						],
					],
					[
						'label'  => '2026-04',
						'points' => [
							[
								'period' => 0,
								'value'  => 1.0,
							],
							[
								'period' => 2,
								'value'  => 0.94,
							],
						],
					],
				],
				'reference_line' => [
					'value' => 0.70,
					'label' => __( '70% at 12 months', 'newspack-plugin' ),
				],
			],
		];
	};

	// --- Error variant: BQ-backed metrics failed; local-only metrics remain populated. ---
	if ( 'error' === $variant ) {
		$error = [
			'state'         => 'error',
			'error_code'    => 'bigquery_proxy_http_error',
			'error_message' => 'HTTP 500',
		];
		$error_scalar = [
			'state'            => 'error',
			'value'            => 0,
			'computable'       => false,
			'denominator'      => null,
			'placeholder_type' => 'rate',
			'data_missing'     => false,
			'error_code'       => 'bigquery_proxy_http_error',
			'error_message'    => 'HTTP 500',
		];
		$error_rate   = $error_scalar;
		$current      = array_merge(
			[ 'window' => $window ],
			[
				// Section 1.
				'reader_lifecycle_funnel'          => array_merge( $error, [ 'stages' => [] ] ),
				// Section 2. Registration leg is always visible (NPPD-1743) — carries
				// the gated shape live code now stamps via with_leg_visibility().
				'anonymous_to_registered_funnel'   => array_merge(
					$error,
					[
						'stages'            => [],
						'visibility'        => 'visible',
						'visibility_reason' => null,
					]
				),
				// Config-matrix legs (NPPD-1742): configured (visible); the query errored.
				'registered_to_subscriber_funnel'  => array_merge(
					$error,
					[
						'stages'            => [],
						'visibility'        => 'visible',
						'visibility_reason' => null,
					] 
				),
				'registered_to_donor_funnel'       => array_merge(
					$error,
					[
						'stages'            => [],
						'visibility'        => 'visible',
						'visibility_reason' => null,
					] 
				),
				// 2.4 — Subscriber → Donor: local-only; stays populated (visibility-gated).
				'subscriber_to_donor_funnel'       => [
					'state'             => 'populated',
					'stages'            => [],
					'visibility'        => 'hidden',
					'visibility_reason' => 'insufficient_data',
				],
				// Section 3.
				'source_mix_registrations'         => array_merge( $error, [ 'slices' => [] ] ),
				'source_mix_subscribers'           => array_merge( $error, [ 'slices' => [] ] ),
				'source_mix_donors'                => array_merge( $error, [ 'slices' => [] ] ),
				// Section 4 — BQ-backed.
				'time_to_register_distribution'    => array_merge( $error, [ 'points' => [] ] ),
				// Section 6.
				'weekly_conversion_rates'          => array_merge(
					$error,
					[
						'weeks'  => [],
						'series' => [ 'registration_rate', 'subscription_conversion_rate' ],
					]
				),
				// Section 7 — influenced scalars.
				'influenced_registration_rate_7d'  => $error_rate,
				'influenced_subscription_rate_14d' => $error_rate,
				'influenced_donation_rate_14d'     => $error_rate,
				'influenced_newsletter_rate_7d'    => $error_rate,
				// Section 8.4.
				'top_pages_no_conversion'          => array_merge(
					$error,
					[
						'rows'                => [],
						'threshold_pageviews' => 100,
					]
				),
				// Sections 8.1–8.3 — local-only; stay populated.
				'stale_registered_count'           => [
					'state'            => 'populated',
					'value'            => 0,
					'computable'       => true,
					'denominator'      => null,
					'placeholder_type' => 'count',
					'data_missing'     => false,
				],
				'at_risk_subscriber_count'         => [
					'state'            => 'populated',
					'value'            => 0,
					'computable'       => true,
					'denominator'      => null,
					'placeholder_type' => 'count',
					'data_missing'     => false,
				],
				'lapsed_donor_count'               => [
					'state'            => 'populated',
					'value'            => 0,
					'computable'       => true,
					'denominator'      => null,
					'placeholder_type' => 'count',
					'data_missing'     => false,
				],
			],
			$deferred()
		);
		return [
			'tab_error' => true, // NPPD-1745: all hub-backed metrics error → banner fires.
			'current'   => $current,
			'previous'  => null,
		];
	}

	// --- Empty variant: queries succeeded with no rows. ---
	if ( 'empty' === $variant ) {
		$empty_stages  = [
			'state'  => 'empty',
			'stages' => [],
		];
		$empty_slices  = [
			'state'  => 'empty',
			'total'  => 0,
			'slices' => [],
		];
		$empty_points  = [
			'state'  => 'empty',
			'points' => [],
		];
		$scalar_zero   = static function ( string $type ) {
			return [
				'state'            => 'populated',
				'value'            => 'count' === $type ? 0 : 0.0,
				'computable'       => false,
				'denominator'      => 'rate' === $type ? 0 : null,
				'placeholder_type' => $type,
				'data_missing'     => false,
			];
		};
		$current = array_merge(
			[ 'window' => $window ],
			[
				// Section 1.
				'reader_lifecycle_funnel'          => $empty_stages,
				// Section 2. Registration leg always visible (NPPD-1743), no rows →
				// component renders the funnel-shaped no_opportunity treatment.
				'anonymous_to_registered_funnel'   => array_merge(
					$empty_stages,
					[
						'visibility'        => 'visible',
						'visibility_reason' => null,
					]
				),
				// Config-matrix legs (NPPD-1742): configured (visible) but no rows →
				// the component renders the funnel-shaped no_opportunity treatment.
				'registered_to_subscriber_funnel'  => array_merge(
					$empty_stages,
					[
						'visibility'        => 'visible',
						'visibility_reason' => null,
					] 
				),
				'registered_to_donor_funnel'       => array_merge(
					$empty_stages,
					[
						'visibility'        => 'visible',
						'visibility_reason' => null,
					] 
				),
				'subscriber_to_donor_funnel'       => [
					'state'             => 'populated',
					'stages'            => [],
					'visibility'        => 'hidden',
					'visibility_reason' => 'insufficient_data',
				],
				// Section 3.
				'source_mix_registrations'         => $empty_slices,
				'source_mix_subscribers'           => $empty_slices,
				'source_mix_donors'                => $empty_slices,
				// Section 4 — BQ-backed.
				'time_to_register_distribution'    => $empty_points,
				// Section 6.
				'weekly_conversion_rates'          => [
					'state'  => 'empty',
					'weeks'  => [],
					'series' => [ 'registration_rate', 'subscription_conversion_rate' ],
				],
				// Section 7 — influenced scalars (non-computable zeros).
				'influenced_registration_rate_7d'  => $scalar_zero( 'rate' ),
				'influenced_subscription_rate_14d' => $scalar_zero( 'rate' ),
				'influenced_donation_rate_14d'     => $scalar_zero( 'rate' ),
				'influenced_newsletter_rate_7d'    => $scalar_zero( 'rate' ),
				// Section 8 — snapshot.
				'stale_registered_count'           => $scalar_zero( 'count' ),
				'at_risk_subscriber_count'         => $scalar_zero( 'count' ),
				'lapsed_donor_count'               => $scalar_zero( 'count' ),
				// Section 8.4.
				'top_pages_no_conversion'          => [
					'state'               => 'empty',
					'rows'                => [],
					'threshold_pageviews' => 100,
				],
			],
			$deferred()
		);
		return [
			'tab_error' => false,
			'current'   => $current,
			'previous'  => null,
		];
	}

	// --- Populated variant (default). ---
	$scalar = static function ( $value, bool $computable, $denominator, string $type ) {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
			'placeholder_type' => $type,
			'data_missing'     => false,
		];
	};

	$build = static function ( float $f, array $w ) use ( $scalar, $deferred ) {
		// Section 1 — Reader lifecycle funnel (5 stages).
		$s1_top    = (int) round( 48000 * $f );
		$s1_stages = [
			[
				'label'      => __( 'Anonymous reader', 'newspack-plugin' ),
				'count'      => $s1_top,
				'pct_of_top' => 1.0,
			],
			[
				'label'      => __( 'Engaged reader', 'newspack-plugin' ),
				'count'      => (int) round( 12400 * $f ),
				'pct_of_top' => round( 12400 / 48000, 4 ),
			],
			[
				'label'      => __( 'Registered reader', 'newspack-plugin' ),
				'count'      => (int) round( 2800 * $f ),
				'pct_of_top' => round( 2800 / 48000, 4 ),
			],
			[
				'label'      => __( 'Newsletter subscriber', 'newspack-plugin' ),
				'count'      => (int) round( 820 * $f ),
				'pct_of_top' => round( 820 / 48000, 4 ),
			],
			[
				'label'      => __( 'Subscriber or donor', 'newspack-plugin' ),
				'count'      => (int) round( 320 * $f ),
				'pct_of_top' => round( 320 / 48000, 4 ),
			],
		];

		// Section 2.1 — Anon → Registered funnel (3 stages).
		$a2r_top    = (int) round( 12400 * $f );
		$a2r_stages = [
			[
				'label'      => __( 'Anonymous', 'newspack-plugin' ),
				'count'      => $a2r_top,
				'pct_of_top' => 1.0,
			],
			[
				'label'      => __( 'Saw a conversion surface', 'newspack-plugin' ),
				'count'      => (int) round( 4200 * $f ),
				'pct_of_top' => round( 4200 / 12400, 4 ),
			],
			[
				'label'      => __( 'Registered', 'newspack-plugin' ),
				'count'      => (int) round( 2800 * $f ),
				'pct_of_top' => round( 2800 / 12400, 4 ),
			],
		];

		// Section 2.2 — Registered → Subscriber funnel (3 stages).
		$r2s_top    = (int) round( 2800 * $f );
		$r2s_stages = [
			[
				'label'      => __( 'Registered', 'newspack-plugin' ),
				'count'      => $r2s_top,
				'pct_of_top' => 1.0,
			],
			[
				'label'      => __( 'Saw a subscription-intent surface', 'newspack-plugin' ),
				'count'      => (int) round( 980 * $f ),
				'pct_of_top' => round( 980 / 2800, 4 ),
			],
			[
				'label'      => __( 'Became subscriber', 'newspack-plugin' ),
				'count'      => (int) round( 180 * $f ),
				'pct_of_top' => round( 180 / 2800, 4 ),
			],
		];

		// Section 2.3 — Registered → Donor funnel (3 stages).
		$r2d_top    = (int) round( 2800 * $f );
		$r2d_stages = [
			[
				'label'      => __( 'Registered', 'newspack-plugin' ),
				'count'      => $r2d_top,
				'pct_of_top' => 1.0,
			],
			[
				'label'      => __( 'Saw a donation-intent surface', 'newspack-plugin' ),
				'count'      => (int) round( 1100 * $f ),
				'pct_of_top' => round( 1100 / 2800, 4 ),
			],
			[
				'label'      => __( 'Became donor', 'newspack-plugin' ),
				'count'      => (int) round( 320 * $f ),
				'pct_of_top' => round( 320 / 2800, 4 ),
			],
		];

		// Section 2.4 — Subscriber → Donor funnel (2 stages, visibility-gated, visible).
		$s2d_top    = (int) round( 750 * $f );
		$s2d_step2  = (int) round( 110 * $f );
		$s2d_stages = [
			[
				'label'      => __( 'Active subscriber', 'newspack-plugin' ),
				'count'      => $s2d_top,
				'pct_of_top' => 1.0,
			],
			[
				'label'      => __( 'Also donor', 'newspack-plugin' ),
				'count'      => $s2d_step2,
				'pct_of_top' => round( $s2d_step2 / $s2d_top, 4 ),
			],
		];

		// Section 3 — Source mix pies (total + slices).
		$reg_total = (int) round( 2800 * $f );
		$sub_total = (int) round( 180 * $f );
		$don_total = (int) round( 320 * $f );

		$reg_slices = [
			[
				'source' => 'gate',
				'count'  => (int) round( 1200 * $f ),
				'pct'    => round( 1200 / 2800, 4 ),
			],
			[
				'source' => 'prompt',
				'count'  => (int) round( 980 * $f ),
				'pct'    => round( 980 / 2800, 4 ),
			],
			[
				'source' => 'direct',
				'count'  => (int) round( 620 * $f ),
				'pct'    => round( 620 / 2800, 4 ),
			],
		];
		$sub_slices = [
			[
				'source' => 'gate',
				'count'  => (int) round( 75 * $f ),
				'pct'    => round( 75 / 180, 4 ),
			],
			[
				'source' => 'prompt',
				'count'  => (int) round( 65 * $f ),
				'pct'    => round( 65 / 180, 4 ),
			],
			[
				'source' => 'direct',
				'count'  => (int) round( 40 * $f ),
				'pct'    => round( 40 / 180, 4 ),
			],
		];
		$don_slices = [
			[
				'source' => 'gate',
				'count'  => (int) round( 140 * $f ),
				'pct'    => round( 140 / 320, 4 ),
			],
			[
				'source' => 'prompt',
				'count'  => (int) round( 120 * $f ),
				'pct'    => round( 120 / 320, 4 ),
			],
			[
				'source' => 'direct',
				'count'  => (int) round( 60 * $f ),
				'pct'    => round( 60 / 320, 4 ),
			],
		];

		// Section 4.1 — Time-to-register CDF (monotonic, 5 points).
		$ttr_points = [
			[
				'day'            => 0,
				'cumulative_pct' => round( 0.42 * $f, 4 ),
			],
			[
				'day'            => 1,
				'cumulative_pct' => min( 1.0, round( 0.61 * $f, 4 ) ),
			],
			[
				'day'            => 3,
				'cumulative_pct' => min( 1.0, round( 0.74 * $f, 4 ) ),
			],
			[
				'day'            => 7,
				'cumulative_pct' => min( 1.0, round( 0.87 * $f, 4 ) ),
			],
			[
				'day'            => 14,
				'cumulative_pct' => min( 1.0, round( 0.97 * $f, 4 ) ),
			],
		];

		// Section 6 — Weekly conversion rates (4 weeks, 2 series).
		$now_dt = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable();
		$weeks  = [];
		for ( $i = 3; $i >= 0; $i-- ) {
			$week_start = $now_dt->modify( sprintf( '-%d days', 7 * $i + 6 ) );
			$reg_rate   = round( ( 0.0225 + 0.003 * ( 3 - $i ) ) * $f, 4 );
			$sub_rate   = round( ( 0.0065 + 0.001 * ( 3 - $i ) ) * $f, 4 );
			$weeks[]    = [
				'week'                         => $week_start->format( 'Y-m-d' ),
				'registration_conversion_rate' => $reg_rate,
				'subscription_conversion_rate' => $sub_rate,
			];
		}

		// Section 7 — Influenced attribution scalars.
		$influenced_reg  = $scalar( round( 0.041 * $f, 3 ), true, null, 'rate' );
		$influenced_sub  = $scalar( round( 0.028 * $f, 3 ), true, (int) round( 180 * $f ), 'rate' );
		$influenced_don  = $scalar( round( 0.034 * $f, 3 ), true, (int) round( 320 * $f ), 'rate' );
		$influenced_news = $scalar( round( 0.032 * $f, 3 ), true, null, 'rate' );

		// Section 8 — Opportunity counts (snapshot; always current-state).
		$stale_reg  = $scalar( (int) round( 1840 * $f ), true, null, 'count' );
		$at_risk    = $scalar( (int) round( 23 * $f ), true, null, 'count' );
		$lapsed     = $scalar( (int) round( 87 * $f ), true, null, 'count' );

		// Section 8.4 — Top pages that don't convert (windowed table).
		$top_pages_rows = [
			[
				'post_id'         => 101,
				'page_url'        => '/local-news/city-council-budget-debate',
				'page_title'      => 'City Council Budget Debate',
				'pageviews'       => (int) round( 4800 * $f ),
				'unique_readers'  => (int) round( 3200 * $f ),
				'conversion_rate' => 0.0,
			],
			[
				'post_id'         => 102,
				'page_url'        => '/politics/state-legislature-session',
				'page_title'      => 'State Legislature Fall Session',
				'pageviews'       => (int) round( 3600 * $f ),
				'unique_readers'  => (int) round( 2400 * $f ),
				'conversion_rate' => round( 0.0008 * $f, 4 ),
			],
			[
				'post_id'         => 103,
				'page_url'        => '/schools/test-scores-released',
				'page_title'      => 'Annual Test Scores Released',
				'pageviews'       => (int) round( 2900 * $f ),
				'unique_readers'  => (int) round( 1950 * $f ),
				'conversion_rate' => round( 0.0012 * $f, 4 ),
			],
		];

		return array_merge(
			[
				'window'                           => $w,
				// Section 1.
				'reader_lifecycle_funnel'          => [
					'state'  => 'populated',
					'stages' => $s1_stages,
				],
				// Section 2. Registration leg always visible (NPPD-1743).
				'anonymous_to_registered_funnel'   => [
					'state'             => 'populated',
					'stages'            => $a2r_stages,
					'visibility'        => 'visible',
					'visibility_reason' => null,
				],
				// Config-matrix legs (NPPD-1742): visible in the populated fixture.
				'registered_to_subscriber_funnel'  => [
					'state'             => 'populated',
					'stages'            => $r2s_stages,
					'visibility'        => 'visible',
					'visibility_reason' => null,
				],
				'registered_to_donor_funnel'       => [
					'state'             => 'populated',
					'stages'            => $r2d_stages,
					'visibility'        => 'visible',
					'visibility_reason' => null,
				],
				'subscriber_to_donor_funnel'       => [
					'state'             => 'populated',
					'stages'            => $s2d_stages,
					'visibility'        => 'visible',
					'visibility_reason' => null,
				],
				// Section 3.
				'source_mix_registrations'         => [
					'state'  => 'populated',
					'total'  => $reg_total,
					'slices' => $reg_slices,
				],
				'source_mix_subscribers'           => [
					'state'  => 'populated',
					'total'  => $sub_total,
					'slices' => $sub_slices,
				],
				'source_mix_donors'                => [
					'state'  => 'populated',
					'total'  => $don_total,
					'slices' => $don_slices,
				],
				// Section 4.1 (BQ-backed).
				'time_to_register_distribution'    => [
					'state'  => 'populated',
					'points' => $ttr_points,
				],
				// Section 6.
				'weekly_conversion_rates'          => [
					'state'  => 'populated',
					'weeks'  => $weeks,
					'series' => [ 'registration_rate', 'subscription_conversion_rate' ],
				],
				// Section 7.
				'influenced_registration_rate_7d'  => $influenced_reg,
				'influenced_subscription_rate_14d' => $influenced_sub,
				'influenced_donation_rate_14d'     => $influenced_don,
				'influenced_newsletter_rate_7d'    => $influenced_news,
				// Sections 8.1–8.3.
				'stale_registered_count'           => $stale_reg,
				'at_risk_subscriber_count'         => $at_risk,
				'lapsed_donor_count'               => $lapsed,
				// Section 8.4.
				'top_pages_no_conversion'          => [
					'state'               => 'populated',
					'rows'                => $top_pages_rows,
					'threshold_pageviews' => 100,
				],
			],
			$deferred()
		);
	};

	// --- Config-matrix smoke variant (NPPD-1742): registrations-only publisher. ---
	// Both conversion-endpoint legs hidden ('not_configured'); the component omits
	// their cells, leaving only the registration and cross-upsell cells.
	if ( 'conversion_registrations_only' === $variant ) {
		$current = $build( 1.0, $window );
		foreach ( [ 'registered_to_subscriber_funnel', 'registered_to_donor_funnel' ] as $leg ) {
			$current[ $leg ] = [
				'state'             => 'empty',
				'stages'            => [],
				'visibility'        => 'hidden',
				'visibility_reason' => 'not_configured',
			];
		}
		return [
			'tab_error' => false,
			'current'   => $current,
			'previous'  => null,
		];
	}

	return [
		'tab_error' => false,
		'current'   => $build( 1.0, $window ),
		'previous'  => $compare ? $build( 0.9, $prev_window ) : null,
	];
};
