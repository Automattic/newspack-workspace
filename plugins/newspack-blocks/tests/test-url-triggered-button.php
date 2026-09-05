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
 *
 * The request-reading tests use the WC_Product stub and the wc_get_product()
 * mock defined in test-modal-checkout-data.php; the suite loads every test
 * file, so they are available here.
 */
class Newspack_Blocks_Test_Url_Triggered_Button extends WP_UnitTestCase {
	/**
	 * Clean up product fixtures and request state.
	 */
	public function tear_down() {
		unset( $GLOBALS['newspack_blocks_test_products'] );
		$_GET = [];
		parent::tear_down();
	}

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
	 * Test that a custom after-checkout destination becomes the block's
	 * after-success attributes.
	 */
	public function test_after_success_custom_url_rides_along() {
		$destination = home_url( '/welcome' );
		$attrs       = Modal_Checkout::build_url_triggered_button_attrs(
			'subscription',
			11,
			0,
			'',
			[
				'behavior'     => 'custom',
				'url'          => $destination,
				'button_label' => 'Get started',
			]
		);
		$this->assertSame( 'custom', $attrs['afterSuccessBehavior'] );
		$this->assertSame( $destination, $attrs['afterSuccessURL'] );
		$this->assertSame( 'Get started', $attrs['afterSuccessButtonLabel'] );
	}

	/**
	 * Test that a custom behavior with no destination is dropped: it would leave
	 * the reader on a continue button that goes nowhere.
	 */
	public function test_after_success_custom_without_a_url_is_dropped() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11, 0, '', [ 'behavior' => 'custom' ] );
		$this->assertArrayNotHasKey( 'afterSuccessBehavior', $attrs );
		$this->assertArrayNotHasKey( 'afterSuccessURL', $attrs );
	}

	/**
	 * Test that an off-site destination is refused. The thank-you screen assigns
	 * it to window.location.href once the reader closes the modal, so accepting
	 * an arbitrary host would be an open redirect fired right after a payment.
	 */
	public function test_after_success_url_must_be_on_this_site() {
		$off_site = Modal_Checkout::build_url_triggered_button_attrs(
			'subscription',
			11,
			0,
			'',
			[
				'behavior' => 'custom',
				'url'      => 'https://evil.example/phish',
			]
		);
		$this->assertArrayNotHasKey( 'afterSuccessBehavior', $off_site );
		$this->assertArrayNotHasKey( 'afterSuccessURL', $off_site );

		$on_site = Modal_Checkout::build_url_triggered_button_attrs(
			'subscription',
			11,
			0,
			'',
			[
				'behavior' => 'custom',
				'url'      => home_url( '/welcome' ),
			]
		);
		$this->assertSame( 'custom', $on_site['afterSuccessBehavior'] );
		$this->assertSame( home_url( '/welcome' ), $on_site['afterSuccessURL'] );
	}

	/**
	 * Test that `referrer` is not accepted: a reader arriving from an email or a
	 * QR code has no same-origin referrer and no history entry to return to.
	 */
	public function test_after_success_referrer_is_not_accepted() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs(
			'subscription',
			11,
			0,
			'',
			[
				'behavior' => 'referrer',
				'url'      => 'https://example.com/welcome',
			]
		);
		$this->assertArrayNotHasKey( 'afterSuccessBehavior', $attrs );
	}

	/**
	 * Test that the button label alone never fabricates an after-success config,
	 * and that no after-success input leaves the attributes clean.
	 */
	public function test_after_success_absent_by_default() {
		$attrs = Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11 );
		$this->assertArrayNotHasKey( 'afterSuccessBehavior', $attrs );

		$label_only = Modal_Checkout::build_url_triggered_button_attrs( 'subscription', 11, 0, '', [ 'button_label' => 'Continue' ] );
		$this->assertArrayNotHasKey( 'afterSuccessBehavior', $label_only );
		$this->assertArrayNotHasKey( 'afterSuccessButtonLabel', $label_only );
	}

	/**
	 * Test that nothing is rendered into the footer without a trigger.
	 */
	public function test_no_button_rendered_without_a_trigger() {
		ob_start();
		Modal_Checkout::render_url_triggered_block();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Test that the request reader resolves attributes from $_GET, so a test —
	 * or anything else that adjusts the request after PHP parses it — sees the
	 * same state production reads.
	 */
	public function test_request_attrs_are_read_from_the_request() {
		$GLOBALS['newspack_blocks_test_products'] = [
			11 => new WC_Product( 11, 'subscription', [], '10', 'Monthly', 0, 'publish' ),
		];
		$_GET = [
			'product_id' => '11',
			'coupon'     => 'SPRING20',
		];
		$attrs = Modal_Checkout::build_url_triggered_button_attrs_from_request();
		$this->assertSame( '11', $attrs['product'] );
		$this->assertSame( 'SPRING20', $attrs['coupon'] );
	}

	/**
	 * Test that an unpublished product is refused.
	 */
	public function test_request_attrs_refuse_an_unpublished_product() {
		$GLOBALS['newspack_blocks_test_products'] = [
			11 => new WC_Product( 11, 'subscription', [], '10', 'Monthly', 0, 'draft' ),
		];
		$_GET = [ 'product_id' => '11' ];
		$this->assertSame( [], Modal_Checkout::build_url_triggered_button_attrs_from_request() );
	}

	/**
	 * Test that a variation belonging to a different parent is refused.
	 */
	public function test_request_attrs_refuse_a_variation_of_another_parent() {
		$GLOBALS['newspack_blocks_test_products'] = [
			11 => new WC_Product( 11, 'variable-subscription', [ 22 ], '10', 'Sub', 0, 'publish' ),
			22 => new WC_Product( 22, 'subscription_variation', [], '10', 'Monthly', 99, 'publish' ),
		];
		$_GET = [
			'product_id'   => '11',
			'variation_id' => '22',
		];
		$this->assertSame( [], Modal_Checkout::build_url_triggered_button_attrs_from_request() );
	}

	/**
	 * Test that a variation of the requested parent is locked onto it.
	 */
	public function test_request_attrs_lock_a_variation_of_the_parent() {
		$GLOBALS['newspack_blocks_test_products'] = [
			11 => new WC_Product( 11, 'variable-subscription', [ 22 ], '10', 'Sub', 0, 'publish' ),
			22 => new WC_Product( 22, 'subscription_variation', [], '10', 'Monthly', 11, 'publish' ),
		];
		$_GET = [
			'product_id'   => '11',
			'variation_id' => '22',
		];
		$attrs = Modal_Checkout::build_url_triggered_button_attrs_from_request();
		$this->assertSame( '22', $attrs['variation'] );
		$this->assertTrue( $attrs['is_variable'] );
	}
}
