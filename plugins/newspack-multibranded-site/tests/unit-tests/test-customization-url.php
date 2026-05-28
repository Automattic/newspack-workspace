<?php
/**
 * Class TestUrlCustomization
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Url as Url_Meta;

/**
 * Test the parse_request filter.
 */
class TestUrlCustomization extends WP_UnitTestCase {

	/**
	 * Tests get current brand and determine current brand methods
	 */
	public function test_parse_request() {
		$term_without_custom_url = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		$term_with_custom_url    = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta( $term_with_custom_url->term_id, Url_Meta::get_key(), 'yes' );

		$this->set_permalink_structure( '/%postname%/' );

		$this->go_to( home_url( $term_without_custom_url->slug ) );
		$this->assertFalse( is_home() );
		$this->assertTrue( is_404() );

		$this->go_to( home_url( $term_with_custom_url->slug ) );
		$this->assertFalse( is_home() );
		$this->assertTrue( is_tax() );
		$this->assertSame( $term_with_custom_url->term_id, get_queried_object_id() );
	}
}
