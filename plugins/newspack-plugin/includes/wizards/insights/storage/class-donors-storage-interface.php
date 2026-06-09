<?php
/**
 * Newspack Insights — Donors Storage Interface (NPPD-1617).
 *
 * Contract for the per-backend Tab 7 (Donors) data layer. Mirrors the
 * Tab 6 {@see Storage_Interface} pattern: two implementations (HPOS,
 * legacy CPT) dispatch via {@see Storage_Detector::detect()}. The
 * shared {@see Donation_Product_Classifier} provides the donation
 * product ID set — Tab 7 uses it with `IN` filters where Tab 6 uses
 * `NOT IN`.
 *
 * SQL bodies reference `~/Sites/insights-docs/formulas/tab-7-donors.md`
 * and the cross-cutting schema reference at
 * `~/Sites/insights-docs/formulas/subscription-donation-schema.md`.
 * The 11 methods below cover the user-spec metric list for Tab 7 v1
 * (ARR and Total revenue are derived in the orchestrator, not here).
 *
 * Per-query join surface (per the schema doc's verified
 * shop_order-only behavior of `wc_order_product_lookup`):
 *
 *   - Shop_order-scoped queries (one-time + renewal revenue, new
 *     donors, average gift) use `{prefix}wc_order_product_lookup`.
 *   - Shop_subscription-scoped queries (active recurring donors,
 *     donation MRR, lapsed donors, recurring retention) use
 *     `{prefix}woocommerce_order_items` +
 *     `{prefix}woocommerce_order_itemmeta._product_id`.
 *
 * The Active Donors UNION metric crosses both surfaces and
 * deduplicates by `customer_id`.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

/**
 * Tab 7 storage contract.
 */
interface Donors_Storage_Interface {

	/**
	 * Distinct customers who count as an "active donor" right now —
	 * UNION of two paths, deduplicated by customer_id:
	 *
	 *   (a) any customer with a `wc-active` recurring donation
	 *       subscription, AND
	 *   (b) any customer who completed a donation `shop_order` in the
	 *       trailing 365 days.
	 *
	 * Captures one-time donors (no subscription) who recently gave AND
	 * recurring donors regardless of recency.
	 *
	 * @return int
	 */
	public function get_active_donors(): int;

	/**
	 * Distinct customers with at least one active recurring donation
	 * subscription right now. Excludes one-time donors. A donor with
	 * both monthly and yearly counts once.
	 *
	 * @return int
	 */
	public function get_active_recurring_donors(): int;

	/**
	 * Donation MRR — sum of active donation subscription revenue
	 * normalized to a monthly rate. Reads frequency from the product's
	 * `_subscription_period` / `_subscription_period_interval` per the
	 * formula doc convention. Each line item contributes its own
	 * contribution; multi-line-item donation subscriptions (rare for
	 * the canonical Newspack family) sum per line item by design.
	 *
	 * @return float
	 */
	public function get_donation_mrr(): float;

	/**
	 * Count + total value of active recurring donation subscriptions
	 * whose `_schedule_next_payment` falls within the next 30 days
	 * from NOW. Same shape and treatment as Tab 6's upcoming renewals;
	 * scoped to the donation product set instead of excluding it.
	 *
	 *   [ 'count' => int, 'total_value' => float ]
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_donation_renewals_30d(): array;

	/**
	 * Count + total value of donation subscriptions ending in the next
	 * 30 days. Covers `wc-active` subs with a scheduled fixed-term end
	 * and `wc-pending-cancel` subs that the donor cancelled mid-cycle
	 * with paid period remaining. Mirrors Tab 6's upcoming cancellations
	 * with the donation filter flipped from NOT IN to IN.
	 *
	 *   [ 'count' => int, 'total_value' => float ]
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_donation_cancellations_30d(): array;

	/**
	 * Distinct customers whose FIRST donation order completed within
	 * the window. Excludes returning donors making their second or
	 * later gift.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return int
	 */
	public function get_new_donors_in_window( DateTimeInterface $start, DateTimeInterface $end ): int;

	/**
	 * Distinct recurring donors whose last donation subscription
	 * cancelled/expired in the window AND who have no remaining
	 * active recurring donation subscription. The Tab 6 churn pattern
	 * scoped to donation products.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return int
	 */
	public function get_lapsed_donors_in_window( DateTimeInterface $start, DateTimeInterface $end ): int;

	/**
	 * Sum of completed donation `shop_order` totals in the window
	 * filtered to one-time donations only (products whose
	 * `_subscription_period` is not one of 'day', 'week', 'month', or
	 * 'year' — i.e. not recurring at any cadence).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return float
	 */
	public function get_one_time_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float;

	/**
	 * Sum of completed donation `shop_order` totals in the window
	 * filtered to recurring donations (products with
	 * `_subscription_period` IN ('day','week','month','year')). These
	 * rows are renewal orders generated by donation subscriptions.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return float
	 */
	public function get_recurring_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float;

	/**
	 * Mean order total across one-time donation `shop_order` rows in
	 * the window. Excludes subscription renewals AND subscription
	 * initial installments — those distort the metric (predictable
	 * recurring amounts) and a sub initial order isn't a "gift" in
	 * the donor's mental model, it's the first slice of a recurring
	 * commitment. Filter is the same period-meta predicate that
	 * scopes {@see get_one_time_donation_revenue()}.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return float Zero when there are no one-time donation orders to average.
	 */
	public function get_average_donation_gift( DateTimeInterface $start, DateTimeInterface $end ): float;

	/**
	 * Of donors who lapsed in the prior window of equal length
	 * preceding `[start, end]`, the fraction who made a new completed
	 * donation order in `[start, end]`.
	 *
	 * Prior window = `[start - duration, start - 1 second]` where
	 * `duration = end - start`.
	 *
	 * Return shape:
	 *   [
	 *     'value'       => float, // recovered / lapsed, range [0,1], 0 when not computable
	 *     'computable'  => bool,  // false when denominator is 0 (no lapsed cohort)
	 *     'denominator' => int,   // size of the prior-window lapsed cohort
	 *   ]
	 *
	 * The UI uses `computable` to render a "no data yet" empty state
	 * instead of a misleading 0%, and surfaces `denominator` inline so
	 * publishers can read "0% (0 of 3 donors)" rather than bare "0%"
	 * when the math is real but the cohort is small.
	 *
	 * @param DateTimeInterface $start Current window start.
	 * @param DateTimeInterface $end   Current window end.
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_lapsed_donor_recovery_rate( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Of recurring donation subscriptions that were active at the
	 * window start, the fraction whose owner customer still has at
	 * least one active recurring donation subscription right now.
	 *
	 * "Active at start" = `_schedule_start <= :start` AND
	 * (`_schedule_cancelled` empty/null/`'0'` OR > :start). The
	 * `'0'` sentinel is WCS's "not cancelled" marker — distinct from
	 * NULL or '' — so it MUST be treated as "not cancelled" in the
	 * filter or the denominator silently drops every currently-active
	 * subscription. The end check is "currently active" (NOW), not
	 * "active at :end" — a v1 simplification documented inline on
	 * the query.
	 *
	 * Return shape:
	 *   [
	 *     'value'       => float, // still_active / active_at_start, range [0,1], 0 when not computable
	 *     'computable'  => bool,  // false when denominator is 0 (no recurring donors at start)
	 *     'denominator' => int,   // distinct customers active at window start
	 *   ]
	 *
	 * See {@see get_lapsed_donor_recovery_rate()} for the UI contract
	 * on `computable` and `denominator`.
	 *
	 * @param DateTimeInterface $start Current window start.
	 * @param DateTimeInterface $end   Current window end (used for
	 *                                 cache-key disambiguation only).
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_recurring_donor_retention( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Per-product donor performance breakdown. One entry per parent
	 * donation product (or standalone product), sorted by lifetime
	 * revenue descending, top 50. Parent entries carry a `variations`
	 * array with one entry per variation, sorted by lifetime revenue
	 * descending.
	 *
	 * Columns per entry:
	 *
	 *   [
	 *     'product_id'              => int,
	 *     'name'                    => string,
	 *     'is_parent'               => bool,
	 *     'billing_model'           => 'recurring' | 'one_time',
	 *     'active_recurring_donors' => int,
	 *     'lapsed_donors_in_window' => int,
	 *     'new_donors_in_window'    => int,
	 *     'one_time_gifts_in_window' => int,
	 *     'recurring_revenue_in_window' => float,
	 *     'lifetime_donation_revenue' => float,
	 *     'variations'              => [
	 *       [
	 *         'variation_id'              => int,
	 *         'label'                     => string,  // 'Monthly' / 'Annual' / etc
	 *         'billing_model'             => 'recurring' | 'one_time',
	 *         'active_recurring_donors'   => int,
	 *         'lapsed_donors_in_window'   => int,
	 *         'new_donors_in_window'      => int,
	 *         'one_time_gifts_in_window'  => int,
	 *         'recurring_revenue_in_window' => float,
	 *         'lifetime_donation_revenue' => float,
	 *       ],
	 *       ...
	 *     ],
	 *   ]
	 *
	 * `billing_model` is derived from the product's `_subscription_period`
	 * meta: `recurring` when the period meta is in (day, week, month,
	 * year), else `one_time`. Parent rows inherit `recurring` if ANY
	 * variation is recurring (the canonical Newspack donation shape),
	 * else `one_time`. The UI uses this to render cells that don't
	 * apply to the product's billing model as em-dashes ("—") instead
	 * of misleading zeros: a one-time product can't have recurring
	 * donors, recurring revenue, or lapsed donors; a recurring
	 * product can't have one-time gifts.
	 *
	 * `lapsed_donors_in_window` is bucketed-per-product using the same
	 * cohort definition as {@see get_lapsed_donors_in_window()}
	 * (cancelled/expired in window AND customer has no current active
	 * donation sub). A customer who lapsed across multiple donation
	 * products in the same window counts once per product row, so
	 * SUM(lapsed_donors_in_window) across rows can exceed the
	 * scorecard's distinct-customer count. In Newspack's typical
	 * data shape a donor only has one recurring donation so the
	 * per-tier counts reconcile to the scorecard in practice.
	 *
	 * `*_in_window` columns are window-scoped to `[start, end]`.
	 * `active_recurring_donors` and `lifetime_donation_revenue` are
	 * current state / lifetime respectively. Parent aggregates equal
	 * the SUM of their variations.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_donations_by_tier( DateTimeInterface $start, DateTimeInterface $end ): array;
}
