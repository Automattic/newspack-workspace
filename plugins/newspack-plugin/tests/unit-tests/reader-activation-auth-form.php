<?php
/**
 * Tests the markup of the reader auth form.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;

/**
 * The inline My Account form and the footer modal can print the auth form on
 * the same page, so each copy needs IDs of its own.
 */
class Newspack_Test_RA_Auth_Form extends WP_UnitTestCase {

	/**
	 * Render the auth form.
	 *
	 * @return DOMXPath The rendered form.
	 */
	private function render_form(): DOMXPath {
		ob_start();
		Reader_Activation::render_auth_form( false );
		$html = ob_get_clean();

		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		return new DOMXPath( $document );
	}

	/**
	 * Get the ID of the input with the given name, and assert its label points to it.
	 *
	 * @param DOMXPath $xpath The rendered form.
	 * @param string   $name  The input name.
	 * @return string The input ID.
	 */
	private function get_labelled_input_id( DOMXPath $xpath, string $name ): string {
		$input = $xpath->query( '//input[@name="' . $name . '"]' )->item( 0 );
		$this->assertNotNull( $input, "Missing input $name." );
		$id = $input->getAttribute( 'id' );
		$this->assertNotSame( '', $id );
		$this->assertSame( 1, $xpath->query( '//label[@for="' . $id . '"]' )->length, "Label for $name does not point to it." );
		return $id;
	}

	/**
	 * Each render gets its own IDs, and the labels and descriptions follow them.
	 */
	public function test_each_form_gets_its_own_ids() {
		$ids = [];
		foreach ( [ $this->render_form(), $this->render_form() ] as $xpath ) {
			foreach ( [ 'npe', 'otp_code', 'password' ] as $name ) {
				$ids[] = $this->get_labelled_input_id( $xpath, $name );
			}
			foreach ( [ 'otp_code', 'password' ] as $name ) {
				$described_by = $xpath->query( '//input[@name="' . $name . '"]' )->item( 0 )->getAttribute( 'aria-describedby' );
				$this->assertSame( 1, $xpath->query( '//*[@id="' . $described_by . '"]' )->length, "Description for $name is missing." );
				$ids[] = $described_by;
			}
		}
		$this->assertCount( 10, array_unique( $ids ) );
	}

	/**
	 * The code input gets its length from the script, so a pasted code with spaces or a dash keeps every digit.
	 */
	public function test_code_input_has_a_length_but_no_maxlength() {
		$input = $this->render_form()->query( '//input[@name="otp_code"]' )->item( 0 );
		$this->assertSame( (string) \Newspack\Magic_Link::OTP_LENGTH, $input->getAttribute( 'data-otp-length' ) );
		$this->assertFalse( $input->hasAttribute( 'maxlength' ) );
	}
}
