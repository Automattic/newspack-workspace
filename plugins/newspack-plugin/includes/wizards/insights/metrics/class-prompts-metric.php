<?php
/**
 * Newspack Insights — Prompts Metric orchestrator (NPPD-1607, Phase 2).
 *
 * Dispatches catalog queries to the Newspack Manager BigQuery proxy and shapes
 * each result for the React layer. Every method reports an explicit `state`:
 *   - 'error'     — the proxy/query failed (carries `error_code` + `error_message`)
 *   - 'empty'     — the query succeeded but returned no rows (collections only)
 *   - 'populated' — the query returned usable data
 *
 * This replaces the Phase 1 `pending: true` flag, which collapsed proxy errors,
 * legitimately-empty results, and malformed responses into one ambiguous
 * "No data yet" state and masked real failures. Scalar scorecards use
 * 'error' | 'populated' only ('empty' has no meaning for a single value — an
 * absent value renders as a non-computable zero).
 *
 * Mirrors {@see Gates_Metric} (Tab 4) one-for-one. Tab 5 carries four conversion
 * intents (registration, newsletter signup, donation, subscription) versus
 * Gates' two, so it has more scorecards and a three-table performance
 * breakdown — but the per-metric envelope is identical.
 *
 * This commit wires the 8 paid-conversion + revenue metrics through the
 * BigQuery proxy + Woo_Order_Resolver join (Task 3.2), on top of the 10
 * free-side scalars wired in Task 3.1. The 5 collection metrics
 * (funnel/distribution/3 perf tables) remain as placeholder envelopes that
 * surface as state 'error' with the `newspack_insights_prompts_not_yet_implemented`
 * code — they will be wired in Task 3.3.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Woo_Order_Resolver;

/**
 * Tab 5 metric orchestrator.
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
final class Prompts_Metric {

	/**
	 * Cache key prefix. Bumped when the response shape changes so
	 * cached payloads from a prior shape don't break a deploy.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab5_v1:';

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Resolver used to match BQ paid-conversion attempts against Woo orders.
	 *
	 * @var Woo_Order_Resolver
	 */
	private Woo_Order_Resolver $woo_resolver;

	/**
	 * Per-request memoization for paid-conversion BQ row fetches.
	 *
	 * Keyed by `<query_name>|Ymd|Ymd` of (query_name, start UTC, end UTC). The
	 * conversion-rate and revenue methods for the same intent + same window
	 * share one round-trip to the hub.
	 *
	 * @var array<string, array{rows:array, conversions:int, revenue:float}|\WP_Error>
	 */
	private array $paid_attempt_cache = [];

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
	 * Error payload for a collection whose query succeeded but returned an
	 * unexpected (non-array) shape — a data-quality bug, not an empty window.
	 *
	 * @param string $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @return array
	 */
	private function malformed_collection( string $rows_key ): array {
		return $this->error_collection(
			$rows_key,
			new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) )
		);
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
		// Non-numeric, or (for counts) a non-integer value, signals catalog/schema
		// drift — malformed data, not an empty window. Surface it as an error so a
		// real data-quality regression isn't masked as a benign zero.
		if ( ! is_numeric( $value ) || ( 'count' === $placeholder_type && (float) $value !== (float) (int) $value ) ) {
			return $this->error_scalar(
				$placeholder_type,
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) )
			);
		}
		return $this->populated_scalar( 'count' === $placeholder_type ? (int) $value : (float) $value, true, null, $placeholder_type );
	}

	/**
	 * Fetch paid-conversion rows for a given query and Woo-join them.
	 *
	 * Returns { rows, conversions, revenue } on success or a WP_Error on proxy
	 * failure. Used by both the conversion-rate and revenue methods for the
	 * same intent + direction; the per-(query_name, window) memoization avoids
	 * a redundant round-trip to the hub when both methods run in one request.
	 *
	 * @param string            $query_name One of the 4 distinct paid query names
	 *                                      (the 4 revenue aliases share rows with
	 *                                      their conversion counterparts; pass the
	 *                                      conversion name so the cache is shared).
	 * @param DateTimeInterface $start      Window start.
	 * @param DateTimeInterface $end        Window end.
	 * @return array{rows:array, conversions:int, revenue:float}|\WP_Error
	 */
	private function fetch_paid_attempts_woo_join(
		string $query_name,
		DateTimeInterface $start,
		DateTimeInterface $end
	) {
		// Normalize both bounds to UTC Ymd so callers passing different timezone
		// objects don't bust the cache for the same logical window. Matches the
		// proxy client's own UTC normalization.
		$utc       = new \DateTimeZone( 'UTC' );
		$cache_key = $query_name . '|'
			. \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' )
			. '|'
			. \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' );

		if ( array_key_exists( $cache_key, $this->paid_attempt_cache ) ) {
			return $this->paid_attempt_cache[ $cache_key ];
		}

		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			$this->paid_attempt_cache[ $cache_key ] = $rows;
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
		$this->paid_attempt_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Bridge placeholder for collection methods not yet wired to a catalog query.
	 *
	 * @param string $rows_key Key holding the (empty) collection: 'stages'|'buckets'|'rows'.
	 * @return array
	 */
	private function placeholder_collection( string $rows_key ): array {
		return $this->error_collection(
			$rows_key,
			new \WP_Error(
				'newspack_insights_prompts_not_yet_implemented',
				__( 'This metric will be wired in a follow-up commit (NPPD-1607 Phase 2).', 'newspack-plugin' )
			)
		);
	}

	// --- Section 1: Prompt exposure -------------------------------------

	/**
	 * Total prompt impressions in window.
	 *
	 * Dispatches `prompts_total_impressions`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_prompt_impressions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_total_impressions', 'prompt_impressions', 'count', $start, $end );
	}

	/**
	 * Unique readers who saw at least one prompt.
	 *
	 * Dispatches `prompts_unique_viewers`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_unique_readers_reached( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_unique_viewers', 'unique_prompt_viewers', 'count', $start, $end );
	}

	/**
	 * Average prompt exposures per reader.
	 *
	 * Dispatches `prompts_avg_prompts_per_reader`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_prompts_per_reader( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_avg_prompts_per_reader', 'avg_prompts_per_reader', 'decimal', $start, $end );
	}

	// --- Section 2: Prompt engagement -----------------------------------

	/**
	 * Click-through rate (clicks ÷ impressions).
	 *
	 * Dispatches `prompts_click_through_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_click_through_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_click_through_rate', 'click_through_rate', 'rate', $start, $end );
	}

	/**
	 * Form submission rate (submissions ÷ form-bearing impressions).
	 *
	 * Dispatches `prompts_form_submission_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_form_submission_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_form_submission_rate', 'form_submission_rate', 'rate', $start, $end );
	}

	/**
	 * Dismissal rate (explicit dismissals ÷ impressions).
	 *
	 * Dispatches `prompts_dismissal_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_dismissal_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_dismissal_rate', 'dismissal_rate', 'rate', $start, $end );
	}

	// --- Section 3: Free reader conversion ------------------------------

	/**
	 * Registration conversion rate, direct attribution.
	 *
	 * Dispatches `prompts_registration_conversion_direct`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_registration_conversion_direct', 'registration_conversion_direct', 'rate', $start, $end );
	}

	/**
	 * Registration conversion rate, influenced (7-day lookback).
	 *
	 * Dispatches `prompts_registration_conversion_influenced_7d`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_registration_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_registration_conversion_influenced_7d', 'registration_conversion_influenced', 'rate', $start, $end );
	}

	/**
	 * Newsletter signup conversion rate, direct attribution.
	 *
	 * Dispatches `prompts_newsletter_signup_conversion_direct`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_newsletter_signup_conversion_direct', 'newsletter_signup_conversion_direct', 'rate', $start, $end );
	}

	/**
	 * Newsletter signup conversion rate, influenced (7-day lookback).
	 *
	 * Dispatches `prompts_newsletter_signup_conversion_influenced_7d`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_newsletter_signup_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy( 'prompts_newsletter_signup_conversion_influenced_7d', 'newsletter_signup_conversion_influenced', 'rate', $start, $end );
	}

	// --- Section 4: Paid reader conversion ------------------------------
	//
	// Each rate dispatches the BQ "attempt" query for the intent + direction,
	// Woo-joins the rows via Woo_Order_Resolver, and returns
	// `conversions / attempts`. The BQ-side `action_type` filter on the hub
	// already scopes attempts to the right product intent (donation vs
	// subscription), so the resolver's product-type-agnostic match is safe.
	//
	// v1 simplification: we use attempts as the denominator (not impressions).
	// The spec acknowledges this as a known trade-off (see formulas-doc
	// Section 4). The follow-up is a separate impression-count query; not
	// blocking for this task.

	/**
	 * Donation conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	/**
	 * Donation conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	/**
	 * Subscription conversion rate, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	/**
	 * Subscription conversion rate, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'rate', $joined );
		}
		$denominator = count( $joined['rows'] );
		$numerator   = $joined['conversions'];
		return $this->populated_scalar(
			$denominator > 0 ? $numerator / $denominator : 0.0,
			$denominator > 0,
			$denominator,
			'rate'
		);
	}

	// --- Section 5: Revenue from prompts --------------------------------
	//
	// Each revenue method dispatches the underlying CONVERSION query name (not
	// the byte-identical hub `*_revenue_*` alias) so the per-window cache is
	// shared with the matching rate method — both can be computed from one
	// proxy round-trip when they appear in the same response.

	/**
	 * Total donation revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Total donation revenue from prompts, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donation_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_donation_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_direct', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	/**
	 * Total subscription revenue from prompts, influenced (14-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscription_revenue_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		$joined = $this->fetch_paid_attempts_woo_join( 'prompts_subscription_conversion_influenced_14d', $start, $end );
		if ( is_wp_error( $joined ) ) {
			return $this->error_scalar( 'currency', $joined );
		}
		return $this->populated_scalar( $joined['revenue'], true, $joined['conversions'], 'currency' );
	}

	// --- Section 6: How readers convert ---------------------------------
	// Placeholder bridge — to be wired in Task 3.3 (collections).

	/**
	 * Conversion funnel — three stages (impression → engagement → conversion).
	 * Placeholder.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_conversion_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder_collection( 'stages' );
	}

	/**
	 * Exposures-before-conversion distribution buckets. Placeholder.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_exposures_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder_collection( 'buckets' );
	}

	// --- Section 7: Performance breakdown -------------------------------
	// Placeholder bridge — to be wired in Task 3.3 (collections).

	/**
	 * Per-prompt breakdown. Placeholder.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_performance_by_prompt( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder_collection( 'rows' );
	}

	/**
	 * Per-intent breakdown (donation / registration / newsletter signup). Placeholder.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_performance_by_intent( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder_collection( 'rows' );
	}

	/**
	 * Per-placement breakdown (overlay / inline / above-header / etc.). Placeholder.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_performance_by_placement( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->placeholder_collection( 'rows' );
	}
}
