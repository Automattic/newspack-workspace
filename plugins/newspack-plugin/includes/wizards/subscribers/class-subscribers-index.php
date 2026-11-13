<?php
/**
 * SQL index over the site's subscriptions, for the Subscribers wizard.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the Subscribers wizard's list questions in SQL: one page of the group
 * list, the distinct group and product names behind its filters, the people in
 * a set of groups, and the readers holding subscriptions that match a filter.
 *
 * A site can hold thousands of subscriptions and groups. When a site's personal
 * plan is itself a team product, every personal subscription is a group
 * subscription. Loading each one as a WC_Subscription to filter or sort them
 * runs the request out of memory, so this reads the few columns those
 * questions need straight from the order tables, and the wizard loads only the
 * page it returns.
 *
 * The columns mirror how the wizard displays a group, and must change with it:
 * - `name` follows Group_Subscription_Settings::get_subscription_settings(): the
 *   custom name, else the product name, else the group label. The
 *   `newspack_group_subscription_settings` filter is not applied, because SQL
 *   cannot run it.
 * - `product` is the name of the canonical product of the first line item, as
 *   WooCommerce_Subscriptions::get_subscription_product_id() resolves it, except
 *   that a first item whose product was deleted is not skipped for the next one.
 *   The names come from wc_get_product(), once per distinct product: a site sells
 *   a handful of plans, so that is a few loads however many groups there are.
 * - `mapped_status` follows Subscribers_Wizard::map_subscription_status(), with
 *   unknown statuses in the on-hold bucket. A trashed or draft subscription is
 *   no group at all: it has no row, rather than showing as on hold.
 *
 * Names compare case- and accent-sensitively, as the PHP they mirror does.
 *
 * Which subscriptions are groups is not decided here. Every group method takes the
 * candidate IDs from Group_Subscription_Settings::get_group_subscription_ids(),
 * which stays the one authority on that. Those candidates can include a
 * subscription whose own settings say it is not a group; the group list drops
 * such a row when it loads the page, but the people and filter queries here
 * count it.
 */
final class Subscribers_Index {

	/**
	 * Columns the group list can sort on, mapped to their SQL expressions.
	 */
	const ORDERBY = [
		'name'      => 'g.name',
		'owner'     => 'u.display_name',
		'createdAt' => 'g.created',
	];

	/**
	 * Product ID → name map from product_names_by_id(), keyed by the group-ID set
	 * it was built for. Cleared by reset() at the start of each request.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static array $product_names_memo = [];

	/**
	 * Clear the per-request product-name map.
	 */
	public static function reset(): void {
		self::$product_names_memo = [];
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS) rather than posts.
	 *
	 * @return bool
	 */
	private static function is_hpos(): bool {
		return function_exists( 'wcs_is_custom_order_tables_usage_enabled' ) && \wcs_is_custom_order_tables_usage_enabled();
	}

	/**
	 * The SQL pieces for reading subscriptions from whichever order store is in
	 * use. Each refers to the subscription as `s`.
	 *
	 * `meta` reads one meta value as a scalar subquery rather than a join, so a
	 * subscription carrying a key twice still yields one row.
	 *
	 * @return array{from:string,id:string,type:string,status:string,created:string,customer:string,meta:callable}
	 */
	private static function store(): array {
		global $wpdb;
		if ( self::is_hpos() ) {
			$meta = $wpdb->prefix . 'wc_orders_meta';
			return [
				'from'     => "{$wpdb->prefix}wc_orders s",
				'id'       => 's.id',
				'type'     => "s.type = 'shop_subscription'",
				'status'   => 's.status',
				'created'  => 's.date_created_gmt',
				'customer' => 's.customer_id',
				'meta'     => function ( string $key ) use ( $meta, $wpdb ): string {
					return $wpdb->prepare( "( SELECT meta_value FROM {$meta} WHERE order_id = s.id AND meta_key = %s LIMIT 1 )", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				},
			];
		}
		$meta = function ( string $key ) use ( $wpdb ): string {
			return $wpdb->prepare( "( SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = s.ID AND meta_key = %s LIMIT 1 )", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		return [
			'from'     => "{$wpdb->posts} s",
			'id'       => 's.ID',
			'type'     => "s.post_type = 'shop_subscription'",
			'status'   => 's.post_status',
			'created'  => 's.post_date_gmt',
			'customer' => 'CAST( ' . $meta( '_customer_user' ) . ' AS UNSIGNED )',
			'meta'     => $meta,
		];
	}

	/**
	 * SQL selecting each group's order_id and the canonical product of its first
	 * line item: the variation when there is one, else the product.
	 *
	 * @param string $ids Integer-only ID list.
	 *
	 * @return string
	 */
	private static function first_product_sql( string $ids ): string {
		global $wpdb;
		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$item_meta = fn( string $key ): string => "( SELECT meta_value FROM {$itemmeta} WHERE order_item_id = fi.first_item AND meta_key = '{$key}' LIMIT 1 )";
		return "SELECT fi.order_id,
				IF( CAST( {$item_meta( '_variation_id' )} AS UNSIGNED ) > 0, CAST( {$item_meta( '_variation_id' )} AS UNSIGNED ), CAST( {$item_meta( '_product_id' )} AS UNSIGNED ) ) AS product_id
			FROM (
				SELECT order_id, MIN( order_item_id ) AS first_item FROM {$items}
				WHERE order_item_type = 'line_item' AND order_id IN ({$ids})
				GROUP BY order_id
			) fi";
	}

	/**
	 * Product ID → name for the distinct products behind a set of groups.
	 * Memoized until reset(): one /groups request reads it several times.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs. Must not be empty.
	 *
	 * @return array<int,string>
	 */
	private static function product_names_by_id( array $group_ids ): array {
		global $wpdb;
		$ids = self::id_list( $group_ids );
		$key = md5( $ids );
		if ( isset( self::$product_names_memo[ $key ] ) ) {
			return self::$product_names_memo[ $key ];
		}
		$names = [];
		if ( function_exists( 'wc_get_product' ) ) {
			$product_ids = $wpdb->get_col( 'SELECT DISTINCT product_id FROM ( ' . self::first_product_sql( $ids ) . ' ) p' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( $product_ids as $product_id ) {
				$product = (int) $product_id ? \wc_get_product( (int) $product_id ) : false;
				if ( $product ) {
					$names[ (int) $product_id ] = (string) $product->get_name();
				}
			}
		}
		self::$product_names_memo = [ $key => $names ];
		return $names;
	}

	/**
	 * The distinct, trimmed product names behind a set of groups, for the
	 * Subscription filter's options.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs.
	 *
	 * @return string[]
	 */
	public static function products( array $group_ids ): array {
		if ( empty( $group_ids ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( fn( $name ) => trim( $name, ' ' ), self::product_names_by_id( $group_ids ) ), 'strlen' ) ) );
	}

	/**
	 * A comma-separated, integer-only list for an IN() clause.
	 *
	 * @param int[] $ids IDs.
	 *
	 * @return string
	 */
	private static function id_list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * The derived table of group rows: id, status, mapped_status, created,
	 * customer_id, name, product, complimentary.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs. Must not be empty.
	 *
	 * @return string SQL for a derived table.
	 */
	private static function rows_sql( array $group_ids ): string {
		global $wpdb;
		$ids      = self::id_list( $group_ids );
		$prefix   = Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX;
		$label    = Group_Subscription::get_label( 'singular' );
		$comp_key = Complimentary_Access::META_KEY;

		$store   = self::store();
		$meta    = $store['meta'];
		$id_col  = $store['id'];
		$status  = "REPLACE( {$store['status']}, 'wc-', '' )";
		$cases   = [];
		foreach ( self::product_names_by_id( $group_ids ) as $product_id => $product_name ) {
			$cases[] = $wpdb->prepare( 'WHEN %d THEN %s', $product_id, $product_name );
		}
		$product   = empty( $cases ) ? "''" : 'CASE fp.product_id ' . implode( ' ', $cases ) . " ELSE '' END";
		$label_sql = $wpdb->prepare( '%s', $label );

		return "SELECT {$id_col} AS id,
				{$status} AS status,
				CASE {$status}
					WHEN 'active' THEN 'active'
					WHEN 'pending-cancel' THEN 'active'
					WHEN 'pending' THEN 'pending'
					WHEN 'cancelled' THEN 'cancelled'
					WHEN 'expired' THEN 'cancelled'
					ELSE 'on-hold'
				END AS mapped_status,
				{$store['created']} AS created,
				{$store['customer']} AS customer_id,
				COALESCE( NULLIF( {$meta( $prefix . 'name' )}, '' ), NULLIF( TRIM( {$product} ), '' ), {$label_sql} ) AS name,
				{$product} AS product,
				IF( {$meta( $comp_key )} IN ( 'yes', '1', 'true' ), 1, 0 ) AS complimentary
			FROM {$store['from']}
			LEFT JOIN ( " . self::first_product_sql( $ids ) . " ) fp ON fp.order_id = {$id_col}
			WHERE {$id_col} IN ({$ids})
				AND {$store['status']} NOT IN ( 'trash', 'draft', 'auto-draft' )";
	}

	/**
	 * WHERE clauses over the derived group rows (`g`) and the owner (`u`).
	 *
	 * @param array $where {
	 *     Optional filters. Every one given must match.
	 *
	 *     @type string   $search        Substring of the group name, product name, owner name or owner email.
	 *     @type string[] $statuses      Mapped statuses: active, pending, on-hold, cancelled.
	 *     @type string[] $products      Exact product names, compared trimmed.
	 *     @type string[] $names         Exact group names, compared trimmed.
	 *     @type bool     $complimentary Only groups carrying the complimentary stamp.
	 * }
	 *
	 * @return string SQL, starting with WHERE.
	 */
	private static function where_sql( array $where ): string {
		global $wpdb;
		$clauses = [ '1=1' ];

		$search = trim( (string) ( $where['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = $wpdb->prepare( '( g.name LIKE %s OR g.product LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s )', $like, $like, $like, $like );
		}
		$columns = [
			'statuses' => 'g.mapped_status',
			'products' => 'CAST( TRIM( g.product ) AS BINARY )',
			'names'    => 'CAST( TRIM( g.name ) AS BINARY )',
		];
		foreach ( $columns as $key => $column ) {
			$values = array_values( array_filter( array_map( 'strval', (array) ( $where[ $key ] ?? [] ) ), 'strlen' ) );
			if ( empty( $values ) ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$clauses[]    = $wpdb->prepare( "{$column} IN ({$placeholders})", $values ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		if ( ! empty( $where['complimentary'] ) ) {
			$clauses[] = 'g.complimentary = 1';
		}
		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	/**
	 * The group rows (`g`) joined to their owners (`u`), filtered.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs. Must not be empty.
	 * @param array $where     Filters accepted by where_sql().
	 *
	 * @return string SQL for a FROM clause.
	 */
	private static function from_sql( array $group_ids, array $where ): string {
		global $wpdb;
		// The owner's name and email live only in the users table.
		$users = $wpdb->users; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		return '( ' . self::rows_sql( $group_ids ) . " ) g LEFT JOIN {$users} u ON u.ID = g.customer_id " . self::where_sql( $where );
	}

	/**
	 * One page of the group list.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs.
	 * @param array $args      {
	 *     Optional. The filters accepted by where_sql(), plus these.
	 *
	 *     @type string $orderby  name | owner | createdAt. Default createdAt.
	 *     @type string $order    asc | desc. Default desc.
	 *     @type int    $per_page Page size. Default 20.
	 *     @type int    $page     1-based page. Default 1.
	 * }
	 *
	 * @return array{ids:int[],total:int} The page's group IDs, in order, and the total match count.
	 */
	public static function query( array $group_ids, array $args = [] ): array {
		global $wpdb;
		if ( empty( $group_ids ) ) {
			return [
				'ids'   => [],
				'total' => 0,
			];
		}
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orderby  = self::ORDERBY[ $args['orderby'] ?? 'createdAt' ] ?? self::ORDERBY['createdAt'];
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

		$from = self::from_sql( $group_ids, $args );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from}" );
		// The ID tiebreak keeps rows sharing a sort value from moving between pages.
		$limit = sprintf( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page );
		$ids   = $total ? $wpdb->get_col( "SELECT g.id FROM {$from} ORDER BY {$orderby} {$order}, g.id {$order}{$limit}" ) : [];
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return [
			'ids'   => array_map( 'intval', $ids ),
			'total' => $total,
		];
	}

	/**
	 * The IDs of the groups matching every filter.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs.
	 * @param array $where     Filters accepted by where_sql().
	 *
	 * @return int[]
	 */
	public static function ids_where( array $group_ids, array $where ): array {
		global $wpdb;
		if ( empty( $group_ids ) ) {
			return [];
		}
		$sql = 'SELECT g.id FROM ' . self::from_sql( $group_ids, $where );
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $ids );
	}

	/**
	 * The distinct, trimmed, non-empty group names.
	 *
	 * @param int[] $group_ids Candidate group subscription IDs.
	 *
	 * @return string[]
	 */
	public static function names( array $group_ids ): array {
		global $wpdb;
		if ( empty( $group_ids ) ) {
			return [];
		}
		$sql    = 'SELECT DISTINCT CAST( TRIM( g.name ) AS BINARY ) FROM ( ' . self::rows_sql( $group_ids ) . ' ) g';
		$values = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( 'strval', $values ), 'strlen' ) );
	}

	/**
	 * Everyone in a set of groups: each group's owner, its members and its
	 * promoted managers, as Group_Subscription::get_all_members() counts them.
	 *
	 * The member and manager filters on get_all_members() are not applied, for
	 * the same reason as the settings filter.
	 *
	 * @param int[] $group_ids Group subscription IDs.
	 *
	 * @return int[] Distinct user IDs.
	 */
	public static function people( array $group_ids ): array {
		global $wpdb;
		if ( empty( $group_ids ) ) {
			return [];
		}
		$ids        = self::id_list( $group_ids );
		$store      = self::store();
		$owners_sql = "SELECT {$store['customer']} FROM {$store['from']} WHERE {$store['id']} IN ({$ids})";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$members_sql = $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s ) AND CAST( meta_value AS UNSIGNED ) IN ({$ids})",
			Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY,
			Group_Subscription::GROUP_SUBSCRIPTION_MANAGER_USER_META_KEY
		);
		$people      = $wpdb->get_col( "{$owners_sql} UNION {$members_sql}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( 'intval', $people ) ) );
	}

	/**
	 * The readers holding a subscription, among all the site's subscriptions,
	 * that match every filter given.
	 *
	 * The holder is the gift recipient when the subscription was given and the
	 * recipient's account exists, otherwise the customer, as
	 * WooCommerce_Subscriptions_Gifting::get_holder_id() decides.
	 *
	 * @param array $where {
	 *     Optional filters.
	 *
	 *     @type string[] $statuses      WooCommerce Subscriptions statuses, without the `wc-` prefix.
	 *     @type int[]    $product_ids   Products or variations any line item holds.
	 *     @type bool     $complimentary Only subscriptions carrying the complimentary stamp.
	 *     @type int[]    $exclude_ids   Subscription IDs to leave out.
	 *     @type bool     $customer      Return the customer, ignoring gift recipients. Default false.
	 * }
	 * @param int   $limit Most holders to return.
	 *
	 * @return int[] Distinct user IDs.
	 */
	public static function holder_ids( array $where, int $limit ): array {
		global $wpdb;
		$store   = self::store();
		$meta    = $store['meta'];
		$joins   = [];
		$clauses = [ $store['type'] ];
		$holder  = $store['customer'];

		if ( empty( $where['customer'] ) && class_exists( 'WCS_Gifting' ) ) {
			$users   = $wpdb->users; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
			$joins[] = "LEFT JOIN {$users} ru ON ru.ID = CAST( " . $meta( '_recipient_user' ) . ' AS UNSIGNED )';
			$holder  = "COALESCE( ru.ID, {$holder} )";
		}
		$statuses = array_values( array_filter( array_map( 'strval', (array) ( $where['statuses'] ?? [] ) ), 'strlen' ) );
		// An empty status list matches nobody rather than dropping the clause, which
		// would match every subscription.
		if ( isset( $where['statuses'] ) && empty( $statuses ) ) {
			return [];
		}
		if ( ! empty( $statuses ) ) {
			$prefixed  = array_map( fn( $status ) => 'wc-' . $status, $statuses );
			$clauses[] = $wpdb->prepare( "{$store['status']} IN (" . implode( ',', array_fill( 0, count( $prefixed ), '%s' ) ) . ')', $prefixed ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		if ( ! empty( $where['complimentary'] ) ) {
			$clauses[] = $meta( Complimentary_Access::META_KEY ) . " IN ( 'yes', '1', 'true' )";
		}
		if ( ! empty( $where['exclude_ids'] ) ) {
			$clauses[] = "{$store['id']} NOT IN (" . self::id_list( $where['exclude_ids'] ) . ')';
		}
		if ( isset( $where['product_ids'] ) ) {
			if ( empty( $where['product_ids'] ) ) {
				return [];
			}
			// Any line item, by product or variation, as wcs_get_subscriptions_for_product() matches.
			$clauses[] = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id
				WHERE oi.order_id = {$store['id']} AND oi.order_item_type = 'line_item'
				AND im.meta_key IN ( '_product_id', '_variation_id' )
				AND CAST( im.meta_value AS UNSIGNED ) IN (" . self::id_list( $where['product_ids'] ) . ') )';
		}

		$sql = "SELECT DISTINCT {$holder} AS holder FROM {$store['from']} " . implode( ' ', $joins ) . ' WHERE ' . implode( ' AND ', $clauses ) . sprintf( ' LIMIT %d', max( 1, $limit ) );
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}
}
