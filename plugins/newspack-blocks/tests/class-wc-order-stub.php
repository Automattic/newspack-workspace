<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for WooCommerce's WC_Order.
 *
 * The newspack-blocks test suite runs without WooCommerce loaded, so the real
 * WC_Order class is absent. This stub carries the two accessors the modal
 * checkout return-URL and tracking callbacks read, and satisfies both the
 * is_a( $order, 'WC_Order' ) and instanceof checks those paths use.
 *
 * @package Newspack_Blocks
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test stub deliberately impersonates WooCommerce's WC_Order.
if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal stub of WooCommerce's WC_Order.
	 */
	class WC_Order { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
		/**
		 * Order ID.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Order key.
		 *
		 * @var string
		 */
		private $order_key;

		/**
		 * Constructor.
		 *
		 * @param int    $id        Order ID.
		 * @param string $order_key Order key.
		 */
		public function __construct( $id = 0, $order_key = '' ) {
			$this->id        = $id;
			$this->order_key = $order_key;
		}

		/**
		 * Get the order ID.
		 *
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Get the order key.
		 *
		 * @return string
		 */
		public function get_order_key() {
			return $this->order_key;
		}
	}
}
