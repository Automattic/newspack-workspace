<?php
/**
 * Newspack Insights — Conversion Journey Metric orchestrator (NPPD-1609, Phase 2A).
 *
 * Tab 3 (Conversion Journey) metric orchestrator. Phase A is complete: every
 * metric is wired to live data — BigQuery via the proxy (lifecycle + anon→
 * registered funnels, source-mix registrations, time-to-register, weekly rates,
 * influenced registration/newsletter, top pages), BigQuery + Woo 30-min join
 * (registered→subscriber/donor funnels, source-mix subscribers/donors,
 * influenced subscription/donation), and pure-Woo via the Subscribers/Donors
 * storage layer (subscriber→donor funnel, at-risk/lapsed/stale opportunity
 * counts). The five Phase-B sections — time-to-subscribe/donate (4.2/4.3),
 * subscriber→donor lag (4.4), and the two cohorts (5.1/5.2) — return
 * `state: 'coming_soon'` until Phase B lands.
 *
 * Mirrors {@see Prompts_Metric} (Tab 5): same `state`-envelope shape for
 * scalars, same ordered-collection shape for viz, same per-method window
 * signature. Conversion Journey is the widest Insights tab (eight sections,
 * 23 metrics) but the per-metric envelope is identical to the per-surface tabs.
 *
 * Method-signature contract: every method takes the current window
 * (`$start`, `$end`) for parity with the other tabs. The previous-window
 * comparison is a controller concern — {@see Conversion_REST_Controller}
 * builds the `current` and `previous` windows by calling the same methods
 * twice — so individual methods never see the comparison window. Only
 * Section 7 (cross-tab influenced attribution) renders deltas in the UI.
 *
 * Snapshot metrics — Section 5 cohorts and Sections 8.1–8.3 — are
 * current-state, not windowed: they accept `$start`/`$end` for signature
 * consistency but ignore them (noted per-method). Phase 2 computes them
 * independent of the date picker.
 *
 * Local-only metrics that do NOT belong in the BQ catalog (flagged per
 * method for the Phase 2 handoff): the Subscriber → Donor funnel (2.4),
 * the Subscriber → Donor lag distribution (4.4), the subscriber retention
 * cohort (5.2), and the three opportunity-bucket counts (8.1–8.3). These
 * are Woo-only (or Woo-plus-a-recently-active-UID-set), computed locally.
 *
 * Phase 2 (Phase A) note: this class now also carries the state-envelope
 * helpers copied from {@see Prompts_Metric} — error_scalar, populated_scalar,
 * error_collection, malformed_collection, compute_metric_from_proxy, and
 * compute_influenced_rate_from_proxy — plus coming_soon helpers for the deferred
 * (Phase B) sections. Wired methods report an explicit `state`
 * ('error' | 'empty' | 'populated'), replacing the Phase 1 `pending` flag;
 * deferred-section methods report `state: 'coming_soon'`.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use Newspack\Insights\BigQuery_Proxy_Client;
use Newspack\Insights\Donors_Metric;
use Newspack\Insights\Source_Matcher;
use Newspack\Insights\Subscribers_Metric;

/**
 * Tab 3 metric orchestrator.
 *
 * @phpstan-type ScalarMetric array{
 *   state: string,
 *   value: int|float,
 *   computable: bool,
 *   denominator: int|null,
 *   placeholder_type: string,
 *   data_missing: bool,
 *   error_code?: string,
 *   error_message?: string,
 * }
 */
final class Conversion_Metric {

	/**
	 * Tab slug used as the Cache namespace for Section 5 snapshots.
	 *
	 * @var string
	 */
	const TAB_SLUG = 'conversion';

	/**
	 * Cache key parts for the combined cohort snapshot entry.
	 *
	 * @var string
	 */
	const SNAPSHOT_KEY = 'cohorts';

	/**
	 * Action Scheduler action name for a one-off cohort snapshot refresh
	 * (triggered on cold-cache misses from the request path).
	 *
	 * @var string
	 */
	const COHORT_REFRESH_ACTION = 'newspack_insights_conversion_cohort_refresh';

	/**
	 * Action Scheduler action name for the weekly recurring cohort pre-warm.
	 *
	 * @var string
	 */
	const COHORT_REFRESH_WEEKLY_ACTION = 'newspack_insights_conversion_cohort_refresh_weekly';

	/**
	 * Action Scheduler group for all cohort-refresh jobs.
	 *
	 * @var string
	 */
	const COHORT_REFRESH_GROUP = 'newspack-insights';

	/**
	 * The three acquisition surfaces every source-attributed metric splits
	 * by. Machine keys (not translated) — the React layer maps them to
	 * display labels. Shared by the Section 3 PieCharts and the Section 4
	 * multi-series cumulative distributions.
	 *
	 * @var string[]
	 */
	const SOURCES = [ 'gate', 'prompt', 'direct' ];

	/**
	 * Data-source classification per metric key (NPPD-1745 fix for Tab 3). Declares,
	 * explicitly, whether each card depends on the hub (BigQuery proxy), on local Woo
	 * order data, or both:
	 *
	 *  - 'hub'    — fully hub-backed; errors when the proxy is down.
	 *  - 'local'  — fully local (Woo order meta or Woo storage layer); survives a
	 *               hub outage.
	 *  - 'hybrid' — local numerator + hub denominator (or vice-versa); a hub
	 *               failure makes it genuinely uncomputable, so it counts as
	 *               hub-backed for the tab-error banner.
	 *
	 * Read by {@see \Newspack\Insights\Conversion_REST_Controller::is_window_all_error()}
	 * so the "whole tab failed" banner fires when **all hub-backed metrics** error,
	 * even though surviving local cards still render — instead of being silently
	 * suppressed by a Woo-only card.
	 *
	 * MIGRATION CHECKLIST: when a card moves from hub to order-meta sourcing, update
	 * its entry here (→ 'local' or 'hybrid'). Keys mirror
	 * {@see Conversion_REST_Controller::build_window()} +
	 * {@see Conversion_REST_Controller::build_snapshot()}.
	 *
	 * COHERENCE GUARD (NPPD-1746): any card that becomes 'hybrid' — a local order-meta
	 * numerator over a hub denominator — inherits a cross-source mismatch: the
	 * complete, server-side order-meta numerator can EXCEED the GA4-undercounted hub
	 * denominator, yielding a >100% / incoherent rate. The lifecycle funnels
	 * (registered → subscriber/donor) are still 'hub' on both sides today, but when
	 * their numerators move to order meta they MUST adopt the same guard the
	 * Prompts/Gates direct rates use ({@see \Newspack\Insights\Gates_Metric::rate_value()}):
	 * suppress to not-computable (em-dash) when numerator > denominator rather than
	 * rendering >100%. Don't let a funnel go 'hybrid' without it.
	 *
	 * NOTE: source_mix_subscribers and source_mix_donors are 'hybrid' because their
	 * numerator is local Woo order-meta and their denominator (total registrations
	 * bucket) comes from the hub. They will migrate to 'local' when the order-meta
	 * rework is complete.
	 *
	 * @var array<string, string>
	 */
	public const METRIC_SOURCES = [
		'reader_lifecycle_funnel'              => 'hub',
		'anonymous_to_registered_funnel'       => 'hub',
		'registered_to_subscriber_funnel'      => 'hub',
		'registered_to_donor_funnel'           => 'hub',
		'subscriber_to_donor_funnel'           => 'local',   // 2.4 — Woo-only, visibility-gated.
		'source_mix_registrations'             => 'hub',
		'source_mix_subscribers'               => 'hybrid',  // Woo records numerator + hub source; errors on hub outage.
		'source_mix_donors'                    => 'hybrid',  // Woo records numerator + hub source; errors on hub outage.
		'time_to_register_distribution'        => 'hub',     // 4.1 — BQ.
		'time_to_subscribe_distribution'       => 'local',   // 4.2 — coming_soon stub; never errors.
		'time_to_donate_distribution'          => 'local',   // 4.3 — coming_soon stub; never errors.
		'subscriber_to_donor_lag_distribution' => 'local',   // 4.4 — Woo.
		'registration_to_conversion_cohort'    => 'local',   // 5.1 — coming_soon / Woo.
		'subscriber_retention_cohort'          => 'local',   // 5.2 — Woo.
		'weekly_conversion_rates'              => 'hub',
		'influenced_registration_rate_7d'      => 'hub',
		'influenced_subscription_rate_14d'     => 'hub',
		'influenced_donation_rate_14d'         => 'hub',
		'influenced_newsletter_rate_7d'        => 'hub',
		'top_pages_no_conversion'              => 'hub',
		'stale_registered_count'               => 'local',   // 8.1 — Woo.
		'at_risk_subscriber_count'             => 'local',   // 8.2 — Woo.
		'lapsed_donor_count'                   => 'local',   // 8.3 — Woo.
	];

	/**
	 * Proxy client used to dispatch catalog queries to the hub.
	 *
	 * @var BigQuery_Proxy_Client
	 */
	private BigQuery_Proxy_Client $proxy;

	/**
	 * Per-request memo for registration_source_events() BQ round-trip.
	 *
	 * Computed once on first call; subsequent calls within the same request
	 * return the cached result. Nullable so null = not-yet-fetched.
	 *
	 * @var array<int, array{ts:int, source:string}>|null
	 */
	private ?array $registration_source_events_cache = null;

	/**
	 * Subscribers_Metric collaborator for Section 8 opportunity counts and
	 * the Registered → Subscriber / Subscriber → Donor funnels.
	 *
	 * @var Subscribers_Metric
	 */
	private Subscribers_Metric $subscribers_metric;

	/**
	 * Donors_Metric collaborator for Section 8 lapsed-donor count and the
	 * Registered → Donor / Subscriber → Donor funnels.
	 *
	 * @var Donors_Metric
	 */
	private Donors_Metric $donors_metric;

	/**
	 * Per-request memo for the subscription/donation configuration-matrix gates
	 * (NPPD-1742). Null until first resolved; both are current-state (not
	 * windowed), so they're computed once and reused across the current/previous
	 * windows the controller builds.
	 *
	 * @var bool|null
	 */
	private ?bool $subscription_leg_configured = null;

	/**
	 * Donation-leg config-matrix gate memo; see $subscription_leg_configured.
	 *
	 * @var bool|null
	 */
	private ?bool $donation_leg_configured = null;

	/**
	 * Minimum cohort size required to show the Subscriber → Donor funnel
	 * (active subscribers AND active donors must both meet this threshold).
	 *
	 * @var int
	 */
	const MIN_COHORT_FOR_SUB_TO_DONOR = 50;

	/**
	 * Maximum months-since offset rendered on a cohort retention series (5.1/5.2).
	 *
	 * @var int
	 */
	const COHORT_MAX_MONTHS = 12;

	/**
	 * Constructor. Optionally inject collaborators (used in tests).
	 *
	 * @param BigQuery_Proxy_Client|null $proxy              Injected proxy client, or null to lazy-resolve.
	 * @param Subscribers_Metric|null    $subscribers_metric Injected Subscribers_Metric, or null to lazy-create.
	 * @param Donors_Metric|null         $donors_metric      Injected Donors_Metric, or null to lazy-create.
	 */
	public function __construct(
		?BigQuery_Proxy_Client $proxy = null,
		?Subscribers_Metric $subscribers_metric = null,
		?Donors_Metric $donors_metric = null
	) {
		$this->proxy              = $proxy ?? new BigQuery_Proxy_Client();
		$this->subscribers_metric = $subscribers_metric ?? new Subscribers_Metric();
		$this->donors_metric      = $donors_metric ?? new Donors_Metric();
	}

	/**
	 * Whether WooCommerce is active. Local-only metrics (subscriber→donor funnel,
	 * opportunity counts, lag distribution) read Woo storage, so they no-op on
	 * non-WC publishers. Filterable so tests can exercise both paths without toggling
	 * a global class (the class is `final`, so it can't be doubled). Public because
	 * the REST controller reads it to scope the tab-error banner: on a non-WC
	 * publisher a hybrid card short-circuits before reaching the hub, so it must not
	 * count as a hub-backed survivor (mirrors the identical pattern in
	 * {@see \Newspack\Insights\Prompts_Metric::woocommerce_active()}, NPPD-1745).
	 *
	 * @return bool
	 */
	public function woocommerce_active(): bool {
		/**
		 * Filters whether Insights treats WooCommerce as active for the
		 * local Woo order-meta and storage-layer metrics on Tab 3.
		 *
		 * @param bool $active Whether the WooCommerce class is loaded.
		 */
		return (bool) apply_filters( 'newspack_insights_woocommerce_active', class_exists( 'WooCommerce' ) );
	}

	/**
	 * Return the canned fixture payload for the Conversion Journey tab.
	 *
	 * Returned by the REST controller when NEWSPACK_INSIGHTS_FIXTURE_MODE is on.
	 * The variant selects a render path: 'populated' (default), 'empty', 'error'.
	 *
	 * @param string $variant One of 'populated', 'empty', 'error'.
	 * @param bool   $compare Whether comparison was requested; when false the
	 *                        `previous` window is null (no period-over-period deltas).
	 * @return array Full { tab_error, current, previous } response shape.
	 */
	public static function get_fixture( string $variant = 'populated', bool $compare = false ): array {
		$build = require NEWSPACK_ABSPATH . 'includes/wizards/insights/fixtures/conversion-fixture.php';
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
			'placeholder_type' => $placeholder_type,
			'data_missing'     => false,
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
	 * @param bool      $data_missing     True when the row is present but lacks required columns (schema drift).
	 * @return array
	 */
	private function populated_scalar( $value, bool $computable, ?int $denominator, string $placeholder_type, bool $data_missing = false ): array {
		return [
			'state'            => 'populated',
			'value'            => $value,
			'computable'       => $computable,
			'denominator'      => $denominator,
			'placeholder_type' => $placeholder_type,
			'data_missing'     => $data_missing,
		];
	}

	/**
	 * Error payload for a collection metric (funnel / distribution / table).
	 *
	 * @param string    $rows_key Key holding the (empty) collection: 'stages'|'slices'|'points'|'groups'|'cohorts'|'rows'|'weeks'.
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
	 * @param string $rows_key Key holding the (empty) collection: 'stages'|'slices'|'points'|'groups'|'cohorts'|'rows'|'weeks'.
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
	 * A proxy WP_Error becomes state 'error'. An empty result or a SAFE_DIVIDE
	 * null becomes a benign 'populated' non-computable zero. A row present but
	 * missing the required column becomes a non-computable zero flagged
	 * `data_missing` (schema drift). A non-numeric or count-drift value becomes
	 * state 'error'.
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
		if ( empty( $rows ) ) {
			// No rows → empty window, legitimately no data.
			return $this->populated_scalar( $zero, false, null, $placeholder_type );
		}
		if ( ! is_array( $rows[0] ) || ! array_key_exists( $row_key, $rows[0] ) ) {
			// Row present but unusable (missing required column / malformed shape)
			// → schema drift or bad deploy. Non-computable, flagged as missing data.
			return $this->populated_scalar( $zero, false, null, $placeholder_type, true );
		}
		$value = $rows[0][ $row_key ];
		// SAFE_DIVIDE returns NULL when the denominator is zero — a legitimate
		// "no eligible events to compute a rate" case, not a schema regression.
		// Benign → non-computable zero, NOT flagged data_missing (unlike the
		// missing-column branch above).
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
	 * Influenced-rate scalar from a hub query that computes the rate + the
	 * distinct-converter denominator BQ-internally (7.2/7.3). One proxy
	 * round-trip — no Woo join. Mirrors compute_metric_from_proxy's error/empty
	 * handling, but also surfaces the denominator (sample size).
	 *
	 * @param string            $query_name      Hub catalog query name.
	 * @param string            $rate_key        Row key for the SAFE_DIVIDE rate (null when no converters).
	 * @param string            $denominator_key Row key for the distinct-converter count.
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
			// No rows → empty window, legitimately no data.
			return $this->populated_scalar( 0.0, false, null, 'rate' );
		}
		if ( ! is_array( $rows[0] ) || ! array_key_exists( $rate_key, $rows[0] ) || ! array_key_exists( $denominator_key, $rows[0] ) ) {
			// Row present but unusable → schema drift. Non-computable, flagged.
			return $this->populated_scalar( 0.0, false, null, 'rate', true );
		}
		$denominator = $rows[0][ $denominator_key ];
		// The denominator is a BigQuery COUNT(DISTINCT) — a non-negative integer. Reject any
		// non-integer numeric (e.g. 8.5) rather than silently truncating it, matching the
		// integer-only count validation in Gates_Metric and Prompts_Metric.
		if ( ! is_numeric( $denominator ) || (float) $denominator !== (float) (int) $denominator ) {
			return $this->error_scalar(
				'rate',
				new \WP_Error( 'bigquery_proxy_malformed_value', __( 'The query returned a non-integer denominator.', 'newspack-plugin' ) )
			);
		}
		$denominator = (int) $denominator;
		$rate        = $rows[0][ $rate_key ];
		// SAFE_DIVIDE returns NULL when there are no converters in the window — a
		// legitimate "nothing to influence" case, not a schema regression.
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
	 * Placeholder for a collection metric in a Phase B "coming soon" section.
	 *
	 * Returns a lightweight sentinel with an empty collection keyed by
	 * `$rows_key` (e.g. 'stages', 'slices', 'points', 'groups', 'cohorts',
	 * 'rows') so the React layer can render a "coming soon" treatment without
	 * needing to know which key the real payload will use.
	 *
	 * @param string $rows_key Key holding the empty collection, matching the
	 *                         key the populated shape will use once wired.
	 * @return array
	 */
	private function coming_soon_collection( string $rows_key ): array {
		return [
			'state'   => 'coming_soon',
			$rows_key => [],
		];
	}

	// --- Section 1: The reader lifecycle --------------------------------

	/**
	 * Reader lifecycle funnel — five nested stages from anonymous reader to
	 * supporter. Dispatches `conversion_journey_lifecycle_funnel`; the hub
	 * returns one row with step_1_anonymous … step_5_supporter counts.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_reader_lifecycle_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_lifecycle_funnel', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			return $this->malformed_collection( 'stages' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		$row  = $rows[0];
		$top  = (int) ( $row['step_1_anonymous'] ?? 0 );
		$safe = $top > 0 ? $top : 1; // Guard division-by-zero; pct_of_top = 0.0 when top is 0.
		$labels = [
			__( 'Anonymous reader', 'newspack-plugin' ),
			__( 'Engaged reader', 'newspack-plugin' ),
			__( 'Registered reader', 'newspack-plugin' ),
			__( 'Newsletter subscriber', 'newspack-plugin' ),
			__( 'Subscriber or donor', 'newspack-plugin' ),
		];
		$keys   = [
			'step_1_anonymous',
			'step_2_engaged',
			'step_3_registered',
			'step_4_newsletter_subscriber',
			'step_5_supporter',
		];
		$stages = [];
		foreach ( $keys as $i => $key ) {
			$count    = (int) ( $row[ $key ] ?? 0 );
			$stages[] = [
				'label'      => $labels[ $i ],
				'count'      => $count,
				'pct_of_top' => $top > 0 ? (float) ( $count / $safe ) : 0.0,
			];
		}
		return [
			'state'  => 'populated',
			'stages' => $stages,
		];
	}

	// --- Section 2: Per-journey conversion funnels ----------------------

	/**
	 * Anonymous → Registered funnel (2.1) — three stages. Always rendered: there
	 * is no configuration gate for the registration journey (every site can
	 * register readers), so the leg is stamped `visibility: 'visible'` and its
	 * empty states are data-driven (NPPD-1743), mirroring the subscription and
	 * donation legs' funnel-shaped empty-state treatment from NPPD-1742.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>, visibility: string, visibility_reason: string|null}
	 */
	public function get_anonymous_to_registered_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->with_leg_visibility( $this->compute_anonymous_to_registered_funnel( $start, $end ) );
	}

	/**
	 * Build the Anonymous → Registered funnel payload (no visibility gating).
	 * Dispatches `conversion_journey_funnel_anon_to_registered`; the hub returns
	 * one row with step_1_anonymous, step_2_saw_conversion_surface,
	 * step_3_registered.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	private function compute_anonymous_to_registered_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_funnel_anon_to_registered', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			return $this->malformed_collection( 'stages' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		$row    = $rows[0];
		$top    = (int) ( $row['step_1_anonymous'] ?? 0 );
		$safe   = $top > 0 ? $top : 1;
		$labels = [
			__( 'Anonymous', 'newspack-plugin' ),
			__( 'Saw a conversion surface', 'newspack-plugin' ),
			__( 'Registered', 'newspack-plugin' ),
		];
		$keys   = [
			'step_1_anonymous',
			'step_2_saw_conversion_surface',
			'step_3_registered',
		];
		$stages = [];
		foreach ( $keys as $i => $key ) {
			$count    = (int) ( $row[ $key ] ?? 0 );
			$stages[] = [
				'label'      => $labels[ $i ],
				'count'      => $count,
				'pct_of_top' => $top > 0 ? (float) ( $count / $safe ) : 0.0,
			];
		}
		return [
			'state'  => 'populated',
			'stages' => $stages,
		];
	}

	/**
	 * Whether the subscription conversion leg should render (NPPD-1742).
	 *
	 * "Configured" is proxied by the presence of at least one active non-donation
	 * subscription — there is no single Newspack-mapped subscription product to
	 * check (unlike donations), and the Subscribers tab is likewise activity-gated.
	 * Memoized: current-state, identical across the current/previous windows.
	 *
	 * @return bool
	 */
	private function is_subscription_leg_configured(): bool {
		if ( null === $this->subscription_leg_configured ) {
			$this->subscription_leg_configured = count( $this->subscribers_metric->get_active_non_donation_subscriber_customer_ids() ) > 0;
		}
		return $this->subscription_leg_configured;
	}

	/**
	 * Whether the donation conversion leg should render (NPPD-1742).
	 *
	 * Activity-based (active donors > 0), NOT product-existence: every Newspack
	 * site ships the canonical donation product on install, so a product check is
	 * true everywhere. Mirrors the Donors tab's own activity-based visibility gate.
	 * Memoized like {@see self::is_subscription_leg_configured()}.
	 *
	 * @return bool
	 */
	private function is_donation_leg_configured(): bool {
		if ( null === $this->donation_leg_configured ) {
			$this->donation_leg_configured = $this->donors_metric->get_active_donors() > 0;
		}
		return $this->donation_leg_configured;
	}

	/**
	 * The hidden-leg payload for an unconfigured conversion stream (NPPD-1742).
	 * `state: 'empty'` keeps the leg out of the all-error tab-banner check; the
	 * `visibility: 'hidden'` flag is what the component reads to omit the cell.
	 *
	 * @return array{state: string, stages: array, visibility: string, visibility_reason: string}
	 */
	private function unconfigured_leg(): array {
		return [
			'state'             => 'empty',
			'stages'            => [],
			'visibility'        => 'hidden',
			'visibility_reason' => 'not_configured',
		];
	}

	/**
	 * Stamp a configured (rendering) leg payload with `visibility: 'visible'`, so
	 * every leg carries the same shape regardless of configuration (NPPD-1742).
	 *
	 * @param array $leg The computed funnel payload.
	 * @return array
	 */
	private function with_leg_visibility( array $leg ): array {
		$leg['visibility']        = 'visible';
		$leg['visibility_reason'] = null;
		return $leg;
	}

	/**
	 * Registered → Subscriber funnel (C10 / 2.2) — three stages, non-donation
	 * subscriptions only. Dispatches
	 * `conversion_journey_funnel_registered_to_subscriber`; the hub returns
	 * rows `{ uid, saw_subscription_surface, became_subscriber }`. Step 3 count
	 * is the SUM of the per-uid `became_subscriber` flag — the number of
	 * registered-in-window uids who ALSO fired a subscription conversion event in
	 * the same window, computed inside BigQuery (NEWS-2598). This replaces the
	 * former Woo customer_id join, which always returned 0 because the BQ uid is a
	 * GA4 pseudo-id, never a WooCommerce customer_id.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_registered_to_subscriber_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		// Configuration matrix (NPPD-1742): when the publisher has no active
		// non-donation subscription product, the subscription leg is not a
		// reader-revenue stream for them — hide the leg rather than render a zero
		// funnel. Component omits the cell on `visibility: 'hidden'`.
		if ( ! $this->is_subscription_leg_configured() ) {
			return $this->unconfigured_leg();
		}
		return $this->with_leg_visibility( $this->compute_registered_to_subscriber_funnel( $start, $end ) );
	}

	/**
	 * Build the Registered → Subscriber funnel payload (no visibility gating).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	private function compute_registered_to_subscriber_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_funnel_registered_to_subscriber', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			return $this->malformed_collection( 'stages' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		// NEWS-2598: `became_subscriber` is the sole source of step 3. A hub on the
		// pre-NEWS-2598 schema emits `{ uid, saw_subscription_surface }` without it;
		// treat that as malformed rather than silently reporting a populated funnel
		// with 0 conversions. BigQuery returns the column for all rows or none, so the
		// first row (already guaranteed an array by the guard above) is representative.
		if ( ! array_key_exists( 'became_subscriber', $rows[0] ) ) {
			return $this->malformed_collection( 'stages' );
		}
		$step_1 = count( $rows );
		$step_2 = 0;
		$step_3 = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->malformed_collection( 'stages' );
			}
			$step_2 += (int) ( $row['saw_subscription_surface'] ?? 0 );
			$step_3 += (int) ( $row['became_subscriber'] ?? 0 );
		}
		$safe = $step_1 > 0 ? $step_1 : 1;
		return [
			'state'  => 'populated',
			'stages' => [
				[
					'label'      => __( 'Registered', 'newspack-plugin' ),
					'count'      => $step_1,
					'pct_of_top' => (float) ( $step_1 / $safe ),
				],
				[
					'label'      => __( 'Saw a subscription-intent surface', 'newspack-plugin' ),
					'count'      => $step_2,
					'pct_of_top' => (float) ( $step_2 / $safe ),
				],
				[
					'label'      => __( 'Became subscriber', 'newspack-plugin' ),
					'count'      => $step_3,
					'pct_of_top' => (float) ( $step_3 / $safe ),
				],
			],
		];
	}

	/**
	 * Registered → Donor funnel (C11 / 2.3) — three stages. Dispatches
	 * `conversion_journey_funnel_registered_to_donor`; the hub returns rows
	 * `{ uid, saw_donation_surface, became_donor }`. Step 3 count is the SUM of
	 * the per-uid `became_donor` flag — the number of registered-in-window uids
	 * who ALSO fired a donation conversion event in the same window, computed
	 * inside BigQuery (NEWS-2598). This replaces the former Woo customer_id join,
	 * which always returned 0 because the BQ uid is a GA4 pseudo-id, never a
	 * WooCommerce customer_id.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	public function get_registered_to_donor_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		// Configuration matrix (NPPD-1742): hide the donation leg when the
		// publisher has no active donors. Activity-based on purpose — every
		// Newspack site ships the canonical donation product on install, so a
		// product-existence check (Donation_Product_Classifier) is true
		// everywhere and would never hide the leg. This mirrors how the Donors
		// tab itself gates visibility on activity, not product presence.
		if ( ! $this->is_donation_leg_configured() ) {
			return $this->unconfigured_leg();
		}
		return $this->with_leg_visibility( $this->compute_registered_to_donor_funnel( $start, $end ) );
	}

	/**
	 * Build the Registered → Donor funnel payload (no visibility gating).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>}
	 */
	private function compute_registered_to_donor_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_funnel_registered_to_donor', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'stages', $rows );
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			return $this->malformed_collection( 'stages' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'stages' => [],
			];
		}
		// NEWS-2598: `became_donor` is the sole source of step 3. A hub on the
		// pre-NEWS-2598 schema emits `{ uid, saw_donation_surface }` without it; treat
		// that as malformed rather than silently reporting a populated funnel with 0
		// conversions. BigQuery returns the column for all rows or none, so the first
		// row (already guaranteed an array by the guard above) is representative.
		if ( ! array_key_exists( 'became_donor', $rows[0] ) ) {
			return $this->malformed_collection( 'stages' );
		}
		$step_1 = count( $rows );
		$step_2 = 0;
		$step_3 = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->malformed_collection( 'stages' );
			}
			$step_2 += (int) ( $row['saw_donation_surface'] ?? 0 );
			$step_3 += (int) ( $row['became_donor'] ?? 0 );
		}
		$safe = $step_1 > 0 ? $step_1 : 1;
		return [
			'state'  => 'populated',
			'stages' => [
				[
					'label'      => __( 'Registered', 'newspack-plugin' ),
					'count'      => $step_1,
					'pct_of_top' => (float) ( $step_1 / $safe ),
				],
				[
					'label'      => __( 'Saw a donation-intent surface', 'newspack-plugin' ),
					'count'      => $step_2,
					'pct_of_top' => (float) ( $step_2 / $safe ),
				],
				[
					'label'      => __( 'Became donor', 'newspack-plugin' ),
					'count'      => $step_3,
					'pct_of_top' => (float) ( $step_3 / $safe ),
				],
			],
		];
	}

	/**
	 * Subscriber → Donor cross-upsell funnel (C19 / 2.4) — two stages,
	 * visibility-gated.
	 *
	 * Local-only (Woo-only): does NOT belong in the BQ catalog. Gated on
	 * {@see MIN_COHORT_FOR_SUB_TO_DONOR} (50) active subscribers AND 50 active
	 * donors. When either cohort falls below the threshold the funnel returns
	 * `visibility: 'hidden'` so React renders the insufficient-data note.
	 * When both cohorts meet the threshold, step_2 is resolved from
	 * {@see Donors_Metric::get_subscriber_donors_in_window()}.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, stages: array<int, array{label: string, count: int, pct_of_top: float}>, visibility: string, visibility_reason: string|null}
	 */
	public function get_subscriber_to_donor_funnel( DateTimeInterface $start, DateTimeInterface $end ): array {
		$subscriber_ids = $this->subscribers_metric->get_active_non_donation_subscriber_customer_ids();
		$active_subs    = count( $subscriber_ids );
		$active_donors  = $this->donors_metric->get_active_donors();

		if ( $active_subs < self::MIN_COHORT_FOR_SUB_TO_DONOR || $active_donors < self::MIN_COHORT_FOR_SUB_TO_DONOR ) {
			return [
				'state'             => 'populated',
				'stages'            => [],
				'visibility'        => 'hidden',
				'visibility_reason' => 'insufficient_data',
			];
		}

		$step_2 = $this->donors_metric->get_subscriber_donors_in_window( $subscriber_ids, $start, $end );
		$safe   = $active_subs > 0 ? $active_subs : 1;
		return [
			'state'             => 'populated',
			'stages'            => [
				[
					'label'      => __( 'Active subscriber', 'newspack-plugin' ),
					'count'      => $active_subs,
					'pct_of_top' => 1.0,
				],
				[
					'label'      => __( 'Also donor', 'newspack-plugin' ),
					'count'      => $step_2,
					'pct_of_top' => (float) ( $step_2 / $safe ),
				],
			],
			'visibility'        => 'visible',
			'visibility_reason' => null,
		];
	}

	// --- Section 3: Where conversions come from -------------------------

	/**
	 * Source mix for new registrations (3.1) — gate / prompt / direct.
	 * Dispatches `conversion_journey_source_mix_registrations`; the hub returns
	 * rows of `{ source, registrations }` where source ∈ gate/prompt/direct.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, total: int, slices: array<int, array{source: string, count: int, pct: float}>}
	 */
	public function get_source_mix_registrations( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_source_mix_registrations', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'slices', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'slices' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'total'  => 0,
				'slices' => [],
			];
		}
		$total  = 0;
		$counts = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->malformed_collection( 'slices' );
			}
			$source          = (string) ( $row['source'] ?? '' );
			$count           = (int) ( $row['registrations'] ?? 0 );
			$counts[ $source ] = $count;
			$total          += $count;
		}
		$safe   = $total > 0 ? $total : 1;
		$slices = [];
		foreach ( $counts as $source => $count ) {
			$slices[] = [
				'source' => $source,
				'count'  => $count,
				'pct'    => (float) ( $count / $safe ),
			];
		}
		return [
			'state'  => 'populated',
			'total'  => $total,
			'slices' => $slices,
		];
	}

	/**
	 * Source mix for new subscribers (C12 / 3.2) — gate / prompt / direct.
	 *
	 * Fetches Woo conversion records via Subscribers_Metric and BQ exposure
	 * events via the proxy, then delegates to compute_source_mix to attribute
	 * each record to a source via Source_Matcher timestamp proximity.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, total: int, slices: array<int, array{source: string, count: int, pct: float}>}
	 */
	public function get_source_mix_subscribers( DateTimeInterface $start, DateTimeInterface $end ): array {
		$records = $this->subscribers_metric->get_new_subscriber_records_in_window( $start, $end );
		return $this->compute_source_mix( 'conversion_journey_source_mix_subscribers', $records, $start, $end );
	}

	/**
	 * Source mix for new donors (C13 / 3.3) — gate / prompt / direct.
	 *
	 * Fetches Woo conversion records via Donors_Metric and BQ exposure events
	 * via the proxy, then delegates to compute_source_mix to attribute each
	 * record to a source via Source_Matcher timestamp proximity.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, total: int, slices: array<int, array{source: string, count: int, pct: float}>}
	 */
	public function get_source_mix_donors( DateTimeInterface $start, DateTimeInterface $end ): array {
		$records = $this->donors_metric->get_new_donor_records_in_window( $start, $end );
		return $this->compute_source_mix( 'conversion_journey_source_mix_donors', $records, $start, $end );
	}

	/**
	 * Classify a BQ source-mix row to gate / prompt / direct.
	 *
	 * @param array<string,mixed> $row BQ row.
	 * @return string One of self::SOURCES.
	 */
	private function classify_source( array $row ): string {
		if ( ! empty( $row['gate_post_id'] ) ) {
			return 'gate';
		}
		if ( ! empty( $row['popup_id'] ) ) {
			return 'prompt';
		}
		return Source_Matcher::SOURCE_DIRECT;
	}

	/**
	 * Empirical CDF over a list of day-lags: one point per distinct day that has
	 * conversions, with the running fraction of the cohort converted by that day.
	 *
	 * @param int[] $days_values Day lags (already truncated to the lookback).
	 * @return array<int, array{day:int, cumulative_pct:float}>
	 */
	private function cumulative_distribution( array $days_values ): array {
		$total = count( $days_values );
		if ( 0 === $total ) {
			return [];
		}
		$per_day = [];
		foreach ( $days_values as $day ) {
			$day             = (int) $day;
			$per_day[ $day ] = ( $per_day[ $day ] ?? 0 ) + 1;
		}
		ksort( $per_day );
		$running = 0;
		$points  = [];
		foreach ( $per_day as $day => $count ) {
			$running += $count;
			$points[] = [
				'day'            => (int) $day,
				'cumulative_pct' => round( $running / $total, 4 ),
			];
		}
		return $points;
	}

	/**
	 * Registration source events for the time-to-convert distributions, behind
	 * the BQ probe gate. Window is a trailing 365 days ending now (snapshot).
	 * Returns [ ['ts'=>int seconds, 'source'=>string], … ], or [] when the probe
	 * reports no registrations or any BQ call fails (degrade to all-direct).
	 *
	 * @return array<int, array{ts:int, source:string}>
	 */
	private function registration_source_events(): array {
		if ( null !== $this->registration_source_events_cache ) {
			return $this->registration_source_events_cache;
		}
		$utc   = new \DateTimeZone( 'UTC' );
		$end   = new \DateTimeImmutable( 'now', $utc );
		$start = $end->modify( '-365 days' );

		$probe = $this->proxy->query( 'conversion_journey_has_registrations_in_window', $start, $end );
		if ( is_wp_error( $probe ) || ! is_array( $probe ) || empty( $probe[0] ) || ! is_array( $probe[0] ) ) {
			$this->registration_source_events_cache = [];
			return $this->registration_source_events_cache;
		}
		$first_row = $probe[0];
		$count     = (int) reset( $first_row ); // Single COUNT column; read defensively regardless of alias.
		if ( $count <= 0 ) {
			$this->registration_source_events_cache = [];
			return $this->registration_source_events_cache;
		}

		$reg = $this->proxy->query( 'conversion_journey_registrations_with_source', $start, $end );
		if ( is_wp_error( $reg ) || ! is_array( $reg ) ) {
			$this->registration_source_events_cache = [];
			return $this->registration_source_events_cache;
		}
		$events = [];
		foreach ( $reg as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$events[] = [
				'ts'     => intdiv( (int) ( $row['reg_ts'] ?? 0 ), 1000000 ),
				'source' => (string) ( $row['source'] ?? Source_Matcher::SOURCE_DIRECT ),
			];
		}
		$this->registration_source_events_cache = $events;
		return $this->registration_source_events_cache;
	}

	/**
	 * Build a multi-series time-to-convert distribution. Lag = first_ts −
	 * registered_ts in whole days, kept to [0,365]. Each reader's source comes
	 * from matching their user_registered to a BQ registration event within
	 * ±WINDOW_REGISTRATION_SECONDS; unmatched → direct. Always emits the three
	 * source groups (zero-filled when empty).
	 *
	 * @param array<int, array<string,int>> $rows         Conversion-lag rows.
	 * @param string                        $first_ts_key Row key for the first-conversion epoch.
	 * @return array{state:string, groups:array}
	 */
	private function build_time_to_convert_distribution( array $rows, string $first_ts_key ): array {
		$events = $this->registration_source_events();
		// Bound the cohort to the same trailing-365-day window that
		// registration_source_events() covers. Converters who registered
		// more than 365 days ago would be silently attributed 'direct'
		// (their registration event fell outside the BQ window), biasing
		// older cohorts. Filtering them out keeps source attribution honest.
		$reg_cutoff      = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-365 days' )->getTimestamp();
		$records         = [];
		$lag_by_customer = [];
		foreach ( $rows as $row ) {
			if ( (int) $row['registered_ts'] < $reg_cutoff ) {
				continue;
			}
			$lag = intdiv( (int) $row[ $first_ts_key ] - (int) $row['registered_ts'], 86400 );
			if ( $lag < 0 || $lag > 365 ) {
				continue;
			}
			$cid                     = (int) $row['customer_id'];
			$records[]               = [
				'key' => $cid,
				'ts'  => (int) $row['registered_ts'],
			];
			$lag_by_customer[ $cid ] = $lag;
		}

		// Greedy single-consume nearest-match: on a busy site, two readers registering
		// within WINDOW_REGISTRATION_SECONDS of each other can have their sources swapped
		// (each consumes the event nearest to them, but which event is "nearest" is
		// order-sensitive). This is an accepted accuracy nuance, not a bug.
		$map         = Source_Matcher::attach_sources( $records, $events, Source_Matcher::WINDOW_REGISTRATION_SECONDS, Source_Matcher::WINDOW_REGISTRATION_SECONDS );
		$days_by_src = [
			'gate'   => [],
			'prompt' => [],
			'direct' => [],
		];
		foreach ( $lag_by_customer as $cid => $lag ) {
			$source                   = $map[ $cid ] ?? Source_Matcher::SOURCE_DIRECT;
			$days_by_src[ $source ][] = $lag;
		}

		$groups = [];
		foreach ( self::SOURCES as $source ) {
			$groups[] = [
				'label'  => $source,
				'points' => $this->cumulative_distribution( $days_by_src[ $source ] ),
			];
		}

		return [
			'state'  => empty( $lag_by_customer ) ? 'empty' : 'populated',
			'groups' => $groups,
		];
	}

	/**
	 * Source-mix via the Woo identity spine. For records that carry order-meta
	 * source signals (gate_post_id / popup_id from the donor storage layer),
	 * classification is done directly from the meta — gate wins over prompt wins
	 * over "no meta". Records without order-meta (all subscriber records, and
	 * donor records with no meta on the first-donation order) fall through to
	 * the BQ temporal matcher.
	 *
	 * BQ round-trip cost: the proxy query is only issued when ≥1 record lacks
	 * order-meta. If every donor record was classified via order meta the BQ
	 * call is skipped entirely, making 3.3 hub-independent for those donations.
	 * Subscriber records (3.2) never carry gate_post_id / popup_id so they
	 * always go through the matcher — unchanged behaviour.
	 *
	 * @param string                                     $query_name Hub catalog query name.
	 * @param array<int, array{customer_id:int, ts:int}> $records    Woo conversion records.
	 * @param DateTimeInterface                          $start      Window start.
	 * @param DateTimeInterface                          $end        Window end.
	 * @return array
	 */
	private function compute_source_mix( string $query_name, array $records, DateTimeInterface $start, DateTimeInterface $end ): array {
		// No conversions in the window → the metric is 'empty' regardless of the
		// source layer, so skip the BQ round-trip entirely. BigQuery only attributes
		// a source to conversions that already exist in Woo; with none there is
		// nothing to attribute, and a proxy error here would be a false 'error'.
		if ( empty( $records ) ) {
			return [
				'state'  => 'empty',
				'total'  => 0,
				'slices' => [],
			];
		}

		// Pass 1: order-meta classification (donor records only).
		// Subscriber records carry no gate_post_id / popup_id keys so they always
		// fall to the matcher below — behaviour is unchanged for 3.2.
		$meta_counts    = [
			'gate'   => 0,
			'prompt' => 0,
			'direct' => 0,
		];
		$matcher_records = []; // Records without usable order-meta → to BQ matcher.

		foreach ( $records as $record ) {
			// array_key_exists differentiates "key present but empty-string" from
			// "key absent" (subscriber records). Both '' and '0' count as "no meta"
			// since the SQL already guards NOT IN ('','0').
			$has_gate  = array_key_exists( 'gate_post_id', $record ) && '' !== (string) $record['gate_post_id'];
			$has_popup = array_key_exists( 'popup_id', $record ) && '' !== (string) $record['popup_id'];

			if ( $has_gate ) {
				++$meta_counts['gate'];
			} elseif ( $has_popup ) {
				++$meta_counts['prompt'];
			} else {
				// No order-meta (or subscriber record with no meta keys): fall to matcher.
				$matcher_records[] = $record;
			}
		}

		// If every record was classified via order meta, skip the BQ round-trip.
		if ( empty( $matcher_records ) ) {
			$total  = array_sum( $meta_counts );
			$safe   = $total > 0 ? $total : 1;
			$slices = [];
			foreach ( self::SOURCES as $source ) {
				$count    = (int) ( $meta_counts[ $source ] ?? 0 );
				$slices[] = [
					'source' => $source,
					'count'  => $count,
					'pct'    => (float) ( $count / $safe ),
				];
			}
			return [
				'state'  => 'populated',
				'total'  => $total,
				'slices' => $slices,
			];
		}

		// Pass 2: BQ temporal matcher for the remaining records.
		// Widen the event fetch by one day before $start so that a conversion
		// occurring just after midnight on the window's first day can still be
		// matched to an exposure event that occurred just before midnight the
		// preceding night. Source_Matcher::WINDOW_ORDER_SECONDS (1800 s) allows
		// lookback up to 30 min before the conversion; a day-granular date
		// boundary can cut off valid events without this guard.
		// createFromInterface() produces a DateTimeImmutable so modify() returns a new instance.
		$events_start = \DateTimeImmutable::createFromInterface( $start )->modify( '-1 day' );
		$bq           = $this->proxy->query( $query_name, $events_start, $end );
		if ( is_wp_error( $bq ) ) {
			return $this->error_collection( 'slices', $bq );
		}
		// A non-array success body, or any non-array row, is a malformed response
		// (consistent with the other BQ-backed metrics) — surface 'error' rather
		// than silently degrading to all-direct.
		if ( ! is_array( $bq ) ) {
			return $this->malformed_collection( 'slices' );
		}

		$events = [];
		foreach ( $bq as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->malformed_collection( 'slices' );
			}
			$events[] = [
				'ts'     => intdiv( (int) ( $row['attempt_ts'] ?? 0 ), 1000000 ),
				'source' => $this->classify_source( $row ),
			];
		}

		$match_input = [];
		foreach ( $matcher_records as $record ) {
			$match_input[] = [
				'key' => (int) $record['customer_id'],
				'ts'  => (int) $record['ts'],
			];
		}

		$map            = Source_Matcher::attach_sources( $match_input, $events, Source_Matcher::WINDOW_ORDER_SECONDS, 0 );
		$matcher_counts = Source_Matcher::count_by_source( $map );

		// Merge order-meta counts with BQ matcher counts.
		$counts = [
			'gate'   => $meta_counts['gate'] + (int) ( $matcher_counts['gate'] ?? 0 ),
			'prompt' => $meta_counts['prompt'] + (int) ( $matcher_counts['prompt'] ?? 0 ),
			'direct' => $meta_counts['direct'] + (int) ( $matcher_counts['direct'] ?? 0 ),
		];

		$total = array_sum( $counts );
		$safe  = $total > 0 ? $total : 1;

		$slices = [];
		foreach ( self::SOURCES as $source ) {
			$count    = (int) ( $counts[ $source ] ?? 0 );
			$slices[] = [
				'source' => $source,
				'count'  => $count,
				'pct'    => (float) ( $count / $safe ),
			];
		}

		return [
			'state'  => 'populated',
			'total'  => $total,
			'slices' => $slices,
		];
	}

	// --- Section 4: How long conversions take ---------------------------
	// Cumulative-distribution LineCharts (replaced the v1 BoxPlot framing).
	// Single-series: { points: [{day, cumulative_pct}, ...] }.
	// Multi-series:  { groups: [{ label, points: [...] }, ...] }.

	/**
	 * Time-to-register cumulative distribution (4.1) — single series.
	 * Dispatches `conversion_journey_time_to_register`; the hub returns per-day
	 * rows `{ days, conversions }`. The CDF is computed in PHP: rows are sorted
	 * by day, a running cumulative sum / total produces `cumulative_pct`
	 * (rounded to 4 dp) for each day.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, points: array<int, array{day: int, cumulative_pct: float}>}
	 */
	public function get_time_to_register_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_time_to_register', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return $this->error_collection( 'points', $rows );
		}
		if ( ! is_array( $rows ) ) {
			return $this->malformed_collection( 'points' );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'points' => [],
			];
		}

		// Guard every row is an array before the typed usort callback + reads
		// below (parity with the other collection methods; a malformed non-array
		// row would otherwise TypeError in the callback).
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->malformed_collection( 'points' );
			}
		}

		// Sort by day ascending.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return (int) ( $a['days'] ?? 0 ) <=> (int) ( $b['days'] ?? 0 );
			}
		);

		// Compute total conversions.
		$total = 0;
		foreach ( $rows as $row ) {
			$total += (int) ( $row['conversions'] ?? 0 );
		}

		// Guard: if all conversions are zero, treat as empty.
		if ( $total <= 0 ) {
			return [
				'state'  => 'empty',
				'points' => [],
			];
		}

		// Build CDF: running cumulative sum / total, rounded to 4 dp.
		$running = 0;
		$points  = [];
		foreach ( $rows as $row ) {
			$running   += (int) ( $row['conversions'] ?? 0 );
			$points[]   = [
				'day'            => (int) ( $row['days'] ?? 0 ),
				'cumulative_pct' => round( $running / $total, 4 ),
			];
		}

		return [
			'state'  => 'populated',
			'points' => $points,
		];
	}

	/**
	 * Time-to-subscribe cumulative distribution (4.2) — three series by source.
	 * Snapshot: ignores the window (all-history). May become windowed later
	 * (e.g. holiday-season period comparison).
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{state: string, groups: array}
	 */
	public function get_time_to_subscribe_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->build_time_to_convert_distribution(
			$this->subscribers_metric->get_subscription_conversion_lags(),
			'first_sub_ts'
		);
	}

	/**
	 * Time-to-donate cumulative distribution (4.3) — three series by source.
	 * Snapshot: ignores the window (all-history). May become windowed later.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{state: string, groups: array}
	 */
	public function get_time_to_donate_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->build_time_to_convert_distribution(
			$this->donors_metric->get_donation_conversion_lags(),
			'first_donation_ts'
		);
	}

	/**
	 * Subscriber → donor lag cumulative distribution (4.4) — single series,
	 * visibility-gated at self::MIN_COHORT_FOR_SUB_TO_DONOR cross-converters.
	 * Pure Woo. Snapshot: ignores the window (all-history). May become windowed
	 * later.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{state: string, points: array, visibility: string, visibility_reason: string|null}
	 */
	public function get_subscriber_to_donor_lag_distribution( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		$days = [];
		foreach ( $this->donors_metric->get_subscriber_to_donor_lags() as $row ) {
			$lag = (int) $row['lag_days'];
			if ( $lag < 0 || $lag > 365 ) {
				continue;
			}
			$days[] = $lag;
		}

		if ( count( $days ) < self::MIN_COHORT_FOR_SUB_TO_DONOR ) {
			return [
				'state'             => 'populated',
				'points'            => [],
				'visibility'        => 'hidden',
				'visibility_reason' => 'insufficient_data',
			];
		}

		return [
			'state'             => 'populated',
			'points'            => $this->cumulative_distribution( $days ),
			'visibility'        => 'visible',
			'visibility_reason' => null,
		];
	}

	// --- Section 5: Cohort retention ------------------------------------
	// Snapshot metrics: pre-computed weekly, independent of the date picker.
	// The $start/$end params are accepted for signature parity and ignored.

	/**
	 * Calendar-month index for cohort bucketing: year*12 + (month-1). The
	 * difference of two indices is the whole-calendar-months between them.
	 *
	 * @param \DateTimeInterface $d Date.
	 * @return int
	 */
	private function month_index( \DateTimeInterface $d ): int {
		return ( (int) $d->format( 'Y' ) ) * 12 + ( (int) $d->format( 'n' ) - 1 );
	}

	/**
	 * 5.1 reference line. Currently returns null: the 5.1 cohort chart autoscales
	 * and shows no default line. The hardcoded 15% was removed because no fixed-%
	 * default fits the network (publisher conversion models diverge widely). Kept
	 * as the single seam where the dynamic baseline will be computed.
	 *
	 * TODO: replace this null with a self-relative baseline — the median cumulative
	 * conversion of mature (>=12-month) cohorts at the 6-month mark — and expose it
	 * as a configurable Newspack publisher setting.
	 *
	 * @return array{value: float, label: string}|null
	 */
	private function registration_reference_line(): ?array {
		return null;
	}

	/**
	 * Compute the 5.1 registration → conversion cohort retention curve (the
	 * expensive snapshot work; called by the weekly/one-off pre-warm handler,
	 * never on the request hot path). Cohort = readers grouped by
	 * `user_registered` month (trailing 365 days). Conversion = the earlier of
	 * the reader's first subscription or first donation order. For each cohort
	 * and each months-since offset N (0..age, capped 12), the value is the
	 * CUMULATIVE fraction of the cohort converted by month N.
	 *
	 * @return array{state:string, cohorts:array, reference_line:array{value:float, label:string}|null}
	 */
	public function compute_registration_to_conversion_cohort(): array {
		$readers = $this->subscribers_metric->get_reader_registration_dates();
		if ( empty( $readers ) ) {
			return array_merge(
				[
					'state'   => 'empty',
					'cohorts' => [],
				],
				[ 'reference_line' => $this->registration_reference_line() ]
			);
		}

		$ids  = array_keys( $readers );
		$subs = $this->subscribers_metric->get_first_subscription_order_dates( $ids );
		$dons = $this->donors_metric->get_first_donation_order_dates( $ids );

		$now_index = $this->month_index( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );

		// Per-cohort tallies: denominator (cohort size) and a histogram of
		// months_since at first conversion (only for converters).
		$denominator   = []; // cohort_index => reader count.
		$converted_at  = []; // cohort_index => [ months_since => converter count ].
		$cohort_labels = []; // cohort_index => 'YYYY-MM'.
		foreach ( $readers as $id => $reg ) {
			$cohort_index                   = $this->month_index( $reg );
			$cohort_labels[ $cohort_index ] = $reg->format( 'Y-m' );
			$denominator[ $cohort_index ]   = ( $denominator[ $cohort_index ] ?? 0 ) + 1;

			$sub  = $subs[ $id ] ?? null;
			$don  = $dons[ $id ] ?? null;
			$conv = null;
			if ( $sub && $don ) {
				$conv = $sub <= $don ? $sub : $don;
			} else {
				$conv = $sub ?? $don;
			}
			if ( null === $conv ) {
				continue;
			}
			$months_since = $this->month_index( $conv ) - $cohort_index;
			if ( $months_since < 0 ) {
				continue;
			}
			$months_since = min( $months_since, self::COHORT_MAX_MONTHS );
			$converted_at[ $cohort_index ][ $months_since ] = ( $converted_at[ $cohort_index ][ $months_since ] ?? 0 ) + 1;
		}

		ksort( $denominator );
		$cohorts = [];
		foreach ( $denominator as $cohort_index => $size ) {
			$age     = min( self::COHORT_MAX_MONTHS, $now_index - $cohort_index );
			$age     = max( 0, $age );
			$points  = [];
			$running = 0;
			for ( $n = 0; $n <= $age; $n++ ) {
				$running += $converted_at[ $cohort_index ][ $n ] ?? 0;
				$points[] = [
					'period' => $n,
					'value'  => round( $running / $size, 4 ),
				];
			}
			$cohorts[] = [
				'label'  => $cohort_labels[ $cohort_index ],
				'points' => $points,
			];
		}

		return array_merge(
			[
				'state'   => empty( $cohorts ) ? 'empty' : 'populated',
				'cohorts' => $cohorts,
			],
			[ 'reference_line' => $this->registration_reference_line() ]
		);
	}

	/**
	 * Registration → conversion cohort (5.1). Snapshot — ignores the window.
	 * Reads the weekly pre-warmed snapshot via Cache::peek; on a cold cache it
	 * schedules a one-off background refresh and returns the graceful
	 * `coming_soon` envelope (never computes the expensive curve inline). The
	 * `reference_line` key is always present in the envelope (null for 5.1 — the
	 * chart autoscales with no default line).
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{state:string, cohorts:array, reference_line:array{value:float, label:string}|null}
	 */
	public function get_registration_to_conversion_cohort( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		if ( Cache::is_disabled() ) {
			return $this->compute_registration_to_conversion_cohort();
		}
		$snapshot = Cache::peek( self::TAB_SLUG, Cache::SOURCE_SNAPSHOT, [ self::SNAPSHOT_KEY ] );
		if ( null !== $snapshot && isset( $snapshot['payload']['registration_to_conversion_cohort'] ) ) {
			return $snapshot['payload']['registration_to_conversion_cohort'];
		}
		self::schedule_cohort_refresh();
		return array_merge(
			$this->coming_soon_collection( 'cohorts' ),
			[ 'reference_line' => $this->registration_reference_line() ]
		);
	}

	/**
	 * Subscriber retention cohort (5.2). Snapshot — ignores the window. Same
	 * pre-warmed-snapshot read + cold-cache schedule-and-degrade contract as
	 * {@see get_registration_to_conversion_cohort()}.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array{state:string, cohorts:array, reference_line:array{value:float, label:string}}
	 */
	public function get_subscriber_retention_cohort( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		if ( Cache::is_disabled() ) {
			return $this->compute_subscriber_retention_cohort();
		}
		$snapshot = Cache::peek( self::TAB_SLUG, Cache::SOURCE_SNAPSHOT, [ self::SNAPSHOT_KEY ] );
		if ( null !== $snapshot && isset( $snapshot['payload']['subscriber_retention_cohort'] ) ) {
			return $snapshot['payload']['subscriber_retention_cohort'];
		}
		self::schedule_cohort_refresh();
		return array_merge(
			$this->coming_soon_collection( 'cohorts' ),
			[ 'reference_line' => $this->retention_reference_line() ]
		);
	}

	// --- Section 6: Conversion rate trends ------------------------------

	/**
	 * Weekly conversion rates (6) — multi-series LineChart. Dispatches
	 * `conversion_journey_weekly_rates`; the hub returns per-week rows with
	 * { week_start, registration_conversion_rate, new_registrations }. The
	 * subscription series is computed HERE: real completed subscriptions per week
	 * (local Woo, via Subscribers_Metric::get_new_subscribers_by_week) divided by the
	 * hub's per-week `new_registrations`, so both series share the hub's registration
	 * definition. The `series` keys (registration_rate, subscription_conversion_rate)
	 * are preserved in every state so React can always build its legend.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, weeks: array, series: string[]}
	 */
	public function get_weekly_conversion_rates( DateTimeInterface $start, DateTimeInterface $end ): array {
		$series = [ 'registration_rate', 'subscription_conversion_rate' ];
		$rows   = $this->proxy->query( 'conversion_journey_weekly_rates', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return array_merge( $this->error_collection( 'weeks', $rows ), [ 'series' => $series ] );
		}
		if ( ! is_array( $rows ) ) {
			return array_merge( $this->malformed_collection( 'weeks' ), [ 'series' => $series ] );
		}
		if ( empty( $rows ) ) {
			return [
				'state'  => 'empty',
				'weeks'  => [],
				'series' => $series,
			];
		}
		// Validate the full payload before the local Woo query below, so a malformed
		// row short-circuits without an unnecessary database hit.
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return array_merge( $this->malformed_collection( 'weeks' ), [ 'series' => $series ] );
			}
		}

		// Real completed subscriptions per week come from the local Woo store (the
		// Subscribers-tab mechanism), keyed on the same Sunday-starting week_start the
		// hub uses for registrations. Dividing here keeps "registration" defined as the
		// hub's np_reader_registered count on both series. Mirrors the unconditional
		// Subscribers_Metric use in get_source_mix_subscribers().
		$subs_by_week = $this->subscribers_metric->get_new_subscribers_by_week( $start, $end );

		$weeks = [];
		foreach ( $rows as $row ) {
			$week_start        = $row['week_start'] ?? '';
			$week_key          = is_scalar( $week_start ) ? (string) $week_start : '';
			$new_registrations = (int) ( $row['new_registrations'] ?? 0 );
			$subscriptions     = (int) ( $subs_by_week[ $week_key ] ?? 0 );
			$weeks[]           = [
				'week'                         => $week_key,
				'registration_conversion_rate' => (float) ( $row['registration_conversion_rate'] ?? 0.0 ),
				'subscription_conversion_rate' => $new_registrations > 0 ? $subscriptions / $new_registrations : 0.0,
			];
		}
		return [
			'state'  => 'populated',
			'weeks'  => $weeks,
			'series' => $series,
		];
	}

	// --- Section 7: Cross-tab influenced attribution --------------------
	// The only section with comparison deltas. These duplicate the Tab 4/5/
	// 6/7 Influenced patterns; Phase 2 may re-query independently or call
	// into the existing tab orchestrators. Phase 1 stubs them so neither
	// approach is locked in.

	/**
	 * Influenced registration rate, 7-day lookback (7.1). Dispatches
	 * `conversion_journey_influenced_registration_7d`; the hub returns one row
	 * with column `influenced_registration_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_registration_rate_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy(
			'conversion_journey_influenced_registration_7d',
			'influenced_registration_rate',
			'rate',
			$start,
			$end
		);
	}

	/**
	 * Influenced subscription rate, 14-day lookback (C14 / 7.2). Dispatches
	 * `conversion_journey_influenced_subscription_14d`; the hub returns one row
	 * with `{ influenced_subscription_rate, conversion_denominator }` computed
	 * BQ-internally — one proxy call, no Woo join.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_subscription_rate_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_rate_from_proxy(
			'conversion_journey_influenced_subscription_14d',
			'influenced_subscription_rate',
			'conversion_denominator',
			$start,
			$end
		);
	}

	/**
	 * Influenced donation rate, 14-day lookback (C15 / 7.3). Dispatches
	 * `conversion_journey_influenced_donation_14d`; the hub returns one row
	 * with `{ influenced_donation_rate, conversion_denominator }` computed
	 * BQ-internally — one proxy call, no Woo join.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_donation_rate_14d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_influenced_rate_from_proxy(
			'conversion_journey_influenced_donation_14d',
			'influenced_donation_rate',
			'conversion_denominator',
			$start,
			$end
		);
	}

	/**
	 * Influenced newsletter signup rate, 7-day lookback (7.4). Dispatches
	 * `conversion_journey_influenced_newsletter_7d`; the hub returns one row
	 * with column `influenced_newsletter_rate`.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_influenced_newsletter_rate_7d( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->compute_metric_from_proxy(
			'conversion_journey_influenced_newsletter_7d',
			'influenced_newsletter_rate',
			'rate',
			$start,
			$end
		);
	}

	// --- Section 8: Opportunity buckets ---------------------------------
	// 8.1–8.3 are current-state snapshot counts: accept the window for
	// signature parity, ignore it. All three are local-only (Woo-only, or
	// Woo plus a recently-active UID set) and do NOT belong in the BQ
	// catalog. 8.3 duplicates Tab 7's Lapsed Donors definition — Phase 2
	// should reuse that orchestrator method rather than re-implement.

	/**
	 * Stale registered readers (C18 / 8.1). Snapshot — ignores the window.
	 *
	 * Local-only: count of registered readers with no active non-donation
	 * subscription and no completed donation in the trailing 365 days,
	 * delegated to {@see Subscribers_Metric::get_stale_registered_users()}.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array
	 */
	public function get_stale_registered_count( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->populated_scalar( $this->subscribers_metric->get_stale_registered_users(), true, null, 'count' );
	}

	/**
	 * At-risk subscribers (C16 / 8.2). Snapshot — ignores the window.
	 *
	 * Local-only (Woo-only): count of active non-donation subscriptions with
	 * a scheduled payment retry, delegated to
	 * {@see Subscribers_Metric::get_at_risk_subscribers()}.
	 *
	 * @param DateTimeInterface $start Window start (ignored — snapshot).
	 * @param DateTimeInterface $end   Window end (ignored — snapshot).
	 * @return array
	 */
	public function get_at_risk_subscriber_count( DateTimeInterface $start, DateTimeInterface $end ): array {
		unset( $start, $end );
		return $this->populated_scalar( $this->subscribers_metric->get_at_risk_subscribers(), true, null, 'count' );
	}

	/**
	 * Lapsed donors (C17 / 8.3). Windowed. Same definition as Tab 7's Lapsed
	 * Donors, delegated to
	 * {@see Donors_Metric::get_lapsed_donors_in_window()}.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_lapsed_donor_count( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->populated_scalar( $this->donors_metric->get_lapsed_donors_in_window( $start, $end ), true, null, 'count' );
	}

	/**
	 * Top pages that don't convert (8.4) — windowed table. Dispatches
	 * `conversion_journey_top_pages_no_conversion`; the hub returns rows of
	 * { post_id, page_url, page_title, pageviews, unique_readers,
	 * conversion_rate }. `threshold_pageviews` is the minimum-traffic cutoff
	 * (spec starting value of 100; tunable in a future phase).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{state: string, rows: array, threshold_pageviews: int}
	 */
	public function get_top_pages_no_conversion( DateTimeInterface $start, DateTimeInterface $end ): array {
		$rows = $this->proxy->query( 'conversion_journey_top_pages_no_conversion', $start, $end );
		if ( is_wp_error( $rows ) ) {
			return array_merge(
				$this->error_collection( 'rows', $rows ),
				[ 'threshold_pageviews' => 100 ]
			);
		}
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && ! is_array( $rows[0] ) ) ) {
			return array_merge(
				$this->malformed_collection( 'rows' ),
				[ 'threshold_pageviews' => 100 ]
			);
		}
		if ( empty( $rows ) ) {
			return [
				'state'               => 'empty',
				'rows'                => [],
				'threshold_pageviews' => 100,
			];
		}
		$table_rows = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return array_merge(
					$this->malformed_collection( 'rows' ),
					[ 'threshold_pageviews' => 100 ]
				);
			}
			$table_rows[] = [
				'post_id'         => (int) ( $row['post_id'] ?? 0 ),
				'page_url'        => (string) ( $row['page_url'] ?? '' ),
				'page_title'      => (string) ( $row['page_title'] ?? '' ),
				'pageviews'       => (int) ( $row['pageviews'] ?? 0 ),
				'unique_readers'  => (int) ( $row['unique_readers'] ?? 0 ),
				'conversion_rate' => (float) ( $row['conversion_rate'] ?? 0.0 ),
			];
		}
		return [
			'state'               => 'populated',
			'rows'                => $table_rows,
			'threshold_pageviews' => 100,
		];
	}

	// --- Section 5: Cohorts (5.2 retention) --------------------------------

	/**
	 * 5.2 reference line (hardcoded per spec): 70% retention at 12 months.
	 *
	 * @return array{value: float, label: string}
	 */
	private function retention_reference_line(): array {
		return [
			'value' => 0.70,
			'label' => __( '70% at 12 months', 'newspack-plugin' ),
		];
	}

	/**
	 * Compute the 5.2 subscriber retention cohort curve (expensive snapshot
	 * work; called by the pre-warm handler, never on the request hot path).
	 * Cohort = customers grouped by their first non-donation subscription month
	 * (trailing 365 days). For offset N (0..age, capped 12), the value is the
	 * fraction of the cohort with ANY subscription active at their own
	 * (first_start + N months): start <= T AND not cancelled/ended before T.
	 *
	 * @return array{state:string, cohorts:array, reference_line:array{value:float, label:string}}
	 */
	public function compute_subscriber_retention_cohort(): array {
		$rows = $this->subscribers_metric->get_new_subscriber_cohort_intervals();
		if ( empty( $rows ) ) {
			return array_merge(
				[
					'state'   => 'empty',
					'cohorts' => [],
				],
				[ 'reference_line' => $this->retention_reference_line() ]
			);
		}

		$utc = new \DateTimeZone( 'UTC' );

		// Group intervals by customer. Each interval: start_ts and nullable terminus_ts.
		$by_customer = [];
		foreach ( $rows as $row ) {
			$cid = (int) $row['customer_id'];
			if ( empty( $row['start'] ) ) {
				continue;
			}
			$start_ts = ( new \DateTimeImmutable( $row['start'], $utc ) )->getTimestamp();

			$terminus_ts = null;
			foreach ( [ $row['cancelled'] ?? null, $row['end'] ?? null ] as $raw ) {
				if ( null === $raw || '' === $raw || '0' === $raw ) {
					continue;
				}
				$ts = ( new \DateTimeImmutable( $raw, $utc ) )->getTimestamp();
				if ( null === $terminus_ts || $ts < $terminus_ts ) {
					$terminus_ts = $ts;
				}
			}
			$by_customer[ $cid ][] = [
				'start'    => $start_ts,
				'terminus' => $terminus_ts,
			];
		}

		$now_index = $this->month_index( new \DateTimeImmutable( 'now', $utc ) );

		// Per cohort (by first-start month): the set of customers and, for each
		// offset, the count still active.
		$cohort_members = []; // cohort_index => [ customer_id => first_start_ts ].
		$cohort_labels  = [];
		foreach ( $by_customer as $cid => $intervals ) {
			$first_start  = min( array_column( $intervals, 'start' ) );
			$first_dt     = ( new \DateTimeImmutable( '@' . $first_start ) )->setTimezone( $utc );
			$cohort_index = $this->month_index( $first_dt );
			$cohort_labels[ $cohort_index ]        = $first_dt->format( 'Y-m' );
			$cohort_members[ $cohort_index ][ $cid ] = $first_start;
		}

		ksort( $cohort_members );
		$cohorts = [];
		foreach ( $cohort_members as $cohort_index => $members ) {
			$size   = count( $members );
			$age    = max( 0, min( self::COHORT_MAX_MONTHS, $now_index - $cohort_index ) );
			$points = [];
			for ( $n = 0; $n <= $age; $n++ ) {
				$active = 0;
				foreach ( $members as $cid => $first_start ) {
					$t = $this->add_months_clamped(
						( new \DateTimeImmutable( '@' . $first_start ) )->setTimezone( $utc ),
						$n
					)->getTimestamp();
					foreach ( $by_customer[ $cid ] as $interval ) {
						if ( $interval['start'] <= $t && ( null === $interval['terminus'] || $interval['terminus'] > $t ) ) {
							$active++;
							break;
						}
					}
				}
				$points[] = [
					'period' => $n,
					'value'  => round( $active / $size, 4 ),
				];
			}
			$cohorts[] = [
				'label'  => $cohort_labels[ $cohort_index ],
				'points' => $points,
			];
		}

		return array_merge(
			[
				'state'   => empty( $cohorts ) ? 'empty' : 'populated',
				'cohorts' => $cohorts,
			],
			[ 'reference_line' => $this->retention_reference_line() ]
		);
	}

	/**
	 * Add N months to a DateTimeImmutable, clamping end-of-month overflow.
	 *
	 * PHP's `modify('+N months')` overflows end-of-month dates into the next month
	 * (e.g. Jan 31 + 1 month → Mar 3). For retention cohorts this shifts the
	 * comparison instant T, flipping the strict `terminus > T` boundary for
	 * end-of-month subscription starts. This helper clamps the result back to
	 * the last day of the target month when overflow occurs, while preserving the
	 * original time-of-day (e.g. Jan 31 +1mo → Feb 28/29 same time, not Mar 3).
	 * All date math is performed in the timezone of $base.
	 *
	 * @param \DateTimeImmutable $base The base datetime (must be in the desired timezone).
	 * @param int                $n    Number of months to add (non-negative).
	 * @return \DateTimeImmutable
	 */
	private function add_months_clamped( \DateTimeImmutable $base, int $n ): \DateTimeImmutable {
		$t = $base->modify( '+' . $n . ' months' );
		// If the day-of-month changed, the month overflowed — clamp to last day of previous month.
		if ( $t->format( 'j' ) !== $base->format( 'j' ) ) {
			$t = $t->modify( 'last day of previous month' );
		}
		return $t;
	}

	// --- Section 5: Action Scheduler handlers and pre-warm scheduling -------

	/**
	 * Action Scheduler handler (one-off and weekly recurring): recompute both
	 * cohort snapshots and write them to the snapshot cache. Constructs a
	 * dependency-free metric (lazy storage), matching the REST controller.
	 *
	 * @return void
	 */
	public static function run_cohort_refresh(): void {
		self::store_cohort_snapshot( new self() );
	}

	/**
	 * Compute both cohorts from the given metric and write them to the snapshot
	 * cache under one key. Split from run_cohort_refresh() so tests can inject a
	 * metric with mocked storage-backed collaborators.
	 *
	 * @param self $metric Metric whose compute_* methods produce the payload.
	 * @return void
	 */
	public static function store_cohort_snapshot( self $metric ): void {
		Cache::refresh(
			self::TAB_SLUG,
			Cache::SOURCE_SNAPSHOT,
			[ self::SNAPSHOT_KEY ],
			static function () use ( $metric ) {
				return [
					'registration_to_conversion_cohort' => $metric->compute_registration_to_conversion_cohort(),
					'subscriber_retention_cohort'       => $metric->compute_subscriber_retention_cohort(),
				];
			}
		);
	}

	/**
	 * Schedule a one-off background refresh of the cohort snapshot if Action
	 * Scheduler is available and no such job is already queued. Called from the
	 * request-path getters when the snapshot cache is cold, so it warms within
	 * minutes instead of waiting for the weekly run.
	 *
	 * @return void
	 */
	private static function schedule_cohort_refresh(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::COHORT_REFRESH_ACTION, [], self::COHORT_REFRESH_GROUP ) ) {
			return;
		}
		as_schedule_single_action( time(), self::COHORT_REFRESH_ACTION, [], self::COHORT_REFRESH_GROUP );
	}

	/**
	 * Timestamp of the next weekly pre-warm slot: this week's Monday 06:00 UTC,
	 * or next week's if that instant has already passed. Computed relative to
	 * $now so it never skips the upcoming Monday when called early on a Monday.
	 *
	 * @param \DateTimeImmutable $now Reference time (UTC).
	 * @return int Unix timestamp.
	 */
	public static function next_weekly_prewarm_timestamp( \DateTimeImmutable $now ): int {
		$monday = $now->modify( 'monday this week' )->setTime( 6, 0 );
		if ( $monday <= $now ) {
			$monday = $monday->modify( '+1 week' );
		}
		return $monday->getTimestamp();
	}

	/**
	 * Ensure a weekly recurring cohort pre-warm is scheduled (Monday 06:00 UTC).
	 * No-op if Action Scheduler is unavailable or the recurring action already
	 * exists. Hooked on `init` by the conversion section.
	 *
	 * @return void
	 */
	public static function maybe_schedule_cohort_prewarm(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false !== as_next_scheduled_action( self::COHORT_REFRESH_WEEKLY_ACTION, [], self::COHORT_REFRESH_GROUP ) ) {
			return;
		}
		$next = self::next_weekly_prewarm_timestamp( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		as_schedule_recurring_action( $next, WEEK_IN_SECONDS, self::COHORT_REFRESH_WEEKLY_ACTION, [], self::COHORT_REFRESH_GROUP );
	}
}
