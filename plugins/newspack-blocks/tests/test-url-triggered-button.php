<?php
/**
 * Tests the block attributes synthesized to serve a checkout-button URL
 * trigger on a page that carries no such block.
 *
 * @package Newspack_Blocks
 */

use Newspack_Blocks\Modal_Checkout;

/**
 * URL-triggered checkout button test case.
 */
class Newspack_Blocks_Test_Url_Triggered_Button extends WP_UnitTestCase {
	/**
	 * Test that a plain product yields a product-only button.
	 */
	public function test_simple_product_attrs() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11 );
		$this->assertSame( '11', $attrs['product'] );
		$this->assertArrayNotHasKey( 'variation', $attrs );
		$this->assertArrayNotHasKey( 'is_variable', $attrs );
		$this->assertNotEmpty( $attrs['text'] );
	}

	/**
	 * Test that a variable parent is flagged so view.php renders the picker,
	 * with no variation locked.
	 */
	public function test_variable_parent_attrs_render_the_picker() {
		foreach ( [ 'variable', 'variable-subscription' ] as $type ) {
			$attrs = Modal_Checkout::build_url_triggered_button_attrs( $type, 11 );
			$this->assertSame( '11', $attrs['product'], $type );
			$this->assertTrue( $attrs['is_variable'], $type );
			$this->assertArrayNotHasKey( 'variation', $attrs, $type );
		}
	}

	/**
	 * Test that a requested variation is locked onto the variable parent, which
	 * is how the editor stores it — view.php then collapses the pair onto the
	 * variation.
	 */
	public function test_variable_parent_with_variation_locks_it() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs( 'variable-subscription', 11, 22 );
		$this->assertSame( '11', $attrs['product'] );
		$this->assertSame( '22', $attrs['variation'] );
		$this->assertTrue( $attrs['is_variable'] );
	}

	/**
	 * Test that a grouped parent is a product-only button: view.php registers
	 * the tiers picker for it without an is_variable flag.
	 */
	public function test_grouped_parent_attrs() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs( 'grouped', 30 );
		$this->assertSame( '30', $attrs['product'] );
		$this->assertArrayNotHasKey( 'is_variable', $attrs );
		$this->assertArrayNotHasKey( 'variation', $attrs );
	}

	/**
	 * Test that a variation requested against a product that cannot serve one
	 * is refused rather than rendered as a button that ignores it.
	 */
	public function test_variation_on_non_variable_product_is_refused() {
		$this->assertSame( [], Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11, 22 ) );
		$this->assertSame( [], Modal_Checkout::build_url_triggered_button_attrs( 'grouped', 30, 31 ) );
	}

	/**
	 * Test that nothing is rendered into the footer without a trigger.
	 */
	public function test_no_button_rendered_without_a_trigger() {
		ob_start();
		Modal_Checkout::render_url_triggered_button();
		$this->assertSame( '', ob_get_clean() );
	}
}
