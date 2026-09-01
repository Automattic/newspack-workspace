<?php
/**
 * Convert a subscription variation into a standalone simple subscription product.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a variation of a variable subscription product into a standalone simple
 * subscription product, keeping the same product ID so existing subscriptions, renewal
 * schedules, gate rules, and automations keyed on that ID keep working.
 *
 * Why the ID must survive: subscription line items, membership plan product lists, and
 * Access Control gate rules all reference products by ID. Creating a fresh product would
 * orphan every one of those references; converting the variation post in place keeps them.
 *
 * The conversion order matters. WC_Order_Item_Product's setters validate post types:
 * set_product_id() rejects a `product_variation` post and set_variation_id() rejects a
 * post that is no longer a variation. So the post is converted first, and line items are
 * then enumerated from raw order-item meta (hydrated items read variation_id as 0 once
 * the post type has changed, so item props can't be used to find them) and rewritten via
 * CRUD, with the stale `_variation_id` meta zeroed directly — a 0→0 prop set records no
 * change, so WC would never persist it.
 */
class Convert_Subscription_Variation {

	/**
	 * Order statuses whose orders still lead to a payment attempt (failed-payment pay
	 * links, pending manual renewals). Their line items are rewritten along with the
	 * subscriptions'; completed/refunded/cancelled orders are history and left alone.
	 *
	 * @var string[]
	 */
	const UNPAID_ORDER_STATUSES = [ 'wc-pending', 'wc-failed', 'wc-on-hold' ];

	/**
	 * Convert variations of a variable subscription product into standalone simple
	 * subscription products, keeping their IDs and migrating all subscription and
	 * unpaid-order line items.
	 *
	 * ## OPTIONS
	 *
	 * <variation-id>...
	 * : One or more variation IDs to convert.
	 *
	 * [--live]
	 * : Run the command in live mode, converting the products and rewriting line items.
	 *   Without it the command reports what would change.
	 *
	 * [--verbose]
	 * : Produce more output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack convert-subscription-variation 222053 222054 --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Assoc arguments.
	 *
	 * @return void
	 */
	public function convert( $args, $assoc_args ) {
		WP_CLI::line( '' );
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active.' );
		}
		$live    = isset( $assoc_args['live'] );
		$verbose = isset( $assoc_args['verbose'] );

		$targets = [];
		foreach ( $args as $raw_id ) {
			$target = self::validate_target( (int) $raw_id );
			if ( \is_wp_error( $target ) ) {
				WP_CLI::error( $target->get_error_message() );
			}
			$targets[] = $target;
		}

		WP_CLI::line( 'Running in ' . ( $live ? 'LIVE' : 'dry run' ) . ' mode.' );
		WP_CLI::line( '' );

		foreach ( $targets as $target ) {
			$report = self::get_report( $target['variation_id'] );
			WP_CLI::line(
				sprintf(
					'Variation %d "%s" (parent %d): %d subscription line items (%s), %d unpaid-order line items, %d historical references left untouched.',
					$target['variation_id'],
					$target['title'],
					$target['parent_id'],
					$report['subscription_items'],
					self::format_status_counts( $report['subscription_items_by_status'] ),
					$report['unpaid_order_items'],
					$report['historical_items']
				)
			);

			if ( ! $live ) {
				continue;
			}

			$converted = self::convert_post( $target );
			if ( \is_wp_error( $converted ) ) {
				WP_CLI::error( $converted->get_error_message() );
			}
			WP_CLI::line( sprintf( 'Converted product %d to a simple subscription (catalog visibility: %s).', $target['variation_id'], $converted['catalog_visibility'] ) );

			$migrated = self::migrate_line_items( $target, $verbose );
			WP_CLI::line(
				sprintf(
					'Rewrote %d line items (%d failures); added notes on %d subscriptions; %d in-scope references remaining.',
					$migrated['items'],
					$migrated['failures'],
					$migrated['notes'],
					$migrated['remaining']
				)
			);
			if ( 0 !== $migrated['remaining'] || 0 !== $migrated['failures'] ) {
				WP_CLI::error( sprintf( 'Line-item migration for %d did not complete cleanly — investigate before re-running.', $target['variation_id'] ) );
			}

			$pruned = self::prune_parent_attribute_options( $target );
			foreach ( $pruned['removed'] as $attribute => $values ) {
				WP_CLI::line( sprintf( 'Removed unused option(s) from parent attribute "%s": %s.', $attribute, implode( ', ', $values ) ) );
			}
			foreach ( $pruned['skipped'] as $attribute => $reason ) {
				WP_CLI::warning( sprintf( 'Left parent attribute "%s" untouched: %s', $attribute, $reason ) );
			}
			WP_CLI::line( '' );
		}

		if ( $live ) {
			WP_CLI::success( sprintf( 'Converted %d variation(s).', count( $targets ) ) );
		} else {
			WP_CLI::success( sprintf( 'Dry run complete for %d variation(s). Re-run with --live to convert.', count( $targets ) ) );
		}
		WP_CLI::line( '' );
	}

	/**
	 * Validate that a post is a variation of a variable subscription product and gather
	 * the data later phases need.
	 *
	 * @param int $variation_id The variation ID.
	 * @return array|WP_Error Target data: variation_id, parent_id, title, attributes
	 *                        (attribute slug => variation's value).
	 */
	public static function validate_target( int $variation_id ) {
		$post = \get_post( $variation_id );
		if ( ! $post ) {
			return new WP_Error( 'newspack_convert_variation', sprintf( 'Post %d does not exist.', $variation_id ) );
		}
		if ( 'product_variation' !== $post->post_type ) {
			return new WP_Error( 'newspack_convert_variation', sprintf( 'Post %d is a "%s", not a product variation.', $variation_id, $post->post_type ) );
		}
		$parent = \wc_get_product( $post->post_parent );
		if ( ! $parent || ! $parent->is_type( 'variable-subscription' ) ) {
			return new WP_Error(
				'newspack_convert_variation',
				sprintf( 'Parent %d of variation %d is not a variable subscription product. Only subscription variations are supported.', (int) $post->post_parent, $variation_id )
			);
		}

		$attributes = [];
		foreach ( \get_post_meta( $variation_id ) as $meta_key => $values ) {
			if ( 0 === strpos( $meta_key, 'attribute_' ) ) {
				$attributes[ substr( $meta_key, strlen( 'attribute_' ) ) ] = (string) $values[0];
			}
		}

		return [
			'variation_id' => $variation_id,
			'parent_id'    => (int) $post->post_parent,
			'title'        => $post->post_title,
			'attributes'   => $attributes,
		];
	}

	/**
	 * Count the line items the migration will rewrite, and the historical references it
	 * will deliberately leave alone.
	 *
	 * @param int $variation_id The variation ID.
	 * @return array Counts: subscription_items, subscription_items_by_status,
	 *               unpaid_order_items, historical_items.
	 */
	public static function get_report( int $variation_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::is_hpos_in_use() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT orders.type AS order_type, orders.status AS order_status, oi.order_item_type, COUNT(*) AS items
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_variation_id' AND oim.meta_value = %d
					INNER JOIN {$wpdb->prefix}wc_orders orders ON orders.id = oi.order_id
					GROUP BY orders.type, orders.status, oi.order_item_type",
					$variation_id
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT orders.post_type AS order_type, orders.post_status AS order_status, oi.order_item_type, COUNT(*) AS items
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_variation_id' AND oim.meta_value = %d
					INNER JOIN {$wpdb->prefix}posts orders ON orders.ID = oi.order_id
					GROUP BY orders.post_type, orders.post_status, oi.order_item_type",
					$variation_id
				)
			);
		}
		// phpcs:enable

		$report = [
			'subscription_items'           => 0,
			'subscription_items_by_status' => [],
			'unpaid_order_items'           => 0,
			'historical_items'             => 0,
		];
		foreach ( $rows as $row ) {
			$items = (int) $row->items;
			if ( 'line_item' !== $row->order_item_type ) {
				$report['historical_items'] += $items;
				continue;
			}
			if ( 'shop_subscription' === $row->order_type ) {
				$report['subscription_items']                                  += $items;
				$report['subscription_items_by_status'][ $row->order_status ]   = ( $report['subscription_items_by_status'][ $row->order_status ] ?? 0 ) + $items;
			} elseif ( 'shop_order' === $row->order_type && in_array( $row->order_status, self::UNPAID_ORDER_STATUSES, true ) ) {
				$report['unpaid_order_items'] += $items;
			} else {
				$report['historical_items'] += $items;
			}
		}
		return $report;
	}

	/**
	 * Convert the variation post into a standalone simple subscription product.
	 *
	 * Subscription variations and simple subscriptions share the same `_subscription_*`
	 * meta keys, so price, period, and interval carry over without translation.
	 *
	 * @param array $target Target data from validate_target().
	 * @return array|WP_Error On success: [ 'catalog_visibility' => string ].
	 */
	public static function convert_post( array $target ) {
		$variation_id = $target['variation_id'];
		$parent       = \wc_get_product( $target['parent_id'] );

		$updated = \wp_update_post(
			[
				'ID'          => $variation_id,
				'post_type'   => 'product',
				'post_parent' => 0,
			],
			true
		);
		if ( \is_wp_error( $updated ) ) {
			return $updated;
		}
		\wp_set_object_terms( $variation_id, 'subscription', 'product_type' );
		foreach ( array_keys( $target['attributes'] ) as $attribute_slug ) {
			\delete_post_meta( $variation_id, 'attribute_' . $attribute_slug );
		}

		// The product type is cached in the `products` group and only CRUD saves
		// invalidate it; without this, wc_get_product() still builds a (now-invalid)
		// variation object and returns false.
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $variation_id );
		\clean_post_cache( $variation_id );

		$product = \wc_get_product( $variation_id );
		if ( ! $product || ! $product->is_type( 'subscription' ) ) {
			return new WP_Error( 'newspack_convert_variation', sprintf( 'Product %d did not resolve as a simple subscription after conversion.', $variation_id ) );
		}

		// Purchase stays possible via direct links and checkout buttons either way;
		// matching the parent keeps a deliberately unlisted catalog unlisted.
		$product->set_catalog_visibility( $parent->get_catalog_visibility() );
		$product->save();
		\wc_delete_product_transients( $variation_id );
		\wc_delete_product_transients( $target['parent_id'] );

		return [ 'catalog_visibility' => $product->get_catalog_visibility() ];
	}

	/**
	 * Rewrite subscription and unpaid-order line items to reference the standalone
	 * product. Idempotent: already-migrated items are rewritten harmlessly and only
	 * first-time migrations add an order note.
	 *
	 * @param array $target  Target data from validate_target().
	 * @param bool  $verbose Whether to print each item.
	 * @return array Counts: items, failures, notes, remaining.
	 */
	public static function migrate_line_items( array $target, bool $verbose = false ): array {
		$variation_id = $target['variation_id'];
		$item_rows    = self::get_in_scope_item_rows( $variation_id );
		$display_keys = self::item_display_meta_keys( $target['attributes'] );

		$noted    = [];
		$counts   = [
			'items'    => 0,
			'failures' => 0,
			'notes'    => 0,
		];
		foreach ( $item_rows as $row ) {
			try {
				$item            = new \WC_Order_Item_Product( (int) $row->order_item_id );
				$first_migration = ( (int) $item->get_product_id() !== $variation_id );
				$item->set_product_id( $variation_id );
				$item->set_name( $target['title'] );
				foreach ( $item->get_meta_data() as $meta ) {
					if ( in_array( strtolower( $meta->key ), $display_keys, true ) ) {
						$item->delete_meta_data( $meta->key );
					}
				}
				$item->save();
				// Hydration reads the stale variation ID as 0 (its post is no longer a
				// variation), so a set_variation_id( 0 ) records no change and save()
				// never persists it; write the meta directly.
				\wc_update_order_item_meta( (int) $row->order_item_id, '_variation_id', 0 );
				$counts['items']++;
				if ( $verbose ) {
					WP_CLI::line( sprintf( 'Rewrote item %d (order %d).', (int) $row->order_item_id, (int) $row->order_id ) );
				}
				if ( $first_migration && 'shop_subscription' === $row->order_type && empty( $noted[ $row->order_id ] ) ) {
					$subscription = \wcs_get_subscription( (int) $row->order_id );
					if ( $subscription ) {
						$subscription->add_order_note(
							sprintf( 'Line item migrated: variation %1$d of product %2$d converted to standalone product %1$d.', $variation_id, $target['parent_id'] )
						);
						$counts['notes']++;
					}
					$noted[ $row->order_id ] = true;
				}
			} catch ( \Exception $e ) {
				$counts['failures']++;
				WP_CLI::warning( sprintf( 'Failed to rewrite item %d (order %d): %s', (int) $row->order_item_id, (int) $row->order_id, $e->getMessage() ) );
			}
		}

		$counts['remaining'] = count( self::get_in_scope_item_rows( $variation_id ) );
		return $counts;
	}

	/**
	 * Enumerate the line items to rewrite from raw order-item meta: every subscription
	 * line item referencing the variation, plus line items on unpaid orders. Items are
	 * matched on raw meta because hydrated item objects read the stale variation ID as 0
	 * once the post type has changed.
	 *
	 * @param int $variation_id The variation ID.
	 * @return array Rows with order_item_id, order_id, order_type.
	 */
	private static function get_in_scope_item_rows( int $variation_id ): array {
		global $wpdb;
		$statuses_in = self::unpaid_statuses_sql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::is_hpos_in_use() ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT oi.order_item_id, oi.order_id, orders.type AS order_type
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_variation_id' AND oim.meta_value = %d
					INNER JOIN {$wpdb->prefix}wc_orders orders ON orders.id = oi.order_id
					WHERE oi.order_item_type = 'line_item'
					AND ( orders.type = 'shop_subscription' OR ( orders.type = 'shop_order' AND orders.status IN ( $statuses_in ) ) )",
					$variation_id
				)
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.order_item_id, oi.order_id, orders.post_type AS order_type
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_variation_id' AND oim.meta_value = %d
				INNER JOIN {$wpdb->prefix}posts orders ON orders.ID = oi.order_id
				WHERE oi.order_item_type = 'line_item'
				AND ( orders.post_type = 'shop_subscription' OR ( orders.post_type = 'shop_order' AND orders.post_status IN ( $statuses_in ) ) )",
				$variation_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Remove the converted variation's attribute values from the parent's attribute
	 * options, unless another remaining variation still uses them.
	 *
	 * Taxonomy-backed attributes store term IDs as parent options but slugs on
	 * variations; those are translated before comparing. If a term can't be resolved the
	 * attribute is left untouched with a warning rather than guessing.
	 *
	 * @param array $target Target data from validate_target().
	 * @return array [ 'removed' => attribute => values[], 'skipped' => attribute => reason ].
	 */
	public static function prune_parent_attribute_options( array $target ): array {
		$result = [
			'removed' => [],
			'skipped' => [],
		];
		$parent = \wc_get_product( $target['parent_id'] );
		if ( ! $parent ) {
			$result['skipped'][''] = sprintf( 'parent %d no longer resolves.', $target['parent_id'] );
			return $result;
		}

		// Values still in use, per attribute slug, across the remaining variations.
		$remaining_values = [];
		foreach ( $parent->get_children() as $child_id ) {
			foreach ( \get_post_meta( $child_id ) as $meta_key => $values ) {
				if ( 0 === strpos( $meta_key, 'attribute_' ) ) {
					$remaining_values[ substr( $meta_key, strlen( 'attribute_' ) ) ][] = (string) $values[0];
				}
			}
		}

		$attributes = $parent->get_attributes();
		$changed    = false;
		foreach ( $target['attributes'] as $attribute_slug => $converted_value ) {
			if ( '' === $converted_value ) {
				// An empty value means "any", which never pins an option.
				continue;
			}
			if ( ! isset( $attributes[ $attribute_slug ] ) ) {
				$result['skipped'][ $attribute_slug ] = 'not found on the parent product.';
				continue;
			}
			$attribute = $attributes[ $attribute_slug ];
			$options   = $attribute->get_options();

			if ( $attribute->is_taxonomy() ) {
				$term = \get_term_by( 'slug', $converted_value, $attribute_slug );
				if ( ! $term ) {
					$result['skipped'][ $attribute_slug ] = sprintf( 'could not resolve term "%s".', $converted_value );
					continue;
				}
				$option_to_remove = (int) $term->term_id;
			} else {
				$option_to_remove = $converted_value;
			}

			$keep = self::compute_pruned_options( $options, $option_to_remove, $remaining_values[ $attribute_slug ] ?? [], $converted_value );
			if ( count( $keep ) === count( $options ) ) {
				continue;
			}

			// get_attributes() returns the product's live attribute objects; mutating one
			// in place edits the product's own data, so change detection records nothing
			// and save() skips the write. Clone before editing.
			$pruned = clone $attribute;
			$pruned->set_options( $keep );
			$attributes[ $attribute_slug ]                = $pruned;
			$changed                                      = true;
			$result['removed'][ $attribute_slug ][] = $converted_value;
		}

		if ( $changed ) {
			$parent->set_attributes( $attributes );
			$parent->save();
			\wc_delete_product_transients( $target['parent_id'] );
		}
		return $result;
	}

	/**
	 * Decide which parent attribute options survive pruning. Pure logic, split out for
	 * unit testing: an option is removed only when it belongs to the converted variation
	 * and no remaining variation still uses that value.
	 *
	 * @param array      $options          The parent attribute's current options (strings for
	 *                                     custom attributes, term IDs for taxonomy ones).
	 * @param string|int $option_to_remove The option matching the converted variation's value.
	 * @param array      $remaining_values Attribute values still used by remaining variations
	 *                                     (always the variation-meta representation, i.e. strings).
	 * @param string     $converted_value  The converted variation's value in variation-meta form.
	 * @return array Options to keep, reindexed.
	 */
	public static function compute_pruned_options( array $options, $option_to_remove, array $remaining_values, string $converted_value ): array {
		if ( in_array( $converted_value, $remaining_values, true ) ) {
			return array_values( $options );
		}
		return array_values(
			array_filter(
				$options,
				function ( $option ) use ( $option_to_remove ) {
					// Loose-by-string comparison: term IDs may arrive as int or numeric string.
					return (string) $option !== (string) $option_to_remove;
				}
			)
		);
	}

	/**
	 * Derive the order-item display meta keys for a variation's attributes. Line items
	 * store the human-facing attribute value under the attribute slug ('plan',
	 * 'pa_color'), which is the `attribute_`-prefixed variation meta key without its
	 * prefix. Pure logic, split out for unit testing.
	 *
	 * @param array $attributes Attribute slug => value, from validate_target().
	 * @return array Lower-cased meta keys to delete from migrated line items.
	 */
	public static function item_display_meta_keys( array $attributes ): array {
		return array_map( 'strtolower', array_keys( $attributes ) );
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (High-Performance Order
	 * Storage) rather than posts. Line items live in the same tables either way; only
	 * the order-record join differs.
	 *
	 * @return bool
	 */
	private static function is_hpos_in_use(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The unpaid-order statuses as a quoted SQL IN list.
	 *
	 * @return string
	 */
	private static function unpaid_statuses_sql(): string {
		return implode(
			', ',
			array_map(
				function ( $status ) {
					return "'" . \esc_sql( $status ) . "'";
				},
				self::UNPAID_ORDER_STATUSES
			)
		);
	}

	/**
	 * Render a status => count map as a compact string for the report line.
	 *
	 * @param array $by_status Status => count.
	 * @return string
	 */
	private static function format_status_counts( array $by_status ): string {
		if ( empty( $by_status ) ) {
			return 'none';
		}
		$parts = [];
		foreach ( $by_status as $status => $count ) {
			$parts[] = sprintf( '%s: %d', preg_replace( '/^wc-/', '', $status ), $count );
		}
		return implode( ', ', $parts );
	}
}
