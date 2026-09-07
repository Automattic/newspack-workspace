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
				'public'       => true,
				'hierarchical' => true,
				'query_var'    => 'test_product_brand',
				// `hierarchical` and `with_front` are what make this fixture
				// behave like WooCommerce's product_brand: the first produces the
				// greedy `(.+?)` pattern that outranks ours, and the second is why
				// the two taxonomies do not collide under a fronted permalink
				// structure. A fixture without them tests a conflict that the
				// production taxonomy would not create.
				'rewrite'      => [
					'slug'       => 'brand',
					'with_front' => false,
				],
			]
		);

		$this->set_permalink_structure( '/%postname%/' );
		// Re-register after the structure is set. A taxonomy registered while
		// `permalink_structure` is empty never has its `rewrite` argument
		// normalized, so it gets no permastruct and `get_term_link()` returns the
		// query-var form — which is not the shape a live site has.
		Taxonomy::register_taxonomy();

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

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
		// WP::parse_request sets `request` to the path relative to home. The
		// resolver compares it against the term's own permalink, so a hand-built
		// request has to carry it or the resolver correctly declines to act.
		$wp->request = 'brand/' . $brand->slug;

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
				'public'       => true,
				'hierarchical' => true,
				'query_var'    => 'test_product_brand',
				// `hierarchical` and `with_front` are what make this fixture
				// behave like WooCommerce's product_brand: the first produces the
				// greedy `(.+?)` pattern that outranks ours, and the second is why
				// the two taxonomies do not collide under a fronted permalink
				// structure. A fixture without them tests a conflict that the
				// production taxonomy would not create.
				'rewrite'      => [
					'slug'       => 'brand',
					'with_front' => false,
				],
			]
		);

		// Simulate a request for a slug that is NOT a brand term.
		global $wp;
		$wp->matched_query = 'test_product_brand=nike';
		$wp->query_vars    = [ 'test_product_brand' => 'nike' ];
		$wp->request       = 'brand/nike';

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $wp->query_vars, 'Brand query var should not be set for non-brand slugs.' );
		$this->assertSame( 'nike', $wp->query_vars['test_product_brand'], 'Conflicting query var should remain for non-brand slugs.' );
	}

	/**
	 * Tests that a taxonomy which captured the request but rewrites under some
	 * other slug is left alone.
	 *
	 * Both guards decline here and the slug pre-filter happens to run first, so
	 * this covers the behaviour rather than isolating one check. The case that
	 * isolates path ownership is the fronted-permastruct test below.
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

		// Give the brand the same slug the other taxonomy captured, so a check
		// that looked only at the slug would treat this as a brand conflict.
		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		global $wp;
		$wp->matched_query = 'test_other_tax=' . $brand->slug;
		$wp->query_vars    = [ 'test_other_tax' => $brand->slug ];
		$wp->request       = 'topic/' . $brand->slug;

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
				'public'       => true,
				'hierarchical' => true,
				'query_var'    => 'test_product_brand',
				// `hierarchical` and `with_front` are what make this fixture
				// behave like WooCommerce's product_brand: the first produces the
				// greedy `(.+?)` pattern that outranks ours, and the second is why
				// the two taxonomies do not collide under a fronted permalink
				// structure. A fixture without them tests a conflict that the
				// production taxonomy would not create.
				'rewrite'      => [
					'slug'       => 'brand',
					'with_front' => false,
				],
			]
		);

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta( $brand->term_id, Url_Meta::get_key(), 'yes' );

		$this->set_permalink_structure( '/%postname%/' );
		Taxonomy::register_taxonomy();

		// Simulate /brand/{slug}/ captured by the conflicting taxonomy. The
		// request has to be present, or the resolver declines for want of a path
		// to compare and the test proves nothing about homepage mode.
		global $wp;
		$wp->matched_query = 'test_product_brand=' . $brand->slug;
		$wp->query_vars    = [ 'test_product_brand' => $brand->slug ];
		$wp->request       = 'brand/' . $brand->slug;

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		// The brand has _custom_url=yes, so it should NOT be claimed at /brand/.
		$this->assertArrayNotHasKey( Taxonomy::SLUG, $wp->query_vars, 'Homepage-mode brand should not be claimed at /brand/ path.' );
		$this->assertSame( $brand->slug, $wp->query_vars['test_product_brand'], 'Conflicting query var should remain for homepage-mode brands.' );
	}

	/**
	 * Tests that a genuine WooCommerce URL is left alone when a fronted permalink
	 * structure puts the two taxonomies on different paths.
	 *
	 * We register with no `rewrite` argument and so inherit `with_front`, while
	 * WooCommerce passes `with_front => false`. Under "/blog/%postname%/" our
	 * brands live at /blog/brand/{slug}/ and WooCommerce's at /brand/{slug}/, so
	 * a shared slug is not a collision. Claiming it here would 301 a shopper off
	 * the store archive onto our path.
	 */
	public function test_parse_request_leaves_woocommerce_url_alone_under_a_fronted_permastruct() {
		$this->set_permalink_structure( '/blog/%postname%/' );
		register_taxonomy(
			'test_product_brand',
			'product',
			[
				'public'       => true,
				'hierarchical' => true,
				'query_var'    => 'test_product_brand',
				'rewrite'      => [
					'slug'       => 'brand',
					'with_front' => false,
				],
			]
		);
		Taxonomy::register_taxonomy();

		// Same slug on both sides — the case a name-only check would misread.
		$brand = $this->factory->term->create_and_get(
			[
				'taxonomy' => Taxonomy::SLUG,
				'slug'     => 'nike',
			]
		);
		$this->assertStringContainsString(
			'/blog/brand/nike',
			get_term_link( $brand ),
			'Precondition: with_front has to put our brand under the permalink front.'
		);

		global $wp;
		$wp->matched_query = 'test_product_brand=nike';
		$wp->query_vars    = [ 'test_product_brand' => 'nike' ];
		$wp->request       = 'brand/nike';

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $wp->query_vars, 'A URL that is not the brand permalink must not be claimed.' );
		$this->assertSame( 'nike', $wp->query_vars['test_product_brand'], 'The WooCommerce query var must survive.' );
	}
}
