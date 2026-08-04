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
	 * Test that the picker-opening path shares that same field list rather than
	 * keeping its own copy — a second list is how a field gets added for one
	 * path and silently dropped by the other.
	 */
	public function test_modal_reuses_the_shared_picker_field_list() {
		$modal = file_get_contents( \NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/modal-checkout/modal.js' );
		$this->assertStringContainsString( 'PICKER_CONTEXT_FIELDS.forEach', $modal );
		// The old inline duplicate started with this literal.
		$this->assertStringNotContainsString( "\t\t\t\t\t\t'after_success_behavior',\n", $modal );
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
	 * Test the destination allowlist for unsigned values, including the
	 * near-miss host that a naive prefix check would wave through.
	 */
	public function test_sanitize_after_success_url_without_a_signature() {
		$this->assertSame( home_url( '/welcome' ), Modal_Checkout::sanitize_after_success_url( home_url( '/welcome' ) ) );
		$this->assertSame( '/welcome', Modal_Checkout::sanitize_after_success_url( '/welcome' ) );
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( 'https://evil.example/phish' ) );
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.evil.example/x' ) );
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( '' ) );

		// Sites that route every reader off-site can still opt in the host.
		$allow = function ( $hosts ) {
			$hosts[] = 'partner.example';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allow );
		$this->assertSame( 'https://partner.example/welcome', Modal_Checkout::sanitize_after_success_url( 'https://partner.example/welcome' ) );
		remove_filter( 'allowed_redirect_hosts', $allow );
	}

	/**
	 * Test that a signature authorizes an off-site destination: a block's URL is
	 * authored in the editor, so it may point anywhere.
	 */
	public function test_a_signature_authorizes_an_off_site_destination() {
		$url       = 'https://partner.example/welcome';
		$signature = Modal_Checkout::sign_after_success_url( $url );
		$this->assertNotEmpty( $signature );
		$this->assertSame( $url, Modal_Checkout::sanitize_after_success_url( $url, $signature ) );
	}

	/**
	 * Test that a signature cannot be forged, replayed onto another URL, or
	 * omitted — the three ways an attacker would try to buy a redirect.
	 */
	public function test_an_invalid_signature_does_not_authorize() {
		$url = 'https://evil.example/phish';
		// No signature.
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( $url ) );
		// Made-up signature.
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( $url, 'not-a-signature' ) );
		// A signature that is valid, but for a different destination.
		$other = Modal_Checkout::sign_after_success_url( 'https://partner.example/welcome' );
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( $url, $other ) );
		// Tampered signature.
		$this->assertSame( '', Modal_Checkout::sanitize_after_success_url( $url, substr( Modal_Checkout::sign_after_success_url( $url ), 0, -1 ) . 'x' ) );
	}

	/**
	 * Test that signatures are per-destination, so one cannot be lifted from a
	 * published page and pointed elsewhere.
	 */
	public function test_signatures_are_bound_to_their_destination() {
		$this->assertNotSame(
			Modal_Checkout::sign_after_success_url( 'https://partner.example/a' ),
			Modal_Checkout::sign_after_success_url( 'https://partner.example/b' )
		);
		$this->assertSame( '', Modal_Checkout::sign_after_success_url( '' ) );
	}

	/**
	 * Test that the signature input is emitted for a destination and omitted
	 * when there is none.
	 */
	public function test_signature_input_markup() {
		$markup = Modal_Checkout::after_success_signature_input( 'https://partner.example/welcome' );
		$this->assertStringContainsString( 'name="' . Modal_Checkout::AFTER_SUCCESS_SIGNATURE_ARG . '"', $markup );
		$this->assertStringContainsString( Modal_Checkout::sign_after_success_url( 'https://partner.example/welcome' ), $markup );
		$this->assertSame( '', Modal_Checkout::after_success_signature_input( '' ) );
	}

	/**
	 * Test that the picker submission carries the signature: without it a reader
	 * choosing a variation would lose an off-site destination.
	 */
	public function test_picker_context_fields_include_the_signature() {
		$trigger = file_get_contents( \NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/modal-checkout/checkout-button-trigger.js' );
		$fields  = substr( $trigger, strpos( $trigger, 'PICKER_CONTEXT_FIELDS = [' ) );
		$fields  = substr( $fields, 0, strpos( $fields, '];' ) );
		$this->assertStringContainsString( "'" . Modal_Checkout::AFTER_SUCCESS_SIGNATURE_ARG . "'", $fields );
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
		Modal_Checkout::render_url_triggered_button();
		$this->assertSame( '', ob_get_clean() );
	}
}
