<?php
/**
 * Tests for the logo data the theme endpoint returns to Theme and Brand.
 *
 * @package Newspack\Tests
 */

use Newspack\Setup_Wizard;

/**
 * Setup wizard theme mods test case.
 *
 * @group setup-wizard
 * @covers \Newspack\Setup_Wizard::api_retrieve_theme_and_set_defaults
 */
class Setup_Wizard_Theme_Mods_Test extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();
		// Without the Newspack Theme there is no primary color to seed the header color from.
		set_theme_mod( 'header_color_hex', '#003da5' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_theme_mod( 'custom_logo' );
		remove_theme_mod( 'header_color_hex' );
		parent::tear_down();
	}

	/**
	 * Create an image attachment carrying the given metadata.
	 *
	 * @param array|null $metadata Attachment metadata, or null for none.
	 * @return int Attachment ID.
	 */
	private function create_logo( $metadata ) {
		$attachment_id = self::factory()->attachment->create_object(
			'logo.png',
			0,
			[ 'post_mime_type' => 'image/png' ]
		);
		if ( $metadata ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		return $attachment_id;
	}

	/**
	 * Fetch the theme mods the endpoint returns.
	 *
	 * @return array
	 */
	private function get_theme_mods() {
		$response = ( new Setup_Wizard() )->api_retrieve_theme_and_set_defaults();
		return $response->get_data()['theme_mods'];
	}

	/**
	 * The theme sizes the header logo from the original upload, so the preview needs those dimensions.
	 */
	public function test_logo_carries_the_original_dimensions() {
		$logo_id = $this->create_logo(
			[
				'width'  => 909,
				'height' => 160,
				'file'   => 'logo.png',
			]
		);
		set_theme_mod( 'custom_logo', $logo_id );

		$logo = $this->get_theme_mods()['custom_logo'];

		$this->assertSame( $logo_id, $logo['id'] );
		$this->assertSame( 909, $logo['width'] );
		$this->assertSame( 160, $logo['height'] );
	}

	/**
	 * A logo without dimensions, such as an SVG, leaves the preview to measure the image itself.
	 */
	public function test_logo_without_metadata_has_no_dimensions() {
		$logo_id = $this->create_logo( null );
		set_theme_mod( 'custom_logo', $logo_id );

		$logo = $this->get_theme_mods()['custom_logo'];

		$this->assertSame( $logo_id, $logo['id'] );
		$this->assertNull( $logo['width'] );
		$this->assertNull( $logo['height'] );
	}
}
