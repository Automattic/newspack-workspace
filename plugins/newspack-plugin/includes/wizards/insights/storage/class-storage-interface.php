<?php
/**
 * Newspack Insights — Storage Interface (NPPD-1616).
 *
 * Contract for the per-backend Tab 6 (Subscribers) data layer. Two
 * implementations: HPOS (`wp_wc_orders` / `wp_wc_orders_meta`) and
 * legacy CPT (`wp_posts` / `wp_postmeta`). Dispatch chosen per-publisher
 * by {@see Storage_Detector::detect()}.
 *
 * SQL bodies live in
 * `~/Sites/insights-docs/formulas/tab-6-subscribers.md` and the
 * cross-cutting reference at
 * `~/Sites/insights-docs/formulas/subscription-donation-schema.md`.
 * Those docs are the authoritative source for query shape; this
 * interface only fixes the PHP boundary.
 *
 * Donation product IDs are injected at construction so the metric
 * methods stay free of the donation set parameter — see
 * {@see Donation_Product_Classifier::get_donation_product_ids()}.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 6 storage contract.
 */
interface Storage_Interface {

	/**
	 * Distinct customers with at least one active non-donation subscription
	 * right now. A reader with two active subscriptions counts once. Excludes
	 * `wc-pending-cancel`.
	 *
	 * @return int
	 */
	public function get_active_non_donation_subscribers(): int;

	/**
	 * Distinct customers whose FIRST non-donation subscription started in
	 * the window. Resubscribes by the same reader do not count again.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return int
	 */
	public function get_new_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int;

	/**
	 * Distinct customers whose ALL non-donation subscriptions ended in the
	 * window (cancelled or expired) AND who have no other active non-donation
	 * subscriptions. Losing one product while keeping another doesn't count.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return int
	 */
	public function get_churned_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int;

	/**
	 * Monthly Recurring Revenue across all active non-donation subscriptions
	 * right now. Yearly subs contribute `total / 12`; quarterly `total / 3`;
	 * monthly contribute their total. Conservative fallback for unrecognized
	 * `_billing_period` / `_billing_interval` pairs.
	 *
	 * @return float
	 */
	public function get_mrr(): float;

	/**
	 * Annual Recurring Revenue = MRR × 12. Exposed for explicitness even
	 * though it's plain arithmetic from MRR; the metric layer caches each
	 * independently.
	 *
	 * @return float
	 */
	public function get_arr(): float;

	/**
	 * Sum of `shop_order` totals for orders containing non-donation
	 * subscription products in the window. Does not subtract refunds.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return float
	 */
	public function get_subscription_revenue_gross( DateTimeInterface $start, DateTimeInterface $end ): float;

	/**
	 * Gross subscription revenue minus refunds processed in the window.
	 * Includes `shop_order` + `shop_order_refund` rows; refunds are negative
	 * so a plain SUM gives the right net. Refund date is when the refund
	 * was processed, not when the original order was placed.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return float
	 */
	public function get_subscription_revenue_net( DateTimeInterface $start, DateTimeInterface $end ): float;

	/**
	 * Refund count divided by subscription order count in the window.
	 *
	 * Return shape:
	 *   [
	 *     'value'       => float, // refunds / orders, range [0,1], 0 when not computable
	 *     'computable'  => bool,  // false when there were no subscription orders in window
	 *     'denominator' => int,   // subscription order count in window
	 *   ]
	 *
	 * The UI uses `computable` to render a "No subscription orders in
	 * this timeframe" empty state instead of a misleading 0%, and
	 * surfaces `denominator` inline as context so small-cohort 0%
	 * reads as "0% of N orders" rather than bare 0%.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_subscription_refund_rate( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Per-subscription tenure rows for the active non-donation subscriber
	 * base. One row per active subscription:
	 *
	 *   [ 'product_name' => string, 'tenure_days' => int ]
	 *
	 * Aggregation into box-plot quartiles happens in the React layer (so the
	 * raw distribution remains available for future drill-downs).
	 *
	 * @return array<int, array{product_name: string, tenure_days: int}>
	 */
	public function get_subscription_tenure_distribution(): array;

	/**
	 * Aggregate count + total value of active non-donation subscriptions
	 * whose `_schedule_next_payment` falls within the next 30 days.
	 *
	 *   [ 'count' => int, 'total_value' => float ]
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_renewals_30d(): array;

	/**
	 * Count + total value of non-donation subscriptions known to be
	 * ending in the next 30 days. Covers two cohorts:
	 *
	 *   - `wc-active` subs with `_schedule_end` in next 30d
	 *     (fixed-term subscription reaching its scheduled end)
	 *   - `wc-pending-cancel` subs with `_schedule_end` in next 30d
	 *     (customer-initiated cancellation, paid period not yet
	 *     exhausted — the sub remains usable until end)
	 *
	 * Both legitimately signal "ending soon" to publishers; WCS uses
	 * `_schedule_end` as the canonical end marker regardless of which
	 * status set it.
	 *
	 *   [ 'count' => int, 'total_value' => float ]
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_cancellations_30d(): array;

	/**
	 * Fraction of payment retry attempts in the window that resulted in
	 * a subscription returning to `wc-active`.
	 *
	 * Return shape:
	 *   [
	 *     'value'       => float, // recoveries / attempts, range [0,1], 0 when not computable
	 *     'computable'  => bool,  // false when no payment retries were scheduled in window
	 *     'denominator' => int,   // retry attempts in window
	 *   ]
	 *
	 * See {@see get_subscription_refund_rate()} for the UI contract
	 * on `computable` and `denominator`.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_failed_payment_retry_rate( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Per-product performance for non-donation subscription products.
	 * One entry per parent product (or standalone simple/subscription
	 * product), ordered by aggregated active subscriber count
	 * descending, top 50. Parent entries carry a `variations` array
	 * with one entry per variation, sorted by active_subs descending:
	 *
	 *   [
	 *     'product_id'       => int,
	 *     'name'             => string,
	 *     'is_parent'        => bool,    // true when this entry has variations
	 *     'active_subs'      => int,     // parent: SUM of variation active_subs
	 *     'churned_subs'     => int,     // parent: SUM (windowed)
	 *     'active_value'     => float,   // parent: SUM
	 *     'lifetime_revenue' => float,   // parent: SUM (approximate; see Tab 6 doc)
	 *     'variations'       => [        // parents only; absent for is_parent=false
	 *       [
	 *         'variation_id'     => int,
	 *         'label'            => string,  // 'Monthly' / 'Annual' / etc
	 *         'active_subs'      => int,
	 *         'churned_subs'     => int,     // windowed
	 *         'active_value'     => float,
	 *         'lifetime_revenue' => float,
	 *       ],
	 *       ...
	 *     ],
	 *   ]
	 *
	 * `churned_subs` is windowed to `[$start, $end]`. The other three
	 * aggregates are current-state / lifetime (see HPOS_Storage's
	 * column-scope comment).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_subscriptions_by_product( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Cancellation reason buckets for non-donation subscriptions whose
	 * `_schedule_cancelled` falls in the window. One row per reason, ordered
	 * by count descending:
	 *
	 *   [ 'cancellation_reason' => string, 'count' => int ]
	 *
	 * Reasons map to `newspack_subscriptions_cancellation_reason` postmeta;
	 * unset values bucket as `'unknown'` (often substantial for cancellations
	 * predating the feature).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{cancellation_reason: string, count: int}>
	 */
	public function get_cancellation_reasons( DateTimeInterface $start, DateTimeInterface $end ): array;
}
