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
	 * Clean up the conflicting taxonomy after each test so it never leaks.
	 */
	public function tearDown(): void {
		foreach ( [ 'test_product_brand', 'test_other_tax' ] as $fixture_taxonomy ) {
			if ( taxonomy_exists( $fixture_taxonomy ) ) {
				unregister_taxonomy( $fixture_taxonomy );
			}
		}
		parent::tearDown();
	}

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

	/**
	 * Tests that the default URL mode resolves when a conflicting taxonomy has
	 * captured the rewrite rule for /brand/{slug}/.
	 */
	public function test_parse_request_resolves_rewrite_conflict() {
		// Register a conflicting taxonomy with "brand" as its rewrite slug,
		// simulating WooCommerce's product_brand taxonomy.
		register_taxonomy(
			'test_product_brand',
			'product',
			[
				'public'    => true,
				'rewrite'   => [ 'slug' => 'brand' ],
				'query_var' => 'test_product_brand',
			]
		);

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		$this->set_permalink_structure( '/%postname%/' );

		// Simulate what happens when /brand/{slug}/ is matched by the
		// conflicting taxonomy's rewrite rule: WP sets the conflicting
		// taxonomy's query var to the slug.
		global $wp;
		$wp->matched_query = 'test_product_brand=' . $brand->slug;
		// `paged` rides along to prove the resolver clears only the conflicting
		// var. A blanket loop over $wp->query_vars would satisfy every other
		// assertion here while silently dropping pagination and any other var
		// WordPress had already parsed.
		$wp->query_vars = [
			'test_product_brand' => $brand->slug,
			'paged'              => 2,
		];

		// Run parse_request — this should detect the conflict and re-route.
		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		$this->assertArrayHasKey( Taxonomy::SLUG, $wp->query_vars, 'Brand query var should be set.' );
		$this->assertSame( $brand->slug, $wp->query_vars[ Taxonomy::SLUG ] );
		$this->assertArrayNotHasKey( 'test_product_brand', $wp->query_vars, 'Conflicting query var should be removed.' );
		$this->assertSame( 2, $wp->query_vars['paged'], 'Unrelated query vars must survive the re-route.' );
	}

	/**
	 * Tests that the conflict resolver does not interfere when the slug does
	 * not match any brand term.
	 */
	public function test_parse_request_no_false_positive_on_conflict() {
		register_taxonomy(
			'test_product_brand',
			'product',
			[
				'public'    => true,
				'rewrite'   => [ 'slug' => 'brand' ],
				'query_var' => 'test_product_brand',
			]
		);

		$this->set_permalink_structure( '/%postname%/' );

		// Simulate a request for a slug that is NOT a brand term.
		global $wp;
		$wp->matched_query = 'test_product_brand=nike';
		$wp->query_vars    = [ 'test_product_brand' => 'nike' ];

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $wp->query_vars, 'Brand query var should not be set for non-brand slugs.' );
		$this->assertSame( 'nike', $wp->query_vars['test_product_brand'], 'Conflicting query var should remain for non-brand slugs.' );
	}

	/**
	 * Tests that a taxonomy which captured the request but rewrites under some
	 * other slug is left alone. Only a collision on /brand/ should reroute.
	 */
	public function test_parse_request_ignores_taxonomy_rewriting_under_another_slug() {
		register_taxonomy(
			'test_other_tax',
			'post',
			[
				'public'    => true,
				'rewrite'   => [ 'slug' => 'topic' ],
				'query_var' => 'test_other_tax',
			]
		);

		// Give the brand the same slug the other taxonomy captured, so the
		// rewrite-slug check is the only thing keeping the resolver out. Without
		// it, every public taxonomy with a query var becomes a brand conflict.
		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		$this->set_permalink_structure( '/%postname%/' );

		global $wp;
		$wp->matched_query = 'test_other_tax=' . $brand->slug;
		$wp->query_vars    = [ 'test_other_tax' => $brand->slug ];

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $wp->query_vars, 'Only a /brand/ collision should reroute.' );
		$this->assertSame( $brand->slug, $wp->query_vars['test_other_tax'], 'The other taxonomy query var should be untouched.' );
	}

	/**
	 * Tests that homepage-mode brands (_custom_url=yes) are not claimed at
	 * /brand/{slug}/ — they live at the site root instead.
	 */
	public function test_parse_request_skips_homepage_mode_brands() {
		register_taxonomy(
			'test_product_brand',
			'product',
			[
				'public'    => true,
				'rewrite'   => [ 'slug' => 'brand' ],
				'query_var' => 'test_product_brand',
			]
		);

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta( $brand->term_id, Url_Meta::get_key(), 'yes' );

		$this->set_permalink_structure( '/%postname%/' );

		// Simulate /brand/{slug}/ captured by the conflicting taxonomy.
		global $wp;
		$wp->matched_query = 'test_product_brand=' . $brand->slug;
		$wp->query_vars    = [ 'test_product_brand' => $brand->slug ];

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		// The brand has _custom_url=yes, so it should NOT be claimed at /brand/.
		$this->assertArrayNotHasKey( Taxonomy::SLUG, $wp->query_vars, 'Homepage-mode brand should not be claimed at /brand/ path.' );
		$this->assertSame( $brand->slug, $wp->query_vars['test_product_brand'], 'Conflicting query var should remain for homepage-mode brands.' );
	}
}
