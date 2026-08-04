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
	 * Test that a requested coupon becomes the block's coupon attribute, which
	 * view.php emits as the form's hidden `coupon` field.
	 */
	public function test_coupon_rides_along_as_a_block_attribute() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11, 0, 'SPRING20' );
		$this->assertSame( 'SPRING20', $attrs['coupon'] );

		$variable = Modal_Checkout::build_url_triggered_button_attrs( 'variable-subscription', 11, 22, 'SPRING20' );
		$this->assertSame( 'SPRING20', $variable['coupon'] );
	}

	/**
	 * Test that no coupon attribute is set without one, and that a code of "0"
	 * still counts as a coupon.
	 */
	public function test_coupon_attribute_is_omitted_when_absent() {
		$this->assertArrayNotHasKey( 'coupon', Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11 ) );
		$this->assertArrayNotHasKey( 'coupon', Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11, 0, '' ) );
		$this->assertSame( '0', Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11, 0, '0' )['coupon'] );
	}

	/**
	 * Test that the picker submission copies the coupon: the picker form carries
	 * none of its own, so without this a reader choosing a variation would lose
	 * the coupon.
	 */
	public function test_picker_context_fields_include_the_coupon() {
		$trigger = file_get_contents( \NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/modal-checkout/checkout-button-trigger.js' );
		$fields  = substr( $trigger, strpos( $trigger, 'PICKER_CONTEXT_FIELDS = [' ) );
		$fields  = substr( $fields, 0, strpos( $fields, '];' ) );
		$this->assertStringContainsString( "'coupon'", $fields );
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
