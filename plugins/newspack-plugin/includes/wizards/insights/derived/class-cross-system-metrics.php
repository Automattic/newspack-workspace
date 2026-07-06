<?php
/**
 * Newspack Insights — Cross-system derived metrics (NPPD-1675).
 *
 * The first Insights metrics that join data across two orchestrators. RPM and
 * average impressions per session both divide a GAM-backed Advertising figure
 * (revenue, impressions) by a GA4-backed Audience figure (sessions); neither is
 * computable from GAM alone. This module is the single seam where those joins
 * live, so future cross-system metrics (revenue per article, conversion rate per
 * impression, …) have an established home rather than every orchestrator learning
 * every other orchestrator's API (the N×M coupling of "Option A").
 *
 * Division of labour with the Advertising orchestrator (NPPD-1675, Option B, at
 * the read layer):
 *   - The Advertising volume/revenue payloads are passed IN by the caller
 *     ({@see \Newspack\Insights\Advertising_Metric::read_window()}), read from the
 *     GAM cache. This module never triggers a GAM report — those are async jobs
 *     that must never run in a web request.
 *   - Sessions are fetched HERE, fresh, from the Audience orchestrator
 *     ({@see \Newspack\Insights\Audience_Metric::get_total_sessions()}, 15-minute
 *     BigQuery cache). Fetching at the read layer — rather than baking sessions
 *     into Advertising's day-long GAM cache — keeps the sessions denominator from
 *     lagging a full day behind (the cache-TTL mismatch flagged in the ticket's
 *     pre-flight).
 *
 * Date/timezone parity (pre-flight 2): both orchestrators take the same `Y-m-d`
 * window strings in the site timezone ({@see wp_timezone()}), so the window
 * boundaries align. The underlying data timezones differ (GAM reports in the ad
 * network's timezone; GA4 sessions in the property's), a sub-window-boundary skew
 * on already day-lagged figures — documented, normalized silently for v1.
 *
 * Payload shapes mirror the orchestrators:
 *   scalar  : { value, computable, type: currency|decimal, numerator, denominator }
 *   overlay : { value: null, computable: false, overlay: { type: data_unavailable } }
 * The overlay (rather than a `computable: false` scalar) is deliberate: a non-rate
 * scalar with `computable: false` renders a literal `0` in the UI, which would
 * misread "we couldn't get sessions" as "your RPM is $0". The data-unavailable
 * overlay is the same treatment the Viewability scorecard uses.
 *
 * @package Newspack
 */

namespace Newspack\Insights\Derived;

use Newspack\Insights\Audience_Metric;

defined( 'ABSPATH' ) || exit;

/**
 * Cross-system derived metrics (Advertising × Audience).
 */
final class Cross_System_Metrics {

	/**
	 * RPM — revenue per thousand sessions: `( revenue / sessions ) * 1000`.
	 * Cross-publisher-comparable in a way total revenue isn't.
	 *
	 * @param array    $revenue_payload Advertising `total_revenue` MetricCard payload
	 *                                  ({ value, computable, … }) for the window.
	 * @param int|null $sessions        Total sessions for the same window, or null
	 *                                  when the Audience orchestrator is unavailable.
	 * @return array MetricCard payload (currency), or the data-unavailable overlay.
	 */
	public static function rpm( array $revenue_payload, ?int $sessions ): array {
		$revenue = self::source_value( $revenue_payload );
		if ( null === $revenue || null === $sessions || $sessions <= 0 ) {
			return self::unavailable();
		}
		return [
			'value'       => ( $revenue / $sessions ) * 1000,
			'computable'  => true,
			'type'        => 'currency',
			'numerator'   => $revenue,
			'denominator' => $sessions,
		];
	}

	/**
	 * Average impressions per session — `impressions / sessions`. An inventory
	 * saturation signal: how many ads the typical visit sees. Displayed as a whole
	 * number (`count` format) — a single decimal reads as false precision on a
	 * ~3-per-session figure — while the raw ratio is preserved so period deltas
	 * stay exact.
	 *
	 * @param array    $impressions_payload Advertising `total_impressions` MetricCard
	 *                                      payload ({ value, computable, … }).
	 * @param int|null $sessions            Total sessions for the same window, or null.
	 * @return array MetricCard payload (count), or the data-unavailable overlay.
	 */
	public static function avg_impressions_per_session( array $impressions_payload, ?int $sessions ): array {
		$impressions = self::source_value( $impressions_payload );
		if ( null === $impressions || null === $sessions || $sessions <= 0 ) {
			return self::unavailable();
		}
		return [
			'value'       => $impressions / $sessions,
			'computable'  => true,
			'type'        => 'count',
			'numerator'   => (int) $impressions,
			'denominator' => $sessions,
		];
	}

	/**
	 * Total sessions for a window from the Audience (GA4/BigQuery) orchestrator.
	 * The cross-orchestrator bridge — the one place the Advertising read path
	 * reaches into Audience. Returns null (not 0) when sessions can't be
	 * established, so callers render "data unavailable" rather than a misleading
	 * zero-denominator result.
	 *
	 * @param string $start_date Inclusive window start, YYYY-MM-DD (site timezone).
	 * @param string $end_date   Inclusive window end, YYYY-MM-DD (site timezone).
	 * @return int|null Total sessions, or null when unavailable.
	 */
	public static function sessions_for_window( string $start_date, string $end_date ): ?int {
		if ( ! class_exists( Audience_Metric::class ) ) {
			return null;
		}
		return Audience_Metric::get_total_sessions( $start_date, $end_date );
	}

	/**
	 * Extract the numeric value from a source MetricCard payload, but only when it
	 * carries a real number — a computable payload with no error/overlay. Guards
	 * the joins against dividing an errored or unavailable source into a plausible-
	 * looking but meaningless derived value.
	 *
	 * @param array $payload Source MetricCard payload.
	 * @return float|null The value, or null when the source can't contribute one.
	 */
	private static function source_value( array $payload ): ?float {
		if ( empty( $payload['computable'] ) || isset( $payload['error'] ) || isset( $payload['overlay'] ) ) {
			return null;
		}
		$value = $payload['value'] ?? null;
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Data-unavailable payload: a derived metric that couldn't be joined because a
	 * source was missing (Audience uncached/outage, or an errored Advertising
	 * volume metric). Rendered as the "data unavailable" overlay, not a zero.
	 *
	 * @return array
	 */
	private static function unavailable(): array {
		return [
			'value'      => null,
			'computable' => false,
			'overlay'    => [ 'type' => 'data_unavailable' ],
		];
	}
}
