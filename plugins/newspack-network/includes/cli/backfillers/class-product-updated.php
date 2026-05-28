<?php
/**
 * Data Backfiller for product_updated events.
 *
 * @package Newspack
 */

namespace Newspack_Network\Backfillers;

use Newspack_Network\Woocommerce\Product_Admin;
use Newspack_Network\Woocommerce\Events as Woocommerce_Events;
use WP_CLI;

/**
 * Backfiller class.
 */
class Product_Updated extends Abstract_Backfiller {

	/**
	 * Gets the output line about the processed item being processed in verbose mode.
	 *
	 * @param \Newspack_Network\Incoming_Events\Abstract_Incoming_Event $event The event.
	 *
	 * @return string
	 */
	protected function get_processed_item_output( $event ) {
		return sprintf( 'Product #%d', $event->get_id() );
	}

	/**
	 * Gets the events to be processed.
	 *
	 * @return \Newspack_Network\Incoming_Events\Abstract_Incoming_Event[] $events An array of events.
	 */
	public function get_events() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$products = get_posts(
			[
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Product_Admin::NETWORK_ID_META_KEY,
						'compare' => '!=',
						'value'   => '',
					],
				],
				'date_query'  => [
					'column'    => 'post_modified_gmt',
					'after'     => $this->start,
					'before'    => $this->end,
					'inclusive' => true,
				],
			]
		);

		$this->maybe_initialize_progress_bar( 'Processing products', count( $products ) );

		$events = [];
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Found %s product(s) with Network IDs eligible for sync.', count( $products ) ) );
		WP_CLI::line( '' );

		foreach ( $products as $product ) {
			$product_data = Woocommerce_Events::product_updated( $product->ID );
			if ( ! $product_data ) {
				continue;
			}
			$timestamp = strtotime( $product->post_modified_gmt );
			$events[]  = new \Newspack_Network\Incoming_Events\Product_Updated( get_bloginfo( 'url' ), $product_data, $timestamp );
		}

		return $events;
	}
}
