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
 * (a large metro newsroom: ~40,000 shop_order / 0 shop_subscription;
 * a mid-size local publisher: ~13,000 / 0).
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

	use Reader_Population_Trait;

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
	 * Note (M2): when no donation product IDs are configured, `NOT IN (0)`
	 * treats ALL subscriptions / orders as non-donation — matching sibling
	 * behavior — so subscriber counts may include donation activity on
	 * misconfigured sites (no donation products defined). This is intentional
	 * and consistent with how the rest of the storage layer handles an empty
	 * donation-product set.
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

		// The start.meta_value != '' guard matches the records method so the
		// count and source-mix records agree on sites where a subscription has
		// a blank _schedule_start.
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
				  AND start.meta_value != ''
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
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return int
	 */
	public function get_winback_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Legacy CPT equivalent of HPOS_Storage::get_winback_subscribers_in_window().
		// Distinct customers who started a non-donation subscription in the window
		// AND hold an earlier-started cancelled/expired non-donation subscription.
		// Disjoint from first-time subscribers. Churned side pre-aggregated to one
		// row per customer and INNER-JOINed (not a correlated EXISTS) for speed.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT ret.customer_id) FROM (
				SELECT cust.meta_value AS customer_id, start.meta_value AS start_val
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
				  AND start.meta_value BETWEEN %s AND %s
				  AND start.meta_value != ''
			) AS ret
			JOIN (
				SELECT cust2.meta_value AS customer_id, MIN(start2.meta_value) AS min_churn_start
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}postmeta start2
					ON start2.post_id = p2.ID AND start2.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = p2.ID AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE p2.post_type = 'shop_subscription'
				  AND p2.post_status IN ('wc-cancelled', 'wc-expired')
				  AND oim2.meta_value NOT IN ($donations)
				  AND start2.meta_value != ''
				GROUP BY cust2.meta_value
			) AS churned
				ON churned.customer_id = ret.customer_id
				AND churned.min_churn_start < ret.start_val",
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
		// new_subs          — WINDOWED via _schedule_start postmeta.
		// Gross starts: counts every sub that started in-window
		// regardless of current status, so a sub that started AND
		// churned in the same timeframe is counted in both new_subs
		// and churned_subs (independent events, by design)
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
					WHEN st.meta_value != '' AND st.meta_value BETWEEN %s AND %s
					THEN p.ID
				END) AS new_subs,
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
			LEFT JOIN {$prefix}postmeta st
				ON st.post_id = p.ID AND st.meta_key = '_schedule_start'
			LEFT JOIN {$prefix}postmeta sch
				ON sch.post_id = p.ID AND sch.meta_key = '_schedule_cancelled'
			WHERE p.post_type = 'shop_subscription'
			  AND pid_meta.meta_value NOT IN ($donations)
			GROUP BY pv.ID, pv.post_title, pv.post_parent, parent_name, sub_period
			ORDER BY active_subs DESC",
			$this->fmt( $start ),
			$this->fmt( $end ),
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
			$new_subs         = (int) $row['new_subs'];
			$churned_subs     = (int) $row['churned_subs'];
			$active_value     = (float) $row['active_value'];
			$lifetime_revenue = (float) $row['lifetime_revenue'];

			// Resolve the bucket this row belongs under. Variation rows fold
			// into their parent product; bare-parent / standalone rows fold into
			// the product's own id. Keying both into the SAME map by the effective
			// product id and accumulating (rather than the parent branch creating
			// and the standalone branch overwriting) is what stops a bare-parent
			// row from clobbering a variable product's accumulated variations. See
			// {@see HPOS_Storage::aggregate_performance_rows()} for the full note.
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
					'product_id'       => $bucket_id,
					'name'             => $bucket_name,
					'is_parent'        => false,
					'active_subs'      => 0,
					'new_subs'         => 0,
					'churned_subs'     => 0,
					'active_value'     => 0.0,
					'lifetime_revenue' => 0.0,
					'variations'       => [],
				];
			}
			$bucket = &$parents[ $bucket_id ];

			// A real variation row promotes the bucket to a parent and supplies
			// the canonical parent display name. Idempotent: a bare-parent row may
			// have seeded the bucket first under the same product's title.
			if ( $parent_id > 0 ) {
				$bucket['is_parent'] = true;
				$bucket['name']      = $bucket_name;
			}

			$bucket['active_subs']      += $active_subs;
			$bucket['new_subs']         += $new_subs;
			$bucket['churned_subs']     += $churned_subs;
			$bucket['active_value']     += $active_value;
			$bucket['lifetime_revenue'] += $lifetime_revenue;
			$bucket['variations'][]      = [
				'variation_id'     => $variation_id,
				'label'            => $label,
				'active_subs'      => $active_subs,
				'new_subs'         => $new_subs,
				'churned_subs'     => $churned_subs,
				'active_value'     => $active_value,
				'lifetime_revenue' => $lifetime_revenue,
			];
			unset( $bucket );
		}

		// Standalone (non-parent) buckets render as a single row; drop the internal
		// single-element variations scaffold to keep their payload on the renderer's
		// is_parent contract. Parents get their variations sorted by active_subs DESC.
		foreach ( $parents as &$entry ) {
			if ( ! $entry['is_parent'] ) {
				unset( $entry['variations'] );
				continue;
			}
			usort(
				$entry['variations'],
				static function ( $a, $b ) {
					return $b['active_subs'] <=> $a['active_subs'];
				}
			);
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

	// -------------------------------------------------------------------------
	// Conversion Journey (Tab 3) storage methods.
	// -------------------------------------------------------------------------

	/**
	 * {@inheritDoc}
	 */
	public function get_at_risk_subscribers(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Active non-donation subscriptions with a non-empty _schedule_payment_retry.
		// Uses posts/postmeta with the same join shape as the sibling HPOS method.
		$sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta retry
				ON retry.post_id = p.ID AND retry.meta_key = '_schedule_payment_retry'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE p.post_type = 'shop_subscription'
			  AND p.post_status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)
			  AND retry.meta_value != ''";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_non_donation_subscriber_customer_ids(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT DISTINCT cust.meta_value
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

		$rows = $wpdb->get_col( $sql );
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $customer_ids Customer IDs to check.
	 * @return int
	 */
	public function count_active_non_donation_subscribers_by_customer_ids( array $customer_ids ): int {
		if ( empty( $customer_ids ) ) {
			return 0;
		}

		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );
		$ids       = $this->id_list( $customer_ids );

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
			  AND oim.meta_value NOT IN ($donations)
			  AND CAST(cust.meta_value AS UNSIGNED) IN ($ids)";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function get_stale_registered_users(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Mirrors HPOS_Storage::get_stale_registered_users(). Base population and
		// exclusion logic are identical — the only difference is using
		// posts/postmeta for donation orders instead of wc_orders/wc_orders_meta,
		// and wc_order_product_lookup (cross-backend) for the product filter.
		// See the HPOS variant for the full Phase-A approximation rationale.
		//
		// Phase-A approximation (M1): hardcodes 'subscriber'/'customer' as reader
		// roles and 'administrator'/'editor' as restricted roles — does NOT honor
		// the filterable newspack_reader_user_roles / newspack_reader_restricted_roles
		// hooks, so sites with custom reader roles may see a slightly off count
		// (acceptable upper-bound for Phase A; adjust in a later iteration).
		$sql = "SELECT COUNT(DISTINCT u.ID)
			FROM {$prefix}users u
			WHERE (
				EXISTS (
					SELECT 1 FROM {$prefix}usermeta um
					WHERE um.user_id = u.ID
					  AND um.meta_key = 'np_reader'
					  AND um.meta_value != ''
				)
				OR EXISTS (
					SELECT 1 FROM {$prefix}usermeta um2
					WHERE um2.user_id = u.ID
					  AND um2.meta_key = '{$prefix}capabilities'
					  AND (
						um2.meta_value LIKE '%\"subscriber\"%'
						OR um2.meta_value LIKE '%\"customer\"%'
					  )
				)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$prefix}usermeta um3
				WHERE um3.user_id = u.ID
				  AND um3.meta_key = '{$prefix}capabilities'
				  AND (
					um3.meta_value LIKE '%\"administrator\"%'
					OR um3.meta_value LIKE '%\"editor\"%'
				  )
			)
			AND u.ID NOT IN (
				SELECT DISTINCT CAST(cust.meta_value AS UNSIGNED)
				FROM {$prefix}posts p
				JOIN {$prefix}postmeta cust
					ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE p.post_type = 'shop_subscription'
				  AND p.post_status = 'wc-active'
				  AND oim.meta_value NOT IN ($donations)
			)
			AND u.ID NOT IN (
				SELECT DISTINCT CAST(cust2.meta_value AS UNSIGNED)
				FROM {$prefix}posts p2
				JOIN {$prefix}postmeta cust2
					ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = p2.ID
				WHERE p2.post_type = 'shop_order'
				  AND p2.post_status IN ('wc-completed', 'wc-processing')
				  AND p2.post_date_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
				  AND opl.product_id IN ($donations)
			)";

		return (int) $wpdb->get_var( $sql );
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

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $customer_ids Customer IDs to look up.
	 * @return array<int, \DateTimeImmutable>
	 */
	public function get_first_subscription_order_dates( array $customer_ids ): array {
		if ( empty( $customer_ids ) ) {
			return [];
		}

		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );
		$ids       = $this->id_list( $customer_ids );

		// Legacy CPT equivalent of HPOS_Storage::get_first_subscription_order_dates():
		// earliest non-donation subscription _schedule_start per _customer_user,
		// scoped to the given customer set. Mirrors get_new_subscribers_in_window(),
		// with one added guard: this query also excludes empty _schedule_start
		// (start.meta_value != '') so a blank value can't yield a bogus epoch date
		// — the window-count method drops blanks implicitly via its BETWEEN bounds.
		// _customer_user is cast to UNSIGNED (it is stored as a string) to match the
		// other list-scoped legacy queries and align grouping with the int keys returned.
		// MIN(start.meta_value) is a lexical comparison: postmeta.meta_value is a string
		// column, and _schedule_start is stored zero-padded `Y-m-d H:i:s`, so lexical
		// order equals chronological order. The donation helper aggregates the real
		// post_date_gmt datetime column, so it carries no such assumption.
		$sql = "SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(start.meta_value) AS first_start
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
			  AND CAST(cust.meta_value AS UNSIGNED) IN ($ids)
			GROUP BY CAST(cust.meta_value AS UNSIGNED)";

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$map  = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['first_start'] ) ) {
				continue;
			}
			$map[ (int) $row['customer_id'] ] = new \DateTimeImmutable( $row['first_start'], new \DateTimeZone( 'UTC' ) );
		}
		return $map;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{customer_id:int, ts:int}>
	 */
	public function get_new_subscriber_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Legacy CPT equivalent of HPOS get_new_subscriber_records_in_window().
		// Guest orders (blank _customer_user → 0 when cast) are excluded from
		// source attribution — see the HPOS variant for the rationale.
		//
		// Source meta lives on the PARENT shop_order (post_parent of the
		// shop_subscription post), NOT on the shop_subscription post itself.
		// Correlated subqueries (MySQL 5.7-safe) read _gate_post_id /
		// _memberships_content_gate / _newspack_popup_id from {prefix}postmeta
		// WHERE post_id = p_sub.post_parent. LIMIT 1 resolves same-timestamp
		// ties — any one tied first-subscription's parent-order meta is used.
		$sql = $wpdb->prepare(
			"SELECT
				first_subs.customer_id,
				first_subs.first_start,
				COALESCE((
					SELECT pm_gate.meta_value
					FROM {$prefix}posts p_sub
					JOIN {$prefix}postmeta cust_s
						ON cust_s.post_id = p_sub.ID AND cust_s.meta_key = '_customer_user'
					JOIN {$prefix}postmeta start_s
						ON start_s.post_id = p_sub.ID AND start_s.meta_key = '_schedule_start'
					JOIN {$prefix}postmeta pm_gate
						ON pm_gate.post_id = p_sub.post_parent AND pm_gate.meta_key = '_gate_post_id'
					JOIN {$prefix}woocommerce_order_items oi_s
						ON oi_s.order_id = p_sub.ID AND oi_s.order_item_type = 'line_item'
					JOIN {$prefix}woocommerce_order_itemmeta oim_s
						ON oim_s.order_item_id = oi_s.order_item_id AND oim_s.meta_key = '_product_id'
					WHERE p_sub.post_type = 'shop_subscription'
					  AND CAST(cust_s.meta_value AS UNSIGNED) = first_subs.customer_id
					  AND oim_s.meta_value NOT IN ($donations)
					  AND start_s.meta_value = first_subs.first_start
					  AND pm_gate.meta_value NOT IN ('', '0')
					LIMIT 1
				), (
					SELECT pm_legacy.meta_value
					FROM {$prefix}posts p_sub
					JOIN {$prefix}postmeta cust_s
						ON cust_s.post_id = p_sub.ID AND cust_s.meta_key = '_customer_user'
					JOIN {$prefix}postmeta start_s
						ON start_s.post_id = p_sub.ID AND start_s.meta_key = '_schedule_start'
					JOIN {$prefix}postmeta pm_legacy
						ON pm_legacy.post_id = p_sub.post_parent AND pm_legacy.meta_key = '_memberships_content_gate'
					JOIN {$prefix}woocommerce_order_items oi_s
						ON oi_s.order_id = p_sub.ID AND oi_s.order_item_type = 'line_item'
					JOIN {$prefix}woocommerce_order_itemmeta oim_s
						ON oim_s.order_item_id = oi_s.order_item_id AND oim_s.meta_key = '_product_id'
					WHERE p_sub.post_type = 'shop_subscription'
					  AND CAST(cust_s.meta_value AS UNSIGNED) = first_subs.customer_id
					  AND oim_s.meta_value NOT IN ($donations)
					  AND start_s.meta_value = first_subs.first_start
					  AND pm_legacy.meta_value NOT IN ('', '0')
					LIMIT 1
				), '') AS gate_post_id,
				COALESCE((
					SELECT pm_popup.meta_value
					FROM {$prefix}posts p_sub
					JOIN {$prefix}postmeta cust_s
						ON cust_s.post_id = p_sub.ID AND cust_s.meta_key = '_customer_user'
					JOIN {$prefix}postmeta start_s
						ON start_s.post_id = p_sub.ID AND start_s.meta_key = '_schedule_start'
					JOIN {$prefix}postmeta pm_popup
						ON pm_popup.post_id = p_sub.post_parent AND pm_popup.meta_key = '_newspack_popup_id'
					JOIN {$prefix}woocommerce_order_items oi_s
						ON oi_s.order_id = p_sub.ID AND oi_s.order_item_type = 'line_item'
					JOIN {$prefix}woocommerce_order_itemmeta oim_s
						ON oim_s.order_item_id = oi_s.order_item_id AND oim_s.meta_key = '_product_id'
					WHERE p_sub.post_type = 'shop_subscription'
					  AND CAST(cust_s.meta_value AS UNSIGNED) = first_subs.customer_id
					  AND oim_s.meta_value NOT IN ($donations)
					  AND start_s.meta_value = first_subs.first_start
					  AND pm_popup.meta_value NOT IN ('', '0')
					LIMIT 1
				), '') AS popup_id
			FROM (
				SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(start.meta_value) AS first_start
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
				  AND CAST(cust.meta_value AS UNSIGNED) > 0
				  AND oim.meta_value NOT IN ($donations)
				  AND start.meta_value != ''
				GROUP BY CAST(cust.meta_value AS UNSIGNED)
			) AS first_subs
			WHERE first_subs.first_start BETWEEN %s AND %s",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return $this->rows_to_subscriber_records( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Map (customer_id, first_start, gate_post_id, popup_id) rows to subscriber
	 * source records with UTC epoch seconds. Blank dates are skipped. UTC parse
	 * keeps the epoch correct regardless of MySQL session timezone.
	 *
	 * @param mixed $rows wpdb->get_results( …, ARRAY_A ) output.
	 * @return array<int, array{customer_id:int, ts:int, gate_post_id:string, popup_id:string}>
	 */
	private function rows_to_subscriber_records( $rows ): array {
		$utc     = new \DateTimeZone( 'UTC' );
		$records = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['first_start'] ) ) {
				continue;
			}
			$records[] = [
				'customer_id'  => (int) $row['customer_id'],
				'ts'           => ( new \DateTimeImmutable( $row['first_start'], $utc ) )->getTimestamp(),
				'gate_post_id' => (string) ( $row['gate_post_id'] ?? '' ),
				'popup_id'     => (string) ( $row['popup_id'] ?? '' ),
			];
		}
		return $records;
	}

	/**
	 * Map (customer_id, <date column>) rows to [ ['customer_id'=>int,'ts'=>int], … ]
	 * with ts as UTC epoch seconds. Blank dates skipped; UTC parse keeps the epoch
	 * correct regardless of MySQL session timezone.
	 *
	 * @param mixed  $rows     wpdb->get_results( …, ARRAY_A ) output.
	 * @param string $date_key Row key holding the UTC `Y-m-d H:i:s` value.
	 * @return array<int, array{customer_id:int, ts:int}>
	 */
	private function rows_to_records( $rows, string $date_key ): array {
		$utc     = new \DateTimeZone( 'UTC' );
		$records = [];
		foreach ( (array) $rows as $row ) {
			if ( empty( $row[ $date_key ] ) ) {
				continue;
			}
			$records[] = [
				'customer_id' => (int) $row['customer_id'],
				'ts'          => ( new \DateTimeImmutable( $row[ $date_key ], $utc ) )->getTimestamp(),
			];
		}
		return $records;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array{customer_id:int, registered_ts:int, first_sub_ts:int}>
	 */
	public function get_subscription_conversion_lags(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Legacy CPT equivalent of HPOS get_subscription_conversion_lags().
		$sql = "SELECT first_subs.customer_id, u.user_registered, first_subs.first_start
			FROM (
				SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id, MIN(start.meta_value) AS first_start
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
			) AS first_subs
			JOIN {$prefix}users u ON u.ID = first_subs.customer_id";

		return $this->rows_to_lags( $wpdb->get_results( $sql, ARRAY_A ), 'first_start', 'first_sub_ts' );
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
	 * {@inheritDoc}
	 *
	 * @param DateTimeInterface $start Inclusive window start.
	 * @param DateTimeInterface $end   Inclusive window end.
	 * @return array<int, array{order_id:int, gate_id:?string, popup_id:?string, order_total:float}>
	 */
	public function get_attributed_subscription_orders( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix        = $wpdb->prefix;
		$donations     = $this->id_list( $this->donation_product_ids );
		$subscriptions = $this->subscription_product_ids_sql();

		// NPPD-1746: two-key subscription attribution, the subscription counterpart
		// to the NPPD-1685 donation reader. A subscription/paywall order can carry
		// `_gate_post_id` (paywall gate) OR `_newspack_popup_id` (popup) — written
		// through different code paths — OR neither (organic). Both meta keys can be
		// written more than once per order, so JOINing either would multiply
		// COUNT/SUM (the NPPD-1685 2x defect): instead derive exactly ONE gate id and
		// ONE popup id per order via correlated MIN(), and match prompt/gate orders
		// with EXISTS. One row per attributed order is returned; gate-vs-popup
		// precedence is applied in Subscribers_Metric (gate wins). Scoped to INITIAL
		// completed/processing orders containing a non-donation subscription product;
		// renewals inherit the parent meta and are excluded via `_subscription_renewal`.
		$sql = $wpdb->prepare(
			"SELECT
				p.ID AS order_id,
				(
					SELECT MIN(g.meta_value)
					FROM {$prefix}postmeta g
					WHERE g.post_id = p.ID AND g.meta_key = '_gate_post_id'
					  AND g.meta_value NOT IN ('', '0')
				) AS gate_id,
				(
					SELECT MIN(pop.meta_value)
					FROM {$prefix}postmeta pop
					WHERE pop.post_id = p.ID AND pop.meta_key = '_newspack_popup_id'
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
			      WHERE opl.order_id = p.ID
			        AND opl.product_id IN ($subscriptions)
			        AND opl.product_id NOT IN ($donations)
			  )
			  AND (
			      EXISTS (
			          SELECT 1 FROM {$prefix}postmeta g
			          WHERE g.post_id = p.ID AND g.meta_key = '_gate_post_id'
			            AND g.meta_value NOT IN ('', '0')
			      )
			      OR EXISTS (
			          SELECT 1 FROM {$prefix}postmeta pop
			          WHERE pop.post_id = p.ID AND pop.meta_key = '_newspack_popup_id'
			            AND pop.meta_value NOT IN ('', '0')
			      )
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return $this->shape_attributed_subscription_rows( (array) $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * Normalize raw attributed-subscription-order rows to the typed per-order shape.
	 * A null gate/popup id (no such meta on the order) stays null so the orchestrator
	 * can apply gate precedence; `order_total` is coerced to float. Shared row shape
	 * with HPOS_Storage so both backends return identically-typed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw `$wpdb` rows.
	 * @return array<int, array{order_id:int, gate_id:?string, popup_id:?string, order_total:float}>
	 */
	private function shape_attributed_subscription_rows( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$gate_id  = isset( $row['gate_id'] ) && '' !== (string) $row['gate_id'] ? (string) $row['gate_id'] : null;
			$popup_id = isset( $row['popup_id'] ) && '' !== (string) $row['popup_id'] ? (string) $row['popup_id'] : null;
			if ( null === $gate_id && null === $popup_id ) {
				continue;
			}
			$out[] = [
				'order_id'    => (int) ( $row['order_id'] ?? 0 ),
				'gate_id'     => $gate_id,
				'popup_id'    => $popup_id,
				'order_total' => (float) ( $row['order_total'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, array{customer_id:int, start:string, cancelled:?string, end:?string}>
	 */
	public function get_new_subscriber_cohort_intervals(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$cutoff = $this->fmt( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-365 days' ) );
		// Upper bound excludes subscriptions with a future _schedule_start (scheduled/pending).
		$now = gmdate( 'Y-m-d H:i:s', ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() );

		// Legacy CPT equivalent of HPOS_Storage::get_new_subscriber_cohort_intervals().
		// _customer_user is cast to UNSIGNED (stored as a string) to align with the
		// other list-scoped legacy queries.
		$sql = $wpdb->prepare(
			"SELECT CAST(cust.meta_value AS UNSIGNED) AS customer_id,
				sm.meta_value AS sched_start,
				cm.meta_value AS sched_cancelled,
				em.meta_value AS sched_end
			FROM {$prefix}posts p
			JOIN {$prefix}postmeta cust
				ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'
			JOIN {$prefix}postmeta sm
				ON sm.post_id = p.ID AND sm.meta_key = '_schedule_start'
			LEFT JOIN {$prefix}postmeta cm
				ON cm.post_id = p.ID AND cm.meta_key = '_schedule_cancelled'
			LEFT JOIN {$prefix}postmeta em
				ON em.post_id = p.ID AND em.meta_key = '_schedule_end'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE p.post_type = 'shop_subscription'
			  AND CAST(cust.meta_value AS UNSIGNED) > 0 -- exclude guest subscriptions (mirrors get_new_subscriber_records_in_window)
			  AND oim.meta_value NOT IN ($donations)
			  AND sm.meta_value != ''
			  AND CAST(cust.meta_value AS UNSIGNED) IN (
				SELECT cohort.customer_id FROM (
					SELECT CAST(cust2.meta_value AS UNSIGNED) AS customer_id, MIN(sm2.meta_value) AS first_start
					FROM {$prefix}posts p2
					JOIN {$prefix}postmeta cust2
						ON cust2.post_id = p2.ID AND cust2.meta_key = '_customer_user'
					JOIN {$prefix}postmeta sm2
						ON sm2.post_id = p2.ID AND sm2.meta_key = '_schedule_start'
					JOIN {$prefix}woocommerce_order_items oi2
						ON oi2.order_id = p2.ID AND oi2.order_item_type = 'line_item'
					JOIN {$prefix}woocommerce_order_itemmeta oim2
						ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
					WHERE p2.post_type = 'shop_subscription'
					  AND CAST(cust2.meta_value AS UNSIGNED) > 0 -- exclude guest subscriptions
					  AND oim2.meta_value NOT IN ($donations)
					  AND sm2.meta_value != ''
					GROUP BY CAST(cust2.meta_value AS UNSIGNED)
					HAVING first_start >= %s AND first_start <= %s
				) cohort
			  )",
			$cutoff,
			$now
		);

		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$result = [];
		foreach ( (array) $rows as $row ) {
			$result[] = [
				'customer_id' => (int) $row['customer_id'],
				'start'       => (string) $row['sched_start'],
				'cancelled'   => null === $row['sched_cancelled'] ? null : (string) $row['sched_cancelled'],
				'end'         => null === $row['sched_end'] ? null : (string) $row['sched_end'],
			];
		}
		return $result;
	}
}
