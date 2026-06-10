<?php
/**
 * Newspack Insights — Gates Metric orchestrator (NPPD-1604).
 *
 * Dispatches catalog queries to the Newspack Manager BigQuery proxy and shapes
 * each result for the React layer. Every method reports an explicit `state`:
 *   - 'error'     — the proxy/query failed (carries `error_code` + `error_message`)
 *   - 'empty'     — the query succeeded but returned no rows
 *   - 'populated' — the query returned usable data
 *
 * This replaces the earlier `pending: true` flag, which collapsed proxy errors,
 * legitimately-empty results, and malformed responses into one ambiguous
 * "No data yet" state and masked real failures. Scalar scorecards use
 * 'error' | 'populated' only ('empty' has no meaning for a single value — an
 * absent value renders as a non-computable zero).
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
 * Tab 4 metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   state: string,
 *   value: int|float,
 *   computable: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 *   error_code?: string,
 *   error_message?: string,
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
	 * @var array<string, array{rows:array, conversions:int, revenue:float}|\WP_Error>
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
	 * Error payload for a scalar scorecard metric. Carries the proxy's error
	 * code + message so the UI can render an error treatment (without exposing
	 * internals to the reader) instead of a misleading zero.
	 *
	 * @param string    $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @param \WP_Error $error            The originating proxy error.
	 * @return array
	 */
	private function error_scalar( string $placeholder_type, \WP_Error $error ): array {
		return [
			'state'            => 'error',
			'value'            => 'decimal' === $placeholder_type ? 0.0 : 0,
			'computable'       => false,
			'denominator'      => null,
			'placeholder_type' => $placeholder_type,
			'error_code'       => $error->get_error_code(),
			'error_message'    => $error->get_error_message(),
		];
	}

	/**
	 * Populated payload for a scalar scorecard metric. A successful query that
	 * yields no usable value is still 'populated' — it renders as a
	 * non-computable zero ('empty' has no meaning for a single scalar).
	 *
	 * @param int|float $value            Metric value.
	 * @param bool      $computable       Whether the value is a real computed figure.
	 * @param int|null  $denominator      Optional denominator.
	 * @param string    $placeholder_type One of 'count', 'rate', 'currency', 'decimal'.
	 * @return array
	 */
	private function populated_scalar( $value, bool $computable, ?int $denominator, string $placeholder_type ): array {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
			'placeholder_type' => $placeholder_type,
		];
	}

	/**
	 * Error payload for a collection metric (funnel / distribution / table).
	 *
	 * @param string    $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @param \WP_Error $error    The originating proxy error.
	 * @return array
	 */
	private function error_collection( string $rows_key, \WP_Error $error ): array {
		return [
			'state'         => 'error',
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message(),
			$rows_key       => [],
		];
	}

	/**
	 * Run a scalar catalog query and extract a single value from the first row.
	 *
	 * A proxy WP_Error becomes state 'error'. A successful query with no usable
	 * value (empty rows, missing key, non-numeric, or count drift) becomes a
	 * 'populated' non-computable zero.
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
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( $placeholder_type, $rows );
		}
		$zero = 'decimal' === $placeholder_type ? 0.0 : 0;
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $row_key, $rows[0] ) ) {
			// Query succeeded with no usable value → non-computable zero.
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		$value = $rows[0][ $row_key ];
		// Non-numeric, or (for counts) a non-integer value that signals catalog
		// drift, can't be trusted as a figure — surface a non-computable zero.
		if ( ! is_numeric( $value ) || ( 'count' === $placeholder_type && (float) $value !== (float) (int) $value ) ) {
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		return $this->populated_scalar( 'count' === $placeholder_type ? (int) $value : (float) $value, true, null, $placeholder_type );
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
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'rate', $rows );
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			// No paywall attempts in the window → a real 0% rate.
			return $this->populated_scalar( 0.0, false, 0, 'rate' );
		}
		$denominator = count( $rows );
		$numerator   = $this->woo_resolver->count_completed_orders( $rows );
		return $this->populated_scalar( $denominator > 0 ? $numerator / $denominator : 0.0, true, $denominator, 'rate' );
	}

	/**
	 * Fetch paywall direct rows and return matched-order count + summed revenue.
	 *
	 * Used by both `get_total_paywall_revenue_direct` and
	 * `get_avg_revenue_per_paywall_conversion` (derived).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{rows:array, conversions:int, revenue:float}|\WP_Error WP_Error on proxy failure.
	 */
	private function fetch_paywall_direct_woo_join(
		DateTimeInterface $start,
		DateTimeInterface $end
	) {
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
		if ( is_wp_error( $rows ) ) {
			$this->paywall_direct_cache[ $cache_key ] = $rows;
			return $rows;
		}

		// A successful but empty response is real "no conversions", not an error:
		// zero conversions / zero revenue, which the callers render as $0.00.
		$rows   = is_array( $rows ) ? $rows : [];
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
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
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
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		$conversions = $joined['conversions'];
		// No conversions → a real $0.00 average, flagged non-computable.
		return $this->populated_scalar(
			$conversions > 0 ? $joined['revenue'] / $conversions : 0.0,
			$conversions > 0,
			$conversions,
			'currency'
		);
	}

	// --- Section 4: How readers convert ---------------------------------

	/**
	 * Conversion funnel — three ordered stages (impression → engagement →
	 * conversion). Returns state: 'error' (proxy failure / malformed row),
	 * 'empty' (query succeeded, no rows), or 'populated' (stages with counts).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{
	 *   state: string,
	 *   stages: array<int, array{label: string, count: int, pct_of_top: float}>,
	 *   error_code?: string,
	 *   error_message?: string
	 * }
	 */
	public function get_conversion_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_funnel', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			// Successful response in an unexpected shape — a data-quality bug, not
			// a legitimately empty window. Surface it as an error.
			return $this->error_collection(
				'stages',
				new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The funnel query returned an unexpected shape.', 'newspack-plugin' ) )
			);
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		$row   = $rows[0];
		$step1 = (int) ( $row['step_1_impression'] ?? 0 );
		$step2 = (int) ( $row['step_2_engagement'] ?? 0 );
		$step3 = (int) ( $row['step_3_conversion'] ?? 0 );
		$top   = $step1 > 0 ? $step1 : 1; // Avoid div-by-zero in pct_of_top.
		return [
			'state'  => 'populated',
			'stages' => [
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
	 *   state: string,
	 *   buckets: array<int, array{label: string, count: int, pct: float}>,
	 *   error_code?: string,
	 *   error_message?: string
	 * }
	 */
	public function get_exposures_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_exposures_before_conversion', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'buckets', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->error_collection(
				'buckets',
				new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The distribution query returned an unexpected shape.', 'newspack-plugin' ) )
			);
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
			return [
				'state'   => 'empty',
				'buckets' => [],
			];
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
			'state'   => 'populated',
			'buckets' => $buckets,
		];
	}

	// --- Section 5: Performance by gate ---------------------------------

	/**
	 * Per-gate breakdown, enriched server-side with each gate's
	 * `wp_posts.post_title` keyed on `gate_post_id`. Returns state: 'error'
	 * (proxy failure / malformed), 'empty' (no rows), or 'populated'.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, error_code?: string, error_message?: string}
	 */
	public function get_performance_by_gate( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'gates_performance_by_gate', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'rows', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->error_collection(
				'rows',
				new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The performance query returned an unexpected shape.', 'newspack-plugin' ) )
			);
		}
		if ( empty( $rows ) ) {
			return [
				'state' => 'empty',
				'rows'  => [],
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
			'state' => 'populated',
			'rows'  => $this->enrich_with_gate_titles( $mapped ),
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
