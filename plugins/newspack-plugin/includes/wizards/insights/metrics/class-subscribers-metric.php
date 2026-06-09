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
	const CACHE_PREFIX = 'newspack_insights_tab6_v4:';

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
	 * @return array<int, array{product_id: int, product_name: string, active_subs: int, churned_subs: int, active_value: float, lifetime_revenue: float}>
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
