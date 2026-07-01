<?php
/**
 * Newspack Insights — Donors Metric orchestrator (NPPD-1617).
 *
 * Tab 7 counterpart to {@see Subscribers_Metric}. Picks the storage
 * backend via {@see Storage_Detector::detect()}, threads the donation
 * product IDs from {@see Donation_Product_Classifier} into the
 * storage constructor, and wraps each metric call in a transient
 * cache keyed by `backend + method + md5(params)`.
 *
 * Caching tiers mirror Tab 6:
 *   - 30 min default for windowed and snapshot metrics
 *   - 60 min for heavy aggregates (donations_by_tier, recovery rate,
 *     retention)
 *
 * Derived metrics (ARR = MRR × 12; total revenue = one-time +
 * recurring) are computed in this layer, not in storage — avoids
 * round-tripping through the database for plain arithmetic.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 7 metric orchestrator.
 */
class Donors_Metric {

	/**
	 * Cache key prefix. Bumped if a backwards-incompatible change in
	 * the cached shape lands.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'newspack_insights_tab7_v11:';

	/**
	 * Cache TTL for windowed and snapshot metrics (30 min).
	 *
	 * @var int
	 */
	const TTL_DEFAULT = 1800;

	/**
	 * Cache TTL for heavy aggregates (60 min).
	 *
	 * @var int
	 */
	const TTL_HEAVY = 3600;

	/**
	 * Selected storage backend.
	 *
	 * @var string Storage_Detector::BACKEND_*.
	 */
	private $backend;

	/**
	 * Active storage implementation.
	 *
	 * @var Donors_Storage_Interface
	 */
	private $storage;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->backend = Storage_Detector::detect();
		$donation_ids  = Donation_Product_Classifier::get_donation_product_ids();

		$this->storage = Storage_Detector::BACKEND_HPOS === $this->backend
			? new HPOS_Donors_Storage( $donation_ids )
			: new Legacy_Donors_Storage( $donation_ids );
	}

	/**
	 * Active storage backend identifier.
	 *
	 * @return string
	 */
	public function get_backend(): string {
		return $this->backend;
	}

	/**
	 * Classification metadata for the response shape.
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
	 * Derived empty-state signal (NPPD-1696): whether a window saw any donation
	 * activity at all. A pure function of three already-computed window values —
	 * no query — mirroring how Gates derives its section totals
	 * ({@see Gates_Metric::paywall_section_totals()}). The REST controller folds
	 * the result into the window payload; the React layer uses it to choose the
	 * Donors tab's `no_opportunity` empty state. The `no_opportunity` /
	 * `no_conversions` decision itself stays in the component, not here.
	 *
	 * Net revenue is the activity proxy, so a window whose only donation was later
	 * fully refunded (net 0) with no new or lapsed donors reads as "no activity" —
	 * an accepted display-only edge, not data harm.
	 *
	 * @param int   $new_donors    First-time donors in the window.
	 * @param int   $lapsed_donors Donors who lapsed in the window.
	 * @param float $total_revenue Net donation revenue in the window.
	 * @return bool
	 */
	public static function window_activity_signal( int $new_donors, int $lapsed_donors, float $total_revenue ): bool {
		return 0.0 !== $total_revenue || $new_donors > 0 || $lapsed_donors > 0;
	}

	/**
	 * Active donors (UNION of recurring + trailing-365 one-time).
	 *
	 * @return int
	 */
	public function get_active_donors(): int {
		return (int) $this->cached(
			'active_donors',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_active_donors();
			}
		);
	}

	/**
	 * Active recurring donors.
	 *
	 * @return int
	 */
	public function get_active_recurring_donors(): int {
		return (int) $this->cached(
			'active_recurring_donors',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_active_recurring_donors();
			}
		);
	}

	/**
	 * Donation MRR.
	 *
	 * @return float
	 */
	public function get_donation_mrr(): float {
		return (float) $this->cached(
			'donation_mrr',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_donation_mrr();
			}
		);
	}

	/**
	 * Donation ARR (MRR × 12).
	 *
	 * @return float
	 */
	public function get_donation_arr(): float {
		return $this->get_donation_mrr() * 12;
	}

	/**
	 * Upcoming donation renewals in the next 30 days.
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_donation_renewals_30d(): array {
		return (array) $this->cached(
			'upcoming_donation_renewals_30d',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_upcoming_donation_renewals_30d();
			}
		);
	}

	/**
	 * Upcoming donation cancellations in the next 30 days.
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_donation_cancellations_30d(): array {
		return (array) $this->cached(
			'upcoming_donation_cancellations_30d',
			[],
			self::TTL_DEFAULT,
			function () {
				return $this->storage->get_upcoming_donation_cancellations_30d();
			}
		);
	}

	/**
	 * New donors in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_new_donors_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		return (int) $this->cached(
			'new_donors_in_window',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_new_donors_in_window( $start, $end );
			}
		);
	}

	/**
	 * Lapsed donors in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_lapsed_donors_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		return (int) $this->cached(
			'lapsed_donors_in_window',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_lapsed_donors_in_window( $start, $end );
			}
		);
	}

	/**
	 * One-time donation revenue in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_one_time_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float {
		return (float) $this->cached(
			'one_time_donation_revenue',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_one_time_donation_revenue( $start, $end );
			}
		);
	}

	/**
	 * Recurring donation revenue in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_recurring_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float {
		return (float) $this->cached(
			'recurring_donation_revenue',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_recurring_donation_revenue( $start, $end );
			}
		);
	}

	/**
	 * Total donation revenue in window (one-time + recurring).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_total_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float {
		return $this->get_one_time_donation_revenue( $start, $end )
			+ $this->get_recurring_donation_revenue( $start, $end );
	}

	/**
	 * Average donation gift in window.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_average_donation_gift( DateTimeInterface $start, DateTimeInterface $end ): float {
		return (float) $this->cached(
			'average_donation_gift',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_average_donation_gift( $start, $end );
			}
		);
	}

	/**
	 * Lapsed donor recovery rate.
	 *
	 * Returns the explicit `{value, computable, denominator}` shape
	 * from storage. UI renders an empty state when `computable` is
	 * false and surfaces `denominator` inline so small-cohort 0%
	 * reads as "0% (0 of N donors)" rather than bare 0%.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_lapsed_donor_recovery_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'lapsed_donor_recovery_rate',
			$this->window_key( $start, $end ),
			self::TTL_HEAVY,
			function () use ( $start, $end ) {
				return $this->storage->get_lapsed_donor_recovery_rate( $start, $end );
			}
		);
	}

	/**
	 * Recurring donor retention.
	 *
	 * See {@see get_lapsed_donor_recovery_rate()} for the response
	 * shape and UI contract.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_recurring_donor_retention( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'recurring_donor_retention',
			$this->window_key( $start, $end ),
			self::TTL_HEAVY,
			function () use ( $start, $end ) {
				return $this->storage->get_recurring_donor_retention( $start, $end );
			}
		);
	}

	/**
	 * Donations by tier.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_donations_by_tier( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'donations_by_tier',
			$this->window_key( $start, $end ),
			self::TTL_HEAVY,
			function () use ( $start, $end ) {
				return $this->storage->get_donations_by_tier( $start, $end );
			}
		);
	}

	// -------------------------------------------------------------------------
	// Conversion Journey (Tab 3) metric wrappers.
	// -------------------------------------------------------------------------

	/**
	 * COUNT(DISTINCT customer_id) among $subscriber_ids who completed a
	 * donation order within the window. List-param — NOT cached.
	 *
	 * @param int[]             $subscriber_ids Customer IDs to check.
	 * @param DateTimeInterface $start          Inclusive window start.
	 * @param DateTimeInterface $end            Inclusive window end.
	 * @return int
	 */
	public function get_subscriber_donors_in_window( array $subscriber_ids, DateTimeInterface $start, DateTimeInterface $end ): int {
		return $this->storage->get_subscriber_donors_in_window( $subscriber_ids, $start, $end );
	}

	/**
	 * COUNT(DISTINCT customer_id) among $customer_ids who have at least one
	 * completed donation order (any time). List-param — NOT cached.
	 *
	 * @param int[] $customer_ids Customer IDs to check.
	 * @return int
	 */
	public function count_completed_donation_order_customers_by_customer_ids( array $customer_ids ): int {
		return $this->storage->count_completed_donation_order_customers_by_customer_ids( $customer_ids );
	}

	/**
	 * Earliest completed/processing donation order date per customer for the
	 * given set. List-param — NOT cached (result varies per call). Keyed by
	 * customer_id.
	 *
	 * @param int[] $customer_ids Customer IDs to look up.
	 * @return array<int, \DateTimeImmutable>
	 */
	public function get_first_donation_order_dates( array $customer_ids ): array {
		return $this->storage->get_first_donation_order_dates( $customer_ids );
	}

	/**
	 * New donor records (customer_id + first-donation epoch ts + order-meta
	 * source signals) in the window. Used by compute_source_mix for order-meta-
	 * primary classification. List-param — NOT cached.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	public function get_new_donor_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array {
		return $this->storage->get_new_donor_records_in_window( $start, $end );
	}

	/**
	 * Donation conversion-lag rows (customer_id, registered_ts, first_donation_ts)
	 * for the 4.3 time-to-donate distribution. List-param — NOT cached.
	 *
	 * @return array<int, array{customer_id:int, registered_ts:int, first_donation_ts:int}>
	 */
	public function get_donation_conversion_lags(): array {
		return $this->storage->get_donation_conversion_lags();
	}

	/**
	 * Subscriber→donor lag rows (lag_days) for the 4.4 distribution. Pure Woo.
	 * List-param — NOT cached.
	 *
	 * @return array<int, array{lag_days:int}>
	 */
	public function get_subscriber_to_donor_lags(): array {
		return $this->storage->get_subscriber_to_donor_lags();
	}

	/**
	 * NPPD-1685: prompt-attributed completed donation conversions for the window,
	 * sourced from order meta (`_newspack_popup_id`) — the anonymous-inclusive,
	 * join-free source for the DIRECT prompt-donation metrics. Captures donors
	 * who were anonymous at attempt time and are excluded by GA4-based joins.
	 * Keyed by popup id: `{ conversions, revenue }`. Reused by
	 * {@see Prompts_Metric::get_donation_revenue_direct()} (and the direct
	 * conversion-rate / per-prompt cards).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<string, array{conversions:int, revenue:float}>
	 */
	public function get_prompt_attributed_donation_conversions( DateTimeInterface $start, DateTimeInterface $end ): array {
		return (array) $this->cached(
			'prompt_attributed_donation_conversions',
			$this->window_key( $start, $end ),
			self::TTL_DEFAULT,
			function () use ( $start, $end ) {
				return $this->storage->get_prompt_attributed_donation_conversions( $start, $end );
			}
		);
	}

	/**
	 * Flush all Tab 7 metric caches. Hook point for NPPD-1605.
	 *
	 * @return void
	 */
	public static function flush_all(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%'
			)
		);
		// phpcs:enable
	}

	/**
	 * Build a window key for cache disambiguation.
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
	 * Cache helper. Lookup transient; on miss, callback, store, return.
	 *
	 * @param string   $method   Storage method name.
	 * @param array    $params   Parameters affecting the result.
	 * @param int      $ttl      Seconds.
	 * @param callable $callback Fresh-value provider.
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
