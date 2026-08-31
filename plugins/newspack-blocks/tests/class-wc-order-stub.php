<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stub for WooCommerce's WC_Order.
 *
 * The newspack-blocks test suite runs without WooCommerce loaded, so the real
 * WC_Order class is absent. This stub carries the accessors the modal checkout
 * return-URL, tracking and cart-data paths read, and satisfies both the
 * is_a( $order, 'WC_Order' ) and instanceof checks those paths use.
 *
 * It is the suite's only definition of the class, loaded from bootstrap.php so
 * it is in place before any test file runs. A second, partial definition in a
 * test file would not merely duplicate this one: both guard on class_exists(),
 * so whichever the suite happened to load first would win and silently take the
 * other's methods away from every test.
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
		protected $id;

		/**
		 * Order key.
		 *
		 * @var string
		 */
		private $order_key;

		/**
		 * Line items.
		 *
		 * @var array
		 */
		protected $items = [];

		/**
		 * Constructor.
		 *
		 * @param int    $id        Order ID.
		 * @param string $order_key Order key.
		 * @param array  $items     Line items.
		 */
		public function __construct( $id = 0, $order_key = '', $items = [] ) {
			$this->id        = $id;
			$this->order_key = $order_key;
			$this->items     = $items;
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

		/**
		 * Get the line items.
		 *
		 * @return array
		 */
		public function get_items() {
			return $this->items;
		}

		/**
		 * Get an order meta value.
		 *
		 * @param string $key Meta key.
		 *
		 * @return string
		 */
		public function get_meta( $key ) {
			unset( $key );
			return '';
		}
	}
}
