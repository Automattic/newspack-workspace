<?php
/**
 * Complimentary access in reports, lists and exports.
 *
 * @package Newspack
 */

namespace Newspack;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps complimentary ("comp") subscriptions and orders out of revenue and subscriber reports.
 *
 * Every report keys on the `_newspack_complimentary` stamp (see Complimentary_Access), never
 * on the product flag. The query hooks that serve the reports also serve content-gate access,
 * ESP sync and reader data, so each report surface is filtered through a hook of its own.
 * WooCommerce Subscriptions' Retention rate report has no hook and still counts comps.
 *
 * Comps stay in the subscription and order lists, which gain a filter for them, and the
 * subscriptions CSV export leaves them out unless asked to include them.
 */
class Complimentary_Access_Reports {
	/**
	 * Order status that WooCommerce Analytics records for a stamped order, without the `wc-` prefix.
	 *
	 * Analytics reports leave out the statuses in their exclusion list. A "status is not" filter
	 * replaces that list, so it brings comps back, under this status; a "status is" filter naming
	 * a real status still leaves them out.
	 */
	const ANALYTICS_STATUS = 'complimentary';

	/**
	 * Query parameter of the "Complimentary" filter on the subscription and order lists.
	 */
	const LIST_FILTER_PARAM = 'newspack_complimentary';

	/**
	 * Option recording that reports were brought up to date with the stamps existing before this class did.
	 */
	const REPORTS_SYNCED_OPTION = 'newspack_complimentary_access_reports_synced';

	/**
	 * Report queries filtered as SQL, each with the alias of the subscription or order it counts:
	 * on HPOS sites, then on sites storing them as posts.
	 */
	const REPORT_QUERY_ALIASES = [
		// "By product".
		'wcs_reports_product_query'                       => [ 'subscriptions', 'subscriptions' ],
		// "By customer".
		'wcs_reports_customer_total_query'                => [ 'subscriptions', 'subscription_posts' ],
		'wcs_reports_customer_total_renewal_switch_query' => [ 'subscriptions', 'subscription_posts' ],
		'wcs_reports_current_customer_query'              => [ 'subscriptions', 'subscription_posts' ],
		'wcs_reports_current_customer_renewal_switch_total_query' => [ 'subscriptions', 'subscription_posts' ],
		// Dashboard widget.
		'woocommerce_subscription_dashboard_status_widget_signup_query' => [ 'wcsubs', 'wcsubs' ],
		'woocommerce_subscription_dashboard_status_widget_signup_revenue_query' => [ 'subscripitons', 'wcsubs' ], // Sic.
		'woocommerce_subscription_dashboard_status_widget_renewal_query' => [ 'wcorder', 'wcorder' ],
		'woocommerce_subscription_dashboard_status_widget_renewal_revenue_query' => [ 'wcorder', 'wcorder' ],
		'woocommerce_subscription_dashboard_status_widget_cancellation_query' => [ 'wcsubs', 'wcsubs' ],
	];

	/**
	 * Statuses the filtered HPOS list queries, which tell its pagination count apart from its view counts.
	 *
	 * @var string[]|null
	 */
	private static ?array $list_statuses = null;

	/**
	 * This connection's `GROUP_CONCAT()` limit before the reports raised it, or 0 when unraised.
	 *
	 * @var int
	 */
	private static int $original_group_concat_limit = 0;

	/**
	 * Report classes of WooCommerce Subscriptions whose results are cached in a transient named after the class.
	 */
	const CACHED_REPORT_CLASSES = [
		'WCS_Report_Dashboard',
		'WCS_Report_Subscription_Events_By_Date',
		'WCS_Report_Upcoming_Recurring_Revenue',
		'WCS_Report_Subscription_By_Product',
		'WCS_Report_Subscription_By_Customer',
	];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init(): void {
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			return;
		}

		// WooCommerce Analytics, including its CSV export, Home stats and dashboard widget.
		add_filter( 'woocommerce_analytics_update_order_stats_data', [ __CLASS__, 'filter_analytics_order_stats' ], 10, 2 );
		add_filter( 'woocommerce_analytics_excluded_order_statuses', [ __CLASS__, 'filter_analytics_excluded_statuses' ] );

		// Legacy WooCommerce reports, and the order-based series of Subscriptions' "Events by date".
		// Before Subscriptions hashes the query to cache its results (10).
		add_filter( 'woocommerce_reports_get_order_report_query', [ __CLASS__, 'filter_order_report_query' ], 5 );

		// Subscriptions' "Events by date" series that come from its own queries.
		add_filter( 'wcs_reports_subscription_events_args', [ __CLASS__, 'raise_group_concat_limit' ] );
		foreach ( [ 'sign_up', 'subscriber_count', 'cancel_count', 'ended_count' ] as $series ) {
			add_filter( "wcs_reports_subscription_events_{$series}_data", [ __CLASS__, 'filter_subscription_event_rows' ] );
		}

		add_filter( 'wcs_reports_upcoming_recurring_revenue_args', [ __CLASS__, 'raise_group_concat_limit' ] );
		add_filter( 'wcs_reports_upcoming_recurring_revenue_data', [ __CLASS__, 'filter_upcoming_recurring_revenue_rows' ] );

		foreach ( array_keys( self::REPORT_QUERY_ALIASES ) as $filter ) {
			add_filter( $filter, [ __CLASS__, 'filter_report_sql' ] );
		}

		add_action( 'admin_init', [ __CLASS__, 'maybe_sync_reports' ] );
		// After the restamp itself (10).
		add_action( Complimentary_Access::SYNC_ACTION, [ __CLASS__, 'handle_restamp' ], 20 );

		// Subscription and order lists.
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ __CLASS__, 'render_list_filter' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_list_filter' ], 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ __CLASS__, 'filter_list_query_args' ] );
		add_filter( 'woocommerce_shop_subscription_list_table_prepare_items_query_args', [ __CLASS__, 'filter_list_query_args' ] );
		add_filter( 'woocommerce_shop_order_list_table_order_count', [ __CLASS__, 'filter_list_order_count' ], 10, 2 );
		add_filter( 'woocommerce_shop_subscription_list_table_order_count', [ __CLASS__, 'filter_list_order_count' ], 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_should_render_blank_state', [ __CLASS__, 'filter_list_blank_state' ] );
		add_filter( 'woocommerce_shop_subscription_list_table_should_render_blank_state', [ __CLASS__, 'filter_list_blank_state' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_legacy_list_query' ] );

		// Subscriptions CSV export.
		add_filter( 'newspack_subscriptions_export_query_args', [ __CLASS__, 'filter_export_query_args' ], 10, 3 );
	}

	/**
	 * Whether orders and subscriptions are stored in WooCommerce's own tables.
	 *
	 * @return bool
	 */
	private static function is_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Record a stamped order under the comp status in WooCommerce Analytics.
	 *
	 * The order's own status is lost from Analytics, which is what keeps it out of every report
	 * by default. Orders synced before this filter existed keep their status until the
	 * historical data is imported again. A refund takes its parent order's real status after
	 * this filter runs, so a refunded comp order would still count its refund; comp products
	 * cost nothing, so there is nothing to refund.
	 *
	 * @param array     $data  Row written to the order stats table.
	 * @param \WC_Order $order Order.
	 *
	 * @return array
	 */
	public static function filter_analytics_order_stats( $data, $order ) {
		if ( is_object( $order ) && is_callable( [ $order, 'get_meta' ] ) && Complimentary_Access::is_complimentary_order( $order ) ) {
			$data['status'] = 'wc-' . self::ANALYTICS_STATUS;
		}
		return $data;
	}

	/**
	 * Leave the comp status out of WooCommerce Analytics.
	 *
	 * @param string[] $statuses Excluded statuses, without the `wc-` prefix.
	 *
	 * @return string[]
	 */
	public static function filter_analytics_excluded_statuses( $statuses ) {
		$statuses   = (array) $statuses;
		$statuses[] = self::ANALYTICS_STATUS;
		return $statuses;
	}

	/**
	 * SQL condition that holds when an order or subscription carries no stamp.
	 *
	 * @param string $alias Table alias of the order or subscription in the query.
	 * @param bool   $hpos  Whether the alias is a row of WooCommerce's orders table rather than of the posts table.
	 *
	 * @return string
	 */
	public static function get_not_complimentary_sql( string $alias, bool $hpos ): string {
		global $wpdb;
		$meta_table = $hpos ? "{$wpdb->prefix}wc_orders_meta" : $wpdb->postmeta;
		$id_column  = $hpos ? 'order_id' : 'post_id';
		return sprintf(
			"NOT EXISTS ( SELECT 1 FROM %1\$s AS newspack_complimentary WHERE newspack_complimentary.%2\$s = %3\$s.ID AND newspack_complimentary.meta_key = '%4\$s' )",
			$meta_table,
			$id_column,
			$alias,
			Complimentary_Access::META_KEY
		);
	}

	/**
	 * Leave comps out of a Subscriptions report query, by the alias the current filter's query uses.
	 *
	 * @param string $query SQL query.
	 *
	 * @return string
	 */
	public static function filter_report_sql( $query ) {
		$aliases = self::REPORT_QUERY_ALIASES[ current_filter() ] ?? null;
		if ( ! $aliases ) {
			return $query;
		}
		$alias    = self::is_hpos() ? $aliases[0] : $aliases[1];
		$filtered = self::exclude_from_report_sql( $query, $alias );
		if ( $filtered === $query ) {
			Logger::log( sprintf( 'No `%s` type or status check in the %s query; complimentary subscriptions stay in that report.', $alias, current_filter() ), 'NEWSPACK-COMPLIMENTARY-ACCESS' );
		}
		return $filtered;
	}

	/**
	 * Add the no-stamp condition next to a report query's type or status condition on a table alias.
	 *
	 * The report queries come as finished SQL. Each one checks the type of the order or
	 * subscription it counts, joined with AND, so the condition goes right after that check.
	 * A query that checks only the status, such as the dashboard's cancellation count, gets it
	 * after the status check. The type check comes first because a status check can also sit
	 * inside a `CASE`, where the condition would change one column rather than the whole query.
	 * A query with neither, as a later version of Subscriptions might write it, is left as it is.
	 *
	 * @param string $query SQL query.
	 * @param string $alias Table alias of the order or subscription the query counts.
	 *
	 * @return string
	 */
	public static function exclude_from_report_sql( $query, string $alias ) {
		if ( ! is_string( $query ) ) {
			return $query;
		}
		$condition = ' AND ' . self::get_not_complimentary_sql( $alias, self::is_hpos() );
		foreach ( [ 'type', 'status' ] as $column ) {
			$pattern = '/\b' . preg_quote( $alias, '/' ) . '\.(?:post_)?' . $column . '\s*(?:NOT\s+IN|IN|=)\s*(?:\([^)]*\)|\'[^\']*\')/i';
			if ( preg_match( $pattern, $query, $matches, PREG_OFFSET_CAPTURE ) ) {
				$end = $matches[0][1] + strlen( $matches[0][0] );
				return substr( $query, 0, $end ) . $condition . substr( $query, $end );
			}
		}
		return $query;
	}

	/**
	 * Leave stamped orders and subscriptions out of a report built by `get_order_report_data()`.
	 *
	 * Legacy WooCommerce reports always read the posts table. Subscriptions' "Events by date"
	 * report builds the same query shape against its own tables on HPOS sites.
	 *
	 * @param array $query Query parts.
	 *
	 * @return array
	 */
	public static function filter_order_report_query( $query ) {
		if ( ! is_array( $query ) || empty( $query['from'] ) || ! isset( $query['where'] ) ) {
			return $query;
		}
		if ( preg_match( '/\bAS\s+orders\b/i', $query['from'] ) ) {
			$query['where'] .= ' AND ' . self::get_not_complimentary_sql( 'orders', true );
		} elseif ( preg_match( '/\bAS\s+posts\b/i', $query['from'] ) ) {
			$query['where'] .= ' AND ' . self::get_not_complimentary_sql( 'posts', false );
		}
		return $query;
	}

	/**
	 * Lift MySQL's default 1,024-character limit on `GROUP_CONCAT()` for this connection.
	 *
	 * The Subscriptions reports list each row's subscription IDs with `GROUP_CONCAT()`, and comps
	 * are taken out by those IDs. A list cut short would leave comps in. A site without a stamp
	 * has nothing to take out, so its reports run as they always did. On a site with one,
	 * "Upcoming recurring revenue" also stops losing the subscriptions past the cut, which
	 * Subscriptions' own projection drops on a busy renewal day.
	 *
	 * @param array $args Report arguments, returned as they are.
	 *
	 * @return array
	 */
	public static function raise_group_concat_limit( $args ) {
		global $wpdb;
		if ( empty( self::get_stamped_ids() ) ) {
			return $args;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( ! self::$original_group_concat_limit ) {
			self::$original_group_concat_limit = (int) $wpdb->get_var( 'SELECT @@SESSION.group_concat_max_len' );
		}
		$wpdb->query( 'SET SESSION group_concat_max_len = GREATEST( @@SESSION.group_concat_max_len, 1048576 )' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return $args;
	}

	/**
	 * Every stamped subscription, as a set keyed by ID.
	 *
	 * Comps are a small share of a site's subscriptions, so the whole set is cheaper to load than
	 * looking up the IDs of every report row, which can list every subscription on a site. Their
	 * renewal orders are stamped too, but the rows list subscriptions only.
	 *
	 * @return array<int, true>
	 */
	public static function get_stamped_ids(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( self::is_hpos() ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta.order_id FROM {$wpdb->prefix}wc_orders_meta AS meta INNER JOIN {$wpdb->prefix}wc_orders AS subscriptions ON subscriptions.id = meta.order_id AND subscriptions.type = 'shop_subscription' WHERE meta.meta_key = %s", Complimentary_Access::META_KEY ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta.post_id FROM {$wpdb->postmeta} AS meta INNER JOIN {$wpdb->posts} AS subscriptions ON subscriptions.ID = meta.post_id AND subscriptions.post_type = 'shop_subscription' WHERE meta.meta_key = %s", Complimentary_Access::META_KEY ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * Split a `GROUP_CONCAT()` list of IDs.
	 *
	 * @param mixed $list Comma-separated IDs.
	 *
	 * @return int[]
	 */
	private static function split_ids( $list ): array {
		return '' === (string) $list ? [] : array_map( 'intval', explode( ',', (string) $list ) );
	}

	/**
	 * Take comps out of the rows of an "Events by date" series: signups, subscribers, cancellations or ends.
	 *
	 * Each row counts subscriptions and lists their IDs. The list also feeds the report's links to
	 * the subscriptions behind a count, so comps leave both. The lists go back to the length the
	 * connection allowed before the limit was raised: Subscriptions caches every row for a week,
	 * and a subscribers row lists every subscription active that day.
	 *
	 * @param array $rows Result rows.
	 *
	 * @return array
	 */
	public static function filter_subscription_event_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		$stamped_ids = self::get_stamped_ids();
		if ( empty( $stamped_ids ) ) {
			return $rows;
		}
		foreach ( $rows as $row ) {
			$row_ids  = self::split_ids( $row->subscription_ids ?? '' );
			$kept_ids = array_filter( $row_ids, fn( $id ) => ! isset( $stamped_ids[ $id ] ) );
			if ( count( $kept_ids ) !== count( $row_ids ) ) {
				$row->count = max( 0, (int) $row->count - ( count( $row_ids ) - count( $kept_ids ) ) );
			}
			$row->subscription_ids = self::truncate_id_list( implode( ',', $kept_ids ) );
		}
		return $rows;
	}

	/**
	 * Cut an ID list back to the connection's own `GROUP_CONCAT()` limit, at a whole ID.
	 *
	 * @param string $list Comma-separated IDs.
	 *
	 * @return string
	 */
	private static function truncate_id_list( string $list ): string {
		$limit = self::$original_group_concat_limit;
		if ( ! $limit || strlen( $list ) <= $limit ) {
			return $list;
		}
		$cut = substr( $list, 0, $limit + 1 );
		return substr( $cut, 0, (int) strrpos( $cut, ',' ) );
	}

	/**
	 * Take comps out of the "Upcoming recurring revenue" rows.
	 *
	 * Each row holds parallel lists, one entry per subscription, which the report reads to project
	 * later renewals, so every list loses the comp's entry and the row's totals are recounted.
	 *
	 * @param array $rows Rows keyed by scheduled date.
	 *
	 * @return array
	 */
	public static function filter_upcoming_recurring_revenue_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		$stamped_ids = self::get_stamped_ids();
		if ( empty( $stamped_ids ) ) {
			return $rows;
		}
		$list_fields = [ 'subscription_ids', 'billing_intervals', 'billing_periods', 'scheduled_ends', 'subscription_totals' ];
		foreach ( $rows as $key => $row ) {
			$lists = [];
			foreach ( $list_fields as $field ) {
				$lists[ $field ] = explode( ',', (string) ( $row->$field ?? '' ) );
			}
			$row_ids        = self::split_ids( $row->subscription_ids ?? '' );
			$kept_positions = array_keys( array_filter( $row_ids, fn( $id ) => ! isset( $stamped_ids[ $id ] ) ) );
			if ( count( $kept_positions ) === count( $row_ids ) ) {
				continue;
			}
			if ( empty( $kept_positions ) ) {
				unset( $rows[ $key ] );
				continue;
			}
			foreach ( $list_fields as $field ) {
				$row->$field = implode( ',', array_map( fn( $position ) => $lists[ $field ][ $position ] ?? '', $kept_positions ) );
			}
			$row->total_renewals  = count( $kept_positions );
			$row->recurring_total = array_sum( array_map( 'floatval', explode( ',', $row->subscription_totals ) ) );
		}
		return $rows;
	}

	/**
	 * Bring the reports up to date once with the flagged products that existed before this class did.
	 *
	 * Cached report results still count comps, and Analytics recorded stamped orders under
	 * their own status. Restamping every flagged product's subscriptions rewrites those orders'
	 * Analytics rows (see handle_restamp()). Stamps left by a deleted product are not reached;
	 * "Import historical data" rewrites those.
	 */
	public static function maybe_sync_reports(): void {
		if ( get_option( self::REPORTS_SYNCED_OPTION ) ) {
			return;
		}
		update_option( self::REPORTS_SYNCED_OPTION, 1 );
		self::clear_report_caches();
		$flagged_product_ids = get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => 'any',
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- A site flags a handful of products.
				'fields'         => 'ids',
				'meta_key'       => Complimentary_Access::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		foreach ( $flagged_product_ids as $product_id ) {
			Complimentary_Access::schedule_product_sync( $product_id );
		}
	}

	/**
	 * Clear the cached results of the Subscriptions reports, which they otherwise keep for up to a week.
	 *
	 * Each report recomputes on its next view.
	 */
	public static function clear_report_caches(): void {
		foreach ( self::CACHED_REPORT_CLASSES as $report_class ) {
			delete_transient( strtolower( $report_class ) );
		}
	}

	/**
	 * Bring the reports up to date with a batch of restamped subscriptions.
	 *
	 * A restamp writes meta alone, which neither Analytics nor the Subscriptions report cache
	 * watches, so a product flagged after its orders were recorded would keep counting them.
	 * Analytics rewrites the related orders' rows here. Its own import queue would not: in
	 * scheduled-import mode it picks up only orders whose modified date moved.
	 *
	 * @param int[] $subscription_ids Restamped subscription IDs.
	 */
	public static function handle_restamp( $subscription_ids ): void {
		self::clear_report_caches();
		$stats_store = '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore';
		if ( ! class_exists( $stats_store ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		foreach ( (array) $subscription_ids as $subscription_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				continue;
			}
			foreach ( $subscription->get_related_orders( 'ids', 'any' ) as $order_id ) {
				$stats_store::sync_order( $order_id );
			}
		}
		// Analytics serves cached results keyed on the request, which these rows don't change.
		if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Cache' ) ) {
			\Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
		}
	}

	/**
	 * The "Complimentary" filter value of the current list request.
	 *
	 * @return string `only`, `exclude`, or '' for no filter.
	 */
	private static function get_list_filter_value(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = isset( $_GET[ self::LIST_FILTER_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::LIST_FILTER_PARAM ] ) ) : '';
		return in_array( $value, [ 'only', 'exclude' ], true ) ? $value : '';
	}

	/**
	 * Render the "Complimentary" filter above the subscription and order lists.
	 *
	 * Shown with content gates, where products can be flagged; the filter itself works anywhere.
	 *
	 * @param string $type  Order type or post type of the list.
	 * @param string $which Which table nav, `top` or `bottom`.
	 */
	public static function render_list_filter( $type, $which = 'top' ): void {
		if ( 'top' !== $which || ! in_array( $type, [ 'shop_order', 'shop_subscription' ], true ) || ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}
		$current = self::get_list_filter_value();
		$options = [
			''        => 'shop_subscription' === $type ? __( 'All subscriptions', 'newspack-plugin' ) : __( 'All orders', 'newspack-plugin' ),
			'only'    => __( 'Complimentary', 'newspack-plugin' ),
			'exclude' => __( 'Not complimentary', 'newspack-plugin' ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A filter option, not a query argument.
		];
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( self::LIST_FILTER_PARAM ); ?>"><?php esc_html_e( 'Filter by complimentary access', 'newspack-plugin' ); ?></label>
		<select name="<?php echo esc_attr( self::LIST_FILTER_PARAM ); ?>" id="<?php echo esc_attr( self::LIST_FILTER_PARAM ); ?>">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the "Complimentary" filter to an HPOS subscription or order list.
	 *
	 * @param array $args `wc_get_orders()` arguments.
	 *
	 * @return array
	 */
	public static function filter_list_query_args( $args ) {
		$clause = Complimentary_Access::get_meta_query_clause( self::get_list_filter_value() );
		if ( $clause ) {
			self::$list_statuses  = (array) ( $args['status'] ?? [] );
			$args['meta_query']   = $args['meta_query'] ?? []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'][] = $clause;
		}
		return $args;
	}

	/**
	 * Count the filtered HPOS list for its pagination.
	 *
	 * Without other filters, the list takes its total from the per-status counts WooCommerce
	 * caches, which include comps, so the "Complimentary" view would report the whole list. The
	 * status views share this count, but their links drop the filter, so they keep the full
	 * counts: only the count of the statuses the list queried changes.
	 *
	 * @param int      $count    Number of subscriptions or orders with the statuses.
	 * @param string[] $statuses Statuses counted.
	 *
	 * @return int
	 */
	public static function filter_list_order_count( $count, $statuses ) {
		$clause = Complimentary_Access::get_meta_query_clause( self::get_list_filter_value() );
		if ( ! $clause || null === self::$list_statuses ) {
			return $count;
		}
		$statuses      = (array) $statuses;
		$list_statuses = self::$list_statuses;
		sort( $statuses );
		sort( $list_statuses );
		if ( $statuses !== $list_statuses ) {
			return $count;
		}
		$results = wc_get_orders(
			[
				'type'       => 'woocommerce_shop_subscription_list_table_order_count' === current_filter() ? 'shop_subscription' : 'shop_order',
				'status'     => (array) $statuses,
				'meta_query' => [ $clause ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'limit'      => 1,
				'return'     => 'ids',
				'paginate'   => true,
			]
		);
		return (int) $results->total;
	}

	/**
	 * Keep the filtered HPOS list on screen when nothing matches.
	 *
	 * An empty list otherwise turns into WooCommerce's first-run screen, which has no filters to
	 * clear the "Complimentary" one with.
	 *
	 * @param bool|null $should_render Whether to render the blank state; null lets WooCommerce decide.
	 *
	 * @return bool|null
	 */
	public static function filter_list_blank_state( $should_render ) {
		return '' === self::get_list_filter_value() ? $should_render : false;
	}

	/**
	 * Apply the "Complimentary" filter to a subscription or order list stored as posts.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function filter_legacy_list_query( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ! in_array( $query->get( 'post_type' ), [ 'shop_order', 'shop_subscription' ], true ) ) {
			return;
		}
		$clause = Complimentary_Access::get_meta_query_clause( self::get_list_filter_value() );
		if ( $clause ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = $clause;
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * Leave comps out of the subscriptions CSV export unless it asks for them.
	 *
	 * An export started from a list filtered by "Complimentary" keeps that filter.
	 *
	 * @param array $args   Query arguments.
	 * @param array $params Captured list parameters.
	 * @param array $config Export options.
	 *
	 * @return array
	 */
	public static function filter_export_query_args( $args, $params = [], $config = [] ) {
		$mode = isset( $params[ self::LIST_FILTER_PARAM ] ) && in_array( $params[ self::LIST_FILTER_PARAM ], [ 'only', 'exclude' ], true )
			? $params[ self::LIST_FILTER_PARAM ]
			: ( empty( $config['include_complimentary'] ) ? 'exclude' : '' );
		$clause = Complimentary_Access::get_meta_query_clause( $mode );
		if ( $clause ) {
			$args['meta_query']   = $args['meta_query'] ?? []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'][] = $clause;
		}
		return $args;
	}
}
