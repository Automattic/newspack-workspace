<?php
/**
 * Tests for brand URL resolution when another taxonomy claims the same rewrite slug.
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;

/**
 * Routes a real request through WP_Rewrite with a colliding taxonomy registered.
 *
 * These go through `go_to()` rather than calling `Url::parse_request()` with a
 * hand-built `WP` object, because the defect being covered is one of rewrite-rule
 * precedence: which of two rules matching the same path wins. A hand-built object
 * asserts the answer instead of producing it.
 */
class TestUrlRewriteConflict extends WP_UnitTestCase {

	/**
	 * Rewrite state captured before the test rebuilds it.
	 *
	 * @var array
	 */
	private $rewrite_snapshot = [];

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rewrite;
		$this->rewrite_snapshot = [
			'extra_permastructs' => $wp_rewrite->extra_permastructs,
			'extra_rules_top'    => $wp_rewrite->extra_rules_top,
		];
	}

	/**
	 * Tear down.
	 *
	 * Restoring the two WP_Rewrite arrays is not optional bookkeeping. Neither is
	 * reset between tests by WordPress itself, so a test that rebuilds them leaks
	 * its routing into every later test in the process — which surfaces as an
	 * unrelated taxonomy test failing only when the suite runs in full.
	 */
	public function tear_down() {
		global $wp_rewrite;
		if ( taxonomy_exists( 'test_colliding_brand' ) ) {
			unregister_taxonomy( 'test_colliding_brand' );
		}
		$wp_rewrite->extra_permastructs = $this->rewrite_snapshot['extra_permastructs'];
		$wp_rewrite->extra_rules_top    = $this->rewrite_snapshot['extra_rules_top'];
		unregister_taxonomy( Taxonomy::SLUG );
		Taxonomy::register_taxonomy();
		parent::tear_down();
	}

	/**
	 * Rebuild routing, optionally with a taxonomy that claims our `brand` slug first.
	 *
	 * Registration order is what production exposes: a plugin registering on an
	 * earlier `init` priority generates its rules before ours, and the first rule
	 * matching a path wins. Two pieces of WP_Rewrite state have to be cleared to
	 * reproduce that here, and only one is obvious. `extra_permastructs` keeps a
	 * key's original slot when overwritten, so ours has to be removed before the
	 * colliding taxonomy registers. And `extra_rules_top` is never reset —
	 * `rewrite_rules()` merges onto whatever earlier calls left in it — so without
	 * clearing it the rules generated first in the process stay first, whatever
	 * order the permastructs are in.
	 *
	 * @param bool $with_conflict Whether to register the colliding taxonomy.
	 */
	private function setup_routing( $with_conflict ) {
		global $wp_rewrite;
		$this->set_permalink_structure( '/%postname%/' );

		unregister_taxonomy( Taxonomy::SLUG );
		unset( $wp_rewrite->extra_permastructs[ Taxonomy::SLUG ] );
		$wp_rewrite->extra_rules_top = [];

		if ( $with_conflict ) {
			register_taxonomy(
				'test_colliding_brand',
				[ 'post' ],
				[
					'public'       => true,
					'hierarchical' => true,
					'query_var'    => true,
					'rewrite'      => [
						'slug'         => 'brand',
						'with_front'   => false,
						'hierarchical' => true,
					],
				]
			);
		}
		Taxonomy::register_taxonomy();
		$wp_rewrite->rules = [];
		// The VIP sniff guards against flushing on a live request. Rebuilding the
		// rules is the whole point here: the rule order this produces is what the
		// tests below are about.
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
	}

	/**
	 * With nothing competing for the slug, a brand archive resolves normally.
	 *
	 * This is the control: it fails if `setup_routing()` has broken routing
	 * outright, which would otherwise make the conflict test below pass for the
	 * wrong reason.
	 */
	public function test_brand_archive_resolves_without_a_conflict() {
		$this->setup_routing( false );
		$term = $this->factory->term->create_and_get(
			[
				'taxonomy' => Taxonomy::SLUG,
				'slug'     => 'lifestyle',
			]
		);

		$this->go_to( home_url( '/brand/lifestyle/' ) );

		$this->assertFalse( is_404(), 'A brand archive must resolve when nothing competes for the slug.' );
		$this->assertSame( $term->term_id, get_queried_object()->term_id ?? 0 );
	}

	/**
	 * A taxonomy that wins the `brand` rule must not take the brand's own archive.
	 */
	public function test_brand_archive_resolves_when_another_taxonomy_wins_the_rule() {
		$this->setup_routing( true );
		$term = $this->factory->term->create_and_get(
			[
				'taxonomy' => Taxonomy::SLUG,
				'slug'     => 'lifestyle',
			]
		);

		$this->go_to( home_url( '/brand/lifestyle/' ) );

		global $wp;
		$this->assertStringContainsString(
			'test_colliding_brand=',
			(string) $wp->matched_query,
			'Precondition: the colliding rule has to be the one that matched, or this test proves nothing.'
		);
		$this->assertFalse( is_404(), 'A collision on the slug must not 404 the brand archive.' );
		$this->assertSame( $term->term_id, get_queried_object()->term_id ?? 0, 'The brand term should be the queried object.' );
	}
}
