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
	 * The same new-subscriber cohort as get_new_subscribers_in_window(), bucketed by
	 * the Sunday-starting week of each customer's first non-donation subscription
	 * start — for the Conversion tab's weekly subscription-conversion trend.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<string,int> Map of week_start ('Y-m-d', Sunday) to new-subscriber count.
	 */
	public function get_new_subscribers_by_week( DateTimeInterface $start, DateTimeInterface $end ): array;

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
	 * Distinct customers who started a non-donation subscription in the window and
	 * had previously churned — i.e. they hold an earlier-started non-donation
	 * subscription that is cancelled or expired, regardless of product. Disjoint
	 * from first-time subscribers (a first-timer has no prior subscription).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return int
	 */
	public function get_winback_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int;

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
	 *     'new_subs'         => int,     // parent: SUM (windowed)
	 *     'churned_subs'     => int,     // parent: SUM (windowed)
	 *     'active_value'     => float,   // parent: SUM
	 *     'lifetime_revenue' => float,   // parent: SUM (approximate; see Tab 6 doc)
	 *     'variations'       => [        // parents only; absent for is_parent=false
	 *       [
	 *         'variation_id'     => int,
	 *         'label'            => string,  // 'Monthly' / 'Annual' / etc
	 *         'active_subs'      => int,
	 *         'new_subs'         => int,     // windowed
	 *         'churned_subs'     => int,     // windowed
	 *         'active_value'     => float,
	 *         'lifetime_revenue' => float,
	 *       ],
	 *       ...
	 *     ],
	 *   ]
	 *
	 * `new_subs` (by `_schedule_start`) and `churned_subs` (by
	 * `_schedule_cancelled`) are windowed to `[$start, $end]`. The other
	 * three aggregates are current-state / lifetime (see HPOS_Storage's
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

	// -------------------------------------------------------------------------
	// Conversion Journey (Tab 3) methods — added for NPPD-1609 Phase 2B.
	// -------------------------------------------------------------------------

	/**
	 * Count of active non-donation subscriptions (type `shop_subscription`,
	 * status `wc-active`, product NOT IN donation IDs) that have a non-empty
	 * `_schedule_payment_retry` meta — i.e. a payment retry is currently
	 * scheduled. Point-in-time snapshot.
	 *
	 * @return int
	 */
	public function get_at_risk_subscribers(): int;

	/**
	 * DISTINCT customer_ids that currently have at least one active non-donation
	 * subscription (type `shop_subscription`, status `wc-active`, product NOT IN
	 * donation IDs). Same population as {@see get_active_non_donation_subscribers()}
	 * but returns the ID set rather than a count.
	 *
	 * @return int[]
	 */
	public function get_active_non_donation_subscriber_customer_ids(): array;

	/**
	 * Count of REGISTERED READERS who have no active non-donation subscription
	 * AND no completed donation order in the trailing 365 days.
	 *
	 * Phase-A approximation: the base population is WordPress users bearing the
	 * `np_reader` user meta (the canonical Reader Activation signal written at
	 * reader registration time). Users whose `np_reader` meta is empty or absent
	 * but who hold a 'subscriber' or 'customer' role are also included as a
	 * non-strict fallback, mirroring
	 * {@see \Newspack\Reader_Activation::is_user_reader()} without a filter layer.
	 * Administrators and editors are excluded (same restricted_roles default as
	 * the production is_user_reader() call).
	 *
	 * Excludes from the base population:
	 *   - users with ≥1 active non-donation subscription today, AND
	 *   - users with ≥1 completed donation order (product IN donation IDs,
	 *     status wc-completed/wc-processing) in the trailing 365 days.
	 *
	 * The "no activity in 90 days" BQ refinement that distinguishes truly stale
	 * readers from recently-active ones who simply haven't converted is deferred
	 * to Phase B (requires the BQ reader-activity export). This count is
	 * therefore an upper bound on stale readers, not an exact match for the
	 * BigQuery definition.
	 *
	 * @return int
	 */
	public function get_stale_registered_users(): int;

	/**
	 * Earliest non-donation subscription start (`_schedule_start`) per customer,
	 * restricted to the given customer set. Same first-start-per-customer
	 * definition as {@see get_new_subscribers_in_window()}, but returns the
	 * dates rather than counting a window. Used by Tab 3 (Conversion Journey)
	 * to compute registration→subscription lag and to anchor the BQ
	 * source-match window.
	 *
	 * Customers in the input list with no non-donation subscription are absent
	 * from the result. Empty input returns `[]` with no DB round-trip.
	 *
	 * Not perfect parity with {@see get_new_subscribers_in_window()} in
	 * data-corruption edge cases: implementations additionally exclude rows
	 * with an empty `_schedule_start` so a blank value can't yield a bogus
	 * epoch date. The window-count metric leans on its `BETWEEN` bounds to
	 * drop blanks implicitly, so the two reconcile on healthy data.
	 *
	 * @param int[] $customer_ids Customer IDs to look up.
	 * @return array<int, \DateTimeImmutable> customer_id => first subscription start (UTC).
	 */
	public function get_first_subscription_order_dates( array $customer_ids ): array;

	/**
	 * Registration dates of READER accounts created in the trailing 365 days,
	 * keyed by user ID. "Reader" matches the base population of
	 * {@see get_stale_registered_users()}: users bearing the `np_reader` meta,
	 * or holding a 'subscriber'/'customer' role, excluding administrators and
	 * editors. The full reader set is returned — converters AND non-converters —
	 * because 5.1 uses it as the cohort denominator.
	 *
	 * Phase-A reader-role approximation applies (hardcoded roles, no filter
	 * layer), same caveat as get_stale_registered_users().
	 *
	 * @return array<int, \DateTimeImmutable> user_id => user_registered (UTC).
	 */
	public function get_reader_registration_dates(): array;

	/**
	 * New non-donation subscribers in the window, each with the epoch timestamp
	 * (UTC seconds) of their FIRST subscription start plus order-meta sourced from
	 * the subscription's parent shop_order. Same population as
	 * {@see get_new_subscribers_in_window()} but returns one record per customer
	 * enriched with parent-order source attribution for Tab 3 source-mix 3.2.
	 * gate_post_id: non-empty _gate_post_id (or legacy _memberships_content_gate)
	 * from the first subscription's parent order; '' if absent. popup_id:
	 * non-empty _newspack_popup_id from the parent order; '' if absent. Records
	 * with both '' fall to the BQ temporal matcher in compute_source_mix.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	public function get_new_subscriber_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * All-history: every customer with a non-donation subscription AND a
	 * wp_users row, with their registration epoch and first-subscription epoch
	 * (both UTC seconds). Drives the 4.2 time-to-subscribe distribution
	 * (lag = first_sub_ts − registered_ts) and its user_registered source anchor.
	 *
	 * @return array<int, array{customer_id:int, registered_ts:int, first_sub_ts:int}>
	 */
	public function get_subscription_conversion_lags(): array;

	/**
	 * Prompt/gate-attributed subscription conversion orders in a window, sourced
	 * from order meta rather than the GA4 attempt → customer_id join (NPPD-1746,
	 * the subscription counterpart to the donation reader in
	 * {@see Donors_Storage_Interface::get_prompt_attributed_donation_conversions()}).
	 *
	 * A subscription/paywall order can carry `_gate_post_id` (it stemmed from a
	 * paywall gate) OR `_newspack_popup_id` (it stemmed from a popup) OR neither
	 * (the subscriptions landing page / organic). This is therefore a TWO-KEY
	 * surface: the method returns, per order, the one gate id and the one popup id
	 * resolved off the order, leaving gate-vs-popup precedence and bucketing to the
	 * orchestrator ({@see \Newspack\Insights\Subscribers_Metric}). Meta-absence is
	 * not a coverage gap — organic orders carry neither key and are correctly
	 * excluded from a prompt/gate conversion metric. No fallback, no union.
	 *
	 * Scope: INITIAL completed/processing orders containing a NON-donation
	 * subscription product — renewal orders inherit the parent's meta and are
	 * excluded via `_subscription_renewal`. Both `_gate_post_id` and
	 * `_newspack_popup_id` can be written more than once per order (and through
	 * different code paths), so each id is derived via a correlated `MIN()` and the
	 * order is matched with `EXISTS` — never a multiplying JOIN — so duplicate meta
	 * cannot re-inflate counts or revenue (the NPPD-1685 2x defect). Only orders
	 * carrying at least one of the two keys are returned.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{order_id:int, gate_id:?string, popup_id:?string, order_total:float}>
	 */
	public function get_attributed_subscription_orders( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * New-subscription campaign attribution rows for the window (NEWS-2591).
	 *
	 * One row per INITIAL (non-renewal) completed/processing order containing a
	 * non-donation subscription product in [$start, $end]. utm_campaign is read off
	 * that initial order (same initial-order scope as get_attributed_subscription_orders(),
	 * minus its gate/popup requirement). Per store: HPOS reads wc_orders_meta via a
	 * MIN() subquery (utm_campaign is written non-unique, so a JOIN would inflate),
	 * legacy reads postmeta. Every subscription order is emitted; untagged orders
	 * carry '' so the grouping layer can surface a "(no campaign)" bucket.
	 *
	 * revenue = initial subscription order total (not recurring / MRR).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{utm_campaign: string, revenue: float}>
	 */
	public function get_new_subscription_campaign_rows( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Subscription schedule intervals for the trailing-365-day new-subscriber
	 * cohort (5.2 retention input). One row per (customer, non-donation
	 * subscription) for every customer whose EARLIEST non-donation subscription
	 * `_schedule_start` falls within the last 365 days. All of those customers'
	 * non-donation subscriptions are returned (not just the first) so the metric
	 * can evaluate "active at month N" across any active subscription, matching
	 * the {@see Donors_Storage_Interface::get_recurring_donor_retention()}
	 * active-at-T predicate.
	 *
	 * `start` is `_schedule_start` (`Y-m-d H:i:s` UTC, non-empty). `cancelled`
	 * and `end` are the raw `_schedule_cancelled` / `_schedule_end` meta values
	 * (may be `''`, `'0'`, or null — WCS's "not terminated" sentinels). Epoch
	 * conversion is the metric layer's job (tz-safe DateTimeImmutable).
	 *
	 * @return array<int, array{customer_id:int, start:string, cancelled:?string, end:?string}>
	 */
	public function get_new_subscriber_cohort_intervals(): array;
}
