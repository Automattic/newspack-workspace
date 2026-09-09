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
	 * Rewrite state as it stood before the test, to put back afterwards.
	 *
	 * @var array
	 */
	private $rewrite_snapshot = [];

	/**
	 * Capture the rewrite state these tests rewrite.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rewrite;
		$this->rewrite_snapshot = [
			'permalink_structure' => get_option( 'permalink_structure' ),
			'extra_permastructs'  => $wp_rewrite->extra_permastructs,
			'extra_rules_top'     => $wp_rewrite->extra_rules_top,
		];
	}

	/**
	 * Put back everything these tests change process-wide.
	 */
	public function tear_down() {
		foreach ( [ 'test_product_brand', 'test_other_tax' ] as $fixture_taxonomy ) {
			if ( taxonomy_exists( $fixture_taxonomy ) ) {
				unregister_taxonomy( $fixture_taxonomy );
			}
		}

		// The framework's own permalink and rewrite resets sit behind
		// WP_RUN_CORE_TESTS, so nothing puts these back for a plugin suite. Two
		// separate leaks matter: the fronted-permastruct test below would
		// otherwise leave every later class on "/blog/%postname%/", and
		// `extra_rules_top` is never cleared by core at all — `rewrite_rules()`
		// merges onto whatever is already there, so rules generated first stay
		// first however the permastructs are ordered afterwards.
		global $wp_rewrite;
		$wp_rewrite->extra_permastructs = $this->rewrite_snapshot['extra_permastructs'];
		$wp_rewrite->extra_rules_top    = $this->rewrite_snapshot['extra_rules_top'];
		$this->set_permalink_structure( $this->rewrite_snapshot['permalink_structure'] );

		parent::tear_down();
	}

	/**
	 * Register a stand-in for WooCommerce's product_brand taxonomy.
	 *
	 * `hierarchical` and `with_front` are what make this behave like the real
	 * registration: the first produces the greedy `(.+?)` pattern that outranks
	 * ours, and the second is why the two taxonomies do not collide under a
	 * fronted permalink structure. A fixture without them tests a conflict the
	 * production taxonomy would not create.
	 */
	private function register_conflicting_taxonomy() {
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
	}

	/**
	 * Set the permalink structure and re-register our taxonomy under it.
	 *
	 * A taxonomy registered while `permalink_structure` is empty never has its
	 * `rewrite` argument normalized, so it gets no permastruct and
	 * `get_term_link()` returns the query-var form — which is not the shape a
	 * live site has, and which makes every assertion about paths vacuous.
	 *
	 * @param string $structure The permalink structure to set.
	 */
	private function set_permalinks_and_reregister( $structure ) {
		$this->set_permalink_structure( $structure );
		Taxonomy::register_taxonomy();
	}

	/**
	 * Run the resolver over a hand-built request and return the resulting vars.
	 *
	 * @param array  $query_vars Query vars as WordPress would have parsed them.
	 * @param string $request    Path relative to home, as `WP::parse_request` sets it.
	 * @return array The query vars the resolver left behind.
	 */
	private function run_resolver( array $query_vars, $request ) {
		global $wp;
		$wp->matched_query = http_build_query( $query_vars );
		$wp->query_vars    = $query_vars;
		$wp->request       = $request;

		Newspack_Multibranded_Site\Customizations\Url::parse_request( $wp );

		return $wp->query_vars;
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
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		// `paged` rides along to prove the resolver clears only the conflicting
		// var. A blanket loop over $wp->query_vars would satisfy every other
		// assertion here while silently dropping pagination and any other var
		// WordPress had already parsed.
		$vars = $this->run_resolver(
			[
				'test_product_brand' => $brand->slug,
				'paged'              => 2,
			],
			'brand/' . $brand->slug
		);

		$this->assertArrayHasKey( Taxonomy::SLUG, $vars, 'Brand query var should be set.' );
		$this->assertSame( $brand->slug, $vars[ Taxonomy::SLUG ] );
		$this->assertArrayNotHasKey( 'test_product_brand', $vars, 'Conflicting query var should be removed.' );
		$this->assertSame( 2, $vars['paged'], 'Unrelated query vars must survive the re-route.' );
	}

	/**
	 * Tests the paths that hang off the archive root: /page/N, /feed and /embed.
	 *
	 * A term's permalink carries none of those suffixes, so an equality test
	 * against it declines every one of them and leaves the URL with the
	 * conflicting taxonomy — which is how a brand archive past page one ends up
	 * linking to an "Older posts" that 404s.
	 *
	 * @dataProvider archive_subpath_provider
	 * @param string $suffix     Appended to the term's own path.
	 * @param array  $extra_vars Vars the matching rewrite rule would also set.
	 */
	public function test_parse_request_resolves_subpaths_of_the_brand_archive( $suffix, array $extra_vars ) {
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		$vars = $this->run_resolver(
			array_merge( [ 'test_product_brand' => $brand->slug ], $extra_vars ),
			'brand/' . $brand->slug . $suffix
		);

		$this->assertArrayHasKey( Taxonomy::SLUG, $vars, "Brand query var should be set for /{$suffix}." );
		$this->assertSame( $brand->slug, $vars[ Taxonomy::SLUG ] );
		$this->assertArrayNotHasKey( 'test_product_brand', $vars, "Conflicting query var should be removed for /{$suffix}." );
	}

	/**
	 * The suffixes a taxonomy's rewrite rules generate beneath the archive root.
	 *
	 * @return array
	 */
	public function archive_subpath_provider() {
		return [
			'paged'      => [ '/page/2', [ 'paged' => 2 ] ],
			'feed'       => [ '/feed', [ 'feed' => 'feed' ] ],
			'typed feed' => [ '/feed/atom', [ 'feed' => 'atom' ] ],
			'embed'      => [ '/embed', [ 'embed' => true ] ],
		];
	}

	/**
	 * Tests that a sibling slug sharing a prefix is not swept up by the subpath
	 * handling above.
	 */
	public function test_parse_request_does_not_claim_a_sibling_sharing_a_prefix() {
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		$brand = $this->factory->term->create_and_get(
			[
				'taxonomy' => Taxonomy::SLUG,
				'slug'     => 'lifestyle',
			]
		);

		$vars = $this->run_resolver(
			[ 'test_product_brand' => 'lifestyle-weekly' ],
			'brand/lifestyle-weekly'
		);

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $vars, 'A longer slug sharing a prefix is a different URL.' );
		$this->assertSame( 'lifestyle-weekly', $vars['test_product_brand'], 'The conflicting query var must survive.' );

		// Control: the resolver is live under this exact fixture.
		$vars = $this->run_resolver( [ 'test_product_brand' => $brand->slug ], 'brand/' . $brand->slug );
		$this->assertSame( $brand->slug, $vars[ Taxonomy::SLUG ], 'The brand’s own path is still claimed.' );
	}

	/**
	 * Tests that a query var carrying something other than a usable slug is
	 * declined rather than passed on to the term lookup.
	 *
	 * Taxonomy query vars are public, so `/?test_product_brand[]=1` is an
	 * unauthenticated request that reaches this code. `get_term_by()` casts to
	 * string, which warns on PHP 8 when handed an array.
	 *
	 * @dataProvider unusable_query_var_provider
	 * @param mixed $value What the query var holds.
	 */
	public function test_parse_request_declines_an_unusable_query_var( $value ) {
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		$vars = $this->run_resolver( [ 'test_product_brand' => $value ], 'brand/anything' );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $vars, 'Nothing should be claimed from an unusable query var.' );
	}

	/**
	 * Query-var values that are not a slug the term lookup can take.
	 *
	 * @return array
	 */
	public function unusable_query_var_provider() {
		return [
			'array'        => [ [ '1' ] ],
			'empty string' => [ '' ],
		];
	}

	/**
	 * Tests that the conflict resolver does not interfere when the slug does
	 * not match any brand term.
	 */
	public function test_parse_request_no_false_positive_on_conflict() {
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		$vars = $this->run_resolver( [ 'test_product_brand' => 'nike' ], 'brand/nike' );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $vars, 'Brand query var should not be set for non-brand slugs.' );
		$this->assertSame( 'nike', $vars['test_product_brand'], 'Conflicting query var should remain for non-brand slugs.' );

		// Control: the same fixture, with a slug that IS a brand, is claimed —
		// so the decline above is the term lookup deciding, not a dead resolver.
		$vars = $this->run_resolver( [ 'test_product_brand' => $brand->slug ], 'brand/' . $brand->slug );
		$this->assertSame( $brand->slug, $vars[ Taxonomy::SLUG ], 'A real brand slug is still claimed.' );
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
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		// Give the brand the same slug the other taxonomy captured, so a check
		// that looked only at the slug would treat this as a brand conflict.
		$brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		$vars = $this->run_resolver( [ 'test_other_tax' => $brand->slug ], 'topic/' . $brand->slug );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $vars, 'Only a /brand/ collision should reroute.' );
		$this->assertSame( $brand->slug, $vars['test_other_tax'], 'The other taxonomy query var should be untouched.' );

		// Control: the same brand, reached through the taxonomy that does
		// rewrite under /brand/, is claimed.
		$vars = $this->run_resolver( [ 'test_product_brand' => $brand->slug ], 'brand/' . $brand->slug );
		$this->assertSame( $brand->slug, $vars[ Taxonomy::SLUG ], 'The /brand/ collision is still resolved.' );
	}

	/**
	 * Tests that homepage-mode brands (_custom_url=yes) are not claimed at
	 * /brand/{slug}/ — they live at the site root instead.
	 */
	public function test_parse_request_skips_homepage_mode_brands() {
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/%postname%/' );

		$homepage_brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );
		add_term_meta( $homepage_brand->term_id, Url_Meta::get_key(), 'yes' );
		$path_brand = $this->factory->term->create_and_get( [ 'taxonomy' => Taxonomy::SLUG ] );

		$vars = $this->run_resolver(
			[ 'test_product_brand' => $homepage_brand->slug ],
			'brand/' . $homepage_brand->slug
		);

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $vars, 'Homepage-mode brand should not be claimed at /brand/ path.' );
		$this->assertSame( $homepage_brand->slug, $vars['test_product_brand'], 'Conflicting query var should remain for homepage-mode brands.' );

		// Control: a brand without that meta, same fixture, is claimed — so the
		// decline above is the URL mode deciding.
		$vars = $this->run_resolver( [ 'test_product_brand' => $path_brand->slug ], 'brand/' . $path_brand->slug );
		$this->assertSame( $path_brand->slug, $vars[ Taxonomy::SLUG ], 'A default-mode brand at the same path is claimed.' );
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
		$this->register_conflicting_taxonomy();
		$this->set_permalinks_and_reregister( '/blog/%postname%/' );

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

		$vars = $this->run_resolver( [ 'test_product_brand' => 'nike' ], 'brand/nike' );

		$this->assertArrayNotHasKey( Taxonomy::SLUG, $vars, 'A URL that is not the brand permalink must not be claimed.' );
		$this->assertSame( 'nike', $vars['test_product_brand'], 'The WooCommerce query var must survive.' );

		// Control: our own path under the same fronted structure is claimed, so
		// the decline above is the path comparison deciding rather than a
		// resolver that never acts under a permalink front.
		$vars = $this->run_resolver( [ 'test_product_brand' => 'nike' ], 'blog/brand/nike' );
		$this->assertSame( 'nike', $vars[ Taxonomy::SLUG ] ?? null, 'The brand’s own fronted path is claimed.' );
	}
}
