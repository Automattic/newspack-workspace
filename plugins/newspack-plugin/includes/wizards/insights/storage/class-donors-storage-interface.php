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

	// -------------------------------------------------------------------------
	// Conversion Journey (Tab 3) methods — added for NPPD-1609 Phase 2B.
	// -------------------------------------------------------------------------

	/**
	 * COUNT(DISTINCT customer_id) among $subscriber_ids who completed a
	 * donation order (type `shop_order`, status IN wc-completed/wc-processing,
	 * product IN donation IDs) with `date_created_gmt` in [start, end].
	 *
	 * Empty $subscriber_ids returns 0 immediately (no DB round-trip).
	 *
	 * @param int[]             $subscriber_ids Customer IDs (active non-donation subscribers).
	 * @param DateTimeInterface $start          Inclusive window start.
	 * @param DateTimeInterface $end            Inclusive window end.
	 * @return int
	 */
	public function get_subscriber_donors_in_window( array $subscriber_ids, DateTimeInterface $start, DateTimeInterface $end ): int;

	/**
	 * COUNT(DISTINCT customer_id) among $customer_ids who have at least one
	 * completed donation order at any time (status wc-completed/wc-processing,
	 * product IN donation IDs). Empty input returns 0 immediately.
	 *
	 * @param int[] $customer_ids Customer IDs to check.
	 * @return int
	 */
	public function count_completed_donation_order_customers_by_customer_ids( array $customer_ids ): int;

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
	 *     'has_recurring'           => bool,
	 *     'has_one_time'            => bool,
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
	 *         'has_recurring'             => bool,
	 *         'has_one_time'              => bool,
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
	 * `has_recurring` / `has_one_time` are two INDEPENDENT flags derived
	 * from each contributing row's `_subscription_period` meta (recurring
	 * when the period is in day/week/month/year, else one-time). A leaf
	 * variation is purely one nature, so exactly one flag is set. A parent
	 * latches EACH flag true as matching variation rows land, so a variable
	 * product that has recurring variations AND a one-time (bare-parent)
	 * gift carries BOTH — the recurring signal never clobbers the one-time
	 * one. The UI shows a column iff its flag is set, rendering the rest as
	 * em-dashes ("—") instead of misleading zeros: a purely one-time product
	 * can't have recurring donors, recurring revenue, or lapsed donors; a
	 * purely recurring product can't have one-time gifts; a mixed product
	 * shows both.
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

	/**
	 * Earliest completed/processing donation order date per customer, restricted
	 * to the given customer set. Same first-donation-per-customer definition as
	 * {@see get_new_donors_in_window()}, but returns the dates rather than
	 * counting a window. Used by Tab 3 (Conversion Journey) to compute
	 * registration→donation lag and to anchor the BQ source-match window.
	 *
	 * Customers in the input list with no completed donation are absent from the
	 * result. Empty input returns `[]` with no DB round-trip.
	 *
	 * @param int[] $customer_ids Customer IDs to look up.
	 * @return array<int, \DateTimeImmutable> customer_id => first donation date (UTC).
	 */
	public function get_first_donation_order_dates( array $customer_ids ): array;

	/**
	 * New donors in the window, each with the epoch timestamp (UTC seconds) of
	 * their FIRST completed/processing donation order, plus order-meta source
	 * signals. Same population as {@see get_new_donors_in_window()} but returns
	 * one record per customer with the anchor timestamp for Source_Matcher
	 * (Tab 3 source-mix 3.3) and order-level meta for primary attribution.
	 *
	 * Field notes: gate_post_id = first non-empty _gate_post_id on the first-
	 * donation order (falls back to _memberships_content_gate if absent); '' if
	 * none. popup_id = first non-empty _newspack_popup_id; '' if none.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	public function get_new_donor_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * All-history: every customer with a completed/processing donation order AND
	 * a wp_users row, with their registration epoch and first-donation epoch
	 * (both UTC seconds). Drives the 4.3 time-to-donate distribution.
	 *
	 * @return array<int, array{customer_id:int, registered_ts:int, first_donation_ts:int}>
	 */
	public function get_donation_conversion_lags(): array;

	/**
	 * All-history cross-converters: customers with a first non-donation
	 * subscription and a STRICTLY-LATER first donation. Returns the lag in whole
	 * days (DATEDIFF(first_donation, first_sub)). Pure Woo — no wp_users join, so
	 * it counts cross-converters regardless of registration record. Drives 4.4.
	 *
	 * @return array<int, array{lag_days:int}>
	 */
	public function get_subscriber_to_donor_lags(): array;

	/**
	 * Prompt-attributed completed donation conversions in a window, sourced from
	 * order meta (`_newspack_popup_id`) (NPPD-1685). The originating prompt id is
	 * written onto the order at checkout
	 * ({@see \Newspack\Donations::checkout_create_order_line_item()}), alongside a
	 * real `customer_id` and total — capturing anonymous-at-attempt donors that
	 * GA4-based customer_id joins would silently drop.
	 *
	 * Scope: INITIAL completed/processing donation orders only — recurring renewal
	 * orders inherit the parent's `_newspack_popup_id` and are excluded via
	 * `_subscription_renewal` — with `date_created_gmt` in [start, end], grouped by
	 * popup id. Meta-absence is NOT a coverage gap: orders without the meta did not
	 * stem from a prompt and are correctly excluded. No fallback, no union.
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<string, array{conversions:int, revenue:float}> popup_id => { conversions, revenue }.
	 */
	public function get_prompt_attributed_donation_conversions( DateTimeInterface $start, DateTimeInterface $end ): array;

	/**
	 * Donation campaign attribution rows for the window (NEWS-2580).
	 *
	 * One row per completed/processing donation order in [$start, $end]. utm_campaign
	 * is read from the donation order meta via the same read path as
	 * get_prompt_attributed_donation_conversions() (_newspack_popup_id). Per store:
	 * HPOS reads wc_orders_meta via a MIN() subquery (utm_campaign is written
	 * non-unique, so a JOIN would inflate), legacy reads postmeta. Every donation
	 * order is emitted; untagged orders carry '' so the grouping layer can surface a
	 * "(no campaign)" bucket. Renewals excluded.
	 *
	 * revenue = donation order total (one-time gift amount).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{utm_campaign: string, revenue: float}>
	 */
	public function get_donation_campaign_rows( DateTimeInterface $start, DateTimeInterface $end ): array;
}
