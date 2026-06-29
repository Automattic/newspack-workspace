<?php
/**
 * Newspack Insights — Engagement Metric orchestrator (Tab 2, NPPD-1648).
 *
 * Returns MetricCard-ready payloads for all Engagement tab metrics via the
 * BigQuery proxy client (NPPD-1729). All 10 visible metrics and the 3
 * previously-hidden metrics are now dispatched through BQ. The GA4 path has
 * been removed (NPPD-1729 Task B5). `article_freshness_vs_engagement` remains
 * hidden in v1.
 *
 * The three box-plot distributions from the original Tab 2 design are cut
 * from the spec entirely and intentionally absent here.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\BigQuery_Proxy_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Engagement (Tab 2) metric orchestrator.
 */
final class Engagement_Metric {

	const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	const CACHE_KEY_PREFIX = 'newspack_insights_engagement_v1:';

	const READER_THRESHOLD = 50;

	/**
	 * Minimum newsletter sessions in the window before the "Engagement by traffic
	 * source" card renders a comparison. Below this the newsletter average is too
	 * noisy — a few unusually engaged or bouncy readers can flip the headline — so
	 * the card shows its "needs data" state instead. Sessions, not readers, so this
	 * is a distinct unit from READER_THRESHOLD.
	 */
	const NEWSLETTER_SESSION_FLOOR = 100;

	/**
	 * Channel names (case-insensitive) that count as newsletter traffic. Everything
	 * else is "other". The BQ query groups by channel (e.g. "Email") rather than the
	 * raw sessionMedium dimension, so we match on channel label.
	 */
	const NEWSLETTER_CHANNELS = [ 'email', 'newsletter' ];

	/**
	 * Resolve the GA4 property ID.
	 *
	 * Reads from `newspack_ga4_info` (set by the Newspack GA4 integration) rather
	 * than the old Site Kit option. Falls back to '' when no property is connected.
	 *
	 * @return string Numeric property ID, or '' when none is connected.
	 */
	private static function resolve_property_id(): string {
		$info = get_option( 'newspack_ga4_info', [] );
		return is_array( $info ) && ! empty( $info['property_id'] ) ? (string) $info['property_id'] : '';
	}

	/**
	 * Build the per-window transient cache key. Uses a 'bq' backend suffix so
	 * existing GA4-keyed transients never collide after the path switch. Includes
	 * the GA4 property ID so that a reconnect to a different property never serves
	 * the previous property's cached payload within the TTL.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string
	 */
	private static function window_cache_key( string $start_date, string $end_date ): string {
		return self::CACHE_KEY_PREFIX . md5(
			self::resolve_property_id() . '|' . $start_date . '|' . $end_date . '|bq'
		);
	}

	/**
	 * Tab-level connection check. BQ path requires no OAuth gate; always returns
	 * null (no error). Retained for API compatibility with the REST controller.
	 *
	 * @return array|null
	 */
	public static function connection_error(): ?array {
		return null;
	}

	/**
	 * Full tab payload for a window (+ optional prior-period under `compare`).
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param bool   $compare    Attach prior-period payload.
	 * @return array
	 */
	public static function get_all( string $start_date, string $end_date, bool $compare = false ): array {
		$error = self::connection_error();
		if ( null !== $error ) {
			return $error;
		}

		$payload = self::compute_window_cached( $start_date, $end_date );

		if ( $compare ) {
			[ $prior_start, $prior_end ] = self::prior_period( $start_date, $end_date );
			$payload['compare']          = self::compute_window_cached( $prior_start, $prior_end );
		}

		return $payload;
	}

	/**
	 * Realistic fixture payload for UI smoke testing without a GA4 connection.
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 *
	 * @return array
	 */
	public static function get_fixture(): array {
		return require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/engagement-fixture.php';
	}

	/**
	 * Immediately-preceding window of equal length.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return string[]
	 */
	private static function prior_period( string $start_date, string $end_date ): array {
		$start       = new \DateTimeImmutable( $start_date );
		$end         = new \DateTimeImmutable( $end_date );
		$days        = (int) $start->diff( $end )->format( '%a' ) + 1;
		$prior_end   = $start->modify( '-1 day' );
		$prior_start = $prior_end->modify( '-' . ( $days - 1 ) . ' days' );
		return [ $prior_start->format( 'Y-m-d' ), $prior_end->format( 'Y-m-d' ) ];
	}

	/**
	 * Cached single-window computation.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_window_cached( string $start_date, string $end_date ): array {
		$cache_key = self::window_cache_key( $start_date, $end_date );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$payload = self::compute_via_bq( $start_date, $end_date );
		set_transient( $cache_key, $payload, self::CACHE_TTL );
		return $payload;
	}

	/**
	 * BQ path — every metric for the window.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_bq( string $start_date, string $end_date ): array {
		$proxy = new BigQuery_Proxy_Client();
		$start = new \DateTimeImmutable( $start_date );
		$end   = new \DateTimeImmutable( $end_date );

		return [
			'window'                                => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			// Overall engagement quality (wired in B4).
			'avg_pages_per_session'                 => self::avg_pages_per_session_via_bq( $proxy, $start, $end ),
			'avg_engaged_session_duration'          => self::avg_engaged_session_duration_via_bq( $proxy, $start, $end ),
			'bounce_rate'                           => self::bounce_rate_via_bq( $proxy, $start, $end ),
			'article_completion_rate'               => self::article_completion_rate_via_bq( $proxy, $start, $end ),
			// Content engagement.
			'most_read_articles'                    => self::most_read_articles_via_bq( $proxy, $start, $end ),
			'articles_by_completion_rate'           => self::articles_by_completion_rate_via_bq( $proxy, $start, $end ),
			'top_authors_by_avg_engagement_time'    => self::top_authors_by_avg_engagement_time_via_bq( $proxy, $start, $end ),
			// Reader segments.
			'engagement_by_device_type'             => self::engagement_by_device_type_via_bq( $proxy, $start, $end ),
			'engagement_by_traffic_source'          => self::engagement_by_traffic_source_via_bq( $proxy, $start, $end ),
			'engagement_by_returning_vs_new'        => self::engagement_by_returning_vs_new_via_bq( $proxy, $start, $end ),
			// Previously BQ-only hidden; now enabled.
			'top_categories_by_engagement'          => self::top_categories_by_engagement_via_bq( $proxy, $start, $end ),
			'mobile_vs_desktop_content_preferences' => self::mobile_vs_desktop_content_preferences_via_bq( $proxy, $start, $end ),
			'top_authors_by_repeat_reader_rate'     => self::top_authors_by_repeat_reader_rate_via_bq( $proxy, $start, $end ),
			// Still hidden in v1.
			'article_freshness_vs_engagement'       => self::hidden_in_v1_payload(),
		];
	}

	/*
	===================================================================
	 * BigQuery proxy shapers (NPPD-1729 Task B4)
	 * ===================================================================
	 */

	/**
	 * Dispatch a catalog query and shape its first-row column into a scalar payload.
	 *
	 * @param BigQuery_Proxy_Client $proxy      Proxy client.
	 * @param string                $query_name Catalog query name.
	 * @param string                $column     Column to read from the first row.
	 * @param string                $type       'count' | 'decimal' | 'duration' | 'rate'.
	 * @param \DateTimeInterface    $start      Window start.
	 * @param \DateTimeInterface    $end        Window end.
	 * @return array
	 */
	private static function proxy_scalar( BigQuery_Proxy_Client $proxy, string $query_name, string $column, string $type, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
				'error'      => $rows->get_error_message(),
			];
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $column, $rows[0] ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
			];
		}
		$value = $rows[0][ $column ];
		if ( null === $value || ! is_numeric( $value ) ) {
			return [
				'value'      => 0,
				'computable' => false,
				'type'       => $type,
			];
		}
		return [
			'value'      => 'count' === $type ? (int) $value : (float) $value,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Dispatch a catalog query and shape all rows into a rows payload. The SQL
	 * column aliases are the row keys (chosen to match the display contract).
	 *
	 * @param BigQuery_Proxy_Client $proxy      Proxy client.
	 * @param string                $query_name Catalog query name.
	 * @param string                $type       'breakdown' | 'table' | 'timeseries'.
	 * @param \DateTimeInterface    $start      Window start.
	 * @param \DateTimeInterface    $end        Window end.
	 * @return array
	 */
	private static function proxy_rows( BigQuery_Proxy_Client $proxy, string $query_name, string $type, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => $type,
				'error'      => $rows->get_error_message(),
			];
		}
		if ( ! is_array( $rows ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => $type,
			];
		}
		return [
			'rows'       => array_values( $rows ),
			'computable' => true,
			'type'       => $type,
		];
	}

	/*
	===================================================================
	 * BigQuery quality scalar methods (NPPD-1729 Task B4)
	 * ===================================================================
	 */

	/**
	 * Avg Pages per Session via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function avg_pages_per_session_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_scalar( $proxy, 'engagement_avg_pages_per_session', 'avg_pages_per_session', 'decimal', $start, $end );
	}

	/**
	 * Avg Engaged Session Duration via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function avg_engaged_session_duration_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_scalar( $proxy, 'engagement_avg_engaged_session_duration', 'avg_engaged_session_duration_sec', 'duration', $start, $end );
	}

	/**
	 * Bounce Rate via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function bounce_rate_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( 'engagement_bounce_rate', $start, $end );
		if ( is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( 'bounce_rate', $rows[0] ) || ! is_numeric( $rows[0]['bounce_rate'] ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => 'rate' ];
		}
		return [ 'value' => (float) $rows[0]['bounce_rate'], 'computable' => true, 'type' => 'rate' ];
	}

	/**
	 * Article Completion Rate via BigQuery.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function article_completion_rate_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( 'engagement_article_completion_rate', $start, $end );
		if ( is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( 'scroll_to_90_rate', $rows[0] ) || ! is_numeric( $rows[0]['scroll_to_90_rate'] ) ) {
			return [ 'value' => 0, 'computable' => false, 'type' => 'rate' ];
		}
		return [ 'value' => (float) $rows[0]['scroll_to_90_rate'], 'computable' => true, 'type' => 'rate' ];
	}

	/*
	===================================================================
	 * BigQuery table methods (NPPD-1729 Task B5)
	 * ===================================================================
	 */

	/**
	 * Most Read Articles via BigQuery.
	 *
	 * The BQ SQL handles HAVING (reader threshold), ranking, LIMIT 50, and column
	 * selection (page_title, unique_readers, avg_engagement_seconds). A plain
	 * proxy_rows passthrough is correct — the PHP shaping done in the GA4 path
	 * (two-report join, engagement_score computation, sort, strip) is already
	 * performed in the BQ query.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function most_read_articles_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_most_read_articles', 'table', $start, $end );
	}

	/**
	 * Articles by Completion Rate via BigQuery.
	 *
	 * The BQ SQL handles HAVING (reader threshold), completion_rate computation,
	 * ORDER BY (rate desc, readers desc), and LIMIT 50. A plain proxy_rows
	 * passthrough is correct.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function articles_by_completion_rate_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_articles_by_completion_rate', 'table', $start, $end );
	}

	/**
	 * Top Authors by Avg Engagement Time via BigQuery.
	 *
	 * The BQ SQL computes avg_engagement_seconds per author, orders by it
	 * descending, and applies a LIMIT. A plain proxy_rows passthrough is correct —
	 * the GA4 path's re-sort by avg_engagement_seconds is already done in SQL.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_authors_by_avg_engagement_time_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_top_authors_by_avg_engagement_time', 'table', $start, $end );
	}

	/**
	 * Engagement by Device Type via BigQuery.
	 *
	 * The BQ SQL computes avg_engagement_seconds = total_engagement / sessions per
	 * device, so the GA4 path's PHP division is already done in SQL. A plain
	 * proxy_rows passthrough is correct (columns: device, sessions,
	 * avg_pages_per_session, avg_engagement_seconds).
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function engagement_by_device_type_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_by_device_type', 'table', $start, $end );
	}

	/**
	 * Engagement by Traffic Source via BigQuery.
	 *
	 * Non-trivial shaping: the BQ query returns one row per channel (e.g. "Email",
	 * "Organic Search", "Direct") with pre-computed avg_engagement_seconds. These
	 * must be bucketed into the same "newsletter" / "other" cohorts the GA4 path
	 * produced, and the same NEWSLETTER_SESSION_FLOOR needs_data guard must be
	 * applied.
	 *
	 * The GA4 path received raw per-medium totals (userEngagementDuration,
	 * sessions) and computed the weighted average in `bucket_by_traffic_source()`.
	 * Here we recover the total engagement per channel by multiplying
	 * avg_engagement_seconds × sessions, then sum within each cohort and divide
	 * again for the cohort average.
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function engagement_by_traffic_source_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		$rows = $proxy->query( 'engagement_by_traffic_source', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => 'table',
				'error'      => $rows->get_error_message(),
			];
		}
		if ( ! is_array( $rows ) ) {
			return [
				'rows'       => [],
				'computable' => false,
				'type'       => 'table',
			];
		}
		return self::bucket_bq_by_traffic_source( $rows );
	}

	/**
	 * Bucket BQ per-channel rows into the newsletter/other cohorts.
	 *
	 * Each input row has the shape:
	 *   { channel: string, sessions: int, avg_pages_per_session: float,
	 *     avg_engagement_seconds: float }
	 *
	 * To produce a cohort-level weighted average: recover total engagement
	 * (sessions × avg_engagement_seconds), sum within the cohort, then divide by
	 * cohort total sessions. This mirrors `bucket_by_traffic_source()` which
	 * operated on raw GA4 totals (userEngagementDuration, sessions).
	 *
	 * @param array $rows BQ rows (channel, sessions, avg_pages_per_session, avg_engagement_seconds).
	 * @return array
	 */
	private static function bucket_bq_by_traffic_source( array $rows ): array {
		$buckets = [
			'newsletter' => [
				'sessions'    => 0,
				'total_eng'   => 0.0,
			],
			'other'      => [
				'sessions'    => 0,
				'total_eng'   => 0.0,
			],
		];
		foreach ( $rows as $row ) {
			$channel  = strtolower( (string) ( $row['channel'] ?? '' ) );
			$sessions = (int) ( $row['sessions'] ?? 0 );
			$avg_eng  = (float) ( $row['avg_engagement_seconds'] ?? 0 );
			$key      = in_array( $channel, self::NEWSLETTER_CHANNELS, true ) ? 'newsletter' : 'other';
			$buckets[ $key ]['sessions']  += $sessions;
			$buckets[ $key ]['total_eng'] += $sessions * $avg_eng;
		}
		$out = [];
		foreach ( $buckets as $key => $b ) {
			$out[] = [
				'segment'                => $key,
				'sessions'               => $b['sessions'],
				'avg_engagement_seconds' => $b['sessions'] > 0 ? $b['total_eng'] / $b['sessions'] : 0,
			];
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => 'table',
			'needs_data' => $buckets['newsletter']['sessions'] < self::NEWSLETTER_SESSION_FLOOR,
		];
	}

	/**
	 * Engagement by Returning vs New via BigQuery.
	 *
	 * The BQ SQL computes avg_engagement_seconds = total_engagement / sessions per
	 * reader_type, so the GA4 path's PHP division is already done in SQL. A plain
	 * proxy_rows passthrough is correct (columns: reader_type, sessions,
	 * avg_pages_per_session, avg_engagement_seconds).
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function engagement_by_returning_vs_new_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_by_returning_vs_new', 'table', $start, $end );
	}

	/**
	 * Top Categories by Engagement via BigQuery (previously hidden in v1, now enabled).
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_categories_by_engagement_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_top_categories_by_engagement', 'table', $start, $end );
	}

	/**
	 * Mobile vs Desktop Content Preferences via BigQuery (previously hidden in v1, now enabled).
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function mobile_vs_desktop_content_preferences_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_mobile_vs_desktop_content_preferences', 'table', $start, $end );
	}

	/**
	 * Top Authors by Repeat Reader Rate via BigQuery (previously hidden in v1, now enabled).
	 *
	 * @param BigQuery_Proxy_Client $proxy Proxy client.
	 * @param \DateTimeInterface    $start Window start.
	 * @param \DateTimeInterface    $end   Window end.
	 * @return array
	 */
	public static function top_authors_by_repeat_reader_rate_via_bq( BigQuery_Proxy_Client $proxy, \DateTimeInterface $start, \DateTimeInterface $end ): array {
		return self::proxy_rows( $proxy, 'engagement_top_authors_by_repeat_reader_rate', 'table', $start, $end );
	}

	/*
	===================================================================
	 * Shared helpers
	 * ===================================================================
	 */

	/**
	 * BQ-only metric payload: hidden in v1.
	 *
	 * @return array
	 */
	private static function hidden_in_v1_payload(): array {
		return [
			'value'        => null,
			'computable'   => false,
			'hidden_in_v1' => true,
		];
	}
}
