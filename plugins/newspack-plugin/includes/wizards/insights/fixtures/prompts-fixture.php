<?php
/**
 * Newspack Insights — Prompts (Tab 5) fixture payload (NPPD-1607).
 *
 * Realistic mock data for UI smoke testing without a BigQuery proxy connection,
 * served by Prompts_REST_Controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
 * The optional `_fixture_state` request param selects a render path:
 *   - 'populated' (default) — every section has data; funnel mirrors Tab 4's
 *     12,400 / 2,800 / 320 shape so both drop-off deltas are visible.
 *   - 'empty'    — every section reports the empty state (succeeded, no rows).
 *   - 'error'    — every section reports the error state (tab banner shows).
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

	$scalar_types = [
		// Section 1 — Prompt exposure.
		'total_prompt_impressions'                   => 'count',
		'unique_readers_reached'                     => 'count',
		'avg_prompts_per_reader'                     => 'decimal',
		// Section 2 — Prompt engagement.
		'click_through_rate'                         => 'rate',
		'form_submission_rate'                       => 'rate',
		'dismissal_rate'                             => 'rate',
		// Section 3 — Free reader conversion.
		'registration_conversion_direct'             => 'rate',
		'registration_conversion_influenced_7d'      => 'rate',
		'newsletter_signup_conversion_direct'        => 'rate',
		'newsletter_signup_conversion_influenced_7d' => 'rate',
		// Section 4 — Paid reader conversion.
		'donation_conversion_direct'                 => 'rate',
		'donation_conversion_influenced_14d'         => 'rate',
		'subscription_conversion_direct'             => 'rate',
		'subscription_conversion_influenced_14d'     => 'rate',
		// Section 5 — Revenue from prompts.
		'donation_revenue_direct'                    => 'currency',
		'donation_revenue_influenced_14d'            => 'currency',
		'subscription_revenue_direct'                => 'currency',
		'subscription_revenue_influenced_14d'        => 'currency',
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
			'conversion_funnel'        => 'stages',
			'exposures_distribution'   => 'buckets',
			'performance_by_prompt'    => 'rows',
			'performance_by_intent'    => 'rows',
			'performance_by_placement' => 'rows',
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
		$current['conversion_funnel']        = [
			'state'  => 'empty',
			'stages' => [],
		];
		$current['exposures_distribution']   = [
			'state'   => 'empty',
			'buckets' => [],
		];
		$current['performance_by_prompt']    = [
			'state' => 'empty',
			'rows'  => [],
		];
		$current['performance_by_intent']    = [
			'state' => 'empty',
			'rows'  => [],
		];
		$current['performance_by_placement'] = [
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

	$build = static function ( float $f, array $window_arg ) use ( $scalar ) {
		return [
			'window'                                     => $window_arg,
			// Section 1 — Prompt exposure.
			'total_prompt_impressions'                   => $scalar( (int) round( 12400 * $f ), null, 'count' ),
			'unique_readers_reached'                     => $scalar( (int) round( 7350 * $f ), null, 'count' ),
			'avg_prompts_per_reader'                     => $scalar( round( 1.7 * $f, 1 ), null, 'decimal' ),
			// Section 2 — Prompt engagement.
			'click_through_rate'                         => $scalar( round( 0.058 * $f, 3 ), null, 'rate' ),
			'form_submission_rate'                       => $scalar( round( 0.034 * $f, 3 ), null, 'rate' ),
			'dismissal_rate'                             => $scalar( round( 0.21 * $f, 3 ), null, 'rate' ),
			// Section 3 — Free reader conversion.
			'registration_conversion_direct'             => $scalar( round( 0.027 * $f, 3 ), null, 'rate' ),
			'registration_conversion_influenced_7d'      => $scalar( round( 0.041 * $f, 3 ), null, 'rate' ),
			'newsletter_signup_conversion_direct'        => $scalar( round( 0.019 * $f, 3 ), null, 'rate' ),
			'newsletter_signup_conversion_influenced_7d' => $scalar( round( 0.032 * $f, 3 ), null, 'rate' ),
			// Section 4 — Paid reader conversion.
			'donation_conversion_direct'                 => $scalar( round( 0.012 * $f, 3 ), (int) round( 320 * $f ), 'rate' ),
			'donation_conversion_influenced_14d'         => $scalar( round( 0.024 * $f, 3 ), (int) round( 320 * $f ), 'rate' ),
			'subscription_conversion_direct'             => $scalar( round( 0.008 * $f, 3 ), (int) round( 180 * $f ), 'rate' ),
			'subscription_conversion_influenced_14d'     => $scalar( round( 0.016 * $f, 3 ), (int) round( 180 * $f ), 'rate' ),
			// Section 5 — Revenue from prompts.
			'donation_revenue_direct'                    => $scalar( round( 2840.75 * $f, 2 ), (int) round( 42 * $f ), 'currency' ),
			'donation_revenue_influenced_14d'            => $scalar( round( 5210.25 * $f, 2 ), (int) round( 78 * $f ), 'currency' ),
			'subscription_revenue_direct'                => $scalar( round( 1680.50 * $f, 2 ), (int) round( 24 * $f ), 'currency' ),
			'subscription_revenue_influenced_14d'        => $scalar( round( 3120.00 * $f, 2 ), (int) round( 45 * $f ), 'currency' ),
			// Section 6 — How readers convert. Funnel uses a visible drop-off shape
			// so both deltas exceed 20% and render in the error color.
			'conversion_funnel'                          => [
				'state'  => 'populated',
				'stages' => [
					[
						'label'      => __( 'Impression', 'newspack-plugin' ),
						'count'      => (int) round( 12400 * $f ),
						'pct_of_top' => 1.0,
					],
					[
						'label'      => __( 'Engagement', 'newspack-plugin' ),
						'count'      => (int) round( 2800 * $f ),
						'pct_of_top' => 0.2258,
					],
					[
						'label'      => __( 'Conversion', 'newspack-plugin' ),
						'count'      => (int) round( 320 * $f ),
						'pct_of_top' => 0.0258,
					],
				],
			],
			'exposures_distribution'                     => [
				'state'   => 'populated',
				'buckets' => [
					[
						'label' => __( '1 exposure', 'newspack-plugin' ),
						'count' => (int) round( 160 * $f ),
						'pct'   => 0.50,
					],
					[
						'label' => __( '2 exposures', 'newspack-plugin' ),
						'count' => (int) round( 90 * $f ),
						'pct'   => 0.28,
					],
					[
						'label' => __( '3–5 exposures', 'newspack-plugin' ),
						'count' => (int) round( 50 * $f ),
						'pct'   => 0.16,
					],
					[
						'label' => __( '6+ exposures', 'newspack-plugin' ),
						'count' => (int) round( 20 * $f ),
						'pct'   => 0.06,
					],
				],
			],
			// Section 7 — Performance breakdown. The locked 15-key schema for
			// per-prompt rows (Task 3.3): null conversion-rate cells exercise the
			// em-dash path for the wrong-intent columns; real 0.0 cells render
			// as "0.0%".
			'performance_by_prompt'                      => [
				'state' => 'populated',
				'rows'  => [
					[
						'popup_id'                     => 201,
						'prompt_title'                 => 'Support our newsroom',
						'intent'                       => 'donation',
						'placement'                    => 'overlay',
						'impressions'                  => (int) round( 4200 * $f ),
						'unique_viewers'               => (int) round( 2500 * $f ),
						'ctr'                          => 0.063,
						'form_submission_rate'         => 0.041,
						'dismissal_rate'               => 0.18,
						'registrations'                => 0,
						'newsletter_signups'           => 0,
						'donation_conversions'         => (int) round( 65 * $f ),
						'donation_conversion_rate'     => 0.0155,
						'subscription_conversions'     => 0,
						'subscription_conversion_rate' => null,
					],
					[
						'popup_id'                     => 202,
						'prompt_title'                 => 'Monthly giving',
						'intent'                       => 'donation',
						'placement'                    => 'inline',
						'impressions'                  => (int) round( 2100 * $f ),
						'unique_viewers'               => (int) round( 1400 * $f ),
						'ctr'                          => 0.048,
						'form_submission_rate'         => 0.029,
						'dismissal_rate'               => 0.12,
						'registrations'                => 0,
						'newsletter_signups'           => 0,
						'donation_conversions'         => (int) round( 22 * $f ),
						'donation_conversion_rate'     => 0.0105,
						'subscription_conversions'     => 0,
						'subscription_conversion_rate' => null,
					],
					[
						'popup_id'                     => 203,
						'prompt_title'                 => 'Become a member',
						'intent'                       => 'registration',
						'placement'                    => 'overlay',
						'impressions'                  => (int) round( 3100 * $f ),
						'unique_viewers'               => (int) round( 1900 * $f ),
						'ctr'                          => 0.071,
						'form_submission_rate'         => 0.045,
						'dismissal_rate'               => 0.22,
						'registrations'                => (int) round( 84 * $f ),
						'newsletter_signups'           => 0,
						'donation_conversions'         => 0,
						'donation_conversion_rate'     => null,
						'subscription_conversions'     => (int) round( 18 * $f ),
						'subscription_conversion_rate' => 0.0058,
					],
					[
						'popup_id'                     => 204,
						'prompt_title'                 => 'Unlimited access',
						'intent'                       => 'registration',
						'placement'                    => 'above-header',
						'impressions'                  => (int) round( 1850 * $f ),
						'unique_viewers'               => (int) round( 1200 * $f ),
						'ctr'                          => 0.052,
						'form_submission_rate'         => 0.031,
						'dismissal_rate'               => 0.16,
						'registrations'                => (int) round( 41 * $f ),
						'newsletter_signups'           => 0,
						'donation_conversions'         => 0,
						'donation_conversion_rate'     => null,
						'subscription_conversions'     => (int) round( 9 * $f ),
						'subscription_conversion_rate' => 0.0049,
					],
					[
						'popup_id'                     => 205,
						'prompt_title'                 => 'Get the morning brief',
						'intent'                       => 'newsletters_subscription',
						'placement'                    => 'inline',
						'impressions'                  => (int) round( 1150 * $f ),
						'unique_viewers'               => (int) round( 820 * $f ),
						'ctr'                          => 0.039,
						'form_submission_rate'         => 0.024,
						'dismissal_rate'               => 0.09,
						'registrations'                => 0,
						'newsletter_signups'           => (int) round( 27 * $f ),
						'donation_conversions'         => 0,
						'donation_conversion_rate'     => null,
						'subscription_conversions'     => 0,
						'subscription_conversion_rate' => null,
					],
				],
			],
			'performance_by_intent'                      => [
				'state' => 'populated',
				'rows'  => [
					[
						'intent'               => 'donation',
						'intent_label'         => 'Donation',
						'impressions'          => (int) round( 6300 * $f ),
						'unique_viewers'       => (int) round( 3900 * $f ),
						'ctr'                  => 0.058,
						'form_submission_rate' => 0.037,
						'dismissal_rate'       => 0.16,
					],
					[
						'intent'               => 'registration',
						'intent_label'         => 'Registration',
						'impressions'          => (int) round( 4950 * $f ),
						'unique_viewers'       => (int) round( 3100 * $f ),
						'ctr'                  => 0.064,
						'form_submission_rate' => 0.040,
						'dismissal_rate'       => 0.20,
					],
					[
						'intent'               => 'newsletters_subscription',
						'intent_label'         => 'Newsletter signup',
						'impressions'          => (int) round( 1150 * $f ),
						'unique_viewers'       => (int) round( 820 * $f ),
						'ctr'                  => 0.039,
						'form_submission_rate' => 0.024,
						'dismissal_rate'       => 0.09,
					],
				],
			],
			'performance_by_placement'                   => [
				'state' => 'populated',
				'rows'  => [
					[
						'placement'       => 'overlay',
						'placement_label' => 'Overlay',
						'impressions'     => (int) round( 7300 * $f ),
						'unique_viewers'  => (int) round( 4400 * $f ),
						'ctr'             => 0.067,
						'dismissal_rate'  => 0.20,
					],
					[
						'placement'       => 'inline',
						'placement_label' => 'Inline',
						'impressions'     => (int) round( 3250 * $f ),
						'unique_viewers'  => (int) round( 2220 * $f ),
						'ctr'             => 0.044,
						'dismissal_rate'  => 0.11,
					],
					[
						'placement'       => 'above-header',
						'placement_label' => 'Above header',
						'impressions'     => (int) round( 1850 * $f ),
						'unique_viewers'  => (int) round( 1200 * $f ),
						'ctr'             => 0.052,
						'dismissal_rate'  => 0.16,
					],
				],
			],
		];
	};

	return [
		'tab_error' => false,
		'current'   => $build( 1.0, $window ),
		'previous'  => $compare ? $build( 0.9, $prev_window ) : null,
	];
};
