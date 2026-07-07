<?php
/**
 * Newspack Insights — HPOS Storage implementation (NPPD-1616).
 *
 * Implements {@see Storage_Interface} against the HPOS tables
 * (`{prefix}wc_orders`, `{prefix}wc_orders_meta`). SQL bodies adapted
 * from `~/Sites/insights-docs/formulas/tab-6-subscribers.md` with CTEs
 * unwound into inline subqueries for MySQL 5.7 compatibility.
 *
 * Product-id joins differ by query type:
 *
 *   - Subscription queries (active/new/churned subscribers, MRR/ARR,
 *     tenure, performance, retry, cancellation reasons, upcoming
 *     renewals) JOIN through `{prefix}woocommerce_order_items` +
 *     `{prefix}woocommerce_order_itemmeta._product_id`. The analytics
 *     lookup `{prefix}wc_order_product_lookup` is shop_order-only on
 *     production data (verified against two production sites — a large
 *     metro newsroom and a mid-size local publisher — ~40,000 / ~13,000
 *     shop_order rows respectively, and zero shop_subscription rows on
 *     either).
 *   - Revenue queries (gross/net/refund_rate) operate on shop_order
 *     and DO use `{prefix}wc_order_product_lookup`, which Woo populates
 *     correctly for shop_order line items.
 *
 * Donation product IDs are injected at construction; an empty set
 * coerces to `(0)` in the NOT IN list (never matches a real product),
 * which keeps SQL valid when a publisher has no donation products yet.
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
 * HPOS implementation of the Tab 6 storage contract.
 */
class HPOS_Storage implements Storage_Interface {

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
	 *                                    May be empty; the SQL helpers
	 *                                    coerce empty arrays to `(0)`.
	 */
	public function __construct( array $donation_product_ids ) {
		$this->donation_product_ids = array_map( 'intval', $donation_product_ids );
	}

	/**
	 * Build a SQL-safe `IN (...)` list from a list of integer product IDs.
	 *
	 * Empty input returns `(0)` so the resulting `NOT IN` clause stays
	 * valid syntactically while never matching a real product.
	 *
	 * Note (M2): when no donation product IDs are configured, `NOT IN (0)`
	 * treats ALL subscriptions / orders as non-donation — matching sibling
	 * behavior — so subscriber counts may include donation activity on
	 * misconfigured sites (no donation products defined). This is intentional
	 * and consistent with how the rest of the storage layer handles an empty
	 * donation-product set.
	 *
	 * @param int[] $ids List of integer IDs.
	 * @return string Comma-separated list of integers (or `0`), unparenthesized.
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
	 * Every column these queries compare against stores UTC: the `*_gmt`
	 * order/post columns and the WooCommerce Subscriptions `_schedule_*` meta
	 * (which WCS persists as UTC datetime strings). Window bounds arrive in the
	 * site timezone (built from `wp_timezone()` in the REST controller), so we
	 * format the absolute instant in UTC here to keep the window aligned on
	 * non-UTC sites. Uses `getTimestamp()` so the result is correct regardless
	 * of the input DateTime's own timezone.
	 *
	 * @param DateTimeInterface $dt DateTime to format.
	 * @return string `Y-m-d H:i:s` UTC-formatted string.
	 */
	private function fmt( DateTimeInterface $dt ): string {
		return gmdate( 'Y-m-d H:i:s', $dt->getTimestamp() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_non_donation_subscribers(): int {
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

		// Customers whose earliest non-donation subscription's _schedule_start
		// falls in the window. Inner aggregate computes first-start per
		// customer; outer count filters that to the window. The om.meta_value != ''
		// guard matches the records method so the count and source-mix records
		// agree on sites where a subscription has a blank _schedule_start.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM (
				SELECT o.customer_id, MIN(om.meta_value) AS first_start
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
	 * @return array<string,int>
	 */
	public function get_new_subscribers_by_week( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Same "customer's earliest non-donation subscription in window" cohort as
		// get_new_subscribers_in_window(), bucketed by the Sunday-starting week of that
		// first _schedule_start. Sunday-start (DAYOFWEEK: Sun=1) matches BigQuery's
		// DATE_TRUNC(date, WEEK) the hub's registration series uses, so both align on
		// week_start. _schedule_start is UTC while the hub's GA4 weeks are property-
		// local, so a subscription within hours of a week boundary can land one
		// bucket over. Acceptable trend fuzz.
		$sql = $wpdb->prepare(
			"SELECT
				DATE(DATE_SUB(first_subs.first_start, INTERVAL (DAYOFWEEK(first_subs.first_start) - 1) DAY)) AS week_start,
				COUNT(*) AS new_subscribers
			FROM (
				SELECT o.customer_id, MIN(om.meta_value) AS first_start
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
			) AS first_subs
			WHERE first_subs.first_start BETWEEN %s AND %s
			GROUP BY week_start
			ORDER BY week_start",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		$result = [];
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
			$result[ (string) $row['week_start'] ] = (int) $row['new_subscribers'];
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Customers whose non-donation subscriptions cancelled/expired in window
	 * AND who have no remaining active non-donation subscriptions. Cancelled/
	 * expired side pre-aggregated to DISTINCT customer_ids and LEFT-JOINed
	 * against the DISTINCT active-subscriber customer_ids (also pre-aggregated),
	 * keeping only rows where the active side is NULL — an anti-join, not a
	 * correlated `NOT IN (subquery)` (MySQL's slowest anti-join form). Same
	 * approach as {@see self::get_winback_subscribers_in_window()}. DISTINCT on
	 * both derived tables prevents JOIN fan-out from the line-item join.
	 * `wc_orders.customer_id` is a native column, so unlike the legacy backend
	 * no `_customer_user` postmeta join is needed on either side.
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
				  AND oim.meta_value NOT IN ($donations)
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
				  AND oim2.meta_value NOT IN ($donations)
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
	 * @return int
	 */
	public function get_winback_subscribers_in_window( DateTimeInterface $start, DateTimeInterface $end ): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Distinct customers who started a non-donation subscription in the window
		// (the "return") AND previously churned — i.e. they hold an earlier-started
		// non-donation subscription that is cancelled or expired. Regardless of
		// product. Disjoint from first-time subscribers by construction: a
		// first-timer has no prior subscription, so no earlier churned one.
		//
		// The churned side is pre-aggregated to one row per customer (their earliest
		// churned-sub start) and INNER-JOINed to the returns, rather than a
		// correlated EXISTS per return row — same result, far cheaper on large
		// publishers. The `min_churn_start < ret.start_val` string comparison is
		// chronological because _schedule_start is stored zero-padded 'Y-m-d H:i:s'.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT ret.customer_id) FROM (
				SELECT o.customer_id, om.meta_value AS start_val
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND om.meta_value BETWEEN %s AND %s
				  AND om.meta_value != ''
			) AS ret
			JOIN (
				SELECT o2.customer_id, MIN(om2.meta_value) AS min_churn_start
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}wc_orders_meta om2
					ON om2.order_id = o2.id AND om2.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi2
					ON oi2.order_id = o2.id AND oi2.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim2
					ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
				WHERE o2.type = 'shop_subscription'
				  AND o2.status IN ('wc-cancelled', 'wc-expired')
				  AND oim2.meta_value NOT IN ($donations)
				  AND om2.meta_value != ''
				GROUP BY o2.customer_id
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

		// phpcs:disable Squiz.PHP.CommentedOutCode.Found -- prose with billing math triggers heuristic

		/*
		 * Normalize each active subscription's total to a monthly rate.
		 * The CASE statement covers all documented Woo billing periods at
		 * any positive integer interval N. Daily subscriptions multiply
		 * the row total by thirty and divide by N, treating a month as
		 * thirty days. Weekly subscriptions multiply by fifty-two over
		 * twelve and divide by N. Monthly subscriptions divide by N.
		 * Yearly subscriptions divide by twelve times N.
		 *
		 * The ELSE branch is truly conservative — it falls through to
		 * total over twelve, which undercounts MRR for anything except
		 * yearly. A publisher with weird intervals will see slightly
		 * lower MRR than reality rather than the previous behavior of
		 * multiplying everything to look monthly. A separate diagnostic
		 * query below counts subscriptions hitting this fallback and
		 * logs a notice via Newspack Logger so the publisher can correct
		 * the product configuration.
		 *
		 * The DISTINCT order-id sub-select dedupes subscriptions that
		 * have more than one non-donation line item so MRR isn't
		 * multiplied across line items.
		 */
		// phpcs:enable Squiz.PHP.CommentedOutCode.Found
		$sql = "SELECT SUM(
				CASE
					WHEN bp.meta_value = 'day'   AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount * 30 / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'week'  AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount * (52/12) / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'month' AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount / CAST(bi.meta_value AS UNSIGNED)
					WHEN bp.meta_value = 'year'  AND CAST(bi.meta_value AS UNSIGNED) > 0
						THEN o.total_amount / (12 * CAST(bi.meta_value AS UNSIGNED))
					ELSE o.total_amount / 12
				END
			)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta bp
				ON bp.order_id = o.id AND bp.meta_key = '_billing_period'
			JOIN {$prefix}wc_orders_meta bi
				ON bi.order_id = o.id AND bi.meta_key = '_billing_interval'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )";

		$mrr = (float) $wpdb->get_var( $sql );

		// Diagnostic: count active non-donation subscriptions whose
		// _billing_period or _billing_interval is unrecognized. If any
		// exist, their MRR contribution was the conservative fallback —
		// surface so the publisher can fix the product config.
		$unrecognized = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta bp ON bp.order_id = o.id AND bp.meta_key = '_billing_period'
			JOIN {$prefix}wc_orders_meta bi ON bi.order_id = o.id AND bi.meta_key = '_billing_interval'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND (
				bp.meta_value NOT IN ('day', 'week', 'month', 'year')
				OR CAST(bi.meta_value AS UNSIGNED) = 0
			  )
			  AND o.id IN (
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
	 * Compute the ID set of subscription-type products (subscription,
	 * variable-subscription, subscription_variation) for use in the
	 * subscription-product filter clauses. Live query per call; callers
	 * should be cached.
	 *
	 * @return string Comma-separated list of integer product IDs, or `0`
	 *                when the publisher has none.
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

		// Sum shop_order totals where order contains a subscription product
		// AND no donation product. Two separate filters on the lookup table.
		$sql = $wpdb->prepare(
			"SELECT SUM(o.total_amount)
			FROM {$prefix}wc_orders o
			WHERE o.type = 'shop_order'
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND o.id IN (
				SELECT DISTINCT order_id
				FROM {$prefix}wc_order_product_lookup
				WHERE product_id IN ($subscription_p)
			  )
			  AND o.id NOT IN (
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

		// Sum across shop_order + shop_order_refund rows. Refunds carry
		// negative totals so SUM yields correct net.
		$sql = $wpdb->prepare(
			"SELECT SUM(o.total_amount)
			FROM {$prefix}wc_orders o
			WHERE o.type IN ('shop_order', 'shop_order_refund')
			  AND o.status IN ('wc-completed', 'wc-processing')
			  AND o.date_created_gmt BETWEEN %s AND %s
			  AND (
				(
					o.type = 'shop_order'
					AND o.id IN (
						SELECT DISTINCT order_id
						FROM {$prefix}wc_order_product_lookup
						WHERE product_id IN ($subscription_p)
						  AND product_id NOT IN ($donations)
					)
				)
				OR (
					o.type = 'shop_order_refund'
					AND o.parent_order_id IN (
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

		// Count subscription orders in window.
		$orders_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}wc_orders
			WHERE type = 'shop_order'
			  AND status IN ('wc-completed', 'wc-processing')
			  AND date_created_gmt BETWEEN %s AND %s
			  AND id IN (
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

		// Count refunds in window whose parent order had a subscription product.
		$refunds_sql = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$prefix}wc_orders r
			WHERE r.type = 'shop_order_refund'
			  AND r.date_created_gmt BETWEEN %s AND %s
			  AND r.parent_order_id IN (
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

		// Tenure days since _schedule_start for each active non-donation
		// subscription line item, grouped client-side by product_name.
		// Excludes empty or future start dates (data-corruption edge case).
		$sql = "SELECT
				p.post_title AS product_name,
				TIMESTAMPDIFF(DAY, om.meta_value, NOW()) AS tenure_days
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta om
				ON om.order_id = o.id AND om.meta_key = '_schedule_start'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			JOIN {$prefix}posts p ON p.ID = CAST(oim.meta_value AS UNSIGNED)
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)
			  AND om.meta_value != ''
			  AND om.meta_value < NOW()";

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
		// subscription is counted once and its total_amount isn't summed twice.
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
				  AND oim.meta_value NOT IN ($donations)
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
	public function get_upcoming_cancellations_30d(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Both wc-active (fixed-term ending naturally) and
		// wc-pending-cancel (customer cancelled mid-cycle) carry a
		// future `_schedule_end` when applicable. DISTINCT id-subselect
		// for the non-donation filter so multi-line-item subs aren't
		// double-summed.
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
				  AND oim.meta_value NOT IN ($donations)
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
	 * @return array{value: float, computable: bool, denominator: int}
	 */
	public function get_failed_payment_retry_rate( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		// Count retry-scheduled subscriptions in window vs. how many ended
		// the window with status wc-active (= successful recovery).
		// DISTINCT id-subselect for the non-donation filter so a
		// multi-line-item subscription doesn't show up as multiple retries.
		$sql = $wpdb->prepare(
			"SELECT
				COUNT(*) AS retry_attempts,
				SUM(CASE WHEN sub.status = 'wc-active' THEN 1 ELSE 0 END) AS recoveries
			FROM (
				SELECT DISTINCT o.id AS subscription_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_payment_retry'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND oim.meta_value NOT IN ($donations)
				  AND om.meta_value BETWEEN %s AND %s
				  AND om.meta_value != ''
			) AS retries
			JOIN {$prefix}wc_orders sub ON sub.id = retries.subscription_id",
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

		// Column scope:
		// active_subs       — current state (independent of window)
		// active_value      — current state
		// lifetime_revenue  — lifetime sum of subscription-record totals
		// attributed per product; not windowed by
		// design (true LTV waits on the v1.1 BQ wrapper)
		// new_subs          — WINDOWED to {start, end} via the
		// `_schedule_start` meta join below. Gross starts:
		// counts every sub that STARTED in-window regardless of
		// current status, so a sub that started AND churned in the
		// same timeframe is counted in both new_subs and
		// churned_subs (independent events, by design)
		// churned_subs      — WINDOWED to {start, end} via the
		// `_schedule_cancelled` meta join below
		//
		// Each subscription line item is counted toward the product it
		// references. A subscription with two non-donation line items
		// contributes to both products' counts and amounts; SUM uses
		// `o.total_amount` so a multi-product sub does NOT inflate the
		// per-product active_value beyond the subscription's actual total
		// (instead it's attributed once per product — a simplification).
		//
		// The LEFT JOINs to `_schedule_start` and `_schedule_cancelled`
		// are required for window scoping. Subscriptions missing the meta
		// (e.g. an active sub has no `_schedule_cancelled`) left-join to
		// NULL and the corresponding CASE naturally rejects them. Woo
		// writes at most one row per subscription for each of these keys,
		// so no row multiplication.

		/*
		 * Query at the effective-product level. Woo's convention for
		 * variable products is to write the PARENT id into the line
		 * item's `_product_id` meta and the actual variation id into a
		 * separate `_variation_id` meta. We COALESCE the latter over
		 * the former so the row resolves to the variation for variable
		 * products (post_parent > 0) and to the standalone product for
		 * simple subs (post_parent = 0).
		 *
		 * The donation filter stays on `_product_id` because the
		 * donation set is keyed by the parent in WC's data model.
		 * Aggregation into parent + nested variations happens in PHP
		 * below.
		 */
		$sql = $wpdb->prepare(
			"SELECT
				pv.ID AS variation_id,
				pv.post_title AS variation_name,
				pv.post_parent AS parent_id,
				COALESCE(pp.post_title, '') AS parent_name,
				COALESCE(period_meta.meta_value, '') AS sub_period,
				COUNT(DISTINCT CASE WHEN o.status = 'wc-active' THEN o.id END) AS active_subs,
				COUNT(DISTINCT CASE
					WHEN st.meta_value != '' AND st.meta_value BETWEEN %s AND %s
					THEN o.id
				END) AS new_subs,
				COUNT(DISTINCT CASE
					WHEN o.status IN ('wc-cancelled', 'wc-expired')
					 AND sch.meta_value BETWEEN %s AND %s
					THEN o.id
				END) AS churned_subs,
				COALESCE(SUM(CASE WHEN o.status = 'wc-active' THEN o.total_amount END), 0) AS active_value,
				COALESCE(SUM(o.total_amount), 0) AS lifetime_revenue
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
			LEFT JOIN {$prefix}wc_orders_meta st
				ON st.order_id = o.id AND st.meta_key = '_schedule_start'
			LEFT JOIN {$prefix}wc_orders_meta sch
				ON sch.order_id = o.id AND sch.meta_key = '_schedule_cancelled'
			WHERE o.type = 'shop_subscription'
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
	 * Aggregate flat per-variation rows from the performance SQL into
	 * the parent + nested variations shape the React layer expects.
	 *
	 * For each row:
	 *   - If parent_id > 0 (variation), attach to its parent's bucket
	 *     and accumulate the parent's aggregates from the variation's
	 *     numbers.
	 *   - If parent_id == 0, the row is either a standalone
	 *     simple/subscription product OR a bare-parent line item on a
	 *     VARIABLE product (a subscription that recorded the parent
	 *     product with _variation_id = 0 — e.g. a name-your-price / gift
	 *     parent-level purchase). It resolves to the product's own id and
	 *     MERGES into that bucket: when the bucket also owns variation
	 *     rows it folds in as a synthetic "(no variation)" variation
	 *     instead of overwriting the bucket and discarding the variations
	 *     (NPPD-1616 regression). A bucket that only ever sees this branch
	 *     stays a single non-parent entry.
	 *
	 * Variation labels come from the _subscription_period meta when
	 * present (month→Monthly, year→Annual, week→Weekly, day→Daily),
	 * falling back to the variation post_title stripped of the parent
	 * name prefix, or 'Variation' as last resort.
	 *
	 * Each parent's variations array is sorted by active_subs DESC.
	 * The outer list is truncated to the top 50 parents/standalones by
	 * active_subs.
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
			// row from clobbering a variable product's accumulated variations.
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

		// Sort each parent's variations by active_subs DESC. Standalone (non-parent)
		// buckets render as a single row, so drop the internal single-element
		// variations scaffold to keep their payload shape on the renderer's
		// is_parent contract (no variations key for simple products).
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

		// Sort outer list by aggregated active_subs DESC, top 50.
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
	 * Pick a variation label. Prefer the period meta translated to a
	 * human-friendly cadence; fall back to the variation's own title
	 * with the parent name + ' - ' prefix stripped; last resort is a
	 * generic "Variation" string.
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

		// Active non-donation subscriptions that currently have a non-empty
		// _schedule_payment_retry meta. COUNT(DISTINCT o.id) rather than
		// customer_id so a customer with two at-risk subscriptions counts twice
		// (matches the displayed subscription count, not customer count).
		$sql = "SELECT COUNT(DISTINCT o.id)
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta retry
				ON retry.order_id = o.id AND retry.meta_key = '_schedule_payment_retry'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
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

		$sql = "SELECT DISTINCT o.customer_id
			FROM {$prefix}wc_orders o
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND o.status = 'wc-active'
			  AND oim.meta_value NOT IN ($donations)";

		$rows = $wpdb->get_col( $sql );
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Base population: registered readers. The canonical Newspack signal is
	 * the `np_reader` user meta written at reader registration time. As a
	 * non-strict fallback we also include users in the 'subscriber' or
	 * 'customer' roles even when np_reader is absent — mirroring
	 * Reader_Activation::is_user_reader( $user, strict=false ). Admins and
	 * editors are excluded (the default restricted_roles set).
	 *
	 * Phase-A approximation (M1): hardcodes 'subscriber'/'customer' as reader
	 * roles and 'administrator'/'editor' as restricted roles — does NOT honor
	 * the filterable newspack_reader_user_roles / newspack_reader_restricted_roles
	 * hooks, so sites with custom reader roles may see a slightly off count
	 * (acceptable upper-bound for Phase A; adjust in a later iteration).
	 *
	 * Exclusion sets: (a) users with ≥1 active non-donation subscription today;
	 * (b) users who completed a donation order in the trailing 365 days. Both
	 * are pre-aggregated to DISTINCT customer_ids and LEFT-JOINed against
	 * `u.ID`, keeping only rows where both sides are NULL — an anti-join, not a
	 * correlated `NOT IN (subquery)` (MySQL's slowest anti-join form). Same
	 * approach as {@see self::get_churned_subscribers_in_window()}. The
	 * base-population / restricted-role checks stay as correlated `EXISTS` —
	 * each is a single indexed lookup on `usermeta.user_id`, not a multi-join
	 * subquery, so they are not a timeout risk.
	 *
	 * Phase-A approximation: the "no BQ-tracked activity in 90 days"
	 * refinement that distinguishes truly stale readers from recently-active
	 * non-converters is deferred to Phase B (requires the BQ event export).
	 * This count is an upper bound, not an exact match for the BQ definition.
	 *
	 * @return int
	 */
	public function get_stale_registered_users(): int {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = "SELECT COUNT(DISTINCT u.ID)
			FROM {$prefix}users u
			LEFT JOIN (
				SELECT DISTINCT o.customer_id
				FROM {$prefix}wc_orders o
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND o.status = 'wc-active'
				  AND oim.meta_value NOT IN ($donations)
			) AS active_subscribers ON active_subscribers.customer_id = u.ID
			LEFT JOIN (
				SELECT DISTINCT o2.customer_id
				FROM {$prefix}wc_orders o2
				JOIN {$prefix}wc_order_product_lookup opl ON opl.order_id = o2.id
				WHERE o2.type = 'shop_order'
				  AND o2.status IN ('wc-completed', 'wc-processing')
				  AND o2.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 365 DAY)
				  AND opl.product_id IN ($donations)
			) AS recent_donors ON recent_donors.customer_id = u.ID
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
			AND active_subscribers.customer_id IS NULL
			AND recent_donors.customer_id IS NULL";

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
				COALESCE(om.meta_value, 'unknown') AS cancellation_reason,
				COUNT(*) AS count
			FROM {$prefix}wc_orders o
			LEFT JOIN {$prefix}wc_orders_meta om
				ON om.order_id = o.id AND om.meta_key = 'newspack_subscriptions_cancellation_reason'
			JOIN {$prefix}wc_orders_meta sch
				ON sch.order_id = o.id AND sch.meta_key = '_schedule_cancelled'
			WHERE o.type = 'shop_subscription'
			  AND o.status IN ('wc-cancelled', 'wc-expired')
			  AND o.id IN (
				SELECT DISTINCT oi.order_id
				FROM {$prefix}woocommerce_order_items oi
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND oim.meta_value NOT IN ($donations)
			  )
			  AND sch.meta_value BETWEEN %s AND %s
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

		// Earliest non-donation subscription _schedule_start per customer,
		// scoped to the given customer set. Mirrors the inner aggregate of
		// get_new_subscribers_in_window() (same first-start definition), with
		// one added guard: this query also excludes empty _schedule_start
		// (om.meta_value != '') so a blank value can't yield a bogus epoch
		// date. The window-count method drops blanks implicitly via its
		// BETWEEN bounds, so results reconcile on healthy data.
		// MIN(om.meta_value) is a lexical comparison: wc_orders_meta.meta_value
		// is a string column, and _schedule_start is stored zero-padded
		// `Y-m-d H:i:s`, so lexical order equals chronological order. The
		// donation helper aggregates a real datetime column, so it carries no
		// such assumption.
		$sql = "SELECT o.customer_id, MIN(om.meta_value) AS first_start
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
			  AND o.customer_id IN ($ids)
			GROUP BY o.customer_id";

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
	 * Same inner aggregate as get_new_subscribers_in_window() (first
	 * non-donation _schedule_start per customer), filtered to the window.
	 * Guest orders (customer_id = 0) are excluded: a guest has no user account
	 * to match against BQ registration events.
	 *
	 * Source meta lives on the PARENT shop_order (the subscription's initial
	 * checkout order), NOT on the shop_subscription row itself. Each
	 * subscription's parent_order_id in {prefix}wc_orders points to the
	 * initial shop_order; we read _gate_post_id / _memberships_content_gate /
	 * _newspack_popup_id from that parent order's meta.
	 *
	 * `first_sub_parent` pre-aggregates, per (customer_id, first_start) pair,
	 * the parent_order_id of that first subscription — `MIN(parent_order_id)`
	 * deterministically resolves the rare case of two subscriptions tying on
	 * the same customer + start timestamp (the original's `LIMIT 1` with no
	 * ORDER BY picked "any one" of the tied rows just as arbitrarily). Gate /
	 * legacy-gate / popup meta are each pre-aggregated to one row per order_id
	 * (`MIN(meta_value)` guards against an order carrying more than one
	 * non-empty value for the same key, mirroring the original's `LIMIT 1`)
	 * and LEFT-JOINed on that resolved parent id, instead of three correlated
	 * per-row subqueries that each re-execute the whole 4-table join on every
	 * outer row.
	 *
	 * gate_post_id: first non-empty _gate_post_id on the parent order, falling
	 * back to legacy _memberships_content_gate (both NOT IN ('','0')).
	 * popup_id: non-empty _newspack_popup_id on the parent order. A LEFT JOIN
	 * miss (no parent order / no meta) still yields '' via COALESCE, exactly
	 * as the original — the metric layer falls those records to the BQ
	 * temporal matcher (unchanged behaviour).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{customer_id:int, ts:int}>
	 */
	public function get_new_subscriber_records_in_window( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$donations = $this->id_list( $this->donation_product_ids );

		$sql = $wpdb->prepare(
			"SELECT
				first_subs.customer_id,
				first_subs.first_start,
				COALESCE(gate.gate_post_id, legacy_gate.gate_post_id, '') AS gate_post_id,
				COALESCE(popup.popup_id, '') AS popup_id
			FROM (
				SELECT o.customer_id, MIN(om.meta_value) AS first_start
				FROM {$prefix}wc_orders o
				JOIN {$prefix}wc_orders_meta om
					ON om.order_id = o.id AND om.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim
					ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
				WHERE o.type = 'shop_subscription'
				  AND o.customer_id > 0
				  AND oim.meta_value NOT IN ($donations)
				  AND om.meta_value != ''
				GROUP BY o.customer_id
			) AS first_subs
			LEFT JOIN (
				SELECT
					o_sub.customer_id,
					om_start_s.meta_value AS first_start,
					MIN(o_sub.parent_order_id) AS parent_id
				FROM {$prefix}wc_orders o_sub
				JOIN {$prefix}wc_orders_meta om_start_s
					ON om_start_s.order_id = o_sub.id AND om_start_s.meta_key = '_schedule_start'
				JOIN {$prefix}woocommerce_order_items oi_s
					ON oi_s.order_id = o_sub.id AND oi_s.order_item_type = 'line_item'
				JOIN {$prefix}woocommerce_order_itemmeta oim_s
					ON oim_s.order_item_id = oi_s.order_item_id AND oim_s.meta_key = '_product_id'
				WHERE o_sub.type = 'shop_subscription'
				  AND oim_s.meta_value NOT IN ($donations)
				GROUP BY o_sub.customer_id, om_start_s.meta_value
			) AS first_sub_parent
				ON first_sub_parent.customer_id = first_subs.customer_id
				AND first_sub_parent.first_start = first_subs.first_start
			LEFT JOIN (
				SELECT order_id, MIN(meta_value) AS gate_post_id
				FROM {$prefix}wc_orders_meta
				WHERE meta_key = '_gate_post_id' AND meta_value NOT IN ('', '0')
				GROUP BY order_id
			) AS gate ON gate.order_id = first_sub_parent.parent_id
			LEFT JOIN (
				SELECT order_id, MIN(meta_value) AS gate_post_id
				FROM {$prefix}wc_orders_meta
				WHERE meta_key = '_memberships_content_gate' AND meta_value NOT IN ('', '0')
				GROUP BY order_id
			) AS legacy_gate ON legacy_gate.order_id = first_sub_parent.parent_id
			LEFT JOIN (
				SELECT order_id, MIN(meta_value) AS popup_id
				FROM {$prefix}wc_orders_meta
				WHERE meta_key = '_newspack_popup_id' AND meta_value NOT IN ('', '0')
				GROUP BY order_id
			) AS popup ON popup.order_id = first_sub_parent.parent_id
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
	 * with ts as UTC epoch seconds. Blank dates are skipped. The UTC parse keeps
	 * the epoch correct regardless of MySQL session timezone.
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

		// First non-donation subscription per customer, joined to wp_users for
		// the registration date. All-history (snapshot). Guests (no users row)
		// are excluded by the join, which is correct: a lag needs a registration.
		$sql = "SELECT first_subs.customer_id, u.user_registered, first_subs.first_start
			FROM (
				SELECT o.customer_id, MIN(om.meta_value) AS first_start
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

		// NPPD-1746: structurally mirrors the legacy reader — both `_gate_post_id`
		// and `_newspack_popup_id` can be written more than once per order, so each
		// is derived via correlated MIN() and matched with EXISTS (never a
		// multiplying JOIN); `total_amount` is a column on the orders table (no meta
		// multiplication). One row per attributed order; gate-precedence is applied
		// in Subscribers_Metric. Initial orders only; renewals excluded via
		// `_subscription_renewal`; scoped to non-donation subscription products.
		//
		// UNVERIFIED AGAINST LIVE HPOS DATA (NPPD-1746): both Phase-1 subscription
		// publishers measured are legacy-storage, so this HPOS query has not
		// executed against real HPOS subscription orders. It is mirrored from the
		// legacy shape and exercised by the DDL integration test; confirm the count +
		// revenue at first HPOS-publisher contact. Same posture as the NPPD-1685
		// donation HPOS reader.
		$sql = $wpdb->prepare(
			"SELECT
				ord.id AS order_id,
				(
					SELECT MIN(g.meta_value)
					FROM {$prefix}wc_orders_meta g
					WHERE g.order_id = ord.id AND g.meta_key = '_gate_post_id'
					  AND g.meta_value NOT IN ('', '0')
				) AS gate_id,
				(
					SELECT MIN(pop.meta_value)
					FROM {$prefix}wc_orders_meta pop
					WHERE pop.order_id = ord.id AND pop.meta_key = '_newspack_popup_id'
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
			      WHERE opl.order_id = ord.id
			        AND opl.product_id IN ($subscriptions)
			        AND opl.product_id NOT IN ($donations)
			  )
			  AND (
			      EXISTS (
			          SELECT 1 FROM {$prefix}wc_orders_meta g
			          WHERE g.order_id = ord.id AND g.meta_key = '_gate_post_id'
			            AND g.meta_value NOT IN ('', '0')
			      )
			      OR EXISTS (
			          SELECT 1 FROM {$prefix}wc_orders_meta pop
			          WHERE pop.order_id = ord.id AND pop.meta_key = '_newspack_popup_id'
			            AND pop.meta_value NOT IN ('', '0')
			      )
			  )",
			$this->fmt( $start ),
			$this->fmt( $end )
		);

		return $this->shape_attributed_subscription_rows( (array) $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * New-subscription campaign attribution rows for the window (NEWS-2591).
	 *
	 * The subscription twin of get_donation_campaign_rows(): one row per INITIAL
	 * (non-renewal) completed/processing order containing a non-donation
	 * subscription product in [$start, $end], keyed on `utm_campaign` read off that
	 * initial order. Mirrors get_attributed_subscription_orders() exactly — same
	 * initial-order scope, renewal + donation-product exclusion, and correlated
	 * MIN() (the meta is written non-unique, so a JOIN would inflate) — but WITHOUT
	 * its gate/popup-attribution requirement, so every subscription order is emitted
	 * (untagged → '') for the "(no campaign)" bucket. HPOS reads `wc_orders_meta`.
	 *
	 * revenue = initial subscription order total (not recurring / MRR).
	 *
	 * @param DateTimeInterface $start Window start.
	 * @param DateTimeInterface $end   Window end.
	 * @return array<int, array{utm_campaign: string, revenue: float}>
	 */
	public function get_new_subscription_campaign_rows( DateTimeInterface $start, DateTimeInterface $end ): array {
		global $wpdb;
		$prefix        = $wpdb->prefix;
		$donations     = $this->id_list( $this->donation_product_ids );
		$subscriptions = $this->subscription_product_ids_sql();

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
			      WHERE opl.order_id = ord.id
			        AND opl.product_id IN ($subscriptions)
			        AND opl.product_id NOT IN ($donations)
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

	/**
	 * Normalize raw attributed-subscription-order rows to the typed per-order shape.
	 * A null gate/popup id (no such meta on the order) stays null so the orchestrator
	 * can apply gate precedence; `order_total` is coerced to float. Shared row shape
	 * with Legacy_Storage so both backends return identically-typed rows.
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

		// Trailing-365-day cohort window in UTC, both bounds via prepare (not NOW()).
		$cutoff = $this->fmt( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-365 days' ) );
		// Upper bound excludes subscriptions with a future _schedule_start (scheduled/pending).
		$now = gmdate( 'Y-m-d H:i:s', ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() );

		// Inner subquery: customers whose earliest non-donation subscription
		// start is within the window (the cohort). Mirrors the first-start
		// definition in get_first_subscription_order_dates(). MIN(meta_value)
		// is a lexical comparison; _schedule_start is zero-padded `Y-m-d H:i:s`,
		// so lexical order == chronological order.
		// Outer query: every non-donation subscription of those customers, with
		// its start/cancelled/end schedule meta.
		$sql = $wpdb->prepare(
			"SELECT o.customer_id,
				sm.meta_value AS sched_start,
				cm.meta_value AS sched_cancelled,
				em.meta_value AS sched_end
			FROM {$prefix}wc_orders o
			JOIN {$prefix}wc_orders_meta sm
				ON sm.order_id = o.id AND sm.meta_key = '_schedule_start'
			LEFT JOIN {$prefix}wc_orders_meta cm
				ON cm.order_id = o.id AND cm.meta_key = '_schedule_cancelled'
			LEFT JOIN {$prefix}wc_orders_meta em
				ON em.order_id = o.id AND em.meta_key = '_schedule_end'
			JOIN {$prefix}woocommerce_order_items oi
				ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
			JOIN {$prefix}woocommerce_order_itemmeta oim
				ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
			WHERE o.type = 'shop_subscription'
			  AND o.customer_id > 0 -- exclude guest subscriptions (mirrors get_new_subscriber_records_in_window)
			  AND oim.meta_value NOT IN ($donations)
			  AND sm.meta_value != ''
			  AND o.customer_id IN (
				SELECT cohort.customer_id FROM (
					SELECT o2.customer_id, MIN(sm2.meta_value) AS first_start
					FROM {$prefix}wc_orders o2
					JOIN {$prefix}wc_orders_meta sm2
						ON sm2.order_id = o2.id AND sm2.meta_key = '_schedule_start'
					JOIN {$prefix}woocommerce_order_items oi2
						ON oi2.order_id = o2.id AND oi2.order_item_type = 'line_item'
					JOIN {$prefix}woocommerce_order_itemmeta oim2
						ON oim2.order_item_id = oi2.order_item_id AND oim2.meta_key = '_product_id'
					WHERE o2.type = 'shop_subscription'
					  AND o2.customer_id > 0 -- exclude guest subscriptions
					  AND oim2.meta_value NOT IN ($donations)
					  AND sm2.meta_value != ''
					GROUP BY o2.customer_id
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
