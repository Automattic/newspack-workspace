<?php
/**
 * Characterization tests for the migrate-membership-gates CLI (NPPD-2059).
 *
 * These pin the behavior of the pure mapping/fingerprint/layout-extraction
 * helpers. The map_rules_to_ac_format tests assert the NPPD-2063 translation table
 * (WC content rules → valid AC 'post_types' / 'specific_posts' / taxonomy slugs).
 * Where a test asserts a buggy result on purpose it is flagged with the follow-up
 * issue ID; that stacked fix will flip the corresponding assertion:
 *
 * - NPPD-2058: extract_gate_layouts() only inspects top-level wrapper blocks, so
 *   nested / reusable-block gate layouts migrate as empty. Pinned by the
 *   extract_gate_layouts / serialize_gate_inner_blocks tests below (they flip red).
 *
 * NOT pinned here: NPPD-2064 (fingerprint-based gate splitting/grouping). That fix
 * lands in group_plans_by_fingerprint() and the merged-product consolidation, which
 * depend on WC_Memberships_Membership_Plan and so are not unit-testable in this
 * harness — they are exercised end-to-end against real WooCommerce Memberships. The
 * compute_rules_fingerprint() tests below only pin the fingerprint's *canonicality*
 * (order-independence), which the 2064 fix preserves; they will NOT flip red, so the
 * 2064 author must add net-new grouping/split tests rather than rely on these.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\CLI\Membership_Gates_Migration;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-membership-gates-migration.php';

/**
 * Characterization tests for the migrate-membership-gates helpers.
 */
class Test_Membership_Gates_Migration extends \WP_UnitTestCase {

	/**
	 * Invoke a private static method on the CLI class via reflection.
	 *
	 * @param string $method_name The method name.
	 * @param array  $arguments   Positional arguments.
	 *
	 * @return mixed The method return value.
	 */
	private function invoke_private_static( string $method_name, array $arguments ) {
		$reflected_method = new \ReflectionMethod( Membership_Gates_Migration::class, $method_name );
		$reflected_method->setAccessible( true );
		return $reflected_method->invoke( null, ...$arguments );
	}

	/**
	 * Build a minimal stand-in for a WC_Memberships_Membership_Plan_Rule.
	 *
	 * The rule mapping only calls get_content_type(), get_content_type_name() and
	 * get_object_ids(), so the WC Memberships plugin is not needed to exercise it.
	 *
	 * @param string $content_type      The WC content type kind ('post_type' or 'taxonomy').
	 * @param string $content_type_name The WC content type name (e.g. 'post', 'category').
	 * @param int[]  $object_ids        The restricted object IDs.
	 *
	 * @return object A rule-shaped object.
	 */
	private function make_rule( string $content_type, string $content_type_name, array $object_ids ) {
		return new class( $content_type, $content_type_name, $object_ids ) {

			/**
			 * The WC content type kind.
			 *
			 * @var string
			 */
			private $content_type;

			/**
			 * The WC content type name.
			 *
			 * @var string
			 */
			private $content_type_name;

			/**
			 * The restricted object IDs.
			 *
			 * @var int[]
			 */
			private $object_ids;

			/**
			 * Constructor.
			 *
			 * @param string $content_type      The WC content type kind.
			 * @param string $content_type_name The WC content type name.
			 * @param int[]  $object_ids        The restricted object IDs.
			 */
			public function __construct( string $content_type, string $content_type_name, array $object_ids ) {
				$this->content_type      = $content_type;
				$this->content_type_name = $content_type_name;
				$this->object_ids        = $object_ids;
			}

			/**
			 * Return the WC content type kind ('post_type' or 'taxonomy').
			 *
			 * @return string
			 */
			public function get_content_type() {
				return $this->content_type;
			}

			/**
			 * Return the WC content type name.
			 *
			 * @return string
			 */
			public function get_content_type_name() {
				return $this->content_type_name;
			}

			/**
			 * Return the restricted object IDs.
			 *
			 * @return int[]
			 */
			public function get_object_ids() {
				return $this->object_ids;
			}
		};
	}

	/**
	 * A post-type rule targeting specific objects maps to a 'specific_posts' rule
	 * whose value is the stringified object IDs — the slug AC enforcement honours for
	 * individual posts (a raw 'post'/'page' slug would never match any post).
	 */
	public function test_map_rules_to_ac_format_maps_specific_post_type_rule_to_specific_posts() {
		$post_rule = $this->make_rule( 'post_type', 'post', [ 12, 34 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $post_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ '12', '34' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * A post-type rule with no object IDs restricts the whole post type, so it maps
	 * to a 'post_types' rule whose value is the post-type slug.
	 */
	public function test_map_rules_to_ac_format_maps_all_posts_rule_to_post_types() {
		$all_posts_rule = $this->make_rule( 'post_type', 'post', [] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $all_posts_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * The post_type vs. taxonomy split relies on the rule's own get_content_type()
	 * discriminator, so a custom post type (here 'guest-author') with specific
	 * objects maps to 'specific_posts' just like a built-in post type — no hardcoded
	 * post-type name list is consulted.
	 */
	public function test_map_rules_to_ac_format_maps_custom_post_type_rule_to_specific_posts() {
		$guest_author_rule = $this->make_rule( 'post_type', 'guest-author', [ 91 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $guest_author_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ '91' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Taxonomy rules already use the taxonomy slug as their AC slug (which AC
	 * enforcement resolves via get_taxonomy()), so they pass through unchanged with a
	 * term-ID value list.
	 */
	public function test_map_rules_to_ac_format_keeps_taxonomy_slug_unchanged() {
		$category_rule = $this->make_rule( 'taxonomy', 'category', [ 5, 6 ] );
		$tag_rule      = $this->make_rule( 'taxonomy', 'post_tag', [ 7 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $category_rule, $tag_rule ] ]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'category',
					'value' => [ '5', '6' ],
				],
				[
					'slug'  => 'post_tag',
					'value' => [ '7' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Two taxonomy rules for the same taxonomy collapse into a single AC rule with a
	 * de-duplicated, stringified term-ID list.
	 */
	public function test_map_rules_to_ac_format_merges_and_dedupes_object_ids_for_the_same_slug() {
		$first_category_rule  = $this->make_rule( 'taxonomy', 'category', [ 1, 2 ] );
		$second_category_rule = $this->make_rule( 'taxonomy', 'category', [ 2, 3 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $first_category_rule, $second_category_rule ] ]
		);

		$this->assertCount( 1, $mapped_rules, 'Same-slug rules collapse into a single AC rule.' );
		$this->assertSame( 'category', $mapped_rules[0]['slug'] );
		$this->assertSame( [ '1', '2', '3' ], $mapped_rules[0]['value'], 'Object IDs are merged, de-duplicated, and stringified.' );
	}

	/**
	 * A mixed rule set exercises all three mappings and their merge semantics at
	 * once: whole-post-type rules merge their post-type slugs under 'post_types',
	 * specific-object rules (across different post types) merge their IDs under
	 * 'specific_posts', and a taxonomy rule keeps its own slug.
	 */
	public function test_map_rules_to_ac_format_merges_mixed_rule_set_by_target_slug() {
		$all_posts_rule    = $this->make_rule( 'post_type', 'post', [] );
		$all_pages_rule    = $this->make_rule( 'post_type', 'page', [] );
		$specific_page     = $this->make_rule( 'post_type', 'page', [ 5 ] );
		$specific_articles = $this->make_rule( 'post_type', 'post', [ 12, 34 ] );
		$category_rule     = $this->make_rule( 'taxonomy', 'category', [ 8 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $all_posts_rule, $all_pages_rule, $specific_page, $specific_articles, $category_rule ] ]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post', 'page' ],
				],
				[
					'slug'  => 'specific_posts',
					'value' => [ '5', '12', '34' ],
				],
				[
					'slug'  => 'category',
					'value' => [ '8' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Rules with an empty content-type name are dropped entirely.
	 */
	public function test_map_rules_to_ac_format_skips_rules_with_empty_content_type() {
		$empty_rule = $this->make_rule( 'post_type', '', [ 7 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $empty_rule ] ] );

		$this->assertSame( [], $mapped_rules );
	}

	/**
	 * NPPD-2064 grouping key: the fingerprint is canonical, so rule sets that are
	 * equivalent up to rule order and object-ID order collapse to the same string
	 * (and therefore into a single gate).
	 */
	public function test_compute_rules_fingerprint_is_independent_of_rule_and_value_order() {
		$rules_in_one_order = [
			[
				'slug'  => 'category',
				'value' => [ '2', '1' ],
			],
			[
				'slug'  => 'post',
				'value' => [ '5' ],
			],
		];
		$rules_in_other_order = [
			[
				'slug'  => 'post',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'category',
				'value' => [ '1', '2' ],
			],
		];

		$fingerprint_one   = $this->invoke_private_static( 'compute_rules_fingerprint', [ $rules_in_one_order ] );
		$fingerprint_other = $this->invoke_private_static( 'compute_rules_fingerprint', [ $rules_in_other_order ] );

		$this->assertSame( $fingerprint_one, $fingerprint_other, 'Equivalent rule sets must share a fingerprint so they merge into one gate (NPPD-2064 seam).' );
	}

	/**
	 * Differing content rules produce different fingerprints, so the plans land in
	 * separate gates.
	 */
	public function test_compute_rules_fingerprint_differs_for_different_rules() {
		$category_rules = [
			[
				'slug'  => 'category',
				'value' => [ '1' ],
			],
		];
		$post_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
		];

		$this->assertNotSame(
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $category_rules ] ),
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $post_rules ] )
		);
	}

	/**
	 * Happy path: top-level non-member-content maps to the registration layout and
	 * top-level member-content maps to the paid-access (custom_access) layout, with
	 * the WooCommerce wrapper blocks themselves stripped from the output.
	 */
	public function test_extract_gate_layouts_reads_top_level_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Become a member to read this.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Thanks for being a member.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Become a member to read this.', $layouts['registration'] );
		$this->assertStringContainsString( 'Thanks for being a member.', $layouts['custom_access'] );
		$this->assertStringNotContainsString( 'woocommerce-memberships/non-member-content', $layouts['registration'], 'The wrapper block markup is not carried into the layout.' );
	}

	/**
	 * NPPD-2058: only top-level wrapper blocks are inspected, so a gate whose
	 * non-member-content wrapper is nested inside another block (here a group)
	 * migrates as an EMPTY registration layout. The stacked NPPD-2058 fix walks
	 * nested/reusable blocks and will make these assertions non-empty.
	 */
	public function test_extract_gate_layouts_returns_empty_for_nested_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:group --><div class="wp-block-group">
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Nested upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
</div><!-- /wp:group -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertSame( '', $layouts['registration'], 'A nested non-member-content wrapper yields an empty registration layout (NPPD-2058 bug).' );
		$this->assertNull( $layouts['custom_access'], 'No top-level member-content wrapper means a null custom-access layout.' );
	}

	/**
	 * The inner-block serializer drops WooCommerce Memberships wrapper blocks while
	 * keeping ordinary content blocks.
	 */
	public function test_serialize_gate_inner_blocks_strips_membership_wrapper_blocks() {
		$inner_blocks = parse_blocks(
			'<!-- wp:paragraph --><p>Keep me.</p><!-- /wp:paragraph -->'
			. '<!-- wp:woocommerce-memberships/member-content --><!-- wp:paragraph --><p>Drop me.</p><!-- /wp:paragraph --><!-- /wp:woocommerce-memberships/member-content -->'
		);

		$serialized = $this->invoke_private_static( 'serialize_gate_inner_blocks', [ $inner_blocks ] );

		$this->assertStringContainsString( 'Keep me.', $serialized );
		$this->assertStringNotContainsString( 'woocommerce-memberships/member-content', $serialized );
		$this->assertStringNotContainsString( 'Drop me.', $serialized );
	}

	/**
	 * Create a published post carrying the given block content, standing in for an
	 * np_memberships_gate.
	 *
	 * A plain post (the default 'post' type) is used because extract_gate_layouts()
	 * only reads post_content — it never checks the post type — so the real
	 * np_memberships_gate CPT (registered by WooCommerce Memberships, which is not
	 * loaded in the unit-test harness) is not needed here.
	 *
	 * @param string $content The block markup.
	 *
	 * @return \WP_Post
	 */
	private function create_gate_post( string $content ): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);
		return get_post( $post_id );
	}
}
