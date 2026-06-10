<?php
/**
 * Newspack Insights — Gates (Tab 4) fixture payload (NPPD-1604).
 *
 * Realistic mock data for UI smoke testing without a BigQuery proxy connection,
 * served by Gates_REST_Controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on. The
 * optional `_fixture_state` request param selects a render path:
 *   - 'populated' (default) — every section has data; the funnel uses Richland
 *     Source's 26,171 / 2,028 / 431 shape so both drop-off deltas render red.
 *   - 'empty'    — every section reports the empty state (succeeded, no rows).
 *   - 'error'    — every section reports the error state (tab banner shows).
 *
 * Returns a closure so the single required file can build any variant. Never
 * enable fixture mode in production.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

return function ( string $variant = 'populated' ): array {
	$now    = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable();
	$end    = $now;
	$start  = $now->modify( '-29 days' );
	$window = [
		'start' => $start->format( 'Y-m-d' ),
		'end'   => $end->format( 'Y-m-d' ),
	];

	$scalar_types = [
		'total_gate_impressions'             => 'count',
		'unique_readers_reached'             => 'count',
		'avg_exposures_per_reader'           => 'decimal',
		'sessions_with_gate'                 => 'rate',
		'regwall_conversion_direct'          => 'rate',
		'regwall_conversion_influenced_7d'   => 'rate',
		'paywall_conversion_direct'          => 'rate',
		'paywall_conversion_influenced_14d'  => 'rate',
		'total_paywall_revenue_direct'       => 'currency',
		'avg_revenue_per_paywall_conversion' => 'currency',
	];

	$zero_value = static function ( string $type ) {
		return 'count' === $type ? 0 : 0.0;
	};

	// --- Error variant: every section failed to load. ---
	if ( 'error' === $variant ) {
		$current = [ 'window' => $window ];
		foreach ( $scalar_types as $key => $type ) {
			$current[ $key ] = [
				'state'            => 'error',
				'value'            => $zero_value( $type ),
				'computable'       => false,
				'denominator'      => null,
				'placeholder_type' => $type,
				'error_code'       => 'bigquery_proxy_http_error',
				'error_message'    => 'HTTP 500',
			];
		}
		$collections = [
			'conversion_funnel'      => 'stages',
			'exposures_distribution' => 'buckets',
			'performance_by_gate'    => 'rows',
		];
		foreach ( $collections as $key => $rows_key ) {
			$current[ $key ] = [
				'state'         => 'error',
				'error_code'    => 'bigquery_proxy_http_error',
				'error_message' => 'HTTP 500',
				$rows_key       => [],
			];
		}
		return [
			'tab_error' => true,
			'current'   => $current,
			'previous'  => null,
		];
	}

	// --- Empty variant: queries succeeded with no rows. ---
	if ( 'empty' === $variant ) {
		$current = [ 'window' => $window ];
		foreach ( $scalar_types as $key => $type ) {
			$current[ $key ] = [
				'state'            => 'populated',
				'value'            => $zero_value( $type ),
				'computable'       => false,
				'denominator'      => 'rate' === $type ? 0 : null,
				'placeholder_type' => $type,
			];
		}
		$current['conversion_funnel']      = [
			'state'  => 'empty',
			'stages' => [],
		];
		$current['exposures_distribution'] = [
			'state'   => 'empty',
			'buckets' => [],
		];
		$current['performance_by_gate']    = [
			'state' => 'empty',
			'rows'  => [],
		];
		return [
			'tab_error' => false,
			'current'   => $current,
			'previous'  => null,
		];
	}

	// --- Populated variant (default). ---
	$scalar = static function ( $value, $denominator, string $type ) {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => true,
			'denominator'      => $denominator,
			'placeholder_type' => $type,
		];
	};

	$build = static function ( float $f ) use ( $scalar, $window ) {
		return [
			'window'                             => $window,
			// Section 1 — Gate exposure.
			'total_gate_impressions'             => $scalar( (int) round( 26171 * $f ), null, 'count' ),
			'unique_readers_reached'             => $scalar( (int) round( 14820 * $f ), null, 'count' ),
			'avg_exposures_per_reader'           => $scalar( round( 1.8 * $f, 1 ), null, 'decimal' ),
			'sessions_with_gate'                 => $scalar( round( 0.34 * $f, 3 ), null, 'rate' ),
			// Section 2 — Free reader conversion.
			'regwall_conversion_direct'          => $scalar( round( 0.071 * $f, 3 ), null, 'rate' ),
			'regwall_conversion_influenced_7d'   => $scalar( round( 0.123 * $f, 3 ), null, 'rate' ),
			// Section 3 — Paid reader conversion.
			'paywall_conversion_direct'          => $scalar( round( 0.021 * $f, 3 ), null, 'rate' ),
			'paywall_conversion_influenced_14d'  => $scalar( round( 0.038 * $f, 3 ), null, 'rate' ),
			'total_paywall_revenue_direct'       => $scalar( round( 4180.5 * $f, 2 ), (int) round( 47 * $f ), 'currency' ),
			'avg_revenue_per_paywall_conversion' => $scalar( round( 88.95 * $f, 2 ), (int) round( 47 * $f ), 'currency' ),
			// Section 4 — How readers convert. Funnel uses Richland Source's shape
			// so both drop-off deltas exceed 20% and render in the error color.
			'conversion_funnel'                  => [
				'state'  => 'populated',
				'stages' => [
					[
						'label'      => __( 'Impression', 'newspack-plugin' ),
						'count'      => (int) round( 26171 * $f ),
						'pct_of_top' => 1.0,
					],
					[
						'label'      => __( 'Engagement', 'newspack-plugin' ),
						'count'      => (int) round( 2028 * $f ),
						'pct_of_top' => 0.0775,
					],
					[
						'label'      => __( 'Conversion', 'newspack-plugin' ),
						'count'      => (int) round( 431 * $f ),
						'pct_of_top' => 0.0165,
					],
				],
			],
			'exposures_distribution'             => [
				'state'   => 'populated',
				'buckets' => [
					[
						'label' => __( '1 exposure', 'newspack-plugin' ),
						'count' => (int) round( 210 * $f ),
						'pct'   => 0.49,
					],
					[
						'label' => __( '2 exposures', 'newspack-plugin' ),
						'count' => (int) round( 120 * $f ),
						'pct'   => 0.28,
					],
					[
						'label' => __( '3–5 exposures', 'newspack-plugin' ),
						'count' => (int) round( 70 * $f ),
						'pct'   => 0.16,
					],
					[
						'label' => __( '6+ exposures', 'newspack-plugin' ),
						'count' => (int) round( 31 * $f ),
						'pct'   => 0.07,
					],
				],
			],
			// Section 5 — Performance by gate. Null rate cells exercise the em-dash path.
			'performance_by_gate'                => [
				'state' => 'populated',
				'rows'  => [
					[
						'gate_post_id'            => 101,
						'gate_name'               => 'Newsletter regwall',
						'impressions'             => (int) round( 14000 * $f ),
						'unique_viewers'          => (int) round( 8200 * $f ),
						'registrations'           => (int) round( 540 * $f ),
						'regwall_conversion_rate' => 0.066,
						'paywall_attempts'        => 0,
						'paywall_attempt_rate'    => null,
					],
					[
						'gate_post_id'            => 102,
						'gate_name'               => 'Subscriber paywall',
						'impressions'             => (int) round( 9800 * $f ),
						'unique_viewers'          => (int) round( 5100 * $f ),
						'registrations'           => 0,
						'regwall_conversion_rate' => null,
						'paywall_attempts'        => (int) round( 320 * $f ),
						'paywall_attempt_rate'    => 0.065,
					],
					[
						'gate_post_id'            => 103,
						'gate_name'               => 'Article meter',
						'impressions'             => (int) round( 6300 * $f ),
						'unique_viewers'          => (int) round( 3900 * $f ),
						'registrations'           => (int) round( 180 * $f ),
						'regwall_conversion_rate' => 0.046,
						'paywall_attempts'        => (int) round( 95 * $f ),
						'paywall_attempt_rate'    => 0.024,
					],
				],
			],
		];
	};

	return [
		'tab_error' => false,
		'current'   => $build( 1.0 ),
		'previous'  => $build( 0.9 ),
	];
};
