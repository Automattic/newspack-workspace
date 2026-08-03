<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ModalCheckoutDataTest
 *
 * @package Newspack_Blocks
 */

use Newspack_Blocks\Modal_Checkout\Checkout_Data;

// These stubs assume WooCommerce is absent from the blocks PHPUnit bootstrap.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed
if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * Minimal WooCommerce product stub for checkout data tests.
	 */
	class WC_Product {
		/**
		 * Product ID.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Product type.
		 *
		 * @var string
		 */
		private $type;

		/**
		 * Child product IDs.
		 *
		 * @var int[]
		 */
		private $children;

		/**
		 * Product price.
		 *
		 * @var string
		 */
		private $price;

		/**
		 * Product name.
		 *
		 * @var string
		 */
		private $name;

		/**
		 * Parent product ID.
		 *
		 * @var int
		 */
		private $parent_id;

		/**
		 * Constructor.
		 *
		 * @param int    $id        Product ID.
		 * @param string $type      Product type.
		 * @param int[]  $children  Child product IDs.
		 * @param string $price     Product price.
		 * @param string $name      Product name.
		 * @param int    $parent_id Parent product ID.
		 */
		public function __construct( $id = 1, $type = 'simple', $children = [], $price = '1', $name = 'Product', $parent_id = 0 ) {
			$this->id        = $id;
			$this->type      = $type;
			$this->children  = $children;
			$this->price     = $price;
			$this->name      = $name;
			$this->parent_id = $parent_id;
		}

		/**
		 * Check the product type.
		 *
		 * @param string|string[] $type Product type.
		 * @return bool
		 */
		public function is_type( $type ) {
			return is_array( $type ) ? in_array( $this->type, $type, true ) : $this->type === $type;
		}

		/**
		 * Get the product type.
		 *
		 * @return string
		 */
		public function get_type() {
			return $this->type;
		}

		/**
		 * Get the product ID.
		 *
		 * @return int
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Get child product IDs.
		 *
		 * @return int[]
		 */
		public function get_children() {
			return $this->children;
		}

		/**
		 * Get the product price.
		 *
		 * @return string
		 */
		public function get_price() {
			return $this->price;
		}

		/**
		 * Get the product parent ID.
		 *
		 * @return int
		 */
		public function get_parent_id() {
			return $this->parent_id;
		}

		/**
		 * Get the product name.
		 *
		 * @return string
		 */
		public function get_name() {
			return $this->name;
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	/**
	 * Minimal WooCommerce variation stub.
	 */
	class WC_Product_Variation extends WC_Product {
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Minimal wc_get_product stub.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|null
	 */
	function wc_get_product( $product_id ) {
		if ( isset( $GLOBALS['newspack_blocks_test_products'][ $product_id ] ) ) {
			return $GLOBALS['newspack_blocks_test_products'][ $product_id ];
		}
		return new WC_Product( $product_id );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Modal checkout data tests.
 */
class Newspack_Blocks_Modal_Checkout_Data_Test extends WP_UnitTestCase_Blocks {
	/**
	 * Clean up product fixtures.
	 */
	public function tear_down() {
		unset( $GLOBALS['newspack_blocks_test_products'] );
		parent::tear_down();
	}

	/**
	 * Variable subscription parents should behave like variable products.
	 */
	public function test_variable_subscription_parent_is_marked_variable() {
		$product = new WC_Product( 1406, 'variable-subscription', [ 1407, 1408 ], '', 'Subscription' );

		$GLOBALS['newspack_blocks_test_products'] = [
			1406 => $product,
			1407 => new WC_Product( 1407, 'subscription', [], '10', 'Monthly', 1406 ),
			1408 => new WC_Product( 1408, 'subscription', [], '20', 'Annual', 1406 ),
		];

		$data = Checkout_Data::get_checkout_data( $product );

		$this->assertSame( '1406', $data['product_id'] );
		$this->assertTrue( $data['is_variable'] );
		$this->assertSame( [ 1407, 1408 ], $data['variation_ids'] );
		$this->assertArrayNotHasKey( 'amount', $data );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
