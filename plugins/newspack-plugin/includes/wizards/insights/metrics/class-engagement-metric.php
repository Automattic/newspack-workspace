<?php
/**
 * Newspack Insights — Engagement Metric orchestrator (Tab 2, NPPD-1648).
 *
 * Composes GA4 Data API `runReport` bodies (translated from
 * `~/Sites/insights-docs/formulas/tab-2-engagement.md`) and returns
 * MetricCard-ready payloads. Dispatches between GA4 (v1, default) and the
 * BigQuery proxy (v1.1, NPPD-1630 — stubbed here) per the
 * `NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4` constant (default true).
 *
 * The three box-plot distributions from the original Tab 2 design are cut
 * from the spec entirely and intentionally absent here.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\GA4\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Engagement (Tab 2) metric orchestrator.
 */
final class Engagement_Metric {

	const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
	const CACHE_KEY_PREFIX = 'newspack_insights_engagement_v1:';

	const READER_THRESHOLD = 50;

	/**
	 * Whether this tab uses the GA4 path. Default true.
	 *
	 * @return bool
	 */
	private static function use_ga4(): bool {
		return ! defined( 'NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4' ) || NEWSPACK_INSIGHTS_ENGAGEMENT_USE_GA4;
	}

	/**
	 * Resolve the GA4 property ID from Site Kit's stored settings.
	 *
	 * @return string
	 */
	private static function resolve_property_id(): string {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		return is_array( $settings ) && ! empty( $settings['propertyID'] ) ? (string) $settings['propertyID'] : '';
	}

	/**
	 * Build the per-window transient cache key. Includes the GA4 property ID so
	 * that a reconnect to a different property never serves the previous
	 * property's cached payload within the TTL.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param bool   $use_ga4    Whether the GA4 backend is active.
	 * @return string
	 */
	private static function window_cache_key( string $start_date, string $end_date, bool $use_ga4 ): string {
		return self::CACHE_KEY_PREFIX . md5(
			self::resolve_property_id() . '|' . $start_date . '|' . $end_date . '|' . ( $use_ga4 ? 'ga4' : 'bq' )
		);
	}

	/**
	 * Tab-level connection check (GA4 path only).
	 *
	 * @return array|null
	 */
	public static function connection_error(): ?array {
		if ( ! self::use_ga4() ) {
			return null;
		}
		$connected = class_exists( '\Newspack\Google_OAuth' )
			&& \Newspack\Google_OAuth::is_oauth_configured()
			&& '' !== self::resolve_property_id();
		if ( $connected ) {
			return null;
		}
		return [
			'tab_error'   => 'oauth_not_connected',
			'banner_text' => __( 'Connect Google Analytics in Newspack → Connections to see this tab.', 'newspack-plugin' ),
		];
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
		$use_ga4   = self::use_ga4();
		$cache_key = self::window_cache_key( $start_date, $end_date, $use_ga4 );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$payload = $use_ga4
			? self::compute_via_ga4( $start_date, $end_date )
			: self::compute_via_bq( $start_date, $end_date );
		set_transient( $cache_key, $payload, self::CACHE_TTL );
		return $payload;
	}

	/**
	 * GA4 path — every metric for the window.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_ga4( string $start_date, string $end_date ): array {
		$pid = self::resolve_property_id();
		return [
			'window'                                => [
				'start' => $start_date,
				'end'   => $end_date,
			],
			// Overall engagement quality.
			'avg_pages_per_session'                 => self::avg_pages_per_session_via_ga4( $pid, $start_date, $end_date ),
			'avg_engaged_session_duration'          => self::avg_engaged_session_duration_via_ga4( $pid, $start_date, $end_date ),
			'bounce_rate'                           => self::bounce_rate_via_ga4( $pid, $start_date, $end_date ),
			'article_completion_rate'               => self::article_completion_rate_via_ga4( $pid, $start_date, $end_date ),
			// Content engagement.
			'most_read_articles'                    => self::most_read_articles_via_ga4( $pid, $start_date, $end_date ),
			'articles_by_completion_rate'           => self::articles_by_completion_rate_via_ga4( $pid, $start_date, $end_date ),
			'top_authors_by_avg_engagement_time'    => self::top_authors_by_avg_engagement_time_via_ga4( $pid, $start_date, $end_date ),
			// Reader segments.
			'engagement_by_device_type'             => self::engagement_by_device_type_via_ga4( $pid, $start_date, $end_date ),
			'engagement_by_newsletter_status'       => self::engagement_by_newsletter_status_via_ga4( $pid, $start_date, $end_date ),
			'engagement_by_returning_vs_new'        => self::engagement_by_returning_vs_new_via_ga4( $pid, $start_date, $end_date ),
			// BQ-only (hidden in v1).
			'top_categories_by_engagement'          => self::hidden_in_v1_payload(),
			'mobile_vs_desktop_content_preferences' => self::hidden_in_v1_payload(),
			'top_authors_by_repeat_reader_rate'     => self::hidden_in_v1_payload(),
			'article_freshness_vs_engagement'       => self::hidden_in_v1_payload(),
		];
	}

	/**
	 * BQ path — v1 stub.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @return array
	 */
	private static function compute_via_bq( string $start_date, string $end_date ): array {
		$keys = [
			'avg_pages_per_session',
			'avg_engaged_session_duration',
			'bounce_rate',
			'article_completion_rate',
			'most_read_articles',
			'articles_by_completion_rate',
			'top_authors_by_avg_engagement_time',
			'engagement_by_device_type',
			'engagement_by_newsletter_status',
			'engagement_by_returning_vs_new',
		];
		$payload = [
			'window' => [
				'start' => $start_date,
				'end'   => $end_date,
			],
		];
		foreach ( $keys as $key ) {
			$payload[ $key ] = self::not_implemented_payload();
		}
		foreach ( [ 'top_categories_by_engagement', 'mobile_vs_desktop_content_preferences', 'top_authors_by_repeat_reader_rate', 'article_freshness_vs_engagement' ] as $hidden ) {
			$payload[ $hidden ] = self::hidden_in_v1_payload();
		}
		return $payload;
	}

	/*
	===================================================================
	 * GA4-standard metrics
	 * ===================================================================
	 */

	/**
	 * Avg Pages per Session — screenPageViewsPerSession.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function avg_pages_per_session_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'screenPageViewsPerSession' ] ) );
		return self::scalar( $result, 'decimal' );
	}

	/**
	 * Avg Engaged Session Duration — averageSessionDuration (seconds).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function avg_engaged_session_duration_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'averageSessionDuration' ] ) );
		return self::scalar( $result, 'duration' );
	}

	/**
	 * Bounce Rate — bounceRate (0-1).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function bounce_rate_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [], [ 'bounceRate' ] ) );
		return self::scalar( $result, 'rate' );
	}

	/**
	 * Engagement by Device Type — deviceCategory / sessions, userEngagementDuration, screenPageViewsPerSession.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function engagement_by_device_type_via_ga4( string $pid, string $s, string $e ): array {
		$body   = self::body( $s, $e, [ 'deviceCategory' ], [ 'sessions', 'userEngagementDuration', 'screenPageViewsPerSession' ] );
		$body  += self::order_by_metric_desc( 'sessions' );
		$result = self::safe_run_report( $pid, $body );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$out = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$sessions = self::num( $row, 0 );
			$out[]    = [
				'device'                 => $row['dimensionValues'][0]['value'] ?? null,
				'sessions'               => (int) $sessions,
				'avg_engagement_seconds' => $sessions > 0 ? self::num( $row, 1 ) / $sessions : 0,
				'avg_pages_per_session'  => self::num( $row, 2 ),
			];
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => 'table',
		];
	}

	/**
	 * Engagement by Returning vs New — newVsReturning / sessions, screenPageViewsPerSession, userEngagementDuration.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function engagement_by_returning_vs_new_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'newVsReturning' ], [ 'sessions', 'screenPageViewsPerSession', 'userEngagementDuration' ] ) );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$out = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$sessions = self::num( $row, 0 );
			$out[]    = [
				'reader_type'            => $row['dimensionValues'][0]['value'] ?? null,
				'sessions'               => (int) $sessions,
				'avg_pages_per_session'  => self::num( $row, 1 ),
				'avg_engagement_seconds' => $sessions > 0 ? self::num( $row, 2 ) / $sessions : 0,
			];
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => 'table',
		];
	}

	/*
	===================================================================
	 * GA4-conditional metrics
	 * ===================================================================
	 */

	/**
	 * Article Completion Rate — article reads that reached the end ÷ article
	 * reads. A `scroll` event is the completion signal under GA4 default
	 * enhanced measurement (it fires once a reader reaches the end of the page).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function article_completion_rate_via_ga4( string $pid, string $s, string $e ): array {
		$num_body                    = self::body( $s, $e, [], [ 'eventCount' ] );
		$num_body['dimensionFilter'] = [
			'andGroup' => [
				'expressions' => [
					self::event_name_expression( 'scroll' ),
					self::custom_event_present_expression( 'post_id' ),
				],
			],
		];
		$num = self::safe_run_report( $pid, $num_body );
		if ( isset( $num['error'] ) || isset( $num['overlay'] ) ) {
			return $num;
		}

		$den_body                    = self::body( $s, $e, [], [ 'screenPageViews' ] );
		$den_body['dimensionFilter'] = self::custom_event_present_filter( 'post_id' );
		$den = self::safe_run_report( $pid, $den_body );
		if ( isset( $den['error'] ) || isset( $den['overlay'] ) ) {
			return $den;
		}

		$numerator   = (int) ( $num['raw']['rows'][0]['metricValues'][0]['value'] ?? 0 );
		$denominator = (int) ( $den['raw']['rows'][0]['metricValues'][0]['value'] ?? 0 );
		return [
			'value'       => $denominator > 0 ? $numerator / $denominator : 0,
			'computable'  => $denominator > 0,
			'type'        => 'rate',
			'numerator'   => $numerator,
			'denominator' => $denominator,
		];
	}

	/**
	 * Most-Read Articles — ranked by a composite of reach, scroll completion,
	 * and engagement time (scroll still factors into the ranking even though it
	 * isn't a displayed column). Two reports joined and scored in PHP; the row
	 * payload exposes readers + avg engagement time.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function most_read_articles_via_ga4( string $pid, string $s, string $e ): array {
		$reach_body                    = self::body( $s, $e, [ 'customEvent:post_id', 'pagePath', 'pageTitle' ], [ 'totalUsers', 'userEngagementDuration' ] );
		$reach_body['dimensionFilter'] = self::custom_event_present_filter( 'post_id' );
		$reach_body                   += self::order_by_metric_desc( 'totalUsers' );
		$reach_body['limit']           = 200;
		$reach                         = self::safe_run_report( $pid, $reach_body );
		if ( isset( $reach['error'] ) || isset( $reach['overlay'] ) ) {
			return $reach;
		}

		$scroll_by_post = self::scroll_events_by_post( $pid, $s, $e );

		$articles = [];
		foreach ( $reach['raw']['rows'] ?? [] as $row ) {
			$readers = (int) self::num( $row, 0 );
			if ( $readers < self::READER_THRESHOLD ) {
				continue;
			}
			$post_id    = $row['dimensionValues'][0]['value'] ?? '';
			$avg_eng    = $readers > 0 ? self::num( $row, 1 ) / $readers : 0;
			$scroll     = $scroll_by_post[ $post_id ] ?? 0;
			// Scroll completion still feeds the composite ranking score, but is
			// no longer surfaced as a displayed column.
			$avg_scroll = $readers > 0 ? min( 1.0, $scroll / $readers ) : 0;
			$articles[] = [
				'post_id'                => $post_id,
				'page_path'              => $row['dimensionValues'][1]['value'] ?? null,
				'page_title'             => $row['dimensionValues'][2]['value'] ?? null,
				'unique_readers'         => $readers,
				'avg_engagement_seconds' => $avg_eng,
				'engagement_score'       => $readers * max( $avg_scroll, 0.1 ) * ( 1 + log( $avg_eng + 1 ) ),
			];
		}
		usort(
			$articles,
			function ( $a, $b ) {
				return $b['engagement_score'] <=> $a['engagement_score'];
			}
		);
		return [
			'rows'       => array_slice( $articles, 0, 50 ),
			'computable' => true,
			'type'       => 'table',
		];
	}

	/**
	 * Articles by Completion Rate — scroll-to-90 events ÷ readers per article.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function articles_by_completion_rate_via_ga4( string $pid, string $s, string $e ): array {
		$readers_body                    = self::body( $s, $e, [ 'customEvent:post_id', 'pagePath', 'pageTitle' ], [ 'totalUsers' ] );
		$readers_body['dimensionFilter'] = self::custom_event_present_filter( 'post_id' );
		$readers_body['limit']           = 1000;
		$readers                         = self::safe_run_report( $pid, $readers_body );
		if ( isset( $readers['error'] ) || isset( $readers['overlay'] ) ) {
			return $readers;
		}

		$scroll_by_post = self::scroll_events_by_post( $pid, $s, $e );

		$rows = [];
		foreach ( $readers['raw']['rows'] ?? [] as $row ) {
			$count = (int) self::num( $row, 0 );
			if ( $count < self::READER_THRESHOLD ) {
				continue;
			}
			$post_id = $row['dimensionValues'][0]['value'] ?? '';
			$scroll  = $scroll_by_post[ $post_id ] ?? 0;
			$rows[]  = [
				'post_id'         => $post_id,
				'page_path'       => $row['dimensionValues'][1]['value'] ?? null,
				'page_title'      => $row['dimensionValues'][2]['value'] ?? null,
				'readers'         => $count,
				'completion_rate' => $count > 0 ? min( 1.0, $scroll / $count ) : 0,
			];
		}
		usort(
			$rows,
			function ( $a, $b ) {
				$by_rate = $b['completion_rate'] <=> $a['completion_rate'];
				return 0 !== $by_rate ? $by_rate : ( $b['readers'] <=> $a['readers'] );
			}
		);
		return [
			'rows'       => array_slice( $rows, 0, 50 ),
			'computable' => true,
			'type'       => 'table',
		];
	}

	/**
	 * Top Authors by Avg Engagement Time — customEvent:author + post_id.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function top_authors_by_avg_engagement_time_via_ga4( string $pid, string $s, string $e ): array {
		$body                    = self::body( $s, $e, [ 'customEvent:author' ], [ 'totalUsers', 'userEngagementDuration' ] );
		$body['dimensionFilter'] = [
			'andGroup' => [
				'expressions' => [
					self::custom_event_present_expression( 'post_id' ),
					self::custom_event_present_expression( 'author' ),
				],
			],
		];
		$body         += self::order_by_metric_desc( 'userEngagementDuration' );
		$body['limit'] = 25;
		$result        = self::safe_run_report( $pid, $body );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$out = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$readers = (int) self::num( $row, 0 );
			$out[]   = [
				'author'                 => $row['dimensionValues'][0]['value'] ?? null,
				'unique_readers'         => $readers,
				'avg_engagement_seconds' => $readers > 0 ? self::num( $row, 1 ) / $readers : 0,
			];
		}
		// The query orders by total userEngagementDuration to pull a strong
		// candidate set (authors with real readership, not one-reader outliers);
		// re-sort that set by the computed per-reader average so the ranking
		// actually matches the metric — "Top Authors by Avg Engagement Time".
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['avg_engagement_seconds'] <=> $a['avg_engagement_seconds'];
			}
		);
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => 'table',
		];
	}

	/**
	 * Engagement by Newsletter Status — customEvent:is_newsletter_subscriber.
	 * Collapses non-'yes' dimension values into a single "not subscribed" bucket.
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array
	 */
	private static function engagement_by_newsletter_status_via_ga4( string $pid, string $s, string $e ): array {
		$result = self::safe_run_report( $pid, self::body( $s, $e, [ 'customEvent:is_newsletter_subscriber' ], [ 'sessions', 'screenPageViewsPerSession', 'userEngagementDuration' ] ) );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		// Aggregate into two buckets. screenPageViewsPerSession is a per-session
		// average, so weight it by sessions before re-dividing.
		$buckets = [
			'subscriber'     => [
				'sessions' => 0,
				'pages'    => 0.0,
				'eng'      => 0.0,
			],
			'not_subscribed' => [
				'sessions' => 0,
				'pages'    => 0.0,
				'eng'      => 0.0,
			],
		];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$key      = ( ( $row['dimensionValues'][0]['value'] ?? '' ) === 'yes' ) ? 'subscriber' : 'not_subscribed';
			$sessions = self::num( $row, 0 );
			$buckets[ $key ]['sessions'] += (int) $sessions;
			$buckets[ $key ]['pages']    += self::num( $row, 1 ) * $sessions;
			$buckets[ $key ]['eng']      += self::num( $row, 2 );
		}
		$labels = [
			'subscriber'     => __( 'Newsletter subscriber', 'newspack-plugin' ),
			'not_subscribed' => __( 'Not subscribed', 'newspack-plugin' ),
		];
		$out = [];
		foreach ( $buckets as $key => $b ) {
			$out[] = [
				'segment'                => $labels[ $key ],
				'sessions'               => $b['sessions'],
				'avg_pages_per_session'  => $b['sessions'] > 0 ? $b['pages'] / $b['sessions'] : 0,
				'avg_engagement_seconds' => $b['sessions'] > 0 ? $b['eng'] / $b['sessions'] : 0,
			];
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => 'table',
		];
	}

	/**
	 * Article-scoped scroll-event counts keyed by post_id. Empty array on
	 * error/overlay (callers treat missing scroll data as zero completion).
	 *
	 * @param string $pid Property ID.
	 * @param string $s   Start date.
	 * @param string $e   End date.
	 * @return array<string,int>
	 */
	private static function scroll_events_by_post( string $pid, string $s, string $e ): array {
		$body                    = self::body( $s, $e, [ 'customEvent:post_id' ], [ 'eventCount' ] );
		$body['dimensionFilter'] = [
			'andGroup' => [
				'expressions' => [
					self::event_name_expression( 'scroll' ),
					self::custom_event_present_expression( 'post_id' ),
				],
			],
		];
		$body['limit'] = 1000;
		$result        = self::safe_run_report( $pid, $body );
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return [];
		}
		$map = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$key = $row['dimensionValues'][0]['value'] ?? '';
			if ( '' !== $key ) {
				$map[ $key ] = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			}
		}
		return $map;
	}

	/*
	===================================================================
	 * Shared helpers
	 * ===================================================================
	 */

	/**
	 * Build a base runReport body.
	 *
	 * @param string   $s       Start date.
	 * @param string   $e       End date.
	 * @param string[] $dims    Dimension names.
	 * @param string[] $metrics Metric names.
	 * @return array
	 */
	private static function body( string $s, string $e, array $dims, array $metrics ): array {
		$body = [
			'dateRanges' => [
				[
					'startDate' => $s,
					'endDate'   => $e,
				],
			],
			'metrics'    => array_map(
				function ( $m ) {
					return [ 'name' => $m ];
				},
				$metrics
			),
		];
		if ( ! empty( $dims ) ) {
			$body['dimensions'] = array_map(
				function ( $d ) {
					return [ 'name' => $d ];
				},
				$dims
			);
		}
		return $body;
	}

	/**
	 * Build an orderBys fragment: one metric, descending.
	 *
	 * @param string $metric Metric name.
	 * @return array
	 */
	private static function order_by_metric_desc( string $metric ): array {
		return [
			'orderBys' => [
				[
					'metric' => [ 'metricName' => $metric ],
					'desc'   => true,
				],
			],
		];
	}

	/**
	 * FilterExpression: eventName EXACT match.
	 *
	 * @param string $event_name Event name.
	 * @return array
	 */
	private static function event_name_expression( string $event_name ): array {
		return [
			'filter' => [
				'fieldName'    => 'eventName',
				'stringFilter' => [
					'matchType' => 'EXACT',
					'value'     => $event_name,
				],
			],
		];
	}

	/**
	 * FilterExpression: a customEvent dimension is present (non-empty).
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_present_expression( string $param ): array {
		return [
			'filter' => [
				'fieldName'    => 'customEvent:' . $param,
				'stringFilter' => [
					'matchType' => 'FULL_REGEXP',
					'value'     => '.+',
				],
			],
		];
	}

	/**
	 * Build a dimensionFilter asserting a customEvent dimension is present.
	 *
	 * @param string $param Event parameter name.
	 * @return array
	 */
	private static function custom_event_present_filter( string $param ): array {
		return [ 'filter' => self::custom_event_present_expression( $param )['filter'] ];
	}

	/**
	 * Read a metric value from a report row as a number.
	 *
	 * @param array $row   Report row.
	 * @param int   $index Metric index.
	 * @return float
	 */
	private static function num( array $row, int $index ): float {
		return (float) ( $row['metricValues'][ $index ]['value'] ?? 0 );
	}

	/**
	 * Run a report, normalizing WP_Error into payload-shaped failures.
	 *
	 * @param string $property_id Property ID.
	 * @param array  $body        runReport body.
	 * @return array
	 */
	private static function safe_run_report( string $property_id, array $body ): array {
		$result = Client::run_report( $property_id, $body );

		if ( is_wp_error( $result ) ) {
			if ( 'custom_dimension_missing' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				return [
					'value'      => null,
					'computable' => false,
					'overlay'    => [
						'type'       => 'custom_dimension_missing',
						'dimensions' => is_array( $data ) && isset( $data['dimensions'] ) ? $data['dimensions'] : [],
					],
				];
			}
			return [
				'value'      => null,
				'computable' => false,
				'error'      => $result->get_error_message(),
			];
		}

		return [ 'raw' => $result ];
	}

	/**
	 * Whether a safe_run_report result is a custom_dimension_missing overlay.
	 *
	 * @param array $result safe_run_report result.
	 * @return bool
	 */
	private static function is_custom_dimension_missing( array $result ): bool {
		return isset( $result['overlay']['type'] ) && 'custom_dimension_missing' === $result['overlay']['type'];
	}

	/**
	 * Transform a single scalar metric value.
	 *
	 * @param array  $result safe_run_report result.
	 * @param string $type   'count' (int) or 'decimal'/'rate'/'duration' (float).
	 * @return array
	 */
	private static function scalar( array $result, string $type ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$raw   = $result['raw']['rows'][0]['metricValues'][0]['value'] ?? null;
		$value = null === $raw
			? ( 'count' === $type ? 0 : 0.0 )
			: ( 'count' === $type ? (int) $raw : (float) $raw );
		return [
			'value'      => $value,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Transform report rows into a list of associative rows.
	 *
	 * @param array    $result      safe_run_report result.
	 * @param string[] $dim_keys    Output keys per dimension.
	 * @param string[] $metric_keys Output keys per metric.
	 * @param string   $type        Payload type token.
	 * @return array
	 */
	private static function rows( array $result, array $dim_keys, array $metric_keys, string $type ): array {
		if ( isset( $result['error'] ) || isset( $result['overlay'] ) ) {
			return $result;
		}
		$out = [];
		foreach ( $result['raw']['rows'] ?? [] as $row ) {
			$entry = [];
			foreach ( $dim_keys as $i => $key ) {
				$entry[ $key ] = $row['dimensionValues'][ $i ]['value'] ?? null;
			}
			foreach ( $metric_keys as $i => $key ) {
				$raw           = $row['metricValues'][ $i ]['value'] ?? null;
				$entry[ $key ] = null === $raw ? null : ( str_contains( (string) $raw, '.' ) ? (float) $raw : (int) $raw );
			}
			$out[] = $entry;
		}
		return [
			'rows'       => $out,
			'computable' => true,
			'type'       => $type,
		];
	}

	/**
	 * Attach a degraded-state overlay to a successful rows payload.
	 *
	 * @param array  $payload rows() output.
	 * @param string $message Overlay message.
	 * @return array
	 */
	private static function mark_degraded( array $payload, string $message ): array {
		if ( isset( $payload['error'] ) || isset( $payload['overlay'] ) ) {
			return $payload;
		}
		$payload['degraded'] = true;
		$payload['overlay']  = [
			'type'    => 'degraded',
			'message' => $message,
		];
		return $payload;
	}

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

	/**
	 * Standard v1 BQ stub payload.
	 *
	 * @return array
	 */
	private static function not_implemented_payload(): array {
		return [
			'value'      => null,
			'computable' => false,
			'error'      => __( 'BQ path not yet implemented. See NPPD-1630.', 'newspack-plugin' ),
		];
	}
}
