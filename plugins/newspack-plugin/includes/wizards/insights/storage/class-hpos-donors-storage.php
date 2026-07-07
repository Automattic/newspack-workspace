<?php
/**
 * Newspack Insights — HPOS Donors Storage implementation (NPPD-1617).
 *
 * Tab 7 counterpart to {@see HPOS_Storage}. Same backend (HPOS tables
 * `{prefix}wc_orders`, `{prefix}wc_orders_meta`), inverted donation
 * filter — Tab 6 uses `NOT IN (:donation_product_ids)`, Tab 7 uses
 * `IN (:donation_product_ids)`. Donation IDs injected at construction
 * by the orchestrator.
 *
 * SQL bodies sourced from `~/Sites/insights-docs/formulas/tab-7-donors.md`
 * with the user-spec metric composition layered on top — Active Donors
 * UNION, windowed Lapsed Donors, Recovery Rate, and Retention are not
 * verbatim in the doc but are built from doc primitives per the
 * declared user spec.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * HPOS implementation of the Tab 7 storage contract.
 */
class HPOS_Donors_Storage implements Donors_Storage_Interface {

	/**
	 * Donation product IDs to include in metric queries.
	 *
	 * @var int[]
	 */
	private $donation_product_ids;

	/**
	 * Constructor.
	 *
	 * @param int[] $donation_product_ids Donation product IDs. Should
	 *                                    be non-empty; the orchestrator
	 *                                    guards the boot config so the
	 *                                    tab is hidden when this is
	 *                                    empty. id_list() still coerces
	 *                                    empty to `(0)` defensively.
	 */
	public function __construct( array $donation_product_ids ) {
		$this->donation_product_ids = array_map( 'intval', $donation_product_ids );
	}

	/**
	 * SQL-safe `IN (...)` list from integer IDs. Empty -> `0`.
	 *
	 * @param int[] $ids Integer IDs.
	 * @return string Comma-separated integers.
	 */
	private function id_list( array $ids ): string {
		if ( empty( $ids ) ) {
			return '0';
		}
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * Format a DateTime for SQL.
	 *
	 * Normalizes to UTC: window bounds arrive in site timezone (the REST
	 * controller builds them with wp_timezone()), but they are compared against
	 * `date_created_gmt` / `_schedule_*` columns stored in UTC. Matches
	 * {@see HPOS_Storage::fmt()} so windowed donor queries don't skew on
	 * non-UTC sites.
	 *
	 * @param DateTimeInterface $dt DateTime.
	 * @return string Y-m-d H:i:s (UTC).
	 */
	private function fmt( DateTimeInterface $dt ): string {
		return gmdate( 'Y-m-d H:i:s', $dt->getTimestamp() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_donors(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// UNION of two paths, deduped by customer_id. Path (a): any
		// customer with a wc-active donation subscription. Path (b):
		// any customer with a completed donation shop_order in the
		// trailing 365 days.
		$sql = "SELECT COUNT(*) FROM (
				SELECT DISTINCT o.customer_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND o.status = 'wc-active'
				  AND oim.meta_value IN ($donations)
				UNION
				SELECT DISTINCT o.customer_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
				WHERE o.type = 'shop_order'
				  AND o.status IN ('wc-completed', 'wc-processing')
				  AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
				  AND opl.product_id IN ($donations)
			) AS active_donor_set
			WHERE customer_id > 0";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_recurring_donors(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT COUNT(DISTINCT o.customer_id)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value IN ($donations)";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Per the formula doc's per-line-item attribution model: reads
	 * frequency from the product's `_subscription_period` /
	 * `_subscription_period_interval` rather than the subscription's
	 * own billing meta. For multi-line-item donation subscriptions
	 * (rare for canonical Newspack family) each line item contributes
	 * its own per-line-item MRR.
	 */
	public function get_donation_mrr(): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Per-line-item attribution: normalize each donation line item's own
		// recurring total (`_line_total`) to a monthly rate. Using the line
		// item total rather than the subscription's `total_amount` keeps a
		// multi-line-item donation subscription from summing the full
		// subscription total once per line item (which would overstate MRR).
		$sql = "SELECT SUM(
				CASE
					WHEN prd.meta_value = 'month' AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(20,2)) / CAST(pri.meta_value AS UNSIGNED)
					WHEN prd.meta_value = 'year'  AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(20,2)) / (12 * CAST(pri.meta_value AS UNSIGNED))
					WHEN prd.meta_value = 'week'  AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(20,2)) * (52/12) / CAST(pri.meta_value AS UNSIGNED)
					WHEN prd.meta_value = 'day'   AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(20,2)) * 30 / CAST(pri.meta_value AS UNSIGNED)
					ELSE CAST(lt.meta_value AS DECIMAL(20,2)) / 12
				END
			)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}woocommerce_order_itemmeta lt
				ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
			JOIN {$prefix}postmeta prd
				ON prd.post_id = CAST(oim.meta_value AS UNSIGNED) AND prd.meta_key = '_subscription_period'
			JOIN {$prefix}postmeta pri
				ON pri.post_id = CAST(oim.meta_value AS UNSIGNED) AND pri.meta_key = '_subscription_period_interval'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value IN ($donations)";

		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_donation_renewals_30d(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// DISTINCT id-subselect for the donation filter so a multi-line-item
		// subscription is counted once and its total_amount isn't summed twice.
		// Mirrors Tab 6's upcoming-renewals query exactly, with the donation
		// filter flipped from NOT IN to IN.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS upcoming_count,
				COALESCE(SUM(o.total_amount), 0) AS upcoming_value
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta om
				ON om.order_id = o.id AND om.meta_key = '_schedule_next_payment'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value IN ($donations)
			  )
			  AND om.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)",
			ARRAY_A
		);

		return [
			'count'       => (int) ( $row['upcoming_count'] ?? 0 ),
			'total_value' => (float) ( $row['upcoming_value'] ?? 0 ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{count: int, total_value: float}
	 */
	public function get_upcoming_donation_cancellations_30d(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Mirrors Tab 6's upcoming-cancellations query exactly with
		// the donation filter flipped from NOT IN to IN.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS upcoming_count,
				COALESCE(SUM(o.total_amount), 0) AS upcoming_value
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta em
				ON em.order_id = o.id AND em.meta_key = '_schedule_end'
			WHERE o.type = 'shop_subscription'
			  AND o.status IN ('wc-active', 'wc-pending-cancel')
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value IN ($donations)
			  )
			  AND em.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)",
			ARRAY_A
		);

		return [
			'count'       => (int) ( $row['upcoming_count'] ?? 0 ),
			'total_value' => (float) ( $row['upcoming_value'] ?? 0 ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_new_donors_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// First donation MIN per customer; outer count filters to window.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT o.customer_id, MIN(o.date_created_gmt) AS first_donation_date
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
				WHERE o.type = 'shop_order'
				  AND o.status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				GROUP BY o.customer_id
			) AS first_donations
			WHERE first_donation_date BETWEEN %s AND %s",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Tab 6 churn pattern scoped to donation products: customers whose
	 * donation subscriptions cancelled/expired in window AND who currently
	 * have no active donation subscription. Cancelled/expired side
	 * pre-aggregated to DISTINCT customer_ids and LEFT-JOINed against the
	 * DISTINCT active-donation-subscriber customer_ids (also pre-aggregated),
	 * keeping only rows where the active side is NULL — an anti-join, not a
	 * correlated `NOT IN (subquery)` (MySQL's slowest anti-join form). Same
	 * approach as {@see HPOS_Storage::get_churned_subscribers_in_window()}.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_lapsed_donors_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT cancellations.customer_id) FROM (
				SELECT DISTINCT o.customer_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_cancelled'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND o.status IN ('wc-cancelled', 'wc-expired')
				  AND oim.meta_value IN ($donations)
				  AND om.meta_value BETWEEN %s AND %s
				  AND om.meta_value != ''
			) AS cancellations
			LEFT JOIN (
				SELECT DISTINCT o2.customer_id
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = o2.id AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE o2.type = 'shop_subscription'
				  AND o2.status = 'wc-active'
				  AND oim2.meta_value IN ($donations)
			) AS active ON active.customer_id = cancellations.customer_id
			WHERE active.customer_id IS NULL",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_one_time_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float {
		return $this->get_donation_revenue_filtered( $start, $end, 'one_time' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_recurring_donation_revenue( DateTimeInterface $start, DateTimeInterface $end ): float {
		return $this->get_donation_revenue_filtered( $start, $end, 'recurring' );
	}

	/**
	 * Shared body for one-time vs recurring donation revenue. Filters
	 * by the presence/absence of a `_subscription_period` postmeta on
	 * the order's donation line-item product. One-time = no period
	 * meta or meta NOT IN month/year/week/day. Recurring = period meta
	 * IN month/year/week/day.
	 *
	 * @param DateTimeInterface $start  Window start.
	 * @param DateTimeInterface $end    Window end.
	 * @param string            $mode   'one_time' | 'recurring'.
	 * @return float
	 */
	private function get_donation_revenue_filtered( DateTimeInterface $start, DateTimeInterface $end, string $mode ): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$period_predicate = 'recurring' === $mode
			? "EXISTS (SELECT 1 FROM {$prefix}postmeta pm
					WHERE pm.post_id = opl.product_id
					  AND pm.meta_key = '_subscription_period'
					  AND pm.meta_value IN ('day','week','month','year'))"
			: "NOT EXISTS (SELECT 1 FROM {$prefix}postmeta pm
					WHERE pm.post_id = opl.product_id
					  AND pm.meta_key = '_subscription_period'
					  AND pm.meta_value IN ('day','week','month','year'))";

		$sql = $wpdb->prepare(
			"SELECT SUM(o.total_amount)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND opl.product_id IN ($donations)
			  AND $period_predicate",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_average_donation_gift( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Restrict to one-time gifts only. Including renewal orders +
		// subscription initial installments would dilute the metric:
		// renewals are predictable amounts that say more about
		// retention than donor generosity, and a sub initial order is
		// "first slice of a recurring commitment" not a gift in the
		// donor's mental model. Use the same period-meta predicate
		// that scopes get_one_time_donation_revenue so the two
		// metrics agree on what "one-time" means.
		$sql = $wpdb->prepare(
			"SELECT AVG(o.total_amount)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND opl.product_id IN ($donations)
			  AND NOT EXISTS (
				SELECT 1 FROM {$prefix}postmeta pm
				WHERE pm.post_id = opl.product_id
				  AND pm.meta_key = '_subscription_period'
				  AND pm.meta_value IN ('day','week','month','year')
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$avg = $wpdb->get_var( $sql );
		return null === $avg ? 0.0 : (float) $avg;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The prior-window lapsed cohort used to be pulled into a PHP array and
	 * serialized back into an `o.customer_id IN ($lapsed_list)` filter for the
	 * "recovered" query — slow, and a `max_allowed_packet` risk on large
	 * cohorts. Now computed as a single reusable derived table (`lapsed`,
	 * itself an anti-join against active donation subscribers — see
	 * {@see self::get_lapsed_donors_in_window()}) that both the denominator
	 * COUNT and the numerator's JOIN read from directly, eliminating the PHP
	 * round trip entirely.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_lapsed_donor_recovery_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		// Prior window of equal length immediately preceding current.
		$duration         = $end->getTimestamp() - $start->getTimestamp();
		$prior_end_ts     = $start->getTimestamp() - 1;
		$prior_start_ts   = $prior_end_ts - $duration;
		$prior_start_iso  = gmdate( 'Y-m-d H:i:s', $prior_start_ts );
		$prior_end_iso    = gmdate( 'Y-m-d H:i:s', $prior_end_ts );
		$current_start_iso = $this->fmt( $start );
		$current_end_iso   = $this->fmt( $end );

		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Lapsed-in-prior cohort (same shape as get_lapsed_donors_in_window
		// but with explicit prior window bounds rather than current window),
		// reused by both the denominator and numerator queries below.
		// Prepared once with the prior-window bounds baked in as literals;
		// nesting a second prepare() around an interpolated %s-bearing string
		// would double-escape / mis-count placeholders.
		$lapsed_cte = $wpdb->prepare(
			"SELECT DISTINCT cancellations.customer_id
			FROM (
				SELECT DISTINCT o.customer_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_cancelled'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND o.status IN ('wc-cancelled', 'wc-expired')
				  AND oim.meta_value IN ($donations)
				  AND om.meta_value BETWEEN %s AND %s
				  AND om.meta_value != ''
			) AS cancellations
			LEFT JOIN (
				SELECT DISTINCT o2.customer_id
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = o2.id AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE o2.type = 'shop_subscription'
				  AND o2.status = 'wc-active'
				  AND oim2.meta_value IN ($donations)
			) AS active ON active.customer_id = cancellations.customer_id
			WHERE active.customer_id IS NULL",
			$prior_start_iso,
			$prior_end_iso
		);

		$lapsed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$lapsed_cte}) AS lapsed" );
		if ( 0 === $lapsed_count ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}

		// Of the lapsed cohort, who made a NEW completed donation order
		// in the current window.
		$recovered_sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT lapsed.customer_id)
			FROM ({$lapsed_cte}) AS lapsed
			JOIN {$prefix}wc_orders o ON o.customer_id = lapsed.customer_id
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND opl.product_id IN ($donations)",
			$current_start_iso,
			$current_end_iso
		);
		$recovered = (int) $wpdb->get_var( $recovered_sql );

		return [
			'value'       => $recovered / $lapsed_count,
			'computable'  => true,
			'denominator' => $lapsed_count,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end (unused — see docblock).
	 * @return float
	 */
	public function get_recurring_donor_retention( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );
		unset( $end ); // included in cache key by orchestrator; SQL uses NOW for the "still active" check.

		// Subscriptions active at :start (subscription start <= :start AND
		// not cancelled before :start). The active-at-start cohort used to
		// be pulled into a PHP array and serialized back into an
		// `o.customer_id IN ($customer_list)` filter for the numerator query —
		// slow, and a `max_allowed_packet` "MySQL server has gone away"
		// trigger on large cohorts. Prepared once with the :start bound
		// baked in as a literal, then reused (already-escaped) as a derived
		// table by both the denominator COUNT and the numerator JOIN below —
		// nesting a second prepare() around an interpolated %s-bearing
		// string would double-escape / mis-count placeholders.
		$active_at_start_cte = $wpdb->prepare(
			"SELECT DISTINCT o.customer_id
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta start_meta
				ON start_meta.order_id = o.id AND start_meta.meta_key = '_schedule_start'
			LEFT JOIN {$prefix}wc_orders_meta cancel_meta
				ON cancel_meta.order_id = o.id AND cancel_meta.meta_key = '_schedule_cancelled'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND oim.meta_value IN ($donations)
			  AND start_meta.meta_value != ''
			  AND start_meta.meta_value <= %s
			  AND (
				cancel_meta.meta_value IS NULL
				OR cancel_meta.meta_value = ''
				OR cancel_meta.meta_value = '0'
				OR cancel_meta.meta_value > %s
			  )",
			$this->fmt( $start ),
			$this->fmt( $start )
		);

		// Denominator: distinct customers who were active at start.
		$denominator = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$active_at_start_cte}) AS active_at_start" );
		if ( 0 === $denominator ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}

		// Numerator: those customers who still have at least one
		// active donation subscription right now.
		$numerator_sql = "SELECT COUNT(DISTINCT active_at_start.customer_id)
			FROM ({$active_at_start_cte}) AS active_at_start
			JOIN {$prefix}wc_orders o ON o.customer_id = active_at_start.customer_id
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value IN ($donations)";
		$numerator     = (int) $wpdb->get_var( $numerator_sql );

		return [
			'value'       => $numerator / $denominator,
			'computable'  => true,
			'denominator' => $denominator,
		];
	}

	// -------------------------------------------------------------------------
	// Conversion Journey (Tab 3) storage methods.
	// -------------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 *
	 * @param int[]             $subscriber_ids Customer IDs (active non-donation subscribers).
	 * @param DateTimeInterface $start          Inclusive window start.
	 * @param DateTimeInterface $end            Inclusive window end.
	 * @return int
	 */
	public function get_subscriber_donors_in_window( array $subscriber_ids, DateTimeInterface $start, DateTimeInterface $end ): int {
		if ( empty( $subscriber_ids ) ) {
			return 0;
		}

		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );
		$ids       = $this->id_list( $subscriber_ids );

		// Donation orders (shop_order, completed/processing) within the window
		// for customers who are in the subscriber list.
		// Uses wc_order_product_lookup as the sibling get_new_donors_in_window()
		// does — per the interface doc, shop_order queries use this table.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT o.customer_id)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND opl.product_id IN ($donations)
			  AND o.customer_id IN ($ids)",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_donations_by_tier( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		/*
		 * Returns flat per-variation (or per-simple-product) rows with
		 * parent info attached. PHP aggregation below rolls
		 * variations under their parent. The columns produced:
		 *
		 *   variation_id, variation_name, parent_id, parent_name,
		 *   sub_period (for label generation), active_recurring_donors,
		 *   lapsed_donors_in_window, new_donors_in_window,
		 *   one_time_gifts_in_window, recurring_revenue_in_window,
		 *   lifetime_donation_revenue
		 *
		 * Three passes — the metrics scope on different order types
		 * and statuses so a single GROUP BY can't cover them all:
		 *   pass 1: shop_subscription, status = wc-active → active_recurring_donors
		 *   pass 2: shop_subscription, status IN (wc-cancelled, wc-expired)
		 *           cancelled in window AND customer has no current
		 *           active donation sub → lapsed_donors_in_window
		 *   pass 3: shop_order → window/lifetime revenue + gift counts
		 * Merge by product_id in PHP.
		 */

		// Pass 1: subscription-side — active recurring donors per
		// (effective) product.
		$subs_sql = "SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT o.customer_id) AS active_recurring_donors
			FROM {$prefix}wc_orders o
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta pid_meta
				ON pid_meta.order_item_id = oi.order_item_id AND pid_meta.meta_key = '_product_id'
			LEFT JOIN {$prefix}woocommerce_order_itemmeta vid_meta
				ON vid_meta.order_item_id = oi.order_item_id AND vid_meta.meta_key = '_variation_id'
			JOIN {$prefix}posts pv
				ON pv.ID = COALESCE( NULLIF( CAST(vid_meta.meta_value AS UNSIGNED), 0 ), CAST(pid_meta.meta_value AS UNSIGNED) )
			LEFT JOIN {$prefix}posts pp ON pp.ID = pv.post_parent
			LEFT JOIN {$prefix}postmeta period_meta
				ON period_meta.post_id = pv.ID AND period_meta.meta_key = '_subscription_period'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND pid_meta.meta_value IN ($donations)
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period";

		$subs_rows = $wpdb->get_results( $subs_sql, ARRAY_A );

		// Pass 2: per-tier lapsed donors. Same churn pattern as the
		// {@see get_lapsed_donors_in_window} scorecard (cancelled or
		// expired in window AND customer has no current active
		// donation sub), but bucketed per (effective) product instead
		// of aggregated. A customer who cancelled subs for multiple
		// donation products in the same window will count once per
		// product row, so SUM(lapsed_donors_in_window) across rows can
		// exceed the scorecard. In Newspack's typical data shape a
		// donor only has one recurring donation, so this reconciles
		// cleanly in practice. The active-subscriber exclusion is a LEFT
		// JOIN anti-join (`active.customer_id IS NULL`) against a
		// pre-aggregated DISTINCT active-donation-subscriber derived table,
		// not a correlated `NOT IN (subquery)` (MySQL's slowest anti-join
		// form) — same approach as {@see self::get_lapsed_donors_in_window()}.
		$lapsed_sql = $wpdb->prepare(
			"SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT o.customer_id) AS lapsed_donors_in_window
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta cm
				ON cm.order_id = o.id AND cm.meta_key = '_schedule_cancelled'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta pid_meta
				ON pid_meta.order_item_id = oi.order_item_id AND pid_meta.meta_key = '_product_id'
			LEFT JOIN {$prefix}woocommerce_order_itemmeta vid_meta
				ON vid_meta.order_item_id = oi.order_item_id AND vid_meta.meta_key = '_variation_id'
			JOIN {$prefix}posts pv
				ON pv.ID = COALESCE( NULLIF( CAST(vid_meta.meta_value AS UNSIGNED), 0 ), CAST(pid_meta.meta_value AS UNSIGNED) )
			LEFT JOIN {$prefix}posts pp ON pp.ID = pv.post_parent
			LEFT JOIN {$prefix}postmeta period_meta
				ON period_meta.post_id = pv.ID AND period_meta.meta_key = '_subscription_period'
			LEFT JOIN (
				SELECT DISTINCT o2.customer_id
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = o2.id AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE o2.type = 'shop_subscription'
				  AND o2.status = 'wc-active'
				  AND oim2.meta_value IN ($donations)
			) AS active ON active.customer_id = o.customer_id
			WHERE o.type = 'shop_subscription'
			  AND o.status IN ('wc-cancelled', 'wc-expired')
			  AND pid_meta.meta_value IN ($donations)
			  AND cm.meta_value BETWEEN %s AND %s
			  AND cm.meta_value != ''
			  AND active.customer_id IS NULL
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$lapsed_rows = $wpdb->get_results( $lapsed_sql, ARRAY_A );

		// Pass 3: shop_order-side metrics — new donors, one-time gifts,
		// recurring revenue, lifetime revenue. Keyed by opl.product_id
		// (the actual purchased product; no variation indirection
		// because opl stores the line-item product directly for
		// shop_orders).
		$orders_sql = $wpdb->prepare(
			"SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT CASE
					WHEN o.date_created_gmt BETWEEN %s AND %s
					 AND nd.first_donation_date BETWEEN %s AND %s
					THEN o.customer_id
				END) AS new_donors_in_window,
				COUNT(DISTINCT CASE
					WHEN o.date_created_gmt BETWEEN %s AND %s
					 AND COALESCE(period_meta.meta_value, '') NOT IN ('day','week','month','year')
					THEN o.id
				END) AS one_time_gifts_in_window,
				COALESCE(SUM(CASE
					WHEN o.date_created_gmt BETWEEN %s AND %s
					 AND period_meta.meta_value IN ('day','week','month','year')
					THEN o.total_amount
				END), 0) AS recurring_revenue_in_window,
				COALESCE(SUM(o.total_amount), 0) AS lifetime_donation_revenue
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
			JOIN {$prefix}posts pv ON pv.ID = opl.product_id
			LEFT JOIN {$prefix}posts pp ON pp.ID = pv.post_parent
			LEFT JOIN {$prefix}postmeta period_meta
				ON period_meta.post_id = pv.ID AND period_meta.meta_key = '_subscription_period'
			LEFT JOIN (
				-- Column prefix on customer_id is required: both
				-- wc_orders and wc_order_product_lookup carry that column,
				-- and an unqualified reference silently resolves to the
				-- opl side (which is 0 for most analytics rows), so
				-- GROUP BY collapses every row into one and the JOIN
				-- below matches nothing.
				SELECT o2.customer_id, MIN(o2.date_created_gmt) AS first_donation_date
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}wc_order_product_lookup opl2 ON opl2.order_id = o2.id
				WHERE o2.type = 'shop_order'
				  AND o2.status IN ('wc-completed','wc-processing')
				  AND opl2.product_id IN ($donations)
				GROUP BY o2.customer_id
			) AS nd ON nd.customer_id = o.customer_id
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed','wc-processing')
			  AND opl.product_id IN ($donations)
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period",
			$this->fmt( $start ),
			$this->fmt( $end ),
			$this->fmt( $start ),
			$this->fmt( $end ),
			$this->fmt( $start ),
			$this->fmt( $end ),
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$orders_rows = $wpdb->get_results( $orders_sql, ARRAY_A );

		// Merge by variation_id. All three passes return the same
		// per-product metadata (variation_id, names, parent, period).
		// Fill in zeros for products that appear in only some of the
		// passes (e.g. a one-time product never appears in subs or
		// lapsed; a recurring product with no orders in the window
		// only appears in subs/lapsed).
		$by_id = [];
		foreach ( $subs_rows as $row ) {
			$by_id[ (int) $row['variation_id'] ] = [
				'variation_id'                => (int) $row['variation_id'],
				'variation_name'              => (string) $row['variation_name'],
				'parent_id'                   => (int) $row['parent_id'],
				'parent_name'                 => (string) $row['parent_name'],
				'sub_period'                  => (string) $row['sub_period'],
				'active_recurring_donors'     => (int) $row['active_recurring_donors'],
				'lapsed_donors_in_window'     => 0,
				'new_donors_in_window'        => 0,
				'one_time_gifts_in_window'    => 0,
				'recurring_revenue_in_window' => 0.0,
				'lifetime_donation_revenue'   => 0.0,
			];
		}
		foreach ( $lapsed_rows as $row ) {
			$id = (int) $row['variation_id'];
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = [
					'variation_id'                => $id,
					'variation_name'              => (string) $row['variation_name'],
					'parent_id'                   => (int) $row['parent_id'],
					'parent_name'                 => (string) $row['parent_name'],
					'sub_period'                  => (string) $row['sub_period'],
					'active_recurring_donors'     => 0,
					'lapsed_donors_in_window'     => 0,
					'new_donors_in_window'        => 0,
					'one_time_gifts_in_window'    => 0,
					'recurring_revenue_in_window' => 0.0,
					'lifetime_donation_revenue'   => 0.0,
				];
			}
			$by_id[ $id ]['lapsed_donors_in_window'] = (int) $row['lapsed_donors_in_window'];
		}
		foreach ( $orders_rows as $row ) {
			$id = (int) $row['variation_id'];
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = [
					'variation_id'            => $id,
					'variation_name'          => (string) $row['variation_name'],
					'parent_id'               => (int) $row['parent_id'],
					'parent_name'             => (string) $row['parent_name'],
					'sub_period'              => (string) $row['sub_period'],
					'active_recurring_donors' => 0,
					'lapsed_donors_in_window' => 0,
				];
			}
			$by_id[ $id ]['new_donors_in_window']        = (int) $row['new_donors_in_window'];
			$by_id[ $id ]['one_time_gifts_in_window']    = (int) $row['one_time_gifts_in_window'];
			$by_id[ $id ]['recurring_revenue_in_window'] = (float) $row['recurring_revenue_in_window'];
			$by_id[ $id ]['lifetime_donation_revenue']   = (float) $row['lifetime_donation_revenue'];
		}

		return $this->aggregate_tier_rows( array_values( $by_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $customer_ids Customer IDs to look up.
	 * @return array<int, \DateTimeImmutable>
	 */
	public function get_first_donation_order_dates( array $customer_ids ): array {
		if ( empty( $customer_ids ) ) {
			return [];
		}

		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );
		$ids       = $this->id_list( $customer_ids );

		// Earliest completed/processing donation order date per customer, scoped
		// to the given customer set. Mirrors the inner aggregate of
		// get_new_donors_in_window() (same first-donation definition).
		$sql = "SELECT o.customer_id, MIN(o.date_created_gmt) AS first_donation_date
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND opl.product_id IN ($donations)
			  AND o.customer_id IN ($ids)
			GROUP BY o.customer_id";

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$map  = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['first_donation_date'] ) ) {
				continue;
			}
			$map[ (int) $row['customer_id'] ] = new \DateTimeImmutable( $row['first_donation_date'], new \DateTimeZone( 'UTC' ) );
		}
		return $map;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	public function get_new_donor_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Same first-donation-per-customer population as get_new_donors_in_window()
		// (earliest completed/processing donation order, first occurrence in window).
		// Guest orders (customer_id = 0) excluded: no user account to match against
		// BQ registration events — same rationale as the old implementation.
		//
		// Order-meta subqueries: correlated against the FIRST donation order for each
		// customer (the order whose date_created_gmt = the outer MIN). MySQL 5.7-safe —
		// no window functions.
		// gate_post_id: first non-empty _gate_post_id, falling back to legacy
		// _memberships_content_gate (both NOT IN ('','0')).
		// popup_id:     non-empty _newspack_popup_id.
		// Empty / missing → '' returned for that column; the metric layer uses
		// classify_source() which treats '' as "no meta" and falls to the matcher.
		//
		// Same-timestamp ties (two orders with the same MIN date) are resolved by the
		// outer GROUP BY: MySQL picks an arbitrary first-order ID for each customer,
		// which is acceptable — the meta is only a fallback signal, not an exact ID.
		$sql = $wpdb->prepare(
			"SELECT
				fd.customer_id,
				fd.first_donation_date,
				COALESCE((
					SELECT om_gate.meta_value
					FROM {$prefix}wc_orders o_first
					JOIN {$prefix}wc_orders_meta om_gate
						ON om_gate.order_id = o_first.id AND om_gate.meta_key = '_gate_post_id'
					JOIN {$prefix}wc_order_product_lookup opl_first ON opl_first.order_id = o_first.id
					WHERE o_first.type = 'shop_order'
					  AND o_first.status IN ('wc-completed', 'wc-processing')
					  AND opl_first.product_id IN ($donations)
					  AND o_first.customer_id = fd.customer_id
					  AND o_first.date_created_gmt = fd.first_donation_date
					  AND om_gate.meta_value NOT IN ('', '0')
					LIMIT 1
				), (
					SELECT om_legacy.meta_value
					FROM {$prefix}wc_orders o_first
					JOIN {$prefix}wc_orders_meta om_legacy
						ON om_legacy.order_id = o_first.id AND om_legacy.meta_key = '_memberships_content_gate'
					JOIN {$prefix}wc_order_product_lookup opl_first ON opl_first.order_id = o_first.id
					WHERE o_first.type = 'shop_order'
					  AND o_first.status IN ('wc-completed', 'wc-processing')
					  AND opl_first.product_id IN ($donations)
					  AND o_first.customer_id = fd.customer_id
					  AND o_first.date_created_gmt = fd.first_donation_date
					  AND om_legacy.meta_value NOT IN ('', '0')
					LIMIT 1
				), '') AS gate_post_id,
				COALESCE((
					SELECT om_popup.meta_value
					FROM {$prefix}wc_orders o_first
					JOIN {$prefix}wc_orders_meta om_popup
						ON om_popup.order_id = o_first.id AND om_popup.meta_key = '_newspack_popup_id'
					JOIN {$prefix}wc_order_product_lookup opl_first ON opl_first.order_id = o_first.id
					WHERE o_first.type = 'shop_order'
					  AND o_first.status IN ('wc-completed', 'wc-processing')
					  AND opl_first.product_id IN ($donations)
					  AND o_first.customer_id = fd.customer_id
					  AND o_first.date_created_gmt = fd.first_donation_date
					  AND om_popup.meta_value NOT IN ('', '0')
					LIMIT 1
				), '') AS popup_id
			FROM (
				SELECT o.customer_id, MIN(o.date_created_gmt) AS first_donation_date
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
				WHERE o.type = 'shop_order'
				  AND o.status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				  AND o.customer_id > 0
				GROUP BY o.customer_id
			) AS fd
			WHERE fd.first_donation_date BETWEEN %s AND %s",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return $this->rows_to_donor_records( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Map (customer_id, first_donation_date, gate_post_id, popup_id) rows to
	 * donor source records with UTC epoch seconds. Blank dates are skipped.
	 * UTC parse keeps the epoch correct regardless of MySQL session timezone.
	 *
	 * @param mixed $rows wpdb->get_results( …, ARRAY_A ) output.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	private function rows_to_donor_records( $rows ): array {
		$utc     = new \DateTimeZone( 'UTC' );
		$records = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['first_donation_date'] ) ) {
				continue;
			}
			$records[] = [
				'customer_id'  => (int) $row['customer_id'],
				'ts'           => ( new \DateTimeImmutable( $row['first_donation_date'], $utc ) )->getTimestamp(),
				'gate_post_id' => (string) ( $row['gate_post_id'] ?? '' ),
				'popup_id'     => (string) ( $row['popup_id'] ?? '' ),
			];
		}
		return $records;
	}

	/**
	 * Aggregate flat per-variation rows into parent + nested
	 * variations shape. Mirrors the Tab 6 pattern but with Tab 7's
	 * five-metric column set.
	 *
	 *   - If parent_id > 0 (variation), attach to its parent's bucket
	 *     and accumulate the parent's aggregates from the variation's
	 *     numbers.
	 *   - If parent_id == 0, the row is either a standalone
	 *     simple/subscription donation product OR a bare-parent line item
	 *     on a VARIABLE donation product (a donation that recorded the
	 *     parent product with _variation_id = 0 — common because Newspack
	 *     donations are name-your-price variable products bought at the
	 *     parent level via Modal_Checkout). It resolves to the product's
	 *     own id and MERGES into that bucket: when the bucket also owns
	 *     variation rows it folds in as a synthetic "(no variation)"
	 *     variation instead of overwriting the bucket and discarding the
	 *     variations. A bucket that only ever sees this branch stays a
	 *     single non-parent entry.
	 *
	 * Each parent's variations are sorted by lifetime_donation_revenue
	 * DESC. The outer list is sorted by aggregated
	 * lifetime_donation_revenue DESC and truncated to top 50 — same
	 * "largest products first" convention as Tab 6's performance table.
	 *
	 * @param array<int, array<string, mixed>> $rows Merged per-variation rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function aggregate_tier_rows( array $rows ): array {
		$parents = [];

		foreach ( $rows as $row ) {
			$variation_id              = (int) $row['variation_id'];
			$variation_name            = (string) $row['variation_name'];
			$parent_id                 = (int) $row['parent_id'];
			$parent_name               = (string) $row['parent_name'];
			$period                    = (string) $row['sub_period'];
			$active_recurring_donors   = (int) $row['active_recurring_donors'];
			$lapsed_donors             = (int) ( $row['lapsed_donors_in_window'] ?? 0 );
			$new_donors                = (int) $row['new_donors_in_window'];
			$one_time_gifts            = (int) $row['one_time_gifts_in_window'];
			$recurring_revenue         = (float) $row['recurring_revenue_in_window'];
			$lifetime_donation_revenue = (float) $row['lifetime_donation_revenue'];

			$is_recurring = in_array( $period, [ 'day', 'week', 'month', 'year' ], true );

			// Resolve the bucket this row belongs under. Variation rows fold
			// into their parent product; bare-parent / standalone rows fold into
			// the product's own id. Keying both into the SAME map by the effective
			// product id and accumulating (rather than the parent branch creating
			// and the standalone branch overwriting) is what stops a bare-parent
			// donation from clobbering a variable product's accumulated variations.
			if ( $parent_id > 0 ) {
				$bucket_id   = $parent_id;
				$bucket_name = '' !== $parent_name ? $parent_name : __( '(unnamed product)', 'newspack-plugin' );
				$label       = $this->variation_label( $period, $variation_name, $parent_name );
			} else {
				$bucket_id   = $variation_id;
				$bucket_name = '' !== $variation_name ? $variation_name : __( '(unnamed product)', 'newspack-plugin' );
				$label       = __( '(no variation)', 'newspack-plugin' );
			}

			if ( ! isset( $parents[ $bucket_id ] ) ) {
				$parents[ $bucket_id ] = [
					'product_id'                  => $bucket_id,
					'name'                        => $bucket_name,
					'is_parent'                   => false,
					// A product's billing nature is tracked as two INDEPENDENT
					// flags, not a single promoted enum: a variable donation
					// product can be recurring AND one-time at once (e.g. a
					// Monthly/Yearly product that also took a one-time gift at the
					// parent level). Both start false and latch true as matching
					// rows land, so the recurring signal never clobbers the
					// one-time one. The renderer shows a column iff its flag is set.
					'has_recurring'               => false,
					'has_one_time'                => false,
					'active_recurring_donors'     => 0,
					'lapsed_donors_in_window'     => 0,
					'new_donors_in_window'        => 0,
					'one_time_gifts_in_window'    => 0,
					'recurring_revenue_in_window' => 0.0,
					'lifetime_donation_revenue'   => 0.0,
					'variations'                  => [],
				];
			}

			// A real variation row promotes the bucket to a parent and supplies
			// the canonical parent display name. Idempotent: a bare-parent row may
			// have seeded the bucket first under the same product's title.
			if ( $parent_id > 0 ) {
				$parents[ $bucket_id ]['is_parent'] = true;
				$parents[ $bucket_id ]['name']      = $bucket_name;
			}

			// Latch the bucket's billing nature. A recurring row sets has_recurring;
			// a one-time row sets has_one_time. A mixed product ends up with both.
			if ( $is_recurring ) {
				$parents[ $bucket_id ]['has_recurring'] = true;
			} else {
				$parents[ $bucket_id ]['has_one_time'] = true;
			}

			$parents[ $bucket_id ]['active_recurring_donors']     += $active_recurring_donors;
			$parents[ $bucket_id ]['lapsed_donors_in_window']     += $lapsed_donors;
			$parents[ $bucket_id ]['new_donors_in_window']        += $new_donors;
			$parents[ $bucket_id ]['one_time_gifts_in_window']    += $one_time_gifts;
			$parents[ $bucket_id ]['recurring_revenue_in_window'] += $recurring_revenue;
			$parents[ $bucket_id ]['lifetime_donation_revenue']   += $lifetime_donation_revenue;
			$parents[ $bucket_id ]['variations'][]                 = [
				'variation_id'                => $variation_id,
				'label'                       => $label,
				// A single variation row is purely one nature; exactly one flag set.
				'has_recurring'               => $is_recurring,
				'has_one_time'                => ! $is_recurring,
				'active_recurring_donors'     => $active_recurring_donors,
				'lapsed_donors_in_window'     => $lapsed_donors,
				'new_donors_in_window'        => $new_donors,
				'one_time_gifts_in_window'    => $one_time_gifts,
				'recurring_revenue_in_window' => $recurring_revenue,
				'lifetime_donation_revenue'   => $lifetime_donation_revenue,
			];
		}

		// Sort each parent's variations by lifetime_donation_revenue DESC.
		// Standalone (non-parent) buckets render as a single row, so drop the
		// internal single-element variations scaffold to keep their payload shape
		// on the renderer's is_parent contract (no variations key for simple
		// products).
		foreach ( $parents as &$entry ) {
			if ( ! $entry['is_parent'] ) {
				unset( $entry['variations'] );
				continue;
			}
			usort(
				$entry['variations'],
				static function ( $a, $b ) {
					return $b['lifetime_donation_revenue'] <=> $a['lifetime_donation_revenue'];
				}
			);
		}
		unset( $entry );

		$out = array_values( $parents );
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['lifetime_donation_revenue'] <=> $a['lifetime_donation_revenue'];
			}
		);
		return array_slice( $out, 0, 50 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array{customer_id:int, registered_ts:int, first_donation_ts:int}>
	 */
	public function get_donation_conversion_lags(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT first_d.customer_id, u.user_registered, first_d.first_donation_date
			FROM (
				SELECT o.customer_id, MIN(o.date_created_gmt) AS first_donation_date
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
				WHERE o.type = 'shop_order'
				  AND o.status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				GROUP BY o.customer_id
			) AS first_d
			JOIN {$prefix}users u ON u.ID = first_d.customer_id";

		return $this->rows_to_lags( $wpdb->get_results( $sql, ARRAY_A ), 'first_donation_date', 'first_donation_ts' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array{lag_days:int}>
	 */
	public function get_subscriber_to_donor_lags(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// First non-donation subscription vs first donation per customer, joined
		// where the donation strictly post-dates the subscription. DATEDIFF over
		// the two UTC values is timezone-safe (date arithmetic, no tz interpretation).
		$sql = "SELECT DATEDIFF(df.first_donation_date, sb.first_sub_date) AS lag_days
			FROM (
				SELECT o.customer_id, MIN(om.meta_value) AS first_sub_date
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND om.meta_value != ''
				GROUP BY o.customer_id
			) AS sb
			JOIN (
				SELECT o.customer_id, MIN(o.date_created_gmt) AS first_donation_date
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o.id
				WHERE o.type = 'shop_order'
				  AND o.status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				GROUP BY o.customer_id
			) AS df ON df.customer_id = sb.customer_id
			WHERE df.first_donation_date > sb.first_sub_date";

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map(
			static function ( $row ) {
				return [ 'lag_days' => (int) $row['lag_days'] ];
			},
			(array) $rows
		);
	}

	/**
	 * Map (customer_id, user_registered, <first date>) rows to lag records with
	 * UTC epoch seconds. Rows with a blank date are skipped. UTC parse keeps the
	 * epochs correct regardless of MySQL session timezone. user_registered is
	 * treated as UTC (WordPress stores it as the GMT registration instant).
	 *
	 * @param mixed  $rows         wpdb rows (ARRAY_A).
	 * @param string $first_key    Row key holding the first-conversion date.
	 * @param string $first_ts_out Output key for the first-conversion epoch.
	 * @return array<int, array<string,int>>
	 */
	private function rows_to_lags( $rows, string $first_key, string $first_ts_out ): array {
		$utc  = new \DateTimeZone( 'UTC' );
		$out  = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row[ $first_key ] ) || empty( $row['user_registered'] ) ) {
				continue;
			}
			$out[] = [
				'customer_id'   => (int) $row['customer_id'],
				'registered_ts' => ( new \DateTimeImmutable( $row['user_registered'], $utc ) )->getTimestamp(),
				$first_ts_out   => ( new \DateTimeImmutable( $row[ $first_key ], $utc ) )->getTimestamp(),
			];
		}
		return $out;
	}

	/**
	 * Variation label picker. Same conventions as Tab 6.
	 *
	 * @param string $period         _subscription_period meta value.
	 * @param string $variation_name Variation post_title.
	 * @param string $parent_name    Parent product post_title.
	 * @return string
	 */
	private function variation_label( string $period, string $variation_name, string $parent_name ): string {
		switch ( strtolower( $period ) ) {
			case 'day':
				return __( 'Daily', 'newspack-plugin' );
			case 'week':
				return __( 'Weekly', 'newspack-plugin' );
			case 'month':
				return __( 'Monthly', 'newspack-plugin' );
			case 'year':
				return __( 'Annual', 'newspack-plugin' );
		}
		if ( '' !== $variation_name ) {
			$prefix = $parent_name . ' - ';
			if ( '' !== $parent_name && 0 === strpos( $variation_name, $prefix ) ) {
				return substr( $variation_name, strlen( $prefix ) );
			}
			return $variation_name;
		}
		return __( 'Variation', 'newspack-plugin' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<string, array{conversions:int, revenue:float}>
	 */
	public function get_prompt_attributed_donation_conversions( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// NPPD-1685 audit fix: structurally mirrors the legacy reader — `_newspack_popup_id`
		// is written more than once per order, so it must NOT be JOINed (that multiplies
		// COUNT/SUM). Filter prompt-attributed orders with EXISTS and derive exactly ONE
		// popup id per order via a correlated MIN() subquery; `total_amount` is a column
		// on the orders table (no meta multiplication). Renewals excluded via NOT EXISTS.
		//
		// UNVERIFIED AGAINST LIVE HPOS DATA (NPPD-1685): the legacy path is proven on
		// real publishers (Evanston 27 / $2,294.84, New Bedford Light 5 / $533.79), but
		// every reachable HPOS-on publisher had zero order volume, so this HPOS query has
		// not executed against real HPOS orders. It is mirrored from the proven legacy
		// shape; confirm the count + revenue at first HPOS-publisher contact.
		$sql = $wpdb->prepare(
			"SELECT o.popup_id,
				COUNT(*) AS conversions,
				SUM(o.order_total) AS revenue
			FROM (
				SELECT ord.id AS order_id,
					(
						SELECT MIN(pop.meta_value)
						FROM {$prefix}wc_orders_meta pop
						WHERE pop.order_id = ord.id
						  AND pop.meta_key = '_newspack_popup_id'
						  AND pop.meta_value NOT IN ('', '0')
					) AS popup_id,
					ord.total_amount AS order_total
				FROM {$prefix}wc_orders ord
				WHERE ord.type = 'shop_order'
				  AND ord.status IN ('wc-completed', 'wc-processing')
				  AND ord.date_created_gmt BETWEEN %s AND %s
				  AND NOT EXISTS (
				      SELECT 1 FROM {$prefix}wc_orders_meta rn
				      WHERE rn.order_id = ord.id AND rn.meta_key = '_subscription_renewal'
				        AND rn.meta_value NOT IN ('', '0')
				  )
				  AND EXISTS (
				      SELECT 1 FROM {$prefix}wc_order_product_lookup opl
				      WHERE opl.order_id = ord.id AND opl.product_id IN ($donations)
				  )
				  AND EXISTS (
				      SELECT 1 FROM {$prefix}wc_orders_meta pop
				      WHERE pop.order_id = ord.id AND pop.meta_key = '_newspack_popup_id'
				        AND pop.meta_value NOT IN ('', '0')
				  )
			) AS o
			GROUP BY o.popup_id",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$out = [];
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$popup_id = (string) ( $row['popup_id'] ?? '' );
			if ( '' === $popup_id ) {
				continue;
			}
			$out[ $popup_id ] = [
				'conversions' => (int) ( $row['conversions'] ?? 0 ),
				'revenue'     => (float) ( $row['revenue'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * Donation campaign attribution rows for the window (NEWS-2580).
	 *
	 * One row per completed/processing donation order in [$start, $end]. Reuses the
	 * read path of get_prompt_attributed_donation_conversions() but keyed on
	 * `utm_campaign` instead of `_newspack_popup_id`: HPOS reads `wc_orders_meta`
	 * via a correlated MIN() subquery (the meta is written non-unique, so a JOIN
	 * would inflate). Unlike the popup reader there is NO popup EXISTS filter and NO
	 * GROUP BY — every donation order is emitted, untagged ones carrying '', so the
	 * Metric_Grouping fold can surface a "(no campaign)" bucket. Renewals excluded
	 * via NOT EXISTS `_subscription_renewal`.
	 *
	 * revenue = donation order total (one-time gift amount).
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{utm_campaign: string, revenue: float}>
	 */
	public function get_donation_campaign_rows( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = $wpdb->prepare(
			"SELECT
				(
					SELECT MIN(c.meta_value)
					FROM {$prefix}wc_orders_meta c
					WHERE c.order_id = ord.id AND c.meta_key = 'utm_campaign' AND c.meta_value <> ''
				) AS utm_campaign,
				ord.total_amount AS revenue
			FROM {$prefix}wc_orders ord
			WHERE ord.type = 'shop_order'
			  AND ord.status IN ('wc-completed', 'wc-processing')
			  AND ord.date_created_gmt BETWEEN %s AND %s
			  AND NOT EXISTS (
			      SELECT 1 FROM {$prefix}wc_orders_meta rn
			      WHERE rn.order_id = ord.id AND rn.meta_key = '_subscription_renewal'
			        AND rn.meta_value NOT IN ('', '0')
			  )
			  AND EXISTS (
			      SELECT 1 FROM {$prefix}wc_order_product_lookup opl
			      WHERE opl.order_id = ord.id AND opl.product_id IN ($donations)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$out = [];
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$out[] = [
				'utm_campaign' => (string) ( $row['utm_campaign'] ?? '' ),
				'revenue'      => (float) ( $row['revenue'] ?? 0 ),
			];
		}
		return $out;
	}
}
