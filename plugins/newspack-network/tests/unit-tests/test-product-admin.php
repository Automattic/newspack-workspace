<?php
/**
 * Class TestProductAdmin
 *
 * @package Newspack_Network
 */

use Newspack_Network\Woocommerce\Product_Admin;

/**
 * Which products can carry a Network ID.
 */
class TestProductAdmin extends WP_UnitTestCase {

	/**
	 * A stand-in product of the given type; the suite doesn't load WooCommerce.
	 *
	 * @param string $type Product type.
	 * @return object
	 */
	private function product_of_type( $type ) {
		return new class( $type ) {
			/**
			 * Constructor.
			 *
			 * @param string $type Product type.
			 */
			public function __construct( public $type ) {}

			/**
			 * Whether the product is of one of the given types.
			 *
			 * @param string|string[] $types Types to check.
			 * @return bool
			 */
			public function is_type( $types ) {
				return in_array( $this->type, (array) $types, true );
			}
		};
	}

	/**
	 * Subscription products and the one-time products the one-time purchase rule sells
	 * (simple and variable) can be linked across sites; other types can't be gated on.
	 */
	public function test_taggable_product_types() {
		foreach ( [ 'subscription', 'variable-subscription', 'simple', 'variable' ] as $type ) {
			$this->assertTrue( Product_Admin::is_taggable( $this->product_of_type( $type ) ), $type );
		}
		foreach ( [ 'grouped', 'external' ] as $type ) {
			$this->assertFalse( Product_Admin::is_taggable( $this->product_of_type( $type ) ), $type );
		}
		$this->assertFalse( Product_Admin::is_taggable( false ) );
	}
}
