<?php
/**
 * Newspack Insights — Subscribers Metric orchestrator (NPPD-1616).
 *
 * Thin dispatch + caching layer over the per-backend storage classes.
 * Picks HPOS or legacy via {@see Storage_Detector::detect()}, threads
 * the precomputed donation product ID set from
 * {@see Donation_Product_Classifier::get_donation_product_ids()} into
 * the storage constructor, and wraps each metric call in a transient
 * cache keyed by `backend + method + params hash`.
 *
 * Cache tiers (per `~/Sites/insights-docs/formulas/tab-6-subscribers.md`):
 *
 *   - 30 min default for windowed metrics and top-line snapshots
 *     (revenue, churn, MRR, ARR, active count, upcoming renewals).
 *   - 60 min for heavy aggregation queries (tenure distribution,
 *     performance by product, cancellation reasons) — these are
 *     materially more expensive on large publishers and the staleness
 *     budget is generous.
 *
 * Comparison-mode is NOT implemented here: the REST layer calls these
 * methods twice (current window + prior window) and the cache makes the
 * second call free if the prior window has already been requested.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 6 metric orchestrator.
 */
class Subscribers_Metric {

	/**
	 * Cache key prefix. Bumped if a backwards-incompatible change in the
	 * shape of any cached result lands.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab6_v6:';

	/**
	 * Cache TTL for windowed and snapshot metrics (30 min).
	 *
	 * @var int
	 */
	const TTL_DEFAULT = 1800;

	/**
	 * Cache TTL for heavy aggregation queries (60 min).
	 *
	 * @var int
	 */
	const TTL_HEAVY = 3600;

	/**
	 * Selected storage backend (HPOS or legacy).
	 *
	 * @var string One of `Storage_Detector::BACKEND_*`.
	 */
	private $backend;

	/**
	 * Active storage implementation.
	 *
	 * @var Storage_Interface
	 */
	private $storage;

	/**
	 * Constructor. Resolves backend, fetches donation IDs, instantiates
	 * storage. Cheap (only hits cached transients); safe to call per
	 * REST request.
	 */
	public function __construct() {
		$this->backend = Storage_Detector::detect();
		$donation_ids  = Donation_Product_Classifier::get_donation_product_ids();

		$this->storage = Storage_Detector::BACKEND_HPOS === $this->backend
			? new HPOS_Storage( $donation_ids )
			: new Legacy_Storage( $donation_ids );
	}

	/**
	 * Active storage backend identifier.
	 *
	 * Exposed for the classification banner so the React layer can show
	 * the publisher which backend is in use.
	 *
	 * @return string
	 */
	public function get_backend(): string {
		return $this->backend;
	}

	/**
	 * Classification metadata for the banner. Aggregates the inputs that
	 * the publisher needs to verify that Insights is reading correctly.
	 *
	 * @return array{backend: string, donation_product_count: int, has_donation_family: bool}
	 */
	public function get_classification_metadata(): array {
		$donation_ids = Donation_Product_Classifier::get_donation_product_ids();
		return [
			'backend'                => $this->backend,
			'donation_product_count' => count( $donation_ids ),
			'has_donation_family'    => ! empty( $donation_ids ),
		];
	}

	/**
	 * Derived empty-state signal (NPPD-1695): whether a window saw any
	 * subscription activity at all. A pure function of values the controller
	 * already fetches — no query — mirroring {@see Donors_Metric::window_activity_signal()}.
	 * The REST controller folds the result into the window payload; the React
	 * layer uses it to choose the windowed section's `no_opportunity` empty
	 * state. The `no_opportunity` / `no_conversions` decision itself stays in
	 * the component, not here.
	 *
	 * Churn EVENTS count as activity (a window where subscribers left is not
	 * empty), so `churned_subscribers > 0` contributes. Zero churn does not —
	 * but a window with zero revenue, zero new, and zero churn genuinely has no
	 * movement and collapses to the empty state. Net revenue is included
	 * alongside gross so a refund-only window (gross 0, net negative) still
	 * reads as activity.
	 *
	 * @param int   $new_subscribers     First-time subscribers in the window.
	 * @param int   $churned_subscribers Subscribers who churned in the window.
	 * @param float $revenue_gross       Gross subscription revenue in the window.
	 * @param float $revenue_net         Net subscription revenue in the window.
	 * @return bool
	 */
	public static function window_activity_signal( int $new_subscribers, int $churned_subscribers, float $revenue_gross, float $revenue_net ): bool {
		return 0.0 !== $revenue_gross || 0.0 !== $revenue_net || $new_subscribers > 0 || $churned_subscribers > 0;
	}

	/**
	 * Distinct active non-donation subscribers right now.
	 *
	 * @return int
	 */
	public function get_active_non_donation_subscribers(): int {
		return (int) $this->cached(
			'active_non_donation_subscribers',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_active_non_donation_subscribers();
			}
		);
	}

	/**
	 * New subscribers in window. See storage contract for semantics.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_new_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		return (int) $this->cached(
			'new_subscribers_in_window',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_new_subscribers_in_window( $start, $end );
			}
		);
	}

	/**
	 * Churned subscribers in window. See storage contract for semantics.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_churned_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		return (int) $this->cached(
			'churned_subscribers_in_window',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_churned_subscribers_in_window( $start, $end );
			}
		);
	}

	/**
	 * Win-back subscribers in window. See storage contract for semantics.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_winback_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		return (int) $this->cached(
			'winback_subscribers_in_window',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_winback_subscribers_in_window( $start, $end );
			}
		);
	}

	/**
	 * Monthly Recurring Revenue (snapshot).
	 *
	 * @return float
	 */
	public function get_mrr(): float {
		return (float) $this->cached(
			'mrr',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_mrr();
			}
		);
	}

	/**
	 * Annual Recurring Revenue (snapshot).
	 *
	 * @return float
	 */
	public function get_arr(): float {
		return (float) $this->cached(
			'arr',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_arr();
			}
		);
	}

	/**
	 * Gross subscription revenue in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_subscription_revenue_gross( DateTimeInterface $start, DateTimeInterface $end ): float {
		return (float) $this->cached(
			'subscription_revenue_gross',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_subscription_revenue_gross( $start, $end );
			}
		);
	}

	/**
	 * Net subscription revenue in window (gross minus refunds processed).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_subscription_revenue_net( DateTimeInterface $start, DateTimeInterface $end ): float {
		return (float) $this->cached(
			'subscription_revenue_net',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_subscription_revenue_net( $start, $end );
			}
		);
	}

	/**
	 * Subscription refund rate in window.
	 *
	 * Returns the explicit `{value, computable, denominator}` shape
	 * from storage. UI renders a "No subscription orders in this
	 * timeframe" empty state when `computable` is false and surfaces
	 * `denominator` inline so small-cohort 0% reads as "0% of N
	 * orders" rather than bare 0%.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_subscription_refund_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'subscription_refund_rate',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_subscription_refund_rate( $start, $end );
			}
		);
	}

	/**
	 * Subscription tenure distribution (one row per active sub).
	 *
	 * NOTE: as of v1 the React Subscribers tab no longer renders the
	 * tenure histogram that previously consumed this data — only the
	 * median / p25 / p75 callouts are shown, and those are derived from
	 * the same per-row payload returned here. This method (and the
	 * corresponding {@see Storage_Interface::get_subscription_tenure_distribution()}
	 * implementations) is preserved for a potential v1.1 revival of a
	 * richer tenure visualization. Do not delete as "dead code"; the
	 * REST endpoint still surfaces this payload and the React layer
	 * still uses the per-row days array.
	 *
	 * @return array<int, array{product_name: string, tenure_days: int}>
	 */
	public function get_subscription_tenure_distribution(): array {
		return (array) $this->cached(
			'subscription_tenure_distribution',
			[],
			self::TTL_HEAVY,
			function () {
				return $this->storage->get_subscription_tenure_distribution();
			}
		);
	}

	/**
	 * Upcoming renewals (count + total value) in the next 30 days.
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_renewals_30d(): array {
		return (array) $this->cached(
			'upcoming_renewals_30d',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_upcoming_renewals_30d();
			}
		);
	}

	/**
	 * Upcoming cancellations (count + total value) in the next 30 days.
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_cancellations_30d(): array {
		return (array) $this->cached(
			'upcoming_cancellations_30d',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_upcoming_cancellations_30d();
			}
		);
	}

	/**
	 * Failed payment retry rate (recoveries / attempts) in window.
	 *
	 * See {@see get_subscription_refund_rate()} for the response shape
	 * and UI contract.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_failed_payment_retry_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'failed_payment_retry_rate',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_failed_payment_retry_rate( $start, $end );
			}
		);
	}

	/**
	 * Per-product performance breakdown (top 50 by active subs).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{product_id: int, product_name: string, active_subs: int, new_subs: int, churned_subs: int, active_value: float, lifetime_revenue: float}>
	 */
	public function get_subscriptions_by_product( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'subscriptions_by_product',
			$this->window_key( $start, $end ),
			self::TTL_HEAVY,
			function () use ( $start, $end ) {
				return $this->storage->get_subscriptions_by_product( $start, $end );
			}
		);
	}

	/**
	 * Cancellation reason buckets in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{cancellation_reason: string, count: int}>
	 */
	public function get_cancellation_reasons( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'cancellation_reasons',
			$this->window_key( $start, $end ),
			self::TTL_HEAVY,
			function () use ( $start, $end ) {
				return $this->storage->get_cancellation_reasons( $start, $end );
			}
		);
	}

	// -------------------------------------------------------------------------
	// Conversion Journey (Tab 3) metric wrappers.
	// -------------------------------------------------------------------------

	/**
	 * Count of active non-donation subscriptions with a scheduled payment retry.
	 * Point-in-time snapshot; cached TTL_DEFAULT (30 min).
	 *
	 * @return int
	 */
	public function get_at_risk_subscribers(): int {
		return (int) $this->cached(
			'at_risk_subscribers',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_at_risk_subscribers();
			}
		);
	}

	/**
	 * DISTINCT customer_ids with at least one active non-donation subscription.
	 * Point-in-time snapshot; cached TTL_DEFAULT (30 min).
	 *
	 * @return int[]
	 */
	public function get_active_non_donation_subscriber_customer_ids(): array {
		return (array) $this->cached(
			'active_non_donation_subscriber_customer_ids',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_active_non_donation_subscriber_customer_ids();
			}
		);
	}

	/**
	 * Count of registered readers with no active non-donation subscription and
	 * no completed donation in the trailing 365 days. Phase-A approximation.
	 * Point-in-time snapshot; cached TTL_DEFAULT (30 min).
	 *
	 * @return int
	 */
	public function get_stale_registered_users(): int {
		return (int) $this->cached(
			'stale_registered_users',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_stale_registered_users();
			}
		);
	}

	/**
	 * Earliest non-donation subscription start per customer for the given set.
	 * List-param — NOT cached (result varies per call). Keyed by customer_id.
	 *
	 * @param int[] $customer_ids Customer IDs to look up.
	 * @return array<int, \DateTimeImmutable>
	 */
	public function get_first_subscription_order_dates( array $customer_ids ): array {
		return $this->storage->get_first_subscription_order_dates( $customer_ids );
	}

	/**
	 * Reader registration dates (trailing 365 days), keyed by user ID. Snapshot
	 * input for the 5.1 cohort. Uncached pass-through — the cohort's caching
	 * layer is the weekly pre-warmed snapshot, not this delegation.
	 *
	 * @return array<int, \DateTimeImmutable>
	 */
	public function get_reader_registration_dates(): array {
		return $this->storage->get_reader_registration_dates();
	}

	/**
	 * New non-donation subscriber records for source-mix attribution (3.2).
	 * Each record carries customer_id, first-sub epoch ts, and source meta
	 * (gate_post_id, popup_id) read from the subscription's parent shop_order.
	 * Records without usable meta fall to the BQ temporal matcher in
	 * compute_source_mix. List-param — NOT cached.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	public function get_new_subscriber_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->storage->get_new_subscriber_records_in_window( $start, $end );
	}

	/**
	 * New subscriptions in the window grouped by `utm_campaign` (NEWS-2591).
	 *
	 * Anonymous-inclusive, Woo-only (no GA4 / hub). Reads one row per initial
	 * subscription order from the storage layer (per store: HPOS `wc_orders_meta`,
	 * legacy `postmeta`), then folds by campaign via
	 * {@see Metric_Grouping::group_records_by_key()}. Untagged orders collapse into a
	 * single trailing "(no campaign)" row. Coverage is the UTM-tagged subset — see
	 * formulas/tab-6-subscribers.md.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{value: string, count: int, amount: float, is_untagged: bool}>
	 */
	public function get_subscriptions_by_campaign( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'subscriptions_by_campaign',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT, // Grouping an already-fetched record set, not a heavy join.
			function () use ( $start, $end ) {
				return Metric_Grouping::group_records_by_key(
					$this->storage->get_new_subscription_campaign_rows( $start, $end ),
					'utm_campaign',
					'revenue',
					// Locale-stable sentinel, NOT __()'d: this output is cached under a
					// locale-agnostic key, so the display label is localized at render
					// time from the is_untagged flag (see CampaignSection.tsx).
					[ 'untagged_label' => '(no campaign)' ]
				);
			}
		);
	}

	/**
	 * Subscription conversion-lag rows (customer_id, registered_ts, first_sub_ts)
	 * for the 4.2 time-to-subscribe distribution. List-param — NOT cached.
	 *
	 * @return array<int, array{customer_id:int, registered_ts:int, first_sub_ts:int}>
	 */
	public function get_subscription_conversion_lags(): array {
		return $this->storage->get_subscription_conversion_lags();
	}

	/**
	 * Prompt/gate-attributed subscription conversions in a window, bucketed by
	 * surface with gate precedence (NPPD-1746). Delegates the per-order read to the
	 * storage backend, then folds the orders into two surface maps:
	 *
	 *   [ 'by_gate'  => [ gate_id  => { conversions, revenue } ],
	 *     'by_popup' => [ popup_id => { conversions, revenue } ] ]
	 *
	 * The Gates tab consumes `by_gate`; the Prompts tab consumes `by_popup`. Each
	 * order lands in exactly one surface (see the precedence note on
	 * {@see bucket_attributed_subscription_orders()}), so the two per-surface rates
	 * never double-count an order.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array{by_gate: array<string, array{conversions:int, revenue:float}>, by_popup: array<string, array{conversions:int, revenue:float}>}
	 */
	public function get_attributed_subscription_conversions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'attributed_subscription_conversions',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return self::bucket_attributed_subscription_orders(
					$this->storage->get_attributed_subscription_orders( $start, $end )
				);
			}
		);
	}

	/**
	 * Fold per-order attributed-subscription rows into per-surface conversion +
	 * revenue maps, applying gate precedence. Pure function (no storage, no cache),
	 * exposed `static` so the precedence rule can be unit-tested directly.
	 *
	 * GATE PRECEDENCE (NPPD-1746): a subscription that passed through a paywall gate
	 * is a gate conversion. An order carrying BOTH `_gate_post_id` and
	 * `_newspack_popup_id` is bucketed to the gate ONLY — never both — so one order
	 * is counted in exactly one surface and the gate-rate and popup-rate can never
	 * double-count it. Zero dual-key orders were observed across both Phase-1
	 * publishers measured, so this branch is dormant on today's data; it is
	 * defined defensively because "never fires today" is exactly what silently
	 * becomes a double-count once the write paths shift and the assumption is
	 * forgotten. The one-id-per-order MIN() resolution in the storage reader and
	 * this gate-over-popup rule together make the bucketing fully deterministic.
	 *
	 * @param array<int, array{order_id:int, gate_id:?string, popup_id:?string, order_total:float}> $orders Per-order rows.
	 * @return array{by_gate: array<string, array{conversions:int, revenue:float}>, by_popup: array<string, array{conversions:int, revenue:float}>}
	 */
	public static function bucket_attributed_subscription_orders( array $orders ): array {
		$by_gate  = [];
		$by_popup = [];
		foreach ( $orders as $order ) {
			$gate_id  = isset( $order['gate_id'] ) ? (string) $order['gate_id'] : '';
			$popup_id = isset( $order['popup_id'] ) ? (string) $order['popup_id'] : '';
			$total    = (float) ( $order['order_total'] ?? 0 );

			if ( '' !== $gate_id ) {
				if ( ! isset( $by_gate[ $gate_id ] ) ) {
					$by_gate[ $gate_id ] = [
						'conversions' => 0,
						'revenue'     => 0.0,
					];
				}
				++$by_gate[ $gate_id ]['conversions'];
				$by_gate[ $gate_id ]['revenue'] += $total;
			} elseif ( '' !== $popup_id ) {
				if ( ! isset( $by_popup[ $popup_id ] ) ) {
					$by_popup[ $popup_id ] = [
						'conversions' => 0,
						'revenue'     => 0.0,
					];
				}
				++$by_popup[ $popup_id ]['conversions'];
				$by_popup[ $popup_id ]['revenue'] += $total;
			}
		}
		return [
			'by_gate'  => $by_gate,
			'by_popup' => $by_popup,
		];
	}

	/**
	 * Trailing-365-day new-subscriber cohort subscription intervals (5.2 input).
	 * Uncached pass-through — caching is the weekly pre-warmed snapshot.
	 *
	 * @return array<int, array{customer_id:int, start:string, cancelled:?string, end:?string}>
	 */
	public function get_new_subscriber_cohort_intervals(): array {
		return $this->storage->get_new_subscriber_cohort_intervals();
	}

	/**
	 * Flush ALL Tab 6 metric caches. Use after a manual data correction
	 * or from the future NPPD-1605 invalidation system; not wired to any
	 * automatic trigger today because the WP transient API has no key
	 * pattern API and individual metrics expire on their own TTL.
	 *
	 * @return void
	 */
	public static function flush_all(): void {
		global $wpdb;
		$prefix = '_transient_' . self::CACHE_PREFIX;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);
		// phpcs:enable
	}

	/**
	 * Common window key builder. Uses UTC epoch seconds so the same window
	 * across DST transitions hashes consistently.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{start: string, end: string}
	 */
	private function window_key( DateTimeInterface $start, DateTimeInterface $end ): array {
		return [
			'start' => $start->format( 'U' ),
			'end'   => $end->format( 'U' ),
		];
	}

	/**
	 * Cache helper. Looks up a transient; on miss, runs the callback,
	 * stores, returns.
	 *
	 * Key shape: `{prefix}{backend}:{method}:{md5(params_json)}`.
	 *
	 * @param string   $method   Storage method name (no leading `get_`).
	 * @param array    $params   Parameters that affect the result.
	 * @param int      $ttl      TTL in seconds.
	 * @param callable $callback Function returning the fresh value.
	 * @return mixed
	 */
	private function cached( string $method, array $params, int $ttl, callable $callback ) {
		$key    = self::CACHE_PREFIX . $this->backend . ':' . $method . ':' . md5( (string) wp_json_encode( $params ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$result = $callback();
		set_transient( $key, $result, $ttl );
		return $result;
	}
}
