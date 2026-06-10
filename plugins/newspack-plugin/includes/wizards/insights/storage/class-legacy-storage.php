<?php
/**
 * Newspack Insights — Legacy CPT Storage implementation (NPPD-1616).
 *
 * Implements {@see Storage_Interface} against the pre-HPOS WooCommerce
 * order storage: orders/subscriptions live in `{prefix}posts` (typed by
 * `post_type`) and their metadata in `{prefix}postmeta`.
 *
 * Mirrors the HPOS implementation method-by-method, with the per-row
 * differences documented in the schema doc:
 * `~/Sites/insights-docs/formulas/subscription-donation-schema.md`
 *
 *   HPOS                          Legacy
 *   wc_orders.id                  posts.ID
 *   wc_orders.type                posts.post_type
 *   wc_orders.status              posts.post_status
 *   wc_orders.date_created_gmt    posts.post_date_gmt
 *   wc_orders.customer_id         postmeta._customer_user
 *   wc_orders.total_amount        postmeta._order_total (DECIMAL string)
 *   wc_orders.parent_order_id     posts.post_parent
 *   wc_orders_meta.*              postmeta.*
 *
 * Line-item tables `{prefix}woocommerce_order_items` and
 * `{prefix}woocommerce_order_itemmeta` are NOT HPOS-specific — they
 * pre-date HPOS and continue to hold line items for every order type
 * on both backends. Subscription-side queries here use them for the
 * same reason as in {@see HPOS_Storage}: production data confirms
 * `{prefix}wc_order_product_lookup` only ever holds shop_order rows
 * (Block Club Chicago: 39,461 shop_order / 0 shop_subscription;
 * Richland Source: 13,279 / 0).
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

use DateTimeInterface;
use Newspack\Logger;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * Legacy CPT implementation of the Tab 6 storage contract.
 */
class Legacy_Storage implements Storage_Interface {

	/**
	 * Donation product IDs to exclude from non-donation metric queries.
	 *
	 * @var int[]
	 */
	private $donation_product_ids;

	/**
	 * Constructor.
	 *
	 * @param int[] $donation_product_ids Donation product IDs to exclude.
	 */
	public function __construct( array $donation_product_ids ) {
		$this->donation_product_ids = array_map( 'intval', $donation_product_ids );
	}

	/**
	 * Build a SQL-safe `IN (...)` list from integer IDs. Empty -> `0`.
	 *
	 * @param int[] $ids List of integer IDs.
	 * @return string Comma-separated integers (or `0`), unparenthesized.
	 */
	private function id_list( array $ids ): string {
		if ( empty( $ids ) ) {
			return '0';
		}
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * Format a datetime for SQL comparison, in UTC.
	 *
	 * Every column these queries compare against stores UTC: the `post_date_gmt`
	 * column and the WooCommerce Subscriptions `_schedule_*` meta (which WCS
	 * persists as UTC datetime strings). Window bounds arrive in the site
	 * timezone (built from `wp_timezone()` in the REST controller), so we format
	 * the absolute instant in UTC here to keep the window aligned on non-UTC
	 * sites. Uses `getTimestamp()` so the result is correct regardless of the
	 * input DateTime's own timezone.
	 *
	 * @param DateTimeInterface $dt DateTime to format.
	 * @return string `Y-m-d H:i:s` UTC-formatted string.
	 */
	private function fmt( DateTimeInterface $dt ): string {
		return gmdate( 'Y-m-d H:i:s', $dt->getTimestamp() );
	}

	/**
	 * Look up subscription product type IDs (same logic as HPOS — uses the
	 * shared product_type taxonomy, not order-storage-specific tables).
	 *
	 * @return string Comma-separated integer IDs (or `0` if none).
	 */
	private function subscription_product_ids_sql(): string {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$rows = $wpdb->get_col(
			"SELECT p.ID
			FROM {$prefix}posts p
			JOIN {$prefix}term_relationships tr ON p.ID = tr.object_id
			JOIN {$prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			JOIN {$prefix}terms t ON tt.term_id = t.term_id
			WHERE tt.taxonomy = 'product_type'
			  AND t.slug IN ('subscription', 'variable-subscription', 'subscription_variation')"
		);

		return $this->id_list( array_map( 'intval', (array) $rows ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_non_donation_subscribers(): int {
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
			  AND oim.meta_value NOT IN ($donations)";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_new_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT cust.meta_value AS customer_id, MIN(start.meta_value) AS first_start
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
				GROUP BY cust.meta_value
			) AS first_subs
			WHERE first_subs.first_start BETWEEN %s AND %s",
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
	public function get_churned_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
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
				  AND oim.meta_value NOT IN ($donations)
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
				  AND oim2.meta_value NOT IN ($donations)
			)",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_mrr(): float {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Same CASE-on-period logic as HPOS_Storage::get_mrr(), but the
		// total amount comes from _order_total postmeta (DECIMAL string).
		// See the HPOS implementation for the documented spec — covers
		// day/week/month/year at any positive interval; ELSE is truly
		// conservative (total / 12) and a diagnostic surfaces any
		// fallthroughs via Newspack\Logger.
		$sql = "SELECT SUM(
				CASE
					WHEN bp.meta_value = 'day'   AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN CAST(tot.meta_value AS DECIMAL(15,2)) * 30 / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'week'  AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN CAST(tot.meta_value AS DECIMAL(15,2)) * (52/12) / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'month' AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN CAST(tot.meta_value AS DECIMAL(15,2)) / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'year'  AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN CAST(tot.meta_value AS DECIMAL(15,2)) / (12 * CAST(bi.meta_value AS UNSIGNED))
					ELSE CAST(tot.meta_value AS DECIMAL(15,2)) / 12
				END
			)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta bp
				ON bp.post_id = p.ID AND bp.meta_key = '_billing_period'
			JOIN {$prefix}postmeta bi
				ON bi.post_id = p.ID AND bi.meta_key = '_billing_interval'
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
				  AND oim.meta_value NOT IN ($donations)
			  )";

		$mrr = (float) $wpdb->get_var( $sql );

		$unrecognized = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta bp ON bp.post_id = p.ID AND bp.meta_key = '_billing_period'
			JOIN {$prefix}postmeta bi ON bi.post_id = p.ID AND bi.meta_key = '_billing_interval'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND (
				bp.meta_value NOT IN ('day', 'week', 'month', 'year')
				OR CAST(bi.meta_value AS UNSIGNED) = 0
			  )
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )"
		);

		if ( $unrecognized > 0 && class_exists( Logger::class ) ) {
			Logger::log(
				sprintf(
					'%d active non-donation subscription(s) have unrecognized _billing_period/_billing_interval combinations. Their MRR contribution fell through to the conservative annual-amortized fallback (total / 12). Review product configuration.',
					$unrecognized
				),
				'NEWSPACK-INSIGHTS'
			);
		}

		return $mrr;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_arr(): float {
		return $this->get_mrr() * 12;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return float
	 */
	public function get_subscription_revenue_gross( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix         = $wpdb->prefix;
		$donations      = $this->id_list( $this->donation_product_ids );
		$subscription_p = $this->subscription_product_ids_sql();

		$sql = $wpdb->prepare(
			"SELECT SUM(CAST(tot.meta_value AS DECIMAL(15,2)))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND p.ID IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
			  )
			  AND p.ID NOT IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($donations)
			  )",
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
	public function get_subscription_revenue_net( DateTimeInterface $start, DateTimeInterface $end ): float {
		global $wpdb;
		$prefix         = $wpdb->prefix;
		$donations      = $this->id_list( $this->donation_product_ids );
		$subscription_p = $this->subscription_product_ids_sql();

		// Sum across shop_order + shop_order_refund. Refund totals are
		// negative so SUM yields the right net.
		$sql = $wpdb->prepare(
			"SELECT SUM(CAST(tot.meta_value AS DECIMAL(15,2)))
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
			WHERE p.post_type IN ('shop_order', 'shop_order_refund')
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND (
				(
					p.post_type = 'shop_order'
					AND p.ID IN (
						SELECT DISTINCT order_id
						FROM {$prefix}wc_order_product_lookup
						WHERE product_id IN ($subscription_p)
						  AND product_id NOT IN ($donations)
					)
				)
				OR (
					p.post_type = 'shop_order_refund'
					AND p.post_parent IN (
						SELECT DISTINCT order_id
						FROM {$prefix}wc_order_product_lookup
						WHERE product_id IN ($subscription_p)
						  AND product_id NOT IN ($donations)
					)
				)
			  )",
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
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_subscription_refund_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix         = $wpdb->prefix;
		$donations      = $this->id_list( $this->donation_product_ids );
		$subscription_p = $this->subscription_product_ids_sql();

		$orders_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}posts p
			WHERE p.post_type = 'shop_order'
			  AND p.post_status IN ('wc-completed', 'wc-processing')
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND p.ID IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
				  AND product_id NOT IN ($donations)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);
		$orders     = (int) $wpdb->get_var( $orders_sql );

		if ( 0 === $orders ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}

		$refunds_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}posts p
			WHERE p.post_type = 'shop_order_refund'
			  AND p.post_date_gmt BETWEEN %s AND %s
			  AND p.post_parent IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
				  AND product_id NOT IN ($donations)
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);
		$refunds     = (int) $wpdb->get_var( $refunds_sql );

		return [
			'value'       => $refunds / $orders,
			'computable'  => true,
			'denominator' => $orders,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_subscription_tenure_distribution(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// One row per active subscription line item; the React layer groups
		// client-side and computes box-plot quartiles.
		$sql = "SELECT
				prod.post_title AS product_name,
				TIMESTAMPDIFF(DAY, start.meta_value, NOW()) AS tenure_days
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta start
				ON start.post_id = p.ID AND start.meta_key = '_schedule_start'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}posts prod ON prod.ID = CAST(oim.meta_value AS UNSIGNED)
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)
			  AND start.meta_value != ''
			  AND start.meta_value < NOW()";

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}
		return array_map(
			function ( $row ) {
				return [
					'product_name' => (string) $row['product_name'],
					'tenure_days'  => (int) $row['tenure_days'],
				];
			},
			$rows
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_upcoming_renewals_30d(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// DISTINCT id-subselect for the non-donation filter so a multi-line-item
		// subscription is counted once and its _order_total isn't summed twice.
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
				  AND oim.meta_value NOT IN ($donations)
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
	public function get_upcoming_cancellations_30d(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Mirrors HPOS implementation; see that variant for rationale.
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
				  AND oim.meta_value NOT IN ($donations)
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
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_failed_payment_retry_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// DISTINCT id-subselect for the non-donation filter so a
		// multi-line-item subscription doesn't show up as multiple retries.
		$sql = $wpdb->prepare(
			"SELECT
				COUNT(*) AS retry_attempts,
				SUM(CASE WHEN sub.post_status = 'wc-active' THEN 1 ELSE 0 END) AS recoveries
			FROM (
				SELECT DISTINCT p.ID AS subscription_id
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta retry
					ON retry.post_id = p.ID AND retry.meta_key = '_schedule_payment_retry'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND retry.meta_value BETWEEN %s AND %s
				  AND retry.meta_value != ''
			) AS retries
			JOIN {$prefix}posts sub ON sub.ID = retries.subscription_id",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$row     = $wpdb->get_row( $sql, ARRAY_A );
		$attempt = (int) ( $row['retry_attempts'] ?? 0 );
		$success = (int) ( $row['recoveries'] ?? 0 );

		if ( 0 === $attempt ) {
			return [
				'value'       => 0.0,
				'computable'  => false,
				'denominator' => 0,
			];
		}

		return [
			'value'       => $success / $attempt,
			'computable'  => true,
			'denominator' => $attempt,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_subscriptions_by_product( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Column scope mirrors HPOS_Storage::get_subscriptions_by_product():
		// active_subs       — current state
		// active_value      — current state
		// lifetime_revenue  — lifetime sum (intentionally not windowed)
		// churned_subs      — WINDOWED via _schedule_cancelled postmeta
		//
		// Each subscription line item is counted toward the product it
		// references. Multi-product subs contribute to each product's
		// counts and amounts; SUM uses the subscription's _order_total so
		// the per-product active_value is attributed once per product
		// (a documented v1 simplification).
		// COALESCE _variation_id over _product_id to resolve to the
		// actual variation for variable products. See
		// HPOS_Storage::get_subscriptions_by_product() for the rationale.
		$sql = $wpdb->prepare(
			"SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT CASE WHEN p.post_status = 'wc-active' THEN p.ID END) AS active_subs,
				COUNT(DISTINCT CASE
					WHEN p.post_status IN ('wc-cancelled', 'wc-expired')
					 AND sch.meta_value BETWEEN %s AND %s
					THEN p.ID
				END) AS churned_subs,
				COALESCE(SUM(CASE WHEN p.post_status = 'wc-active' THEN CAST(tot.meta_value AS DECIMAL(15,2)) END), 0) AS active_value,
				COALESCE(SUM(CAST(tot.meta_value AS DECIMAL(15,2))), 0) AS lifetime_revenue
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta tot
				ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
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
			LEFT JOIN {$prefix}postmeta sch
				ON sch.post_id = p.ID AND sch.meta_key = '_schedule_cancelled'
			WHERE p.post_type = 'shop_subscription'
			  AND pid_meta.meta_value NOT IN ($donations)
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period
			ORDER BY active_subs DESC",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}
		return $this->aggregate_performance_rows( $rows );
	}

	/**
	 * Aggregate flat per-variation rows into parent + nested
	 * variations shape. Duplicated from {@see HPOS_Storage} — pure PHP
	 * transformation with no backend-specific logic, so duplication
	 * keeps each storage class self-contained.
	 *
	 * @param array<int, array<string, mixed>> $rows Flat SQL rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function aggregate_performance_rows( array $rows ): array {
		$parents = [];

		foreach ( $rows as $row ) {
			$variation_id     = (int) $row['variation_id'];
			$variation_name   = (string) $row['variation_name'];
			$parent_id        = (int) $row['parent_id'];
			$parent_name      = (string) $row['parent_name'];
			$period           = (string) $row['sub_period'];
			$active_subs      = (int) $row['active_subs'];
			$churned_subs     = (int) $row['churned_subs'];
			$active_value     = (float) $row['active_value'];
			$lifetime_revenue = (float) $row['lifetime_revenue'];

			if ( $parent_id > 0 ) {
				if ( ! isset( $parents[ $parent_id ] ) ) {
					$parents[ $parent_id ] = [
						'product_id'       => $parent_id,
						'name'             => '' !== $parent_name ? $parent_name : __( '(unnamed product)', 'newspack-plugin' ),
						'is_parent'        => true,
						'active_subs'      => 0,
						'churned_subs'     => 0,
						'active_value'     => 0.0,
						'lifetime_revenue' => 0.0,
						'variations'       => [],
					];
				}
				$parents[ $parent_id ]['active_subs']      += $active_subs;
				$parents[ $parent_id ]['churned_subs']     += $churned_subs;
				$parents[ $parent_id ]['active_value']     += $active_value;
				$parents[ $parent_id ]['lifetime_revenue'] += $lifetime_revenue;
				$parents[ $parent_id ]['variations'][]     = [
					'variation_id'     => $variation_id,
					'label'            => $this->variation_label( $period, $variation_name, $parent_name ),
					'active_subs'      => $active_subs,
					'churned_subs'     => $churned_subs,
					'active_value'     => $active_value,
					'lifetime_revenue' => $lifetime_revenue,
				];
			} else {
				$parents[ $variation_id ] = [
					'product_id'       => $variation_id,
					'name'             => '' !== $variation_name ? $variation_name : __( '(unnamed product)', 'newspack-plugin' ),
					'is_parent'        => false,
					'active_subs'      => $active_subs,
					'churned_subs'     => $churned_subs,
					'active_value'     => $active_value,
					'lifetime_revenue' => $lifetime_revenue,
				];
			}
		}

		foreach ( $parents as &$entry ) {
			if ( isset( $entry['variations'] ) ) {
				usort(
					$entry['variations'],
					static function ( $a, $b ) {
						return $b['active_subs'] <=> $a['active_subs'];
					}
				);
			}
		}
		unset( $entry );

		$out = array_values( $parents );
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['active_subs'] <=> $a['active_subs'];
			}
		);
		return array_slice( $out, 0, 50 );
	}

	/**
	 * Pick a variation label. See HPOS_Storage::variation_label() for
	 * the full doc; duplicated here so each storage class is
	 * self-contained.
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
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array
	 */
	public function get_cancellation_reasons( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// DISTINCT id-subselect on the non-donation filter so a sub with
		// multiple line items doesn't get counted multiple times under the
		// same reason.
		$sql = $wpdb->prepare(
			"SELECT
				COALESCE(reason.meta_value, 'unknown') AS cancellation_reason,
				COUNT(*) AS count
			FROM {$prefix}posts p
			LEFT JOIN {$prefix}postmeta reason
				ON reason.post_id = p.ID AND reason.meta_key = 'newspack_subscriptions_cancellation_reason'
			JOIN {$prefix}postmeta cancelled
				ON cancelled.post_id = p.ID AND cancelled.meta_key = '_schedule_cancelled'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status IN ('wc-cancelled', 'wc-expired')
			  AND p.ID IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )
			  AND cancelled.meta_value BETWEEN %s AND %s
			GROUP BY cancellation_reason
			ORDER BY count DESC",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return [];
		}
		return array_map(
			function ( $row ) {
				return [
					'cancellation_reason' => (string) $row['cancellation_reason'],
					'count'               => (int) $row['count'],
				];
			},
			$rows
		);
	}
}
