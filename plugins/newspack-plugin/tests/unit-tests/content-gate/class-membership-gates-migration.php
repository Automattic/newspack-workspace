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
	 * Build a plan-group descriptor as group_plans_by_fingerprint() would, carrying
	 * just the access method group_requires_purchase() inspects.
	 *
	 * @param string $access_method The WCM plan access method ('purchase' or 'signup').
	 *
	 * @return array
	 */
	private function make_group_plan( string $access_method ): array {
		return [
			'pid'           => 0,
			'name'          => 'Plan',
			'access_method' => $access_method,
			'ac_rules'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * A group is purchase-gated only when EVERY plan requires a purchase — the two
	 * gate modes AND for a logged-in reader, so a mixed group would demand the
	 * subscription from members the signup plan granted for free. A group holding one
	 * signup plan and one purchase plan is therefore registration-gated, not
	 * purchase-gated.
	 */
	public function test_group_requires_purchase_only_when_every_plan_is_purchase() {
		$all_purchase = [ $this->make_group_plan( 'purchase' ), $this->make_group_plan( 'purchase' ) ];
		$mixed        = [ $this->make_group_plan( 'signup' ), $this->make_group_plan( 'purchase' ) ];
		$all_signup   = [ $this->make_group_plan( 'signup' ) ];

		$this->assertTrue(
			$this->invoke_private_static( 'group_requires_purchase', [ $all_purchase ] ),
			'A group where every plan requires a purchase is purchase-gated.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'group_requires_purchase', [ $mixed ] ),
			'A mixed signup+purchase group is registration-gated — the signup plan grants the more permissive access.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'group_requires_purchase', [ $all_signup ] ),
			'A signup-only group is registration-gated.'
		);
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
	 * discriminator, so a whole-post-type rule for a custom post type (here
	 * 'guest-author') maps to a 'post_types' rule carrying that custom post-type slug
	 * as its value — no hardcoded post-type name list is consulted.
	 */
	public function test_map_rules_to_ac_format_maps_custom_post_type_to_post_types() {
		$guest_author_rule = $this->make_rule( 'post_type', 'guest-author', [] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $guest_author_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'guest-author' ],
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
	 * Two rules that map to the same AC slug are merged into one rule with a
	 * de-duplicated, stringified value list.
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
	 * 'specific_posts', and a taxonomy rule keeps its own slug. The 'post_types'
	 * value is sorted (see the canonicalization test below).
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
					'value' => [ 'page', 'post' ],
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
			$mapped_rules,
			'Only post_types is canonicalized; specific_posts and taxonomy values keep insertion order (the fingerprint orders those numeric IDs via SORT_NUMERIC).'
		);
	}

	/**
	 * The 'post_types' value is sorted, so two plans restricting the same post types
	 * in a different rule order produce identical mapped output — and therefore the
	 * same grouping fingerprint, so they share one gate instead of splitting into
	 * duplicates. (Post-type slugs are non-numeric, and compute_rules_fingerprint()'s
	 * SORT_NUMERIC pass would otherwise leave their order untouched.)
	 */
	public function test_map_rules_to_ac_format_canonicalizes_post_types_value_order() {
		$posts_then_pages = [
			$this->make_rule( 'post_type', 'post', [] ),
			$this->make_rule( 'post_type', 'page', [] ),
		];
		$pages_then_posts = [
			$this->make_rule( 'post_type', 'page', [] ),
			$this->make_rule( 'post_type', 'post', [] ),
		];

		$mapped_posts_first = $this->invoke_private_static( 'map_rules_to_ac_format', [ $posts_then_pages ] );
		$mapped_pages_first = $this->invoke_private_static( 'map_rules_to_ac_format', [ $pages_then_posts ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'page', 'post' ],
				],
			],
			$mapped_posts_first,
			'post_types values are sorted, so rule order does not change the output.'
		);
		$this->assertSame( $mapped_posts_first, $mapped_pages_first, 'Rule order does not change the mapped output.' );

		$this->assertSame(
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $mapped_posts_first ] ),
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $mapped_pages_first ] ),
			'Identical output yields identical fingerprints, so the plans group into one gate.'
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
	 * A gate post may interleave several top-level wrappers of the same type (a post
	 * mixing public and members-only sections). Every wrapper's content is kept, in
	 * document order, for both wrapper types — no wrapper silently wins over another.
	 */
	public function test_extract_gate_layouts_concatenates_repeated_top_level_wrappers() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>First upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>First members-only section.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Second upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Second members-only section.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'First upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'Second upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'First members-only section.', $layouts['custom_access'] );
		$this->assertStringContainsString( 'Second members-only section.', $layouts['custom_access'] );
		$this->assertLessThan(
			strpos( $layouts['registration'], 'Second upsell.' ),
			strpos( $layouts['registration'], 'First upsell.' ),
			'Wrappers are concatenated in document order.'
		);
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
	 * A gate whose every content rule carries a slug the evaluator cannot resolve is
	 * reported as unenforceable.
	 *
	 * A raw WooCommerce content-type name ('post') is the canonical shape of an
	 * unresolvable slug: Content_Restriction_Control::rule_matches_post() handles
	 * 'post_types', 'specific_posts' and 'newsletters' by name and treats every other
	 * slug as a taxonomy, so it falls through to get_taxonomy( 'post' ) — which is
	 * null — and the gate matches no post at all.
	 */
	public function test_verify_migrated_gate_flags_content_rules_the_evaluator_cannot_resolve() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post',
					'value' => [ '1' ],
				],
			] 
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0] );
	}

	/**
	 * A gate whose rules are only partly resolvable under-gates rather than failing
	 * outright: the rules combine with 'any', so the content behind the dead slugs is
	 * left readable while the rest is gated. That partial leak is reported too, since
	 * reporting such a gate clean would hide the leak until cutover.
	 */
	public function test_verify_migrated_gate_flags_content_rules_only_some_of_which_resolve() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post',
					'value' => [ '1' ],
				],
				[
					'slug'  => 'category',
					'value' => [ '2' ],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0], 'The dead slug is named so the operator knows what is left ungated.' );
	}

	/**
	 * A gate migrated from a plan that required a purchase, but whose paid access mode
	 * was never activated, lets any registered reader through: registration mode alone
	 * stops nobody with an account, since the migration never writes
	 * require_verification. That is a worse outcome than an inert gate — the content
	 * silently loses its paywall at cutover — so it must not pass verification.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_with_no_paid_access_mode() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'its paid access mode is not active', $issues[0] );
		$this->assertSame(
			[],
			$this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, false ] ),
			'The same gate is fine for a signup-only plan — only the purchase requirement makes it a leak.'
		);
	}

	/**
	 * An active paid access mode with no access rules asks for no purchase:
	 * is_post_restricted() skips an empty rule set, so a registered reader passes.
	 * Reachable when every one of a plan's products is a variation (dropped as gates
	 * reference parent products only) or when the plan has no products at all.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_whose_paid_access_has_no_rules() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid access fixture layout', '' ),
				'access_rules'   => [],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * The paid path migrated fully — an active paid access mode constrained by the
	 * plan's products — so nothing is reported.
	 */
	public function test_verify_migrated_gate_passes_a_purchase_gate_with_product_access_rules() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid access fixture layout', '' ),
				'access_rules'   => [
					[
						[
							'slug'  => 'subscription',
							'value' => [ 123 ],
						],
					],
				],
			]
		);

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] ) );
	}

	/**
	 * The evaluator only checks that a mode's layout ID is truthy, so a blank layout
	 * post counts as "gated" and the reader gets a truncated article with nothing
	 * under it — no form, no upsell, no explanation.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_an_empty_layout() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		$layout_id = \Newspack\Content_Gate::get_registration_settings( $gate_id )['gate_layout_id'];
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => '',
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'points at an empty layout', $issues[0] );
	}

	/**
	 * A gate written with rule slugs the evaluator handles by name, an active mode and
	 * a layout post passes verification with no issues.
	 */
	public function test_verify_migrated_gate_passes_for_an_enforceable_gate() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] ) );
	}

	/**
	 * An active mode pointing at no layout post restricts nothing —
	 * Content_Restriction_Control requires a truthy gate_layout_id — so it is flagged.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_no_layout() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);
		\Newspack\Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => 0,
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains( 'the registration mode is active with no layout', $issues );
	}

	/**
	 * A gate whose modes are all inactive is skipped outright by the evaluator.
	 */
	public function test_verify_migrated_gate_flags_a_gate_with_no_active_mode() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);
		\Newspack\Content_Gate::update_registration_settings( $gate_id, [ 'active' => false ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains( 'neither the registration nor the paid access mode is active', $issues );
	}

	/**
	 * Slug resolvability mirrors Content_Restriction_Control::rule_matches_post():
	 * three slugs are handled by name and everything else must be a registered
	 * taxonomy.
	 */
	public function test_is_content_rule_slug_resolvable_matches_the_evaluator() {
		foreach ( [ 'post_types', 'specific_posts', 'newsletters', 'category', 'post_tag' ] as $resolvable_slug ) {
			$this->assertTrue(
				$this->invoke_private_static( 'is_content_rule_slug_resolvable', [ $resolvable_slug ] ),
				sprintf( 'Expected "%s" to be resolvable.', $resolvable_slug )
			);
		}
		foreach ( [ 'post', 'page', 'not_a_taxonomy' ] as $unresolvable_slug ) {
			$this->assertFalse(
				$this->invoke_private_static( 'is_content_rule_slug_resolvable', [ $unresolvable_slug ] ),
				sprintf( 'Expected "%s" to be unresolvable.', $unresolvable_slug )
			);
		}
	}

	// -------------------------------------------------------------------------
	// compute_pre_write_issues() — dry-run predictive verification
	// -------------------------------------------------------------------------

	/**
	 * A plan with all-unresolvable content-rule slugs is flagged in dry-run, mirroring
	 * the "none of its content rules resolve" check in verify_migrated_gate().
	 */
	public function test_compute_pre_write_issues_flags_all_unresolvable_slugs() {
		$ac_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
		];
		$layouts  = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0] );
	}

	/**
	 * When only some slugs are unresolvable, the partial-leak variant of the message
	 * is produced — the content behind the dead rules stays ungated while the rest is
	 * covered.
	 */
	public function test_compute_pre_write_issues_flags_partially_unresolvable_slugs() {
		$ac_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
			[
				'slug'  => 'category',
				'value' => [ '2' ],
			],
		];
		$layouts = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
	}

	/**
	 * A purchase plan with no custom_access layout extracted is flagged — apply_layout()
	 * will be skipped for the paid access mode, so any registered reader gets through.
	 * Mirrors verify_migrated_gate()'s "paid access mode is not active" check.
	 */
	public function test_compute_pre_write_issues_flags_purchase_plan_with_no_custom_access_layout() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts, [ 123 ] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access mode will not be activated', $issues[0] );
	}

	/**
	 * A purchase plan whose merged product IDs are all empty is flagged — access_rules
	 * will be an empty array, so the paid access mode asks for no purchase and any
	 * registered reader passes. Mirrors verify_migrated_gate()'s "active but has no
	 * access rules" check.
	 */
	public function test_compute_pre_write_issues_flags_purchase_plan_with_empty_product_ids() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => '<p>Member content.</p>',
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * A signup-only plan with resolvable slugs produces no pre-write issues.
	 */
	public function test_compute_pre_write_issues_returns_empty_for_a_clean_signup_plan() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Register.</p>',
			'custom_access' => null,
		];

		$this->assertSame(
			[],
			$this->invoke_private_static(
				'compute_pre_write_issues',
				[ $ac_rules, false, $layouts, [] ]
			)
		);
	}

	/**
	 * A purchase plan with a custom_access layout and at least one product ID is clean.
	 */
	public function test_compute_pre_write_issues_returns_empty_for_a_clean_purchase_plan() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => '<p>Welcome.</p>',
		];

		$this->assertSame(
			[],
			$this->invoke_private_static(
				'compute_pre_write_issues',
				[ $ac_rules, true, $layouts, [ 99 ] ]
			)
		);
	}

	/**
	 * Create a published gate with an active registration mode pointing at a real
	 * layout post — i.e. enforceable except for the content rules under test.
	 *
	 * @param array[] $content_rules AC-format content rules.
	 *
	 * @return int The gate post ID.
	 */
	private function create_enforceable_gate( array $content_rules ): int {
		$gate_id = \Newspack\Content_Gate::create_gate( [ 'title' => 'Verification fixture' ] );
		\Newspack\Content_Rules::update_gate_content_rules( $gate_id, $content_rules );
		\Newspack\Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Verification fixture layout', '' ),
			]
		);
		return $gate_id;
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
