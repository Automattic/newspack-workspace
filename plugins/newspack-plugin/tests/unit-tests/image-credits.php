<?php
/**
 * Tests the Newspack_Image_Credits class.
 *
 * @package Newspack
 */

use Newspack\Newspack_Image_Credits;

/**
 * Class Test_Image_Credits
 */
class Test_Image_Credits extends WP_UnitTestCase {
	/**
	 * Create an attachment with credit meta.
	 *
	 * @param string $credit_url Credit URL meta value.
	 * @return int Attachment ID.
	 */
	private function create_attachment_with_credit( $credit_url ) {
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		update_post_meta( $attachment_id, '_media_credit', 'Test Photographer' );
		update_post_meta( $attachment_id, '_media_credit_url', $credit_url );
		return $attachment_id;
	}

	/**
	 * A credit URL stored without a protocol renders as an absolute link.
	 */
	public function test_credit_url_without_protocol_renders_absolute_link() {
		$attachment_id = $this->create_attachment_with_credit( 'images.com' );
		$credit_string = Newspack_Image_Credits::get_media_credit_string( $attachment_id );
		$this->assertStringContainsString( 'href="http://images.com"', $credit_string );
	}

	/**
	 * A credit URL stored with a protocol is preserved as-is.
	 */
	public function test_credit_url_with_protocol_is_preserved() {
		$attachment_id = $this->create_attachment_with_credit( 'https://images.com' );
		$credit_string = Newspack_Image_Credits::get_media_credit_string( $attachment_id );
		$this->assertStringContainsString( 'href="https://images.com"', $credit_string );
	}

	/**
	 * A credit URL with a disallowed scheme is never rendered as a link.
	 */
	public function test_credit_url_with_disallowed_scheme_is_not_linked() {
		$attachment_id = $this->create_attachment_with_credit( 'javascript:alert(1)' );
		$credit_string = Newspack_Image_Credits::get_media_credit_string( $attachment_id );
		$this->assertStringNotContainsString( 'javascript:', $credit_string );
		$this->assertStringNotContainsString( '<a ', $credit_string );
		$this->assertStringContainsString( 'Test Photographer', $credit_string );
	}

	/**
	 * A protocol-relative credit URL is preserved as-is.
	 */
	public function test_credit_url_protocol_relative_is_preserved() {
		$attachment_id = $this->create_attachment_with_credit( '//images.com' );
		$credit_string = Newspack_Image_Credits::get_media_credit_string( $attachment_id );
		$this->assertStringContainsString( 'href="//images.com"', $credit_string );
	}

	/**
	 * The media-modal edit field round-trips the stored value untouched.
	 */
	public function test_media_modal_field_preserves_stored_value() {
		$attachment_id = $this->create_attachment_with_credit( 'images.com' );
		$fields        = Newspack_Image_Credits::add_media_credit( [], get_post( $attachment_id ) );
		$this->assertSame( 'images.com', $fields['media_credit_url']['value'] );
	}
}
