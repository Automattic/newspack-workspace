<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Test stubs for WooCommerce's WC_Order and wc_get_is_paid_statuses(), and
 * WooCommerce Subscriptions' WC_Subscription.
 *
 * The newspack-blocks test suite runs without WooCommerce loaded, so the real
 * WC_Order class is absent. This stub carries the accessors the modal checkout
 * return-URL, tracking and cart-data paths read, and satisfies both the
 * is_a( $order, 'WC_Order' ) and instanceof checks those paths use.
 *
 * It is the suite's only definition of each class, loaded from bootstrap.php so
 * it is in place before any test file runs. A second, partial definition in a
 * test file would not merely duplicate one here: both guard on class_exists(),
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
		 * Order meta, keyed by meta key.
		 *
		 * @var array
		 */
		protected $meta = [];

		/**
		 * Order status, without the wc- prefix.
		 *
		 * @var string
		 */
		protected $status = 'pending';

		/**
		 * Meta each order last saved, keyed by order ID: what a later request
		 * would load. Tests that read it reset it.
		 *
		 * @var array
		 */
		public static $saved_meta = [];

		/**
		 * Constructor.
		 *
		 * @param int    $id        Order ID.
		 * @param string $order_key Order key.
		 * @param array  $items     Line items.
		 * @param array  $meta      Order meta, keyed by meta key.
		 */
		public function __construct( $id = 0, $order_key = '', $items = [], $meta = [] ) {
			$this->id        = $id;
			$this->order_key = $order_key;
			$this->items     = $items;
			$this->meta      = $meta;
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
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * Set an order meta value.
		 *
		 * @param string $key   Meta key.
		 * @param mixed  $value Meta value.
		 */
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		/**
		 * Persist meta to the saved store, where a later request would load it.
		 */
		public function save_meta_data() {
			self::$saved_meta[ $this->id ] = $this->meta;
		}

		/**
		 * Set the order status.
		 *
		 * @param string $status Status, without the wc- prefix.
		 */
		public function set_status( $status ) {
			$this->status = $status;
		}

		/**
		 * Whether the order has a paid status.
		 *
		 * @return bool
		 */
		public function is_paid() {
			return in_array( $this->status, wc_get_is_paid_statuses(), true );
		}
	}
}

if ( ! function_exists( 'wc_get_is_paid_statuses' ) ) {
	/**
	 * Stub of WooCommerce's wc_get_is_paid_statuses(), with its default paid
	 * statuses.
	 *
	 * @return string[]
	 */
	function wc_get_is_paid_statuses() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Test stub deliberately impersonates WooCommerce's function.
		return [ 'processing', 'completed' ];
	}
}

if ( ! class_exists( 'WC_Subscription' ) ) {
	/**
	 * Minimal stub of WooCommerce Subscriptions' WC_Subscription, which extends
	 * WC_Order. A subscription created by hand in wp-admin has no parent order,
	 * so get_parent() returns false for it.
	 */
	class WC_Subscription extends WC_Order { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
		/**
		 * Parent order, or false when there is none.
		 *
		 * @var WC_Order|false
		 */
		private $parent;

		/**
		 * Constructor.
		 *
		 * @param array          $items  Line items.
		 * @param WC_Order|false $parent Parent order, or false.
		 * @param int            $id     Subscription ID.
		 */
		public function __construct( $items = [], $parent = false, $id = 901 ) {
			parent::__construct( $id, '', $items );
			$this->parent = $parent;
		}

		public function get_parent() {
			return $this->parent;
		}
	}
}
