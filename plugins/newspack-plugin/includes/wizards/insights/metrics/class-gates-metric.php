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
use Newspack\Insights\Subscribers_Metric;

/**
 * Tab 4 metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   state: string,
 *   value: int|float,
 *   computable: bool,
 *   denominator: int|null,
 *   numerator: int|null,
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
	const CACHE_PREFIX = 'newspack_insights_tab4_v2:';

	/**
	 * Data-source classification per metric key (NPPD-1746), the Gates-tab twin of
	 * {@see Prompts_Metric::METRIC_SOURCES}:
	 *
	 *  - 'hub'    — fully hub-backed; errors when the proxy is down.
	 *  - 'local'  — fully local (Woo order meta); survives a hub outage.
	 *  - 'hybrid' — local numerator + hub denominator; a hub failure makes it
	 *               genuinely uncomputable, so it counts as hub-backed for the banner.
	 *
	 * Read by {@see \Newspack\Insights\Gates_REST_Controller::is_window_all_error()}
	 * so the "whole tab failed" banner fires when all hub-backed metrics error, even
	 * though a surviving local card (paywall revenue, sourced from order meta) still
	 * renders. Keys mirror {@see Gates_REST_Controller::build_window()}.
	 *
	 * @var array<string, string>
	 */
	public const METRIC_SOURCES = [
		'total_gate_impressions'             => 'hub',
		'unique_readers_reached'             => 'hub',
		'avg_exposures_per_reader'           => 'hub',
		'sessions_with_gate'                 => 'hub',
		'regwall_conversion_direct'          => 'hub',
		'regwall_conversion_influenced_7d'   => 'hub',
		'paywall_conversion_direct'          => 'hybrid', // NPPD-1746: local order-meta (gate) numerator + hub per-gate-impressions denominator.
		'paywall_conversion_influenced_14d'  => 'hub',    // BQ-internal influenced rate + denominator (no local Woo); see regwall_conversion_influenced_7d.
		'total_paywall_revenue_direct'       => 'local',  // NPPD-1746: pure Woo order meta (gate surface); survives a hub outage.
		'avg_revenue_per_paywall_conversion' => 'local',  // NPPD-1746: derived from the same order-meta source as total revenue.
		'conversion_funnel'                  => 'hub',
		'exposures_distribution'             => 'hub',
		'performance_by_gate'                => 'hybrid', // NPPD-1686: hub per-gate rows + local order-meta paywall conversions.
	];

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Per-request memo for `gates_performance_by_gate` hub rows, keyed by `Ymd|Ymd`
	 * window (NPPD-1746). The direct paywall-rate denominator
	 * ({@see self::fetch_gate_impressions_by_gate()}) and the per-gate table
	 * ({@see self::get_performance_by_gate()}) both read this query for the same
	 * window in one request; memoizing avoids the duplicate hub round-trip (the same
	 * fix NPPD-1745 applied to the prompts performance query).
	 *
	 * @var array<string, array|\WP_Error>
	 */
	private array $performance_by_gate_cache = [];

	/**
	 * Subscribers_Metric collaborator (NPPD-1746). Owns the WC-native subscription
	 * storage used to source the DIRECT paywall conversion + revenue metrics (gate
	 * surface) from order meta.
	 * Lazily built on first direct-paywall call ({@see self::subscribers_metric()}).
	 *
	 * @var Subscribers_Metric|null
	 */
	private ?Subscribers_Metric $subscribers_metric;

	/**
	 * Constructor. Optionally inject collaborators (used in tests).
	 *
	 * @param BigQuery_Proxy_Client|null $proxy              Injected proxy client, or null to lazy-resolve.
	 * @param Subscribers_Metric|null    $subscribers_metric Injected subscribers collaborator (NPPD-1746), or null to lazy-create.
	 */
	public function __construct(
		?BigQuery_Proxy_Client $proxy = null,
		?Subscribers_Metric $subscribers_metric = null
	) {
		$this->proxy              = $proxy ?? new BigQuery_Proxy_Client();
		$this->subscribers_metric = $subscribers_metric;
	}

	/**
	 * Lazily resolve the Subscribers_Metric collaborator (NPPD-1746).
	 *
	 * @return Subscribers_Metric
	 */
	private function subscribers_metric(): Subscribers_Metric {
		if ( null === $this->subscribers_metric ) {
			$this->subscribers_metric = new Subscribers_Metric();
		}
		return $this->subscribers_metric;
	}

	/**
	 * Whether WooCommerce is active. The direct paywall conversion/revenue metrics
	 * read local Woo orders (NPPD-1746), so they no-op to an empty state on non-WC
	 * publishers. Filterable so tests can exercise both paths without toggling a
	 * global class (the class is `final`, so it can't be doubled). Mirrors
	 * {@see Prompts_Metric::woocommerce_active()}. Public because the REST controller
	 * reads it to scope the tab-error banner (a non-WC hybrid card short-circuits
	 * before reaching the hub, so it must not count as a hub-backed survivor).
	 *
	 * @return bool
	 */
	public function woocommerce_active(): bool {
		/** This filter is documented in includes/wizards/insights/metrics/class-prompts-metric.php */
		return (bool) apply_filters( 'newspack_insights_woocommerce_active', class_exists( 'WooCommerce' ) );
	}

	/**
	 * Coherence-guarded conversion-rate value (NPPD-1746), the gate-surface twin of
	 * {@see Prompts_Metric::rate_value()}. Numerator is an order-meta (local,
	 * anonymous-inclusive) conversion count; denominator is the anonymous-inclusive
	 * hub impressions of the SAME gates. Returns a float (a genuine 0.0 when there
	 * are impressions but no conversions) or null when not computable: no impressions
	 * (em-dash), or conversions > impressions (a cross-surface incoherence that must
	 * not render as a >100% rate).
	 *
	 * @param int $conversions Gate-attributed subscription conversions (order meta).
	 * @param int $impressions Matched-population gate impressions (hub).
	 * @return float|null
	 */
	private function rate_value( int $conversions, int $impressions ): ?float {
		if ( $impressions <= 0 ) {
			return null;
		}
		if ( $conversions > $impressions ) {
			return null;
		}
		return (float) $conversions / $impressions;
	}

	/**
	 * Canned fixture payload for UI smoke testing without a BigQuery connection.
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * The variant selects a render path: 'populated' (default), 'empty', 'error'.
	 *
	 * @param string $variant One of 'populated', 'empty', 'error'.
	 * @param bool   $compare Whether comparison was requested; when false the
	 *                        `previous` window is null (no period-over-period deltas).
	 * @return array Full { tab_error, current, previous } response shape.
	 */
	public static function get_fixture( string $variant = 'populated', bool $compare = false ): array {
		$build = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/gates-fixture.php';
		return $build( $variant, $compare );
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
			'numerator'        => null,
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
	 * @param int|null  $numerator        Optional numerator (NPPD-1694). Surfaced only
	 *                                    for rate scorecards whose underlying count is
	 *                                    computed locally (the paywall Woo join); null
	 *                                    everywhere the count isn't available, e.g. the
	 *                                    precomputed-rate regwall cards.
	 * @param bool      $data_missing     True when the payload was built from a schema
	 *                                    that is missing expected columns (drift signal).
	 * @return array
	 */
	private function populated_scalar( $value, bool $computable, ?int $denominator, string $placeholder_type, ?int $numerator = null, bool $data_missing = false ): array {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
			'numerator'        => $numerator,
			'placeholder_type' => $placeholder_type,
			'data_missing'     => $data_missing,
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
		// SAFE_DIVIDE returns NULL when the denominator is zero — a legitimate
		// "no eligible events to compute a rate" case, not a schema regression.
		// Same handling as the missing-key branch above: non-computable zero.
		if ( null === $value ) {
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
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
	 * Compute a regwall conversion rate from a precomputed hub rate, optionally
	 * surfacing the new count fields the empty-state pattern needs (NPPD-1702).
	 *
	 * Unlike the paywall rate (computed locally from a Woo join), the regwall rate
	 * is precomputed server-side by the hub. This method reads that rate exactly as
	 * `compute_metric_from_proxy` would, then *additionally* reads two integer count
	 * columns whose names vary by rate (passed via `$denominator_col` / `$numerator_col`):
	 * the Direct query exposes `registration_impressions_total` (denominator / {N}) and
	 * `registrations_total` (numerator); the converter-denominated Influenced query
	 * (NPPD-1821) exposes `new_registrations_total` and `influenced_registrations_total`.
	 *
	 * The production-safety crux: those columns do not exist in the hub response
	 * yet. When they are absent, this returns numerator + denominator as `null` —
	 * byte-for-byte today's regwall envelope — so the React layer renders the
	 * percentage scorecards and no empty state (graceful degradation). An absent
	 * field is NOT a zero: a present `0` is a real "no impressions" signal, a
	 * missing field is "the hub hasn't deployed yet." The two are kept distinct all
	 * the way to the component. The fields are read as a pair: a half-populated
	 * response (one present, one absent/non-numeric) is treated as absent so a
	 * malformed envelope degrades rather than half-renders a count fallback.
	 *
	 * @param string            $query_name      Catalog name (`gates_regwall_conversion_*`).
	 * @param string            $rate_key        Column holding the precomputed rate.
	 * @param DateTimeInterface $start           Window start.
	 * @param DateTimeInterface $end             Window end.
	 * @param string            $denominator_col Column holding the denominator count ({N}).
	 * @param string            $numerator_col   Column holding the numerator count.
	 * @return array
	 */
	private function compute_regwall_rate_from_proxy(
		string $query_name,
		string $rate_key,
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $denominator_col = 'registration_impressions_total',
		string $numerator_col = 'registrations_total'
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'rate', $rows );
		}
		if ( empty( $rows ) || ! is_array( $rows[0] ) || ! array_key_exists( $rate_key, $rows[0] ) ) {
			// Query succeeded with no usable value → non-computable zero. Mirrors
			// compute_metric_from_proxy exactly, including the int 0 it uses for a
			// non-decimal placeholder (a rate). Counts stay null: nothing to surface.
			return $this->populated_scalar( 0, false, null, 'rate' );
		}
		$row   = $rows[0];
		$value = $row[ $rate_key ];
		// SAFE_DIVIDE NULL: legitimate "no eligible events", non-computable zero.
		if ( null === $value ) {
			return $this->populated_scalar( 0, false, null, 'rate' );
		}
		// Non-numeric rate signals catalog/schema drift — surface as an error so a
		// real data-quality regression isn't masked as a benign zero.
		if ( ! is_numeric( $value ) ) {
			return $this->error_scalar(
				'rate',
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) )
			);
		}
		// Read the denominator + numerator count columns iff BOTH are present and
		// integer-valued. Column names vary by rate: the Direct query exposes
		// `registration_impressions_total` / `registrations_total`; the converter-
		// denominated Influenced query (NPPD-1821) exposes `new_registrations_total` /
		// `influenced_registrations_total`. Absent → null counts → today's envelope.
		$impressions = $this->read_optional_count( $row, $denominator_col );
		$registrations = $this->read_optional_count( $row, $numerator_col );
		if ( null === $impressions || null === $registrations ) {
			return $this->populated_scalar( (float) $value, true, null, 'rate' );
		}
		return $this->populated_scalar( (float) $value, true, $impressions, 'rate', $registrations );
	}

	/**
	 * Read an optional non-negative integer column from a proxy row (NPPD-1702).
	 *
	 * Returns the int when the key is present and integer-valued (a float like 3.7
	 * is rejected as catalog drift), or `null` when the key is absent or not a
	 * clean integer. Distinguishing "absent" from "0" is the whole point: callers
	 * use `null` to mean "the hub hasn't deployed this field yet."
	 *
	 * @param array  $row Proxy row.
	 * @param string $key Column name.
	 * @return int|null
	 */
	private function read_optional_count( array $row, string $key ): ?int {
		if ( ! array_key_exists( $key, $row ) || null === $row[ $key ] ) {
			return null;
		}
		$raw = $row[ $key ];
		if ( ! is_numeric( $raw ) || (float) $raw !== (float) (int) $raw ) {
			return null;
		}
		return (int) $raw;
	}

	/**
	 * Read a BQ-internal influenced rate metric: one row carrying a precomputed
	 * SAFE_DIVIDE rate (null when there are no converters) and an integer denominator.
	 *
	 * @param string            $query_name      Catalog query name.
	 * @param string            $rate_key        Rate column key.
	 * @param string            $denominator_key Denominator column key.
	 * @param DateTimeInterface $start           Window start.
	 * @param DateTimeInterface $end             Window end.
	 * @return array
	 */
	private function compute_influenced_rate_from_proxy(
		string $query_name,
		string $rate_key,
		string $denominator_key,
		DateTimeInterface $start,
		DateTimeInterface $end
	): array {
		$rows = $this->proxy->query( $query_name, $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_scalar( 'rate', $rows );
		}
		if ( empty( $rows ) ) {
			return $this->populated_scalar( 0.0, false, null, 'rate' );
		}
		if ( ! is_array( $rows[0] ) || ! array_key_exists( $rate_key, $rows[0] ) || ! array_key_exists( $denominator_key, $rows[0] ) ) {
			return $this->populated_scalar( 0.0, false, null, 'rate', null, true );
		}
		$denominator = $rows[0][ $denominator_key ];
		// The denominator is a BigQuery COUNT(DISTINCT) — a non-negative integer. Reject any
		// non-integer numeric (e.g. 8.5) rather than silently truncating it, matching the strict
		// count-field validation used elsewhere in this class. The ported Conversion_Metric reader
		// is looser here; hardening it is a separate follow-up.
		if ( ! is_numeric( $denominator ) || (float) $denominator !== (float) (int) $denominator ) {
			return $this->error_scalar(
				'rate',
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-integer denominator.', 'newspack-plugin' ) )
			);
		}
		$denominator = (int) $denominator;
		$rate        = $rows[0][ $rate_key ];
		if ( null === $rate ) {
			return $this->populated_scalar( 0.0, false, $denominator, 'rate' );
		}
		if ( ! is_numeric( $rate ) ) {
			return $this->error_scalar(
				'rate',
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-numeric value.', 'newspack-plugin' ) )
			);
		}
		return $this->populated_scalar( (float) $rate, $denominator > 0, $denominator, 'rate' );
	}

	/**
	 * Fetch (memoized per window) the `gates_performance_by_gate` hub rows, so the
	 * direct paywall-rate denominator and the per-gate table share one round-trip
	 * per request (NPPD-1746).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array|\WP_Error Rows, or the proxy error.
	 */
	private function fetch_performance_by_gate_rows( DateTimeInterface $start, DateTimeInterface $end ) {
		$utc       = new DateTimeZone( 'UTC' );
		$cache_key = \DateTimeImmutable::createFromInterface( $start )->setTimezone( $utc )->format( 'Ymd' )
			. '|'
			. \DateTimeImmutable::createFromInterface( $end )->setTimezone( $utc )->format( 'Ymd' );
		if ( ! array_key_exists( $cache_key, $this->performance_by_gate_cache ) ) {
			$this->performance_by_gate_cache[ $cache_key ] = $this->proxy->query( 'gates_performance_by_gate', $start, $end );
		}
		return $this->performance_by_gate_cache[ $cache_key ];
	}

	/**
	 * Per-gate impressions map from the hub's `gates_performance_by_gate` rows
	 * (NPPD-1746), keyed by gate_post_id (string). Used as the per-gate-keyed
	 * denominator source for the direct paywall rate: the rate restricts its
	 * denominator to the impressions of the gates that actually converted (see
	 * {@see self::get_paywall_conversion_direct()}).
	 *
	 * NOTE (denominator precision): the ideal denominator is `checkout_impressions`
	 * (impressions on gates carrying a checkout button — the paywall-capable subset).
	 * The hub query already COMPUTES it directly (`COUNTIF(has_checkout_button='yes')`
	 * over `np_gate_interaction(seen)` events — not derived from attempts); NPPD-1749
	 * adds it to the query's output columns. This reader PREFERS it when present and
	 * falls back to total `impressions` until that hub change ships — forward-
	 * compatible, correct both before and after. Total `impressions` equals
	 * `checkout_impressions` for a pure paywall gate and OVERcounts for a
	 * registration-heavy mixed gate (where the rate then reads low — conservative,
	 * never inflated; on Tab 4's live membership gates the dilution is large, hence
	 * NPPD-1749). Anonymous-inclusive either way, so no anonymous-at-attempt bias.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<string, int>|\WP_Error gate_post_id => impressions, or the proxy error.
	 */
	private function fetch_gate_impressions_by_gate( DateTimeInterface $start, DateTimeInterface $end ) {
		$rows = $this->fetch_performance_by_gate_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! is_array( $rows ) ) {
			// Malformed hub response (not an array of rows): surface it so the paywall
			// rate errors instead of silently dividing by a fabricated 0 denominator
			// (NPPD-1745 #3, mirrored proactively to the paywall rate).
			return new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) );
		}
		$map = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$gate_id = (string) ( $row['gate_post_id'] ?? '' );
			if ( '' === $gate_id || '0' === $gate_id ) {
				continue;
			}
			// Prefer the paywall-capable subset (NPPD-1749) once the hub exposes it;
			// fall back to total gate impressions until then.
			$map[ $gate_id ] = isset( $row['checkout_impressions'] )
				? (int) $row['checkout_impressions']
				: (int) ( $row['impressions'] ?? 0 );
		}
		return $map;
	}

	/**
	 * Per-gate `checkout_impressions` map (NPPD-1817) from the hub's
	 * `gates_performance_by_gate` rows, keyed by gate post id (string). Used to
	 * capability-RESTRICT the direct paywall rate: a gate is paywall-capable when its
	 * `checkout_impressions` (seen events on the gate carrying a checkout button) is
	 * > 0, so the rate's numerator (conversions) shares its capable-impressions
	 * denominator's population and reconciles with the per-gate table, which credits a
	 * paywall conversion only to a capable gate. Without this, a converting-but-not-
	 * capable gate inflates the numerator over a denominator that excludes its
	 * impressions (the NPPD-1746 scalar/table divergence class).
	 *
	 * Distinct from {@see self::fetch_gate_impressions_by_gate()}, which conflates a
	 * present-but-zero `checkout_impressions` with the pre-column total-impressions
	 * fallback; this map exposes only rows that actually carry the column, returning
	 * `null` when none do (hub hasn't shipped NPPD-1749) so the caller keeps the
	 * pre-column per-gate-keyed denominator.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<string, int>|null|\WP_Error Map, null if the column is absent, or the proxy error.
	 */
	private function fetch_checkout_impressions_by_gate( DateTimeInterface $start, DateTimeInterface $end ) {
		$rows = $this->fetch_performance_by_gate_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'bigquery_proxy_malformed_rows', __( 'The query returned an unexpected shape.', 'newspack-plugin' ) );
		}
		$map = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['checkout_impressions'] ) ) {
				continue;
			}
			$gate_id = (string) ( $row['gate_post_id'] ?? '' );
			if ( '' === $gate_id || '0' === $gate_id ) {
				continue;
			}
			if ( null === $map ) {
				$map = [];
			}
			$map[ $gate_id ] = (int) $row['checkout_impressions'];
		}
		return $map;
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
		return $this->compute_regwall_rate_from_proxy( 'gates_regwall_conversion_direct', 'regwall_conversion_rate_direct', $start, $end );
	}

	/**
	 * Regwall conversion rate, influenced (7-day lookback).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_regwall_conversion_influenced_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1821: the influenced query is converter-denominated, so its count columns
		// are `new_registrations_total` (denominator) / `influenced_registrations_total`
		// (numerator), not the Direct query's `registration_impressions_total` / `registrations_total`.
		return $this->compute_regwall_rate_from_proxy( 'gates_regwall_conversion_influenced_7d', 'regwall_conversion_influenced', $start, $end, 'new_registrations_total', 'influenced_registrations_total' );
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
		// NPPD-1746/1817: rate = gate-attributed subscription conversions (Woo order
		// meta, anonymous-inclusive) ÷ paywall-capable gate impressions (hub,
		// `gates_performance_by_gate`). When the hub exposes per-gate `checkout_impressions`
		// (NPPD-1749), numerator and denominator are both restricted to paywall-CAPABLE
		// gates so they share a population and reconcile with the per-gate table; until
		// then it falls back to the per-gate-keyed total-impressions denominator over the
		// gates that converted. Numerator local, denominator hub → 'hybrid' (see the
		// Gates controller METRIC_SOURCES): a hub impressions failure makes the rate
		// genuinely uncomputable and counts toward the tab-error banner. Same coherence
		// guard as the prompts subscription/donation rate-direct. Replaces the prior
		// attempt-row denominator (`gates_paywall_conversion_direct`), which undercounted
		// via the GA4 cookie → customer_id join.
		if ( ! $this->woocommerce_active() ) {
			// Non-WC publisher: no local conversions to count. Empty state, not a fake
			// 0% (NPPD-1737 Option A scoping).
			return $this->populated_scalar( 0.0, false, 0, 'rate', 0 );
		}

		$by_gate                = $this->subscribers_metric()->get_attributed_subscription_conversions( $start, $end )['by_gate'];
		$capability_impressions = $this->fetch_checkout_impressions_by_gate( $start, $end );
		if ( is_wp_error( $capability_impressions ) ) {
			return $this->error_scalar( 'rate', $capability_impressions );
		}

		$conversions = 0;
		$impressions = 0;
		if ( is_array( $capability_impressions ) ) {
			// NPPD-1817: the hub exposes per-gate `checkout_impressions` (NPPD-1749), so
			// restrict BOTH sides to paywall-CAPABLE gates (checkout_impressions > 0) — a
			// tab-level capable denominator that reconciles with the per-gate table (which
			// credits a paywall conversion only to a capable gate). A converting-but-not-
			// capable gate is excluded from both the numerator and the denominator.
			foreach ( $capability_impressions as $gate_id => $gate_impressions ) {
				if ( $gate_impressions <= 0 ) {
					continue;
				}
				$impressions += $gate_impressions;
				$conversions += (int) ( $by_gate[ $gate_id ]['conversions'] ?? 0 );
			}
		} else {
			// Forward-compat fallback (hub hasn't shipped `checkout_impressions` yet):
			// per-gate-keyed total impressions, restricted to the gates that converted.
			$impressions_by_gate = $this->fetch_gate_impressions_by_gate( $start, $end );
			if ( is_wp_error( $impressions_by_gate ) ) {
				return $this->error_scalar( 'rate', $impressions_by_gate );
			}
			foreach ( $by_gate as $gate_id => $row ) {
				$conversions += (int) $row['conversions'];
				$impressions += (int) ( $impressions_by_gate[ (string) $gate_id ] ?? 0 );
			}
		}

		$rate = $this->rate_value( $conversions, $impressions );
		return null === $rate
			? $this->populated_scalar( 0.0, false, $impressions, 'rate', $conversions )
			: $this->populated_scalar( $rate, true, $impressions, 'rate', $conversions );
	}

	/**
	 * Paywall conversion rate, influenced (14-day lookback).
	 *
	 * BQ-internal rate + denominator (hub computes it; no Woo join). Replaces the prior
	 * attempt-row path which undercounted via the GA4 cookie → customer_id cast.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_paywall_conversion_influenced_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_rate_from_proxy(
			'gates_paywall_conversion_influenced_14d',
			'paywall_conversion_influenced_rate',
			'conversion_denominator',
			$start,
			$end
		);
	}

	/**
	 * Total revenue from paywall conversions, direct attribution.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_total_paywall_revenue_direct( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1746: source DIRECT paywall revenue from order meta (gate surface —
		// `_gate_post_id` on initial subscription orders) instead of the GA4 attempt
		// → customer_id join. Mirrors the prompts subscription revenue-direct. Local /
		// hub-resilient: the em-dash is conversions-based ("no gate-attributed
		// subscriptions"), not impressions-based — revenue does not fetch impressions
		// by design, so its em-dash semantics differ on purpose from the sibling
		// (hybrid) rate card.
		if ( ! $this->woocommerce_active() ) {
			// Non-WC publisher: no orders to read. Empty state, not a real $0.
			return $this->populated_scalar( 0.0, false, 0, 'currency' );
		}
		[ $conversions, $revenue ] = $this->sum_gate_attributed_subscriptions( $start, $end );
		return $this->populated_scalar( $revenue, $conversions > 0, $conversions, 'currency' );
	}

	/**
	 * Sum gate-attributed subscription conversions + revenue (order meta) for the
	 * window — the shared local numerator behind both paywall revenue-direct cards
	 * (NPPD-1746), so total revenue and average-per-conversion read one source and
	 * reconcile (total = avg × conversions).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{0:int, 1:float} [ conversions, revenue ].
	 */
	private function sum_gate_attributed_subscriptions( DateTimeInterface $start, DateTimeInterface $end ): array {
		$by_gate     = $this->subscribers_metric()->get_attributed_subscription_conversions( $start, $end )['by_gate'];
		$conversions = 0;
		$revenue     = 0.0;
		foreach ( $by_gate as $row ) {
			$conversions += (int) $row['conversions'];
			$revenue     += (float) $row['revenue'];
		}
		return [ $conversions, $revenue ];
	}

	/**
	 * Average revenue per paywall conversion.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_avg_revenue_per_paywall_conversion( DateTimeInterface $start, DateTimeInterface $end ): array {
		// NPPD-1746: derived from the SAME order-meta source as
		// get_total_paywall_revenue_direct() so the two reconcile (total = avg ×
		// conversions). On a non-WC publisher there are no orders → empty state.
		if ( ! $this->woocommerce_active() ) {
			return $this->populated_scalar( 0.0, false, 0, 'currency' );
		}
		[ $conversions, $revenue ] = $this->sum_gate_attributed_subscriptions( $start, $end );
		// No conversions → a real $0.00 average, flagged non-computable.
		return $this->populated_scalar(
			$conversions > 0 ? $revenue / $conversions : 0.0,
			$conversions > 0,
			$conversions,
			'currency'
		);
	}

	/**
	 * Paid-section totals for the empty-state gate (NPPD-1694).
	 *
	 * Pure derivation from already-computed scalars — no extra query. The
	 * section component reads these to choose between the normal scorecard
	 * render and an `<EmptyMetricSection>`:
	 *   - `paywall_impressions_total` = the Direct rate denominator (sessions with a
	 *     paywall impression; this is the {N} in the "no conversions" copy).
	 *   - `paywall_conversions_total` = the most inclusive conversion count across
	 *     attributions — `max` of the Direct numerator (gate-attributed Woo
	 *     conversions) and the Influenced *denominator*. The BQ-internal Influenced
	 *     metric reports `conversion_denominator` (COUNT(DISTINCT) of paywall
	 *     converters in the window) and no numerator, so reading its denominator is
	 *     what keeps an Influenced-only window (Direct 0, Influenced rate positive)
	 *     rendering its scorecards rather than hiding real data behind a "no
	 *     conversions" empty state.
	 *
	 * @param array $direct     The `paywall_conversion_direct` scalar payload.
	 * @param array $influenced The `paywall_conversion_influenced_14d` scalar payload.
	 * @return array{paywall_impressions_total:int, paywall_conversions_total:int}
	 */
	public static function paywall_section_totals( array $direct, array $influenced ): array {
		return [
			'paywall_impressions_total' => (int) ( $direct['denominator'] ?? 0 ),
			'paywall_conversions_total' => max(
				(int) ( $direct['numerator'] ?? 0 ),
				(int) ( $influenced['denominator'] ?? 0 )
			),
		];
	}

	/**
	 * Free-section totals for the empty-state gate (NPPD-1702).
	 *
	 * The Free counterpart to `paywall_section_totals`, with one deliberate and
	 * load-bearing difference: it returns `int|null`, never coercing a missing
	 * count to `0`. `null` means "the hub hasn't deployed the count fields yet"
	 * (the `compute_regwall_rate_from_proxy` numerator/denominator are null in that
	 * case); the React layer reads it to *degrade to today's percentage render*
	 * rather than show a false `no_opportunity`. Coercing to 0 here — as the
	 * paywall helper does, where the denominator always exists because it's
	 * computed locally — would silently break a working production section the
	 * moment this ships, with no hub change involved. An absent field is not a zero.
	 *
	 *   - `registration_impressions_total` = the Direct rate denominator (sessions
	 *     with a registration gate impression; the {N} in the "no registrations"
	 *     copy). Mirrors paywall keying attempts off the Direct denominator.
	 *   - `registrations_total` = the most inclusive registration count across
	 *     attributions (max of Direct and Influenced numerators), so a section with
	 *     Influenced-only registrations still renders its scorecards. `null` only
	 *     when neither attribution carries a count (fields absent).
	 *
	 * @param array $direct     The `regwall_conversion_direct` scalar payload.
	 * @param array $influenced The `regwall_conversion_influenced_7d` scalar payload.
	 * @return array{registration_impressions_total:int|null, registrations_total:int|null}
	 */
	public static function regwall_section_totals( array $direct, array $influenced ): array {
		// `isset` is intentional: it treats a present-but-null denominator the same
		// as a missing key — both mean "no count from the hub" → null. A present 0
		// passes through as 0 (a real "no impressions" signal, NOT degradation).
		$impressions = isset( $direct['denominator'] ) ? (int) $direct['denominator'] : null;

		$direct_regs     = $direct['numerator'] ?? null;
		$influenced_regs = $influenced['numerator'] ?? null;
		$registrations   = ( null === $direct_regs && null === $influenced_regs )
			? null
			: max( (int) $direct_regs, (int) $influenced_regs );

		return [
			'registration_impressions_total' => $impressions,
			'registrations_total'            => $registrations,
		];
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
			return $this->malformed_collection( 'stages' );
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
			return $this->malformed_collection( 'buckets' );
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
		$rows = $this->fetch_performance_by_gate_rows( $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'rows', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'rows' );
		}
		if ( empty( $rows ) ) {
			return [
				'state' => 'empty',
				'rows'  => [],
			];
		}

		// NPPD-1686: per-gate PAYWALL CONVERSIONS replace the old engagement-intent
		// paywall_attempts. Numerator = gate-attributed subscription conversions from Woo
		// order meta (`_gate_post_id`), anonymous-inclusive, keyed per gate; denominator = the
		// gate's checkout-capable impressions (`checkout_impressions`, NPPD-1749). Same
		// order-meta + capability model as the scalar paywall rate-direct and the prompts
		// donation column (NPPD-1746/1757): a gate is paywall-capable when checkout_impressions
		// > 0, so a regwall-only gate (0) gets null paywall columns (em-dash) while a capable
		// gate with zero completions shows a real 0 / 0%. Both are WC-gated (order meta + Woo
		// tables) → non-WC publishers get nulls. Unlike the scalar, the table does NOT fall back
		// to total impressions when checkout_impressions is absent: a paywall rate over total
		// (regwall-inclusive) impressions would badly overcount, so an absent column degrades to
		// em-dash rather than a wrong number.
		$wc      = $this->woocommerce_active();
		$by_gate = $wc ? $this->subscribers_metric()->get_attributed_subscription_conversions( $start, $end )['by_gate'] : [];

		$mapped = [];
		foreach ( $rows as $row ) {
			$gate_post_id        = (int) ( $row['gate_post_id'] ?? 0 );
			$paywall_impressions = isset( $row['checkout_impressions'] ) ? (int) $row['checkout_impressions'] : null;
			$paywall_capable     = $wc && null !== $paywall_impressions && $paywall_impressions > 0;
			$paywall_conversions = $paywall_capable ? (int) ( $by_gate[ $gate_post_id ]['conversions'] ?? 0 ) : 0;

			$mapped[] = [
				'gate_post_id'            => $gate_post_id,
				'gate_name'               => null, // filled below by enrich_with_gate_titles().
				'impressions'             => (int) ( $row['impressions'] ?? 0 ),
				'unique_viewers'          => (int) ( $row['unique_viewers'] ?? 0 ),
				'registrations'           => (int) ( $row['registrations'] ?? 0 ),
				'regwall_conversion_rate' => isset( $row['regwall_conversion_rate'] ) && null !== $row['regwall_conversion_rate'] ? (float) $row['regwall_conversion_rate'] : null,
				'paywall_conversions'     => $paywall_capable ? $paywall_conversions : null,
				'paywall_conversion_rate' => $paywall_capable ? $this->rate_value( $paywall_conversions, (int) $paywall_impressions ) : null,
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
