<?php
/**
 * Newspack batched CSV exporter for WooCommerce Subscriptions.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-csv-batch-exporter.php';

/**
 * Exports WooCommerce Subscriptions to CSV in pages, honoring the
 * subscriptions admin list filters (status, product, customer, payment
 * method, group, search, month).
 *
 * Extensibility contract (used by the group-subscription export, NPPD-1719,
 * the same way Memberships-for-Teams extended the WC Memberships export):
 * - `newspack_subscriptions_export_headers` filters the column id => label map.
 * - `newspack_subscriptions_export_row` filters each row (keyed by column id).
 * - `newspack_subscriptions_export_query_args` filters the query args built
 *   from the captured list params.
 *
 * This class must only be loaded after WooCommerce's WC_CSV_Batch_Exporter
 * abstract (see CSV_Exports::load_exporter_dependencies()).
 */
class Subscriptions_CSV_Exporter extends CSV_Batch_Exporter {

	/**
	 * Type of export, used in WC filter names.
	 *
	 * @var string
	 */
	protected $export_type = 'newspack_subscriptions';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->filename = 'newspack-subscriptions-export.csv';
		parent::__construct();
	}

	/**
	 * Default column id => label map.
	 *
	 * @return array
	 */
	public function get_default_column_names() {
		/**
		 * Filters the subscriptions export columns.
		 *
		 * Pair with `newspack_subscriptions_export_row` to add custom columns.
		 *
		 * @param array $columns Column id => label map.
		 */
		return apply_filters(
			'newspack_subscriptions_export_headers',
			array_merge(
				[
					'subscription_id'     => __( 'Subscription ID', 'newspack-plugin' ),
					'status'              => __( 'Status', 'newspack-plugin' ),
					'date_created'        => __( 'Date Created', 'newspack-plugin' ),
					'start_date'          => __( 'Start Date', 'newspack-plugin' ),
					'trial_end_date'      => __( 'Trial End Date', 'newspack-plugin' ),
					'next_payment_date'   => __( 'Next Payment Date', 'newspack-plugin' ),
					'last_payment_date'   => __( 'Last Payment Date', 'newspack-plugin' ),
					'end_date'            => __( 'End Date', 'newspack-plugin' ),
					'billing_period'      => __( 'Billing Period', 'newspack-plugin' ),
					'billing_interval'    => __( 'Billing Interval', 'newspack-plugin' ),
					'product_ids'         => __( 'Product IDs', 'newspack-plugin' ),
					'product_names'       => __( 'Product Names', 'newspack-plugin' ),
					'quantities'          => __( 'Quantities', 'newspack-plugin' ),
					'total'               => __( 'Total', 'newspack-plugin' ),
					'currency'            => __( 'Currency', 'newspack-plugin' ),
					'payment_method'      => __( 'Payment Method', 'newspack-plugin' ),
					'customer_id'         => __( 'Customer ID', 'newspack-plugin' ),
					'customer_username'   => __( 'Customer Username', 'newspack-plugin' ),
					'customer_email'      => __( 'Customer Email', 'newspack-plugin' ),
					'customer_first_name' => __( 'Customer First Name', 'newspack-plugin' ),
					'customer_last_name'  => __( 'Customer Last Name', 'newspack-plugin' ),
					'parent_order_id'     => __( 'Parent Order ID', 'newspack-plugin' ),
				],
				self::get_address_column_labels()
			)
		);
	}

	/**
	 * Translate captured admin-list query params into wc_get_orders-style args
	 * for wcs_get_orders_with_meta_query(). Handles both the HPOS and legacy
	 * CPT list-table param shapes.
	 *
	 * List-table sorting params are intentionally ignored: the export order is
	 * always ID ascending (CSV consumers re-sort; a deterministic, insert-
	 * stable key keeps offset pagination consistent when subscriptions are
	 * created mid-export — both order datastores map 'ID' to the primary key).
	 *
	 * @param array  $params    Parsed query-string params from the admin list.
	 * @param string $cache_key Per-run cache key (the export filename) used to
	 *                          memoize the product-filter ID set across the
	 *                          run's pages; '' disables caching (the default,
	 *                          keeping the method pure for direct callers).
	 * @return array Query args.
	 */
	public static function build_query_args( array $params, string $cache_key = '' ): array {
		// Array-shaped params (a mangled ?m[]=... URL) would TypeError in the
		// string handling below; degrade to "filter ignored" instead.
		$params = array_filter( \wc_clean( $params ), 'is_scalar' );
		$args   = [
			'type'    => 'shop_subscription',
			'orderby' => 'ID',
			'order'   => 'ASC',
		];

		// Status: CPT lists send post_status, HPOS lists send status. Default
		// to every subscription status, matching the admin list's "All" view.
		$status = '';
		if ( ! empty( $params['post_status'] ) ) {
			$status = $params['post_status'];
		} elseif ( ! empty( $params['status'] ) ) {
			$status = $params['status'];
		}
		if ( '' === $status || 'all' === $status ) {
			$args['status'] = array_keys( \wcs_get_subscription_statuses() );
		} else {
			$args['status'] = [ \wcs_sanitize_subscription_status_key( $status ) ];
		}

		// Product filter: resolve to a subscription ID set, intersected with
		// any other ID restriction. Never pass product_id to the query
		// functions directly — that path runs unpaged.
		if ( ! empty( $params['_wcs_product'] ) ) {
			$product_subscription_ids = self::get_product_subscription_ids( absint( $params['_wcs_product'] ), $cache_key );
			$args                     = \WCS_Admin_Post_Types::set_post__in_query_var( $args, $product_subscription_ids );
		}

		// Customer filter.
		if ( ! empty( $params['_customer_user'] ) ) {
			$customer_subscription_ids = \WCS_Customer_Store::instance()->get_users_subscription_ids( absint( $params['_customer_user'] ) );
			$args                      = \WCS_Admin_Post_Types::set_post__in_query_var( $args, $customer_subscription_ids );
		}

		// Payment method filter (mirrors WCS's own list filter semantics).
		if ( ! empty( $params['_payment_method'] ) ) {
			if ( '_manual_renewal' === $params['_payment_method'] ) {
				$args['meta_query'][] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'key'   => '_requires_manual_renewal',
					'value' => 'true',
				];
			} else {
				$args['payment_method'] = $params['_payment_method'];
			}
		}

		// Newspack group-subscription filter.
		if (
			! empty( $params['_newspack_group_subscription'] )
			&& in_array( $params['_newspack_group_subscription'], [ 'group', 'non-group' ], true )
			&& class_exists( __NAMESPACE__ . '\Group_Subscription_Settings' )
		) {
			$args = Group_Subscription_Settings::apply_group_filter(
				$args,
				$params['_newspack_group_subscription'],
				Group_Subscription_Settings::get_group_subscription_ids()
			);
		}

		// Search: native in HPOS mode; resolved to an ID set in CPT mode
		// (matching what each list table actually shows).
		if ( ! empty( $params['s'] ) ) {
			if ( \wcs_is_custom_order_tables_usage_enabled() ) {
				$args['s'] = $params['s'];
			} else {
				$args = \WCS_Admin_Post_Types::set_post__in_query_var( $args, \wcs_subscription_search( $params['s'] ) );
			}
		}

		// Month filter (m=YYYYMM) becomes an inclusive date_created range.
		if ( ! empty( $params['m'] ) && preg_match( '/^\d{6}$/', $params['m'] ) ) {
			$year                 = substr( $params['m'], 0, 4 );
			$month                = substr( $params['m'], 4, 2 );
			$last_day             = gmdate( 't', gmmktime( 0, 0, 0, (int) $month, 1, (int) $year ) );
			$args['date_created'] = sprintf( '%1$s-%2$s-01...%1$s-%2$s-%3$s', $year, $month, $last_day );
		}

		/**
		 * Filters the subscriptions export query args.
		 *
		 * @param array $args   wc_get_orders-style query args.
		 * @param array $params The captured admin-list params.
		 */
		return apply_filters( 'newspack_subscriptions_export_query_args', $args, $params );
	}

	/**
	 * Resolve a product's subscription ID set, memoized for the duration of one
	 * export run.
	 *
	 * WCS resolves this via wcs_get_subscriptions_for_product(), an unpaged
	 * (LIMIT -1) query that — unlike the customer store — it does not cache, so
	 * a paged export would otherwise re-run it (and pass the full ID set as
	 * post__in) on every page. When a per-run cache key is given, the result is
	 * stored in a short-lived transient so it resolves once per run instead of
	 * once per page. The key is the export filename, which is unique per run,
	 * so a later run always re-resolves against current data.
	 *
	 * The cached set is as large as the product's subscription count, so on a
	 * very large site it can outgrow an object cache's per-entry ceiling — in
	 * which case set_transient() simply doesn't stick and every page re-runs
	 * the query, i.e. the pre-cache behavior. The same unbounded set is handed
	 * to post__in either way; that is inherent to WCS's product filter.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $cache_key  Per-run cache key, or '' to skip caching.
	 * @return int[] Subscription IDs.
	 */
	private static function get_product_subscription_ids( int $product_id, string $cache_key ): array {
		$transient = '' !== $cache_key
			? 'newspack_export_product_subs_' . md5( $cache_key . ':' . $product_id )
			: '';
		if ( '' !== $transient ) {
			$cached = \get_transient( $transient );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$ids = array_map( 'intval', array_keys( \wcs_get_subscriptions_for_product( $product_id ) ) );
		if ( '' !== $transient ) {
			\set_transient( $transient, $ids, HOUR_IN_SECONDS );
		}
		return $ids;
	}

	/**
	 * Prepare one page of subscription rows.
	 */
	public function prepare_data_to_export(): void {
		// Pass the run's filename so a product filter's ID set is resolved once
		// per run rather than re-queried on every page.
		$args             = self::build_query_args( $this->list_params, $this->get_filename() );
		$args['limit']    = $this->get_limit();
		$args['offset']   = ( $this->get_page() - 1 ) * $this->get_limit();
		$args['paginate'] = true;
		$args['return']   = 'ids';

		// Do not "simplify" this to wcs_get_subscriptions( [ 'paged' => n ] ):
		// that function accepts paged but never applies it (page 1 forever),
		// and its product_id path runs an unpaged limit=-1 query.
		$results = \wcs_get_orders_with_meta_query( $args );

		// Pinned to page 1's count so a set that shrinks mid-run can't end the
		// export early with a truncated CSV (see pin_total_rows()).
		$this->pin_total_rows( (int) $results->total );

		// Hydrate the page first (one wcs_get_subscription() per ID is
		// unavoidable), then prime the user cache in one query so the
		// customer_* columns don't cost a lookup per row.
		$subscriptions = [];
		foreach ( $results->orders as $subscription_id ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				$subscriptions[] = $subscription;
			}
		}
		$customer_ids = array_filter( array_map( fn( $subscription ) => (int) $subscription->get_customer_id(), $subscriptions ) );
		if ( ! empty( $customer_ids ) && function_exists( 'cache_users' ) ) {
			\cache_users( array_unique( $customer_ids ) );
		}

		$this->row_data = array_map( [ $this, 'get_row_data' ], $subscriptions );
	}

	/**
	 * Build one CSV row (raw values; escaping happens at write time via
	 * WC_CSV_Exporter::format_data()).
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @return array Row keyed by column id.
	 */
	public function get_row_data( $subscription ) {
		$product_ids   = [];
		$product_names = [];
		$quantities    = [];
		foreach ( $subscription->get_items() as $item ) {
			$product_ids[]   = \wcs_get_canonical_product_id( $item );
			$product_names[] = $item->get_name();
			$quantities[]    = $item->get_quantity();
		}

		$customer_id = (int) $subscription->get_customer_id();
		$user        = $customer_id ? \get_user_by( 'id', $customer_id ) : false;

		$payment_method = (string) $subscription->get_payment_method_title();
		if ( '' === $payment_method && $subscription->is_manual() ) {
			$payment_method = __( 'Manual renewal', 'newspack-plugin' );
		}

		$date_created = $subscription->get_date_created();

		$row = [
			'subscription_id'     => $subscription->get_id(),
			'status'              => $subscription->get_status(),
			'date_created'        => $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : '',
			'start_date'          => self::get_date_field( $subscription, 'start' ),
			'trial_end_date'      => self::get_date_field( $subscription, 'trial_end' ),
			'next_payment_date'   => self::get_date_field( $subscription, 'next_payment' ),
			'last_payment_date'   => self::get_date_field( $subscription, 'last_order_date_created' ),
			'end_date'            => self::get_date_field( $subscription, 'end' ),
			'billing_period'      => $subscription->get_billing_period(),
			'billing_interval'    => $subscription->get_billing_interval(),
			'product_ids'         => implode( ', ', $product_ids ),
			'product_names'       => $this->implode_values( $product_names ),
			'quantities'          => implode( ', ', $quantities ),
			'total'               => $subscription->get_total(),
			'currency'            => $subscription->get_currency(),
			'payment_method'      => $payment_method,
			'customer_id'         => $customer_id,
			'customer_username'   => $user ? $user->user_login : '',
			'customer_email'      => $user ? $user->user_email : '',
			'customer_first_name' => $user ? $user->first_name : '',
			'customer_last_name'  => $user ? $user->last_name : '',
			'parent_order_id'     => $subscription->get_parent_id(),
			'billing_first_name'  => $subscription->get_billing_first_name(),
			'billing_last_name'   => $subscription->get_billing_last_name(),
			'billing_company'     => $subscription->get_billing_company(),
			'billing_address_1'   => $subscription->get_billing_address_1(),
			'billing_address_2'   => $subscription->get_billing_address_2(),
			'billing_city'        => $subscription->get_billing_city(),
			'billing_state'       => $subscription->get_billing_state(),
			'billing_postcode'    => $subscription->get_billing_postcode(),
			'billing_country'     => $subscription->get_billing_country(),
			'billing_email'       => $subscription->get_billing_email(),
			'billing_phone'       => $subscription->get_billing_phone(),
			'shipping_first_name' => $subscription->get_shipping_first_name(),
			'shipping_last_name'  => $subscription->get_shipping_last_name(),
			'shipping_company'    => $subscription->get_shipping_company(),
			'shipping_address_1'  => $subscription->get_shipping_address_1(),
			'shipping_address_2'  => $subscription->get_shipping_address_2(),
			'shipping_city'       => $subscription->get_shipping_city(),
			'shipping_state'      => $subscription->get_shipping_state(),
			'shipping_postcode'   => $subscription->get_shipping_postcode(),
			'shipping_country'    => $subscription->get_shipping_country(),
		];

		/**
		 * Filters a subscriptions export row.
		 *
		 * Pair with `newspack_subscriptions_export_headers` to add custom columns.
		 *
		 * @param array            $row          Row values keyed by column id.
		 * @param \WC_Subscription $subscription The subscription being exported.
		 */
		return apply_filters( 'newspack_subscriptions_export_row', $row, $subscription );
	}

	/**
	 * Get a schedule date as a string, normalizing empty (0) dates to ''.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @param string           $date_type    Date type, e.g. 'start', 'next_payment'.
	 * @return string
	 */
	private static function get_date_field( $subscription, $date_type ) {
		$date = $subscription->get_date( $date_type );
		return $date ? (string) $date : '';
	}
}
