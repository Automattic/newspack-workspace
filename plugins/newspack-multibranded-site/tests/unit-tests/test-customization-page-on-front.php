<?php
/**
 * Class TestCustomizationPageOnFront
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Show_Page_On_Front as Show_Page_On_Front_Meta;
use Newspack_Multibranded_Site\Customizations\Show_Page_On_Front;

/**
 * Test the Page on Front Customization.
 */
class TestCustomizationPageOnFront extends WP_UnitTestCase {

	/**
	 * Tests the hook that populates the front pages option
	 */
	public function test_options_hook() {
		$brand_with_page_on_front = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		add_term_meta( $brand_with_page_on_front->term_id, Show_Page_On_Front_Meta::get_key(), 123 );
		$this->assertSame( $brand_with_page_on_front->term_id, Show_Page_On_Front::get_brand_page_is_cover_for( 123 ) );

		add_term_meta( $brand_with_page_on_front->term_id, Show_Page_On_Front_Meta::get_key(), 456 );
		$this->assertSame( $brand_with_page_on_front->term_id, Show_Page_On_Front::get_brand_page_is_cover_for( 456 ) );
		$this->assertNull( Show_Page_On_Front::get_brand_page_is_cover_for( 123 ) );

		update_term_meta( $brand_with_page_on_front->term_id, Show_Page_On_Front_Meta::get_key(), 0 );
		$this->assertNull( Show_Page_On_Front::get_brand_page_is_cover_for( 123 ) );
		$this->assertNull( Show_Page_On_Front::get_brand_page_is_cover_for( 456 ) );
	}

	/**
	 * Ensure that front page filter is not applied when visiting the feed for the brand
	 */
	public function test_rss_feed_intact() {
		$brand_with_page_on_front = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		$page2 = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Page 2',
				'post_type'  => 'page',
			)
		);
		add_term_meta( $brand_with_page_on_front->term_id, Show_Page_On_Front_Meta::get_key(), $page2->ID );

		$this->go_to( get_term_link( $brand_with_page_on_front ) );
		$this->assertTrue( Show_Page_On_Front::is_filtered() );

		$this->go_to( get_term_link( $brand_with_page_on_front ) . '&feed=rss' );
		$this->assertFalse( Show_Page_On_Front::is_filtered() );
	}
}
