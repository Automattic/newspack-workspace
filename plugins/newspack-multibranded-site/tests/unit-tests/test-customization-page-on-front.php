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
	 * Creates a brand with a dedicated page set as its front page.
	 *
	 * @param string $post_content The content of the front page.
	 * @return array{0: WP_Term, 1: WP_Post} The brand term and the front page.
	 */
	private function create_brand_with_page_on_front( $post_content = 'Front page content.' ) {
		$brand = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		$page = $this->factory->post->create_and_get(
			array(
				'post_title'   => 'Brand Front Page',
				'post_type'    => 'page',
				'post_content' => $post_content,
			)
		);

		add_term_meta( $brand->term_id, Show_Page_On_Front_Meta::get_key(), $page->ID );

		return array( $brand, $page );
	}

	/**
	 * The rewritten query is a page, with no leftover archive flags (NPPM-2993 root cause).
	 */
	public function test_brand_front_page_is_not_an_archive() {
		list( $brand ) = $this->create_brand_with_page_on_front();

		$this->go_to( get_term_link( $brand ) );

		$this->assertTrue( is_page(), 'Brand front page should be a page.' );
		$this->assertTrue( is_singular(), 'Brand front page should be singular.' );
		$this->assertFalse( is_archive(), 'Brand front page should not be an archive.' );
		$this->assertFalse( is_tax(), 'Brand front page should not be a taxonomy archive.' );
	}

	/**
	 * A the_content filter gated on is_archive() must not replace the page (NPPM-2993).
	 */
	public function test_brand_front_page_content_is_not_replaced_by_archive_filter() {
		list( $brand ) = $this->create_brand_with_page_on_front( 'Unique block content.' );

		// Mirrors Jetpack's jetpack_the_content_to_the_excerpt() gate.
		add_filter(
			'the_content',
			function ( $content ) {
				if ( is_home() || is_archive() ) {
					return '<p>REPLACED BY EXCERPT</p>';
				}
				return $content;
			}
		);

		$this->go_to( get_term_link( $brand ) );

		$this->assertTrue( have_posts(), 'The brand front page should be queried.' );
		the_post();
		$rendered = apply_filters( 'the_content', get_the_content() );

		$this->assertStringContainsString( 'Unique block content.', $rendered );
		$this->assertStringNotContainsString( 'REPLACED BY EXCERPT', $rendered );
	}

	/**
	 * The brand front page gets front page body classes, not archive ones.
	 */
	public function test_brand_front_page_body_classes() {
		list( $brand, $page ) = $this->create_brand_with_page_on_front();

		$this->go_to( get_term_link( $brand ) );
		$classes = get_body_class();

		$this->assertContains( 'newspack-front-page', $classes );
		$this->assertContains( 'page-template-default', $classes );
		$this->assertContains( 'page-id-' . $page->ID, $classes );
		$this->assertNotContains( 'archive', $classes );
		$this->assertNotContains( 'page-id-' . $brand->term_id, $classes );
	}

	/**
	 * A secondary query must not clear the main query's filtered state.
	 */
	public function test_secondary_query_does_not_reset_filtered_state() {
		list( $brand ) = $this->create_brand_with_page_on_front();

		$this->go_to( get_term_link( $brand ) );
		$this->assertTrue( Show_Page_On_Front::is_filtered() );

		new WP_Query( array( 'post_type' => 'post' ) );

		$this->assertTrue( Show_Page_On_Front::is_filtered(), 'A secondary query should not reset the filtered state.' );
	}

	/**
	 * The brand URL stays canonical and does not redirect to the page permalink.
	 */
	public function test_brand_front_page_url_does_not_redirect() {
		// Without a permalink structure redirect_canonical() bails early and asserts nothing.
		$this->set_permalink_structure( '/%postname%/' );
		list( $brand ) = $this->create_brand_with_page_on_front();

		$brand_url = get_term_link( $brand );
		$this->go_to( $brand_url );

		$this->assertEmpty( redirect_canonical( $brand_url, false ), 'The brand URL should not redirect.' );
	}

	/**
	 * The page's own URL canonicalizes to the brand URL (and proves the test above is not vacuous).
	 */
	public function test_brand_front_page_permalink_canonicalizes_to_brand_url() {
		$this->set_permalink_structure( '/%postname%/' );
		list( $brand, $page ) = $this->create_brand_with_page_on_front();

		$ugly_url = home_url( '/?page_id=' . $page->ID );
		$this->go_to( $ugly_url );

		$this->assertSame( get_term_link( $brand ), redirect_canonical( $ugly_url, false ) );
	}

	/**
	 * An ordinary page must not get the front page body class.
	 *
	 * Makes the is_filtered() guard in body_class() load-bearing: is_page() alone is
	 * true on any page, so without the guard this class would leak onto every page.
	 */
	public function test_ordinary_page_does_not_get_front_page_class() {
		$page = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Ordinary Page',
				'post_type'  => 'page',
			)
		);

		$this->go_to( get_permalink( $page ) );

		$this->assertNotContains( 'newspack-front-page', get_body_class() );
	}

	/**
	 * The displayed brand wins over a brand assigned to the cover page.
	 *
	 * With the query flags fixed, get_queried_object() returns the cover page, so the
	 * resolution cascade would otherwise pick up a brand assigned to that page. The
	 * displayed brand is authoritative.
	 */
	public function test_current_brand_prefers_the_front_page_brand() {
		list( $brand_a, $page ) = $this->create_brand_with_page_on_front();

		$brand_b = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		wp_set_object_terms( $page->ID, array( $brand_b->term_id ), Taxonomy::SLUG );

		$this->go_to( get_term_link( $brand_a ) );

		$current = Taxonomy::get_current();
		$this->assertInstanceOf( 'WP_Term', $current );
		$this->assertSame( $brand_a->term_id, $current->term_id );
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
