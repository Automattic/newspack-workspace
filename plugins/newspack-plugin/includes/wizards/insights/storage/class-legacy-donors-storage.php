<?php
/**
 * Newspack Insights — Legacy CPT Donors Storage implementation
 * (NPPD-1617).
 *
 * Tab 7 counterpart to {@see Legacy_Storage}. Mirrors HPOS_Donors_Storage
 * method-by-method, with the per-row source swapped from HPOS tables
 * to legacy CPT (`{prefix}posts` typed by `post_type`,
 * `{prefix}postmeta` for order meta, and `_customer_user` /
 * `_order_total` postmeta replacing HPOS's `customer_id` / `total_amount`
 * columns).
 *
 * The line-item tables (`{prefix}woocommerce_order_items`,
 * `{prefix}woocommerce_order_itemmeta`) and the analytics lookup
 * (`{prefix}wc_order_product_lookup`) are cross-backend and queried
 * identically here.
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
 * Legacy CPT implementation of the Tab 7 storage contract.
 */
class Legacy_Donors_Storage implements Donors_Storage_Interface {

	/**
	 * Donation product IDs to include in metric queries.
	 *
	 * @var int[]
	 */
	private $donation_product_ids;

	/**
	 * Constructor.
	 *
	 * @param int[] $donation_product_ids Donation product IDs.
	 */
	public function __construct( array $donation_product_ids ) {
		$this->donation_product_ids = array_map( 'intval', $donation_product_ids );
	}

	/**
	 * SQL-safe `IN (...)` list. Empty -> `0`.
	 *
	 * @param int[] $ids Integer IDs.
	 * @return string
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
	 * `post_date_gmt` / `_schedule_*` columns stored in UTC. Matches
	 * {@see Legacy_Storage::fmt()} so windowed donor queries don't skew on
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

		// UNION of two paths, deduped by customer_id. See HPOS variant
		// for the rationale.
		$sql = "SELECT COUNT(*) FROM (
				SELECT DISTINCT cust.meta_value AS customer_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND p.post_status = 'wc-active'
				  AND oim.meta_value IN ($donations)
				UNION
				SELECT DISTINCT cust.meta_value AS customer_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND p.post_date_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
				  AND opl.product_id IN ($donations)
			) AS active_donor_set
			WHERE CAST(customer_id AS UNSIGNED) > 0";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_recurring_donors(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT COUNT(DISTINCT cust.meta_value)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND oim.meta_value IN ($donations)";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_donation_mrr(): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Per-line-item attribution: normalize each donation line item's own
		// recurring total (`_line_total`) to a monthly rate. Using the line
		// item total rather than the subscription's `_order_total` keeps a
		// multi-line-item donation subscription from summing the full
		// subscription total once per line item (which would overstate MRR).
		$sql = "SELECT SUM(
				CASE
					WHEN prd.meta_value = 'month' AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(15,2)) / CAST(pri.meta_value AS UNSIGNED)
					WHEN prd.meta_value = 'year'  AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(15,2)) / (12 * CAST(pri.meta_value AS UNSIGNED))
					WHEN prd.meta_value = 'week'  AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(15,2)) * (52/12) / CAST(pri.meta_value AS UNSIGNED)
					WHEN prd.meta_value = 'day'   AND CAST(pri.meta_value AS UNSIGNED) > 0
						THEN CAST(lt.meta_value AS DECIMAL(15,2)) * 30 / CAST(pri.meta_value AS UNSIGNED)
					ELSE CAST(lt.meta_value AS DECIMAL(15,2)) / 12
				END
			)
			FROM {$prefix}posts p
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}woocommerce_order_itemmeta lt
				ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
			JOIN {$prefix}postmeta prd
				ON prd.post_id = CAST(oim.meta_value AS UNSIGNED) AND prd.meta_key = '_subscription_period'
			JOIN {$prefix}postmeta pri
				ON pri.post_id = CAST(oim.meta_value AS UNSIGNED) AND pri.meta_key = '_subscription_period_interval'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
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

		// Mirrors Tab 6's legacy upcoming-renewals query, donation filter
		// flipped from NOT IN to IN. See HPOS variant for the DISTINCT
		// subselect rationale.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS upcoming_count,
				COALESCE(SUM(CAST(tot.meta_value AS DECIMAL(15,2))), 0) AS upcoming_value
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta next
				ON next.post_id = p.ID AND next.meta_key = '_schedule_next_payment'
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value IN ($donations)
			  )
			  AND next.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)",
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

		// Mirrors Tab 6's legacy upcoming-cancellations query with the
		// donation filter flipped from NOT IN to IN.
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS upcoming_count,
				COALESCE(SUM(CAST(tot.meta_value AS DECIMAL(15,2))), 0) AS upcoming_value
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta end_meta
				ON end_meta.post_id = p.ID AND end_meta.meta_key = '_schedule_end'
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status IN ('wc-active', 'wc-pending-cancel')
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value IN ($donations)
			  )
			  AND end_meta.meta_value BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)",
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

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT cust.meta_value AS customer_id, MIN(p.post_date_gmt) AS first_donation_date
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				GROUP BY cust.meta_value
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
	 * Cancelled/expired side pre-aggregated to DISTINCT customer_ids and
	 * LEFT-JOINed against the DISTINCT active-donation-subscriber customer_ids
	 * (also pre-aggregated), keeping only rows where the active side is NULL —
	 * an anti-join, not a correlated `NOT IN (subquery)` (MySQL's slowest
	 * anti-join form). Same approach as
	 * {@see Legacy_Storage::get_churned_subscribers_in_window()}. DISTINCT on
	 * both derived tables prevents JOIN fan-out from the line-item join.
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
				SELECT DISTINCT cust.meta_value AS customer_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}postmeta cancelled
					ON cancelled.post_id = p.ID AND cancelled.meta_key = '_schedule_cancelled'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND p.post_status IN ('wc-cancelled', 'wc-expired')
				  AND oim.meta_value IN ($donations)
				  AND cancelled.meta_value BETWEEN %s AND %s
				  AND cancelled.meta_value != ''
			) AS cancellations
			LEFT JOIN (
				SELECT DISTINCT cust2.meta_value AS customer_id
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = p2.ID AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE p2.post_type = 'shop_subscription'
				  AND p2.post_status = 'wc-active'
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
	 * Shared body for one-time vs recurring donation revenue.
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @param string            $mode  'one_time' | 'recurring'.
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
			"SELECT SUM(CAST(tot.meta_value AS DECIMAL(15,2)))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
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

		// One-time gifts only. See HPOS implementation for rationale.
		// Same period-meta predicate as get_one_time_donation_revenue.
		$sql = $wpdb->prepare(
			"SELECT AVG(CAST(tot.meta_value AS DECIMAL(15,2)))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
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
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_lapsed_donor_recovery_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
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

		$lapsed_sql = $wpdb->prepare(
			"SELECT DISTINCT cancellations.customer_id
			FROM (
				SELECT cust.meta_value AS customer_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}postmeta cancelled
					ON cancelled.post_id = p.ID AND cancelled.meta_key = '_schedule_cancelled'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND p.post_status IN ('wc-cancelled', 'wc-expired')
				  AND oim.meta_value IN ($donations)
				  AND cancelled.meta_value BETWEEN %s AND %s
				  AND cancelled.meta_value != ''
			) AS cancellations
			WHERE cancellations.customer_id NOT IN (
				SELECT DISTINCT cust2.meta_value
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = p2.ID AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE p2.post_type = 'shop_subscription'
				  AND p2.post_status = 'wc-active'
				  AND oim2.meta_value IN ($donations)
			)",
			$prior_start_iso,
			$prior_end_iso
		);

		$lapsed_customer_ids = $wpdb->get_col( $lapsed_sql );
		if ( empty( $lapsed_customer_ids ) ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}
		$lapsed_count = count( $lapsed_customer_ids );
		$lapsed_list  = $this->id_list( array_map( 'intval', $lapsed_customer_ids ) );

		$recovered_sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT cust.meta_value)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND opl.product_id IN ($donations)
			  AND CAST(cust.meta_value AS UNSIGNED) IN ($lapsed_list)",
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
		unset( $end ); // cache key only; SQL uses NOW for the "still active" check.

		$active_at_start_sql = $wpdb->prepare(
			"SELECT DISTINCT cust.meta_value AS customer_id, p.ID AS subscription_id, p.post_status
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}postmeta start_meta
				ON start_meta.post_id = p.ID AND start_meta.meta_key = '_schedule_start'
			LEFT JOIN {$prefix}postmeta cancel_meta
				ON cancel_meta.post_id = p.ID AND cancel_meta.meta_key = '_schedule_cancelled'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE p.post_type = 'shop_subscription'
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
		$rows = $wpdb->get_results( $active_at_start_sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}

		$customers_active_at_start = array_unique( array_map( 'intval', array_column( $rows, 'customer_id' ) ) );
		$denominator               = count( $customers_active_at_start );
		if ( 0 === $denominator ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}

		$customer_list = $this->id_list( $customers_active_at_start );
		$numerator_sql = "SELECT COUNT(DISTINCT cust.meta_value)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND oim.meta_value IN ($donations)
			  AND CAST(cust.meta_value AS UNSIGNED) IN ($customer_list)";
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

		// Mirrors HPOS variant: donation shop_orders in window for the
		// subscriber customer list. wc_order_product_lookup is cross-backend.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT CAST(cust.meta_value AS UNSIGNED))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND opl.product_id IN ($donations)
			  AND CAST(cust.meta_value AS UNSIGNED) IN ($ids)",
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

		// Subscription pass: active recurring donors per (effective) product.
		$subs_sql = "SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT cust.meta_value) AS active_recurring_donors
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta pid_meta
				ON pid_meta.order_item_id = oi.order_item_id AND pid_meta.meta_key = '_product_id'
			LEFT JOIN {$prefix}woocommerce_order_itemmeta vid_meta
				ON vid_meta.order_item_id = oi.order_item_id AND vid_meta.meta_key = '_variation_id'
			JOIN {$prefix}posts pv
				ON pv.ID = COALESCE( NULLIF( CAST(vid_meta.meta_value AS UNSIGNED), 0 ), CAST(pid_meta.meta_value AS UNSIGNED) )
			LEFT JOIN {$prefix}posts pp ON pp.ID = pv.post_parent
			LEFT JOIN {$prefix}postmeta period_meta
				ON period_meta.post_id = pv.ID AND period_meta.meta_key = '_subscription_period'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND pid_meta.meta_value IN ($donations)
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period";

		$subs_rows = $wpdb->get_results( $subs_sql, ARRAY_A );

		// Lapsed-donors pass: per-tier bucket of the {@see get_lapsed_donors_in_window}
		// scorecard cohort. See HPOS variant for the over-count note.
		$lapsed_sql = $wpdb->prepare(
			"SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT cust.meta_value) AS lapsed_donors_in_window
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}postmeta cancelled
				ON cancelled.post_id = p.ID AND cancelled.meta_key = '_schedule_cancelled'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta pid_meta
				ON pid_meta.order_item_id = oi.order_item_id AND pid_meta.meta_key = '_product_id'
			LEFT JOIN {$prefix}woocommerce_order_itemmeta vid_meta
				ON vid_meta.order_item_id = oi.order_item_id AND vid_meta.meta_key = '_variation_id'
			JOIN {$prefix}posts pv
				ON pv.ID = COALESCE( NULLIF( CAST(vid_meta.meta_value AS UNSIGNED), 0 ), CAST(pid_meta.meta_value AS UNSIGNED) )
			LEFT JOIN {$prefix}posts pp ON pp.ID = pv.post_parent
			LEFT JOIN {$prefix}postmeta period_meta
				ON period_meta.post_id = pv.ID AND period_meta.meta_key = '_subscription_period'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status IN ('wc-cancelled', 'wc-expired')
			  AND pid_meta.meta_value IN ($donations)
			  AND cancelled.meta_value BETWEEN %s AND %s
			  AND cancelled.meta_value != ''
			  AND cust.meta_value NOT IN (
				SELECT DISTINCT cust2.meta_value
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = p2.ID AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE p2.post_type = 'shop_subscription'
				  AND p2.post_status = 'wc-active'
				  AND oim2.meta_value IN ($donations)
			  )
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$lapsed_rows = $wpdb->get_results( $lapsed_sql, ARRAY_A );

		// shop_order pass: four metrics aggregated per opl.product_id.
		$orders_sql = $wpdb->prepare(
			"SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT CASE
					WHEN p.post_date_gmt BETWEEN %s AND %s
					 AND nd.first_donation_date BETWEEN %s AND %s
					THEN cust.meta_value
				END) AS new_donors_in_window,
				COUNT(DISTINCT CASE
					WHEN p.post_date_gmt BETWEEN %s AND %s
					 AND COALESCE(period_meta.meta_value, '') NOT IN ('day','week','month','year')
					THEN p.ID
				END) AS one_time_gifts_in_window,
				COALESCE(SUM(CASE
					WHEN p.post_date_gmt BETWEEN %s AND %s
					 AND period_meta.meta_value IN ('day','week','month','year')
					THEN CAST(tot.meta_value AS DECIMAL(15,2))
				END), 0) AS recurring_revenue_in_window,
				COALESCE(SUM(CAST(tot.meta_value AS DECIMAL(15,2))), 0) AS lifetime_donation_revenue
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
			JOIN {$prefix}posts pv ON pv.ID = opl.product_id
			LEFT JOIN {$prefix}posts pp ON pp.ID = pv.post_parent
			LEFT JOIN {$prefix}postmeta period_meta
				ON period_meta.post_id = pv.ID AND period_meta.meta_key = '_subscription_period'
			LEFT JOIN (
				-- See HPOS variant for why cust2.meta_value must be
				-- qualified — opl2 carries customer_id and would
				-- shadow an unqualified reference.
				SELECT cust2.meta_value AS customer_id, MIN(p2.post_date_gmt) AS first_donation_date
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl2 ON opl2.order_id = p2.ID
				WHERE p2.post_type = 'shop_order'
				  AND p2.post_status IN ('wc-completed','wc-processing')
				  AND opl2.product_id IN ($donations)
				GROUP BY cust2.meta_value
			) AS nd ON nd.customer_id = cust.meta_value
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed','wc-processing')
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

		// Legacy CPT equivalent of HPOS_Donors_Storage::get_first_donation_order_dates():
		// earliest completed/processing donation order post_date_gmt per
		// _customer_user, scoped to the given set. Mirrors get_new_donors_in_window().
		// _customer_user is cast to UNSIGNED (it is stored as a string) to match the
		// other list-scoped legacy donor queries and align grouping with the int keys returned.
		$sql = "SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(p.post_date_gmt) AS first_donation_date
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND opl.product_id IN ($donations)
			  AND CAST(cust.meta_value AS UNSIGNED) IN ($ids)
			GROUP BY CAST(cust.meta_value AS UNSIGNED)";

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

		// Legacy CPT equivalent of HPOS get_new_donor_records_in_window().
		// Guest orders (blank _customer_user → 0 when cast) are excluded from
		// source attribution — see the HPOS variant for the rationale.
		//
		// Order-meta subqueries: correlated against the FIRST donation order for each
		// customer (the order whose post_date_gmt = the outer MIN). MySQL 5.7-safe.
		// Legacy orders use postmeta for order meta, keyed by post ID.
		// gate_post_id: first non-empty _gate_post_id, falling back to legacy
		// _memberships_content_gate (both NOT IN ('','0')).
		// popup_id:     non-empty _newspack_popup_id.
		// Empty / missing → '' for that column.
		//
		// Same-timestamp tie handling: GROUP BY customer in the inner aggregate; the
		// outer meta subqueries use LIMIT 1 so any tied first-order's meta is used.
		$sql = $wpdb->prepare(
			"SELECT
				fd.customer_id,
				fd.first_donation_date,
				COALESCE((
					SELECT pm_gate.meta_value
					FROM {$prefix}posts p_first
					JOIN {$prefix}postmeta cust_first
						ON cust_first.post_id = p_first.ID AND cust_first.meta_key = '_customer_user'
					JOIN {$prefix}postmeta pm_gate
						ON pm_gate.post_id = p_first.ID AND pm_gate.meta_key = '_gate_post_id'
					JOIN {$prefix}wc_order_product_lookup opl_first ON opl_first.order_id = p_first.ID
					WHERE p_first.post_type = 'shop_order'
					  AND p_first.post_status IN ('wc-completed', 'wc-processing')
					  AND opl_first.product_id IN ($donations)
					  AND CAST(cust_first.meta_value AS UNSIGNED) = fd.customer_id
					  AND p_first.post_date_gmt = fd.first_donation_date
					  AND pm_gate.meta_value NOT IN ('', '0')
					LIMIT 1
				), (
					SELECT pm_legacy.meta_value
					FROM {$prefix}posts p_first
					JOIN {$prefix}postmeta cust_first
						ON cust_first.post_id = p_first.ID AND cust_first.meta_key = '_customer_user'
					JOIN {$prefix}postmeta pm_legacy
						ON pm_legacy.post_id = p_first.ID AND pm_legacy.meta_key = '_memberships_content_gate'
					JOIN {$prefix}wc_order_product_lookup opl_first ON opl_first.order_id = p_first.ID
					WHERE p_first.post_type = 'shop_order'
					  AND p_first.post_status IN ('wc-completed', 'wc-processing')
					  AND opl_first.product_id IN ($donations)
					  AND CAST(cust_first.meta_value AS UNSIGNED) = fd.customer_id
					  AND p_first.post_date_gmt = fd.first_donation_date
					  AND pm_legacy.meta_value NOT IN ('', '0')
					LIMIT 1
				), '') AS gate_post_id,
				COALESCE((
					SELECT pm_popup.meta_value
					FROM {$prefix}posts p_first
					JOIN {$prefix}postmeta cust_first
						ON cust_first.post_id = p_first.ID AND cust_first.meta_key = '_customer_user'
					JOIN {$prefix}postmeta pm_popup
						ON pm_popup.post_id = p_first.ID AND pm_popup.meta_key = '_newspack_popup_id'
					JOIN {$prefix}wc_order_product_lookup opl_first ON opl_first.order_id = p_first.ID
					WHERE p_first.post_type = 'shop_order'
					  AND p_first.post_status IN ('wc-completed', 'wc-processing')
					  AND opl_first.product_id IN ($donations)
					  AND CAST(cust_first.meta_value AS UNSIGNED) = fd.customer_id
					  AND p_first.post_date_gmt = fd.first_donation_date
					  AND pm_popup.meta_value NOT IN ('', '0')
					LIMIT 1
				), '') AS popup_id
			FROM (
				SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(p.post_date_gmt) AS first_donation_date
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				  AND CAST(cust.meta_value AS UNSIGNED) > 0
				GROUP BY CAST(cust.meta_value AS UNSIGNED)
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
	 * Aggregate flat per-variation rows into parent + nested variations.
	 * Duplicated from {@see HPOS_Donors_Storage} — pure PHP transform
	 * keeping each storage class self-contained.
	 *
	 * @param array<int, array<string, mixed>> $rows Merged rows.
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
			// See {@see HPOS_Donors_Storage::aggregate_tier_rows()} for the full note.
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
					// Two INDEPENDENT billing-nature flags, not a promoted enum, so
					// a mixed product's recurring signal never clobbers its one-time
					// one. See {@see HPOS_Donors_Storage::aggregate_tier_rows()}.
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

			// Latch the bucket's billing nature; a mixed product ends up with both.
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

		// Standalone (non-parent) buckets render as a single row; drop the internal
		// single-element variations scaffold to keep their payload on the renderer's
		// is_parent contract. Parents get their variations sorted by revenue DESC.
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
				SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(p.post_date_gmt) AS first_donation_date
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				GROUP BY CAST(cust.meta_value AS UNSIGNED)
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

		// Legacy CPT equivalent of HPOS get_subscriber_to_donor_lags().
		$sql = "SELECT DATEDIFF(df.first_donation_date, sb.first_sub_date) AS lag_days
			FROM (
				SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(start.meta_value) AS first_sub_date
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}postmeta start
					ON start.post_id = p.ID AND start.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND start.meta_value != ''
				GROUP BY CAST(cust.meta_value AS UNSIGNED)
			) AS sb
			JOIN (
				SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(p.post_date_gmt) AS first_donation_date
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND opl.product_id IN ($donations)
				GROUP BY CAST(cust.meta_value AS UNSIGNED)
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
	 * Variation label picker.
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

		// NPPD-1685 audit fix: `_newspack_popup_id` is written more than once per
		// order (the checkout hook adds it without `unique = true`), so JOINing that
		// meta multiplies COUNT/SUM by the number of duplicate rows. Don't
		// join-then-dedupe — filter prompt-attributed orders with EXISTS and derive
		// exactly ONE popup id per order via a correlated MIN() subquery, so
		// duplicate meta cannot re-inflate even if an order ever carried two
		// different popup ids. Donation membership and renewal exclusion are also
		// EXISTS / NOT EXISTS so neither can multiply rows. Renewal orders inherit
		// the parent's popup meta and are excluded via `_subscription_renewal`.
		// Proven on Evanston (27 / $2,294.84) and New Bedford Light (5 / $533.79).
		$sql = $wpdb->prepare(
			"SELECT o.popup_id,
				COUNT(*) AS conversions,
				SUM(o.order_total) AS revenue
			FROM (
				SELECT p.ID AS order_id,
					(
						SELECT MIN(pop.meta_value)
						FROM {$prefix}postmeta pop
						WHERE pop.post_id = p.ID
						  AND pop.meta_key = '_newspack_popup_id'
						  AND pop.meta_value NOT IN ('', '0')
					) AS popup_id,
					(
						SELECT MAX(CAST(tot.meta_value AS DECIMAL(15,2)))
						FROM {$prefix}postmeta tot
						WHERE tot.post_id = p.ID AND tot.meta_key = '_order_total'
					) AS order_total
				FROM {$prefix}posts p
				WHERE p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed', 'wc-processing')
				  AND p.post_date_gmt BETWEEN %s AND %s
				  AND NOT EXISTS (
				      SELECT 1 FROM {$prefix}postmeta rn
				      WHERE rn.post_id = p.ID AND rn.meta_key = '_subscription_renewal'
				        AND rn.meta_value NOT IN ('', '0')
				  )
				  AND EXISTS (
				      SELECT 1 FROM {$prefix}wc_order_product_lookup opl
				      WHERE opl.order_id = p.ID AND opl.product_id IN ($donations)
				  )
				  AND EXISTS (
				      SELECT 1 FROM {$prefix}postmeta pop
				      WHERE pop.post_id = p.ID AND pop.meta_key = '_newspack_popup_id'
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
	 * Legacy CPT equivalent of the HPOS get_donation_campaign_rows(): one row per
	 * completed/processing donation order in [$start, $end], keyed on `utm_campaign`
	 * read from {prefix}postmeta via a correlated MIN() subquery (the meta is written
	 * non-unique, so a JOIN would inflate). No popup filter and no GROUP BY — every
	 * donation order is emitted (untagged → ''), so the Metric_Grouping fold can
	 * surface a "(no campaign)" bucket. Renewals excluded via NOT EXISTS
	 * `_subscription_renewal`.
	 *
	 * revenue = donation order total (`_order_total`, one-time gift amount).
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
					FROM {$prefix}postmeta c
					WHERE c.post_id = p.ID AND c.meta_key = 'utm_campaign' AND c.meta_value <> ''
				) AS utm_campaign,
				(
					SELECT MAX(CAST(tot.meta_value AS DECIMAL(15,2)))
					FROM {$prefix}postmeta tot
					WHERE tot.post_id = p.ID AND tot.meta_key = '_order_total'
				) AS revenue
			FROM {$prefix}posts p
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND NOT EXISTS (
			      SELECT 1 FROM {$prefix}postmeta rn
			      WHERE rn.post_id = p.ID AND rn.meta_key = '_subscription_renewal'
			        AND rn.meta_value NOT IN ('', '0')
			  )
			  AND EXISTS (
			      SELECT 1 FROM {$prefix}wc_order_product_lookup opl
			      WHERE opl.order_id = p.ID AND opl.product_id IN ($donations)
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
