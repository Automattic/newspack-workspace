<?php
/**
 * Reusable WooCommerce-order DB fixtures for insights storage integration tests
 * (NPPD-1685).
 *
 * The insights unit suite mocks WooCommerce and ships no WC order tables, so no
 * storage SQL has ever been exercised against real tables (that is how the 2x
 * duplicate-meta bug survived). This trait stands up the real order tables —
 * legacy (`wc_order_product_lookup`; `posts`/`postmeta` already exist) and HPOS
 * (`wc_orders`, `wc_orders_meta`, `wc_order_product_lookup`) — and inserts orders
 * with arbitrary meta, including the duplicate `_newspack_popup_id` rows that
 * trigger the bug.
 *
 * Reused by the prompt-attributed donation reader test today; the rate-direct,
 * per-prompt, and source-mix reader tests will reuse the same tables + the same
 * duplicate-meta anchor.
 *
 * Tables are InnoDB and created in setUpBeforeClass (outside the per-test
 * transaction) so per-test row inserts roll back automatically.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

/**
 * DDL + row-insert helpers for legacy and HPOS WooCommerce order fixtures.
 */
trait Insights_Woo_Order_Fixtures {

	/**
	 * Create the WC order tables (idempotent). Call from setUpBeforeClass().
	 *
	 * @return void
	 */
	protected static function create_woo_order_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		// HPOS authoritative store.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}wc_orders ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(20) NULL, type VARCHAR(20) NULL, date_created_gmt DATETIME NULL, total_amount DECIMAL(26,8) NULL, customer_id BIGINT UNSIGNED NULL ) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}wc_orders_meta ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NULL, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL, KEY order_id ( order_id ) ) ENGINE=InnoDB" );
		// Product lookup (both backends maintain this).
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}wc_order_product_lookup ( order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY ( order_id, product_id ) ) ENGINE=InnoDB" );
		// Line-item tables — shared by both backends (not HPOS-specific). Needed by
		// subscription queries (churn/winback/performance), which JOIN through these
		// rather than the shop_order-only analytics lookup (see class docblocks).
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}woocommerce_order_items ( order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_item_name TEXT NOT NULL, order_item_type VARCHAR(200) NOT NULL DEFAULT '', order_id BIGINT UNSIGNED NOT NULL, KEY order_id ( order_id ) ) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}woocommerce_order_itemmeta ( meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_item_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(255) NULL, meta_value LONGTEXT NULL, KEY order_item_id ( order_item_id ) ) ENGINE=InnoDB" );
	}

	/**
	 * Drop the WC order tables. Call from tearDownAfterClass().
	 *
	 * @return void
	 */
	protected static function drop_woo_order_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->query( "DROP TABLE IF EXISTS {$p}wc_orders" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}wc_orders_meta" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}wc_order_product_lookup" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}woocommerce_order_items" );
		$wpdb->query( "DROP TABLE IF EXISTS {$p}woocommerce_order_itemmeta" );
	}

	/**
	 * Default order creation date (GMT) — inside any sane test window.
	 *
	 * @return string Y-m-d H:i:s
	 */
	private function default_order_date_gmt(): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-5 days' ) );
	}

	/**
	 * Insert a legacy (posts/postmeta) order with its product-lookup row.
	 *
	 * @param array $args Order spec: `product_id`, `total` (`_order_total`),
	 *                    `popup_ids` (one `_newspack_popup_id` row per element,
	 *                    duplicates allowed), `gate_ids` (one `_gate_post_id` row per
	 *                    element, duplicates allowed — NPPD-1746),
	 *                    `renewal` (`_subscription_renewal`),
	 *                    `status` (default 'wc-completed'), `date` (GMT 'Y-m-d H:i:s').
	 * @return int The created order's post ID.
	 */
	protected function insert_legacy_order( array $args ): int {
		global $wpdb;
		$date     = $args['date'] ?? $this->default_order_date_gmt();
		$order_id = wp_insert_post(
			[
				// Honor an explicit order_id (via import_id) so legacy and HPOS fixtures
				// share the same order id — readers that key results by order id can then
				// assert identically across both backends. import_id 0 = auto-assign.
				'import_id'   => (int) ( $args['order_id'] ?? 0 ),
				'post_type'   => 'shop_order',
				'post_status' => $args['status'] ?? 'wc-completed',
				'post_title'  => 'Order',
			]
		);
		// Force the GMT date deterministically (wp_insert_post derives it from site tz).
		$wpdb->update( $wpdb->posts, [ 'post_date_gmt' => $date ], [ 'ID' => $order_id ] );

		$wpdb->insert(
			"{$wpdb->prefix}wc_order_product_lookup",
			[
				'order_id'   => $order_id,
				'product_id' => $args['product_id'] ?? self::DONATION_PRODUCT_ID,
			]
		);
		foreach ( (array) ( $args['popup_ids'] ?? [] ) as $pid ) {
			add_post_meta( $order_id, '_newspack_popup_id', $pid ); // unique = false → duplicates allowed.
		}
		foreach ( (array) ( $args['gate_ids'] ?? [] ) as $gid ) {
			add_post_meta( $order_id, '_gate_post_id', $gid ); // unique = false → duplicates allowed (NPPD-1746).
		}
		foreach ( (array) ( $args['campaigns'] ?? [] ) as $campaign ) {
			add_post_meta( $order_id, 'utm_campaign', $campaign ); // unique = false → non-unique meta (NEWS-2591).
		}
		if ( isset( $args['total'] ) ) {
			add_post_meta( $order_id, '_order_total', $args['total'] );
		}
		if ( ! empty( $args['renewal'] ) ) {
			add_post_meta( $order_id, '_subscription_renewal', $args['renewal'] );
		}
		return (int) $order_id;
	}

	/**
	 * Insert an HPOS (wc_orders) order with its product-lookup + meta rows.
	 *
	 * @param array $args Same shape as {@see insert_legacy_order()}; `order_id` is required.
	 * @return int The order ID.
	 */
	protected function insert_hpos_order( array $args ): int {
		global $wpdb;
		$p        = $wpdb->prefix;
		$order_id = (int) $args['order_id'];
		$wpdb->insert(
			"{$p}wc_orders",
			[
				'id'               => $order_id,
				'status'           => $args['status'] ?? 'wc-completed',
				'type'             => 'shop_order',
				'date_created_gmt' => $args['date'] ?? $this->default_order_date_gmt(),
				'total_amount'     => $args['total'] ?? 0,
			]
		);
		$wpdb->insert(
			"{$p}wc_order_product_lookup",
			[
				'order_id'   => $order_id,
				'product_id' => $args['product_id'] ?? self::DONATION_PRODUCT_ID,
			]
		);
		foreach ( (array) ( $args['popup_ids'] ?? [] ) as $pid ) {
			$wpdb->insert(
				"{$p}wc_orders_meta",
				[
					'order_id'   => $order_id,
					'meta_key'   => '_newspack_popup_id',
					'meta_value' => $pid,
				]
			);
		}
		foreach ( (array) ( $args['gate_ids'] ?? [] ) as $gid ) {
			$wpdb->insert(
				"{$p}wc_orders_meta",
				[
					'order_id'   => $order_id,
					'meta_key'   => '_gate_post_id',
					'meta_value' => $gid,
				]
			);
		}
		foreach ( (array) ( $args['campaigns'] ?? [] ) as $campaign ) {
			$wpdb->insert(
				"{$p}wc_orders_meta",
				[
					'order_id'   => $order_id,
					'meta_key'   => 'utm_campaign',
					'meta_value' => $campaign,
				]
			);
		}
		if ( ! empty( $args['renewal'] ) ) {
			$wpdb->insert(
				"{$p}wc_orders_meta",
				[
					'order_id'   => $order_id,
					'meta_key'   => '_subscription_renewal',
					'meta_value' => $args['renewal'],
				]
			);
		}
		return $order_id;
	}

	/**
	 * Insert a `shop_subscription` line item (order_item + `_product_id` itemmeta)
	 * against an already-created order row (legacy `posts` or HPOS `wc_orders`).
	 * Shared by both backends — the line-item tables are backend-agnostic.
	 *
	 * @param int $order_id   The subscription's order/post id.
	 * @param int $product_id Line-item product id.
	 * @return void
	 */
	private function insert_subscription_line_item( int $order_id, int $product_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}woocommerce_order_items",
			[
				'order_item_name' => 'Subscription',
				'order_item_type' => 'line_item',
				'order_id'        => $order_id,
			]
		);
		$order_item_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			"{$p}woocommerce_order_itemmeta",
			[
				'order_item_id' => $order_item_id,
				'meta_key'      => '_product_id',
				'meta_value'    => $product_id,
			]
		);
	}

	/**
	 * Insert a legacy (posts/postmeta) `shop_subscription`, with its customer,
	 * line item, and optional `_schedule_cancelled` / `_schedule_start` meta —
	 * the exact shape {@see Legacy_Storage::get_churned_subscribers_in_window()}
	 * and its winback counterpart query.
	 *
	 * @param array $args Spec: `order_id` (required), `customer_id` (required),
	 *                    `product_id` (required), `status` (post_status, e.g.
	 *                    'wc-cancelled' / 'wc-expired' / 'wc-active'),
	 *                    `schedule_cancelled` (optional 'Y-m-d H:i:s'),
	 *                    `schedule_start` (optional 'Y-m-d H:i:s').
	 * @return int The created subscription's post ID.
	 */
	protected function insert_legacy_subscription( array $args ): int {
		global $wpdb;
		$order_id = wp_insert_post(
			[
				'import_id'   => (int) $args['order_id'],
				'post_type'   => 'shop_subscription',
				'post_status' => $args['status'],
				'post_title'  => 'Subscription',
			]
		);
		add_post_meta( $order_id, '_customer_user', (string) $args['customer_id'] );
		if ( isset( $args['schedule_cancelled'] ) ) {
			add_post_meta( $order_id, '_schedule_cancelled', $args['schedule_cancelled'] );
		}
		if ( isset( $args['schedule_start'] ) ) {
			add_post_meta( $order_id, '_schedule_start', $args['schedule_start'] );
		}
		$this->insert_subscription_line_item( (int) $order_id, (int) $args['product_id'] );
		return (int) $order_id;
	}

	/**
	 * Insert an HPOS (`wc_orders`) `shop_subscription`, with its customer_id,
	 * line item, and optional `_schedule_cancelled` / `_schedule_start` meta —
	 * the exact shape {@see HPOS_Storage::get_churned_subscribers_in_window()}
	 * and its winback counterpart query.
	 *
	 * @param array $args Same shape as {@see insert_legacy_subscription()}.
	 * @return int The order ID.
	 */
	protected function insert_hpos_subscription( array $args ): int {
		global $wpdb;
		$p        = $wpdb->prefix;
		$order_id = (int) $args['order_id'];
		$wpdb->insert(
			"{$p}wc_orders",
			[
				'id'               => $order_id,
				'status'           => $args['status'],
				'type'             => 'shop_subscription',
				'date_created_gmt' => $args['date'] ?? $this->default_order_date_gmt(),
				'total_amount'     => $args['total'] ?? 0,
				'customer_id'      => (int) $args['customer_id'],
			]
		);
		if ( isset( $args['schedule_cancelled'] ) ) {
			$wpdb->insert(
				"{$p}wc_orders_meta",
				[
					'order_id'   => $order_id,
					'meta_key'   => '_schedule_cancelled',
					'meta_value' => $args['schedule_cancelled'],
				]
			);
		}
		if ( isset( $args['schedule_start'] ) ) {
			$wpdb->insert(
				"{$p}wc_orders_meta",
				[
					'order_id'   => $order_id,
					'meta_key'   => '_schedule_start',
					'meta_value' => $args['schedule_start'],
				]
			);
		}
		$this->insert_subscription_line_item( $order_id, (int) $args['product_id'] );
		return $order_id;
	}
}
