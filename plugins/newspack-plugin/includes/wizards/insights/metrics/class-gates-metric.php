<?php
/**
 * Newspack Insights — Gates Metric orchestrator (NPPD-1604, Phase 1).
 *
 * Phase 1 placeholder layer. Every metric returns a `pending: true`
 * payload with a zero value and a `placeholder_type` so the React
 * layer can render the spec's empty-state value ("0", "0%", "$0.00",
 * "0.0") without inferring type. No storage layer, no SQL — the data
 * is intentionally synthetic until NPPD-1630 wires the BigQuery
 * query proxy into the same method signatures.
 *
 * Phase 2 swap point: each method in this class will move from
 * returning a placeholder to dispatching a `query_name` against the
 * Newspack Manager BQ catalog. The orchestrator's responsibility
 * (caching, response shape) stays here; storage rolls in beneath.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use DateTimeZone;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Woo_Order_Resolver;

/**
 * Tab 4 placeholder metric orchestrator.
 *
 * @phpstan-type RateMetric array{
 *   value: int|float,
 *   computable: bool,
 *   pending: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 * }
 */
final class Gates_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab4_v1:';

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Resolver used to match BQ paywall attempts against Woo orders.
	 *
	 * @var Woo_Order_Resolver
	 */
	private Woo_Order_Resolver $woo_resolver;

	/**
	 * Per-request memoization for `fetch_paywall_direct_woo_join`.
	 *
	 * Keyed by `Ymd|Ymd` of the (start, end) UTC dates. The two revenue methods
	 * (`get_total_paywall_revenue_direct` and `get_avg_revenue_per_paywall_conversion`)
	 * both source from `gates_paywall_revenue_direct`; this cache avoids issuing
	 * two identical HTTP round-trips to the hub for the same window.
	 *
	 * @var array<string, array{rows:array, conversions:int, revenue:float}|null>
	 */
	private array $paywall_direct_cache = [];

	/**
	 * Constructor. Optionally inject collaborators (used in tests).
	 *
	 * @param BigQuery_Proxy_Client|null $proxy        Injected proxy client, or null to lazy-resolve.
	 * @param Woo_Order_Resolver|null    $woo_resolver Injected Woo resolver, or null to lazy-create.
	 */
	public function __construct(
		?BigQuery_Proxy_Client $proxy = null,
		?Woo_Order_Resolver $woo_resolver = null
	) {
		$this->proxy        = $proxy ?? new BigQuery_Proxy_Client();
		$this->woo_resolver = $woo_resolver ?? new Woo_Order_Resolver();
	}

	/**
	 * Build the standard placeholder shape for a single scorecard
	 * metric. Type is encoded in `placeholder_type` so React can pick
	 * the right format token ("0" vs "0%" vs "$0.00" vs "0.0") without
	 * inferring from the field name.
	 *
	 * @param string $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @return array{value: int|float, computable: bool, pending: bool, denominator: null, placeholder_type: string}
	 */
	private function placeholder( string $placeholder_type ): array {
		return [
			'value'            => 'decimal' === $placeholder_type ? 0.0 : 0,
			'computable'       => false,
			'pending'          => true,
			'denominator'      => null,
			'placeholder_type' => $placeholder_type,
		];
	}

	/**
	 * Run a scalar catalog query and extract a single value from the first row.
	 *
	 * Returns a payload in the same shape as `placeholder()`. On any failure path
	 * (proxy not configured, BQ error, empty rows, missing key, non-numeric value),
	 * falls back to `placeholder( $placeholder_type )` with `pending: true`.
	 *
	 * @param string            $query_name        Catalog `query_name`.
	 * @param string            $row_key           Column to extract from the first row.
	 * @param string            $placeholder_type  'count' | 'rate' | 'currency' | 'decimal'.
	 * @param DateTimeInterface $start             Window start.
	 * @param DateTimeInterface $end               Window end.
	 * @return array
	 */
	private function compute_metric_from_proxy(
		string $query_name,
		string $row_key,
		string $placeholder_type,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $row_key, $rows[0] ) ) {
			return $this->placeholder( $placeholder_type );
		}
		$value = $rows[0][ $row_key ];
		if ( ! is_numeric( $value ) ) {
			return $this->placeholder( $placeholder_type );
		}
		// For count metrics, reject values that aren't representable as integers
		// (e.g. a float column would indicate catalog drift; fall back loudly).
		if ( 'count' === $placeholder_type && (float) $value !== (float) (int) $value ) {
			return $this->placeholder( $placeholder_type );
		}
		return [
			'value'            => 'count' === $placeholder_type ? (int) $value : (float) $value,
			'computable'       => true,
			'pending'          => false,
			'denominator'      => null,
			'placeholder_type' => $placeholder_type,
		];
	}

	/**
	 * Compute a paywall conversion rate from BQ rows + Woo completion join.
	 *
	 * @param string            $query_name Catalog name (`gates_paywall_conversion_*`).
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return array
	 */
	private function compute_paywall_rate_from_proxy(
		string $query_name,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) || empty( $rows ) ) {
			return $this->placeholder( 'rate' );
		}
		$denominator = count( $rows );
		$numerator   = $this->woo_resolver->count_completed_orders( $rows );
		return [
			'value'            => $denominator > 0 ? $numerator / $denominator : 0.0,
			'computable'       => true,
			'pending'          => false,
			'denominator'      => $denominator,
			'placeholder_type' => 'rate',
		];
	}

	/**
	 * Fetch paywall direct rows and return matched-order count + summed revenue.
	 *
	 * Used by both `get_total_paywall_revenue_direct` and
	 * `get_avg_revenue_per_paywall_conversion` (derived).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{rows:array, conversions:int, revenue:float}|null
	 */
	private function fetch_paywall_direct_woo_join(
		DateTimeInterface $start,
		DateTimeInterface $end
	): ?array {
		// Normalize both bounds to UTC Ymd so callers passing different timezone
		// objects don't bust the cache for the same logical window. Matches the
		// proxy client's own UTC normalization.
		$utc       = new \DateTimeZone( 'UTC' );
		$cache_key = \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' )
			. '|'
			. \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' );

		if ( array_key_exists( $cache_key, $this->paywall_direct_cache ) ) {
			return $this->paywall_direct_cache[ $cache_key ];
		}

		$rows = $this->proxy->query( 'gates_paywall_revenue_direct', $start, $end );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) || empty( $rows ) ) {
			$this->paywall_direct_cache[ $cache_key ] = null;
			return null;
		}

		$result = [
			'rows'        => $rows,
			'conversions' => $this->woo_resolver->count_completed_orders( $rows ),
			'revenue'     => $this->woo_resolver->sum_completed_revenue( $rows ),
		];
		$this->paywall_direct_cache[ $cache_key ] = $result;
		return $result;
	}

	// --- Section 1: Gate exposure ---------------------------------------

	/**
	 * Total gate impressions in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_gate_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_total_impressions', 'gate_impressions', 'count', $start, $end );
	}

	/**
	 * Unique readers who saw at least one gate.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_unique_readers_reached( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_unique_viewers', 'unique_gate_viewers', 'count', $start, $end );
	}

	/**
	 * Average gate exposures per reader.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_exposures_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_avg_exposures_per_reader', 'avg_exposures_per_reader', 'decimal', $start, $end );
	}

	/**
	 * Percentage of sessions that hit at least one gate.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_sessions_with_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_sessions_with_gate', 'pct_sessions_with_gate', 'rate', $start, $end );
	}

	// --- Section 2: Free reader conversion ------------------------------

	/**
	 * Regwall conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_regwall_conversion_direct', 'regwall_conversion_rate_direct', 'rate', $start, $end );
	}

	/**
	 * Regwall conversion rate, influenced (7-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'gates_regwall_conversion_influenced_7d', 'regwall_conversion_influenced', 'rate', $start, $end );
	}

	// --- Section 3: Paid reader conversion ------------------------------

	/**
	 * Paywall conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_paywall_rate_from_proxy( 'gates_paywall_conversion_direct', $start, $end );
	}

	/**
	 * Paywall conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_paywall_rate_from_proxy( 'gates_paywall_conversion_influenced_14d', $start, $end );
	}

	/**
	 * Total revenue from paywall conversions, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_paywall_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paywall_direct_woo_join( $start, $end );
		if ( null === $joined ) {
			return $this->placeholder( 'currency' );
		}
		return [
			'value'            => $joined['revenue'],
			'computable'       => true,
			'pending'          => false,
			'denominator'      => $joined['conversions'],
			'placeholder_type' => 'currency',
		];
	}

	/**
	 * Average revenue per paywall conversion.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_revenue_per_paywall_conversion( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paywall_direct_woo_join( $start, $end );
		if ( null === $joined || $joined['conversions'] <= 0 ) {
			return $this->placeholder( 'currency' );
		}
		return [
			'value'            => $joined['revenue'] / $joined['conversions'],
			'computable'       => true,
			'pending'          => false,
			'denominator'      => $joined['conversions'],
			'placeholder_type' => 'currency',
		];
	}

	// --- Section 4: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three stages with zeros and a pending flag.
	 * Stage shape kept stable so the React Funnel viz can render the
	 * same chrome regardless of phase.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   pending: bool,
	 *   stages: array<int, array{label: string, count: int, pct_of_top: float}>
	 * }
	 */
	public function get_conversion_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_funnel', $start, $end );
		if ( is_wp_error( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) ) {
			return [
				'pending' => true,
				'stages'  => [
					[
						'label'      => __( 'Impression', 'newspack-plugin' ),
						'count'      => 0,
						'pct_of_top' => 0.0,
					],
					[
						'label'      => __( 'Engagement', 'newspack-plugin' ),
						'count'      => 0,
						'pct_of_top' => 0.0,
					],
					[
						'label'      => __( 'Conversion', 'newspack-plugin' ),
						'count'      => 0,
						'pct_of_top' => 0.0,
					],
				],
			];
		}
		$row   = $rows[0];
		$step1 = (int) ( $row['step_1_impression'] ?? 0 );
		$step2 = (int) ( $row['step_2_engagement'] ?? 0 );
		$step3 = (int) ( $row['step_3_conversion'] ?? 0 );
		$top   = $step1 > 0 ? $step1 : 1; // Avoid div-by-zero in pct_of_top.
		return [
			'pending' => false,
			'stages'  => [
				[
					'label'      => __( 'Impression', 'newspack-plugin' ),
					'count'      => $step1,
					'pct_of_top' => 1.0,
				],
				[
					'label'      => __( 'Engagement', 'newspack-plugin' ),
					'count'      => $step2,
					'pct_of_top' => $step2 / $top,
				],
				[
					'label'      => __( 'Conversion', 'newspack-plugin' ),
					'count'      => $step3,
					'pct_of_top' => $step3 / $top,
				],
			],
		];
	}

	/**
	 * Exposures-before-conversion distribution buckets.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   pending: bool,
	 *   buckets: array<int, array{label: string, count: int, pct: float}>
	 * }
	 */
	public function get_exposures_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_exposures_before_conversion', $start, $end );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return $this->empty_distribution();
		}

		$by_bucket = [];
		foreach ( $rows as $row ) {
			if ( isset( $row['bucket'] ) ) {
				$by_bucket[ $row['bucket'] ] = [
					'count' => (int) ( $row['converters_in_bucket'] ?? 0 ),
					'pct'   => (float) ( $row['pct_of_converters'] ?? 0.0 ),
				];
			}
		}

		if ( empty( $by_bucket ) ) {
			return $this->empty_distribution();
		}

		$ordered_keys   = [ '1', '2', '3-5', '6+' ];
		$ordered_labels = [
			'1'   => __( '1 exposure', 'newspack-plugin' ),
			'2'   => __( '2 exposures', 'newspack-plugin' ),
			'3-5' => __( '3–5 exposures', 'newspack-plugin' ),
			'6+'  => __( '6+ exposures', 'newspack-plugin' ),
		];
		$buckets        = [];
		foreach ( $ordered_keys as $key ) {
			$buckets[] = [
				'label' => $ordered_labels[ $key ],
				'count' => $by_bucket[ $key ]['count'] ?? 0,
				'pct'   => $by_bucket[ $key ]['pct'] ?? 0.0,
			];
		}
		return [
			'pending' => false,
			'buckets' => $buckets,
		];
	}

	/**
	 * Empty distribution shape (Phase 1-style placeholder for the React table).
	 *
	 * @return array
	 */
	private function empty_distribution(): array {
		return [
			'pending' => true,
			'buckets' => [
				[
					'label' => __( '1 exposure', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
				[
					'label' => __( '2 exposures', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
				[
					'label' => __( '3–5 exposures', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
				[
					'label' => __( '6+ exposures', 'newspack-plugin' ),
					'count' => 0,
					'pct'   => 0.0,
				],
			],
		];
	}

	// --- Section 5: Performance by gate ---------------------------------

	/**
	 * Per-gate breakdown. Phase 1 returns an empty `rows` array; the
	 * React PerformanceByGateSection renders the spec's empty-state
	 * copy when the array is empty. Phase 2 will populate this with
	 * real BQ rows enriched server-side from `wp_posts.post_title`
	 * keyed on `gate_post_id`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{pending: bool, rows: array}
	 */
	public function get_performance_by_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_performance_by_gate', $start, $end );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) || empty( $rows ) ) {
			return [
				'pending' => true,
				'rows'    => [],
			];
		}

		$mapped = [];
		foreach ( $rows as $row ) {
			$gate_post_id = (int) ( $row['gate_post_id'] ?? 0 );
			$mapped[]     = [
				'gate_post_id'            => $gate_post_id,
				'gate_name'               => null, // filled below by enrich_with_gate_titles().
				'impressions'             => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'          => (int) ( $row['unique_viewers'] ?? 0 ),
				'registrations'           => (int) ( $row['registrations'] ?? 0 ),
				'regwall_conversion_rate' => isset( $row['regwall_conversion_rate'] ) && null !== $row['regwall_conversion_rate'] ? (float) $row['regwall_conversion_rate'] : null,
				'paywall_attempts'        => (int) ( $row['paywall_attempts'] ?? 0 ),
				'paywall_attempt_rate'    => isset( $row['paywall_attempt_rate'] ) && null !== $row['paywall_attempt_rate'] ? (float) $row['paywall_attempt_rate'] : null,
			];
		}

		return [
			'pending' => false,
			'rows'    => $this->enrich_with_gate_titles( $mapped ),
		];
	}

	/**
	 * Enrich performance rows with the `post_title` of each `gate_post_id`.
	 *
	 * @param array $rows Rows containing `gate_post_id` int.
	 * @return array Rows with `gate_name` filled in.
	 */
	private function enrich_with_gate_titles( array $rows ): array {
		$ids = array_filter( array_unique( array_column( $rows, 'gate_post_id' ) ) );
		if ( empty( $ids ) ) {
			return $rows;
		}
		// Use `get_post()` per unique ID rather than `get_posts( post_type => 'any' )`:
		// the popup CPT (`newspack_popups_cpt`) is registered with
		// `exclude_from_search = true`, which excludes it from the `'any'` query.
		// `get_post()` works regardless of post-type registration and is cached
		// by `WP_Object_Cache`, so repeat lookups in the same request are free.
		$titles = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && '' !== $post->post_title ) {
				$titles[ $id ] = $post->post_title;
			}
		}
		foreach ( $rows as &$row ) {
			$id               = $row['gate_post_id'];
			$row['gate_name'] = isset( $titles[ $id ] )
				? $titles[ $id ]
				/* translators: %d is a Newspack popup post ID. */
				: sprintf( __( 'Gate #%d', 'newspack-plugin' ), $id );
		}
		unset( $row );
		return $rows;
	}
}
