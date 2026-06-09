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
	 * @param DateTimeInterface $dt DateTime.
	 * @return string Y-m-d H:i:s.
	 */
	private function fmt( DateTimeInterface $dt ): string {
		return $dt->format( 'Y-m-d H:i:s' );
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

			$is_recurring  = in_array( $period, [ 'day', 'week', 'month', 'year' ], true );
			$billing_model = $is_recurring ? 'recurring' : 'one_time';

			if ( $parent_id > 0 ) {
				if ( ! isset( $parents[ $parent_id ] ) ) {
					$parents[ $parent_id ] = [
						'product_id'                  => $parent_id,
						'name'                        => '' !== $parent_name ? $parent_name : __( '(unnamed product)', 'newspack-plugin' ),
						'is_parent'                   => true,
						// See HPOS implementation for the floor +
						// upgrade-on-recurring-variation pattern.
						'billing_model'               => 'one_time',
						'active_recurring_donors'     => 0,
						'lapsed_donors_in_window'     => 0,
						'new_donors_in_window'        => 0,
						'one_time_gifts_in_window'    => 0,
						'recurring_revenue_in_window' => 0.0,
						'lifetime_donation_revenue'   => 0.0,
						'variations'                  => [],
					];
				}
				if ( $is_recurring ) {
					$parents[ $parent_id ]['billing_model'] = 'recurring';
				}
				$parents[ $parent_id ]['active_recurring_donors']     += $active_recurring_donors;
				$parents[ $parent_id ]['lapsed_donors_in_window']     += $lapsed_donors;
				$parents[ $parent_id ]['new_donors_in_window']        += $new_donors;
				$parents[ $parent_id ]['one_time_gifts_in_window']    += $one_time_gifts;
				$parents[ $parent_id ]['recurring_revenue_in_window'] += $recurring_revenue;
				$parents[ $parent_id ]['lifetime_donation_revenue']   += $lifetime_donation_revenue;
				$parents[ $parent_id ]['variations'][]                 = [
					'variation_id'                => $variation_id,
					'label'                       => $this->variation_label( $period, $variation_name, $parent_name ),
					'billing_model'               => $billing_model,
					'active_recurring_donors'     => $active_recurring_donors,
					'lapsed_donors_in_window'     => $lapsed_donors,
					'new_donors_in_window'        => $new_donors,
					'one_time_gifts_in_window'    => $one_time_gifts,
					'recurring_revenue_in_window' => $recurring_revenue,
					'lifetime_donation_revenue'   => $lifetime_donation_revenue,
				];
			} else {
				$parents[ $variation_id ] = [
					'product_id'                  => $variation_id,
					'name'                        => '' !== $variation_name ? $variation_name : __( '(unnamed product)', 'newspack-plugin' ),
					'is_parent'                   => false,
					'billing_model'               => $billing_model,
					'active_recurring_donors'     => $active_recurring_donors,
					'lapsed_donors_in_window'     => $lapsed_donors,
					'new_donors_in_window'        => $new_donors,
					'one_time_gifts_in_window'    => $one_time_gifts,
					'recurring_revenue_in_window' => $recurring_revenue,
					'lifetime_donation_revenue'   => $lifetime_donation_revenue,
				];
			}
		}

		foreach ( $parents as &$entry ) {
			if ( isset( $entry['variations'] ) ) {
				usort(
					$entry['variations'],
					static function ( $a, $b ) {
						return $b['lifetime_donation_revenue'] <=> $a['lifetime_donation_revenue'];
					}
				);
			}
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
}
